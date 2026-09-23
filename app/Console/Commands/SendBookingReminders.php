<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\OrderSlot;
use App\Mail\BookingReminder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SendBookingReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reminders:send-booking';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send booking reminders to vendors (24h and 1h before appointment)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting booking reminders check...');

        $now = Carbon::now();

        // Fetch organization preferences for booking reminders (across all tenants)
        $orgPreferences = \App\Models\NotificationPreference::withoutGlobalScopes()
            ->where('event_type', 'booking_reminder')
            ->whereNull('user_id')
            ->get()
            ->groupBy('organization_id');

        // Fetch all upcoming non-cancelled slots with vendors
        $slots = OrderSlot::with(['vendor', 'order.organization', 'service'])
            ->where('date', '>=', $now->toDateString())
            ->whereNotNull('vendor_id')
            ->where(function ($query) {
                $query->whereNull('status')->orWhere('status', '!=', 'cancelled');
            })
            ->get();

        $this->info("Total active upcoming slots fetched: {$slots->count()}");

        $defaultIntervals = [
            ['value' => 24, 'unit' => 'hours'],
            ['value' => 1, 'unit' => 'hours'],
        ];

        foreach ($slots as $slot) {
            $slotDateTime = Carbon::parse($slot->date . ' ' . $slot->start_time);
            $hoursDiff = $now->diffInMinutes($slotDateTime, false) / 60.0;

            if ($hoursDiff <= 0) {
                continue; // Skip past appointments
            }

            // Determine organization intervals
            $orgId = $slot->order?->organization_id ?? $slot->vendor?->organization_id;
            $intervals = $defaultIntervals;

            if ($orgId && isset($orgPreferences[$orgId])) {
                $pref = $orgPreferences[$orgId]->firstWhere('role', 'vendor') 
                     ?? $orgPreferences[$orgId]->first();
                if ($pref && !empty($pref->intervals) && is_array($pref->intervals)) {
                    $intervals = $pref->intervals;
                }
            }

            foreach ($intervals as $intervalItem) {
                // Normalize interval item to value and unit
                if (is_array($intervalItem) && isset($intervalItem['value'], $intervalItem['unit'])) {
                    $val = (float) $intervalItem['value'];
                    $unit = strtolower($intervalItem['unit']);
                } elseif (is_numeric($intervalItem)) {
                    $val = (float) $intervalItem;
                    $unit = 'hours';
                } elseif (is_string($intervalItem) && preg_match('/^(\d+)([a-zA-Z]+)$/', $intervalItem, $matches)) {
                    $val = (float) $matches[1];
                    $unitChar = strtolower($matches[2]);
                    $unit = match ($unitChar) {
                        'w' => 'weeks',
                        'd' => 'days',
                        'h' => 'hours',
                        'm' => 'minutes',
                        default => 'hours',
                    };
                } else {
                    continue;
                }

                $targetHours = match ($unit) {
                    'weeks'   => $val * 24 * 7,
                    'days'    => $val * 24,
                    'hours'   => $val,
                    'minutes' => $val / 60.0,
                    default   => $val,
                };

                // Precision window check: slot is within the milestone window for the hourly cron
                // (e.g. For a 24h reminder: triggers when remaining time is between 22.8h and 24.0h)
                if ($hoursDiff <= $targetHours && $hoursDiff > ($targetHours - 1.2)) {
                    $type = "{$val}_{$unit}";
                    $legacyType = "{$val}h";

                    // Check if already sent under structured or legacy milestone key
                    $alreadySent = DB::table('booking_reminders')
                        ->where('order_slot_id', $slot->id)
                        ->whereIn('type', [$type, $legacyType])
                        ->exists();

                    if ($alreadySent) {
                        continue;
                    }

                    try {
                        // Dispatch reminders via unified EmailDispatchService
                        app(\App\Services\EmailDispatchService::class)->dispatch('booking_reminder', $slot);

                        DB::table('booking_reminders')->insert([
                            'order_slot_id' => $slot->id,
                            'type' => $type,
                            'sent_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        Log::info('Booking reminder dispatched', [
                            'slot_id' => $slot->id,
                            'milestone' => $type,
                            'hours_remaining' => round($hoursDiff, 2),
                        ]);

                        $this->info("Dispatched {$type} reminder for Slot #{$slot->id} (Remaining: " . round($hoursDiff, 2) . "h)");
                    } catch (\Exception $e) {
                        Log::error('Failed to dispatch booking reminder', [
                            'slot_id' => $slot->id,
                            'milestone' => $type,
                            'error' => $e->getMessage(),
                        ]);
                        $this->error("Failed to dispatch {$type} reminder for Slot #{$slot->id}: " . $e->getMessage());
                    }
                }
            }
        }

        $this->info('Booking reminders check completed.');
    }
}
