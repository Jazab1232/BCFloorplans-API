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

        // Default reminder intervals in hours
        $intervals = [24, 1];

        // Fetch from settings if available
        $setting = \App\Models\Setting::where('key', 'booking_reminders')->first();
        if ($setting && isset($setting->value['intervals'])) {
            $intervals = $setting->value['intervals'];
        }

        Log::info('Reminder intervals', [
            'intervals' => $intervals,
            'now' => $now->toDateTimeString(),
        ]);

        // Fetch all upcoming slots with vendors
        $slots = OrderSlot::with(['vendor', 'order','service'])
            ->where('date', '>=', $now->toDateString())
            ->whereNotNull('vendor_id')
            ->get();

        Log::info('Total slots fetched', ['count' => $slots->count()]);

        foreach ($slots as $slot) {
            $slotDateTime = Carbon::parse($slot->date . ' ' . $slot->start_time);
            $hoursDiff = $now->diffInHours($slotDateTime, false);

            Log::info('Slot time check', [
                'slot_id' => $slot->id,
                'slot_time' => $slotDateTime->toDateTimeString(),
                'hours_diff' => $hoursDiff,
            ]);

            $type = null;
            $tolerance = 0.5; // ±30 minutes

            foreach ($intervals as $interval) {
                if ($hoursDiff >= $interval - $tolerance && $hoursDiff <= $interval + $tolerance) {
                    $type = $interval . 'h';
                    break;
                }
            }

            if (!$type) {
                continue; // Skip slots outside any reminder window
            }

            // Skip if reminder already sent
            $alreadySent = DB::table('booking_reminders')
                ->where('order_slot_id', $slot->id)
                ->where('type', $type)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            try {
                // Dispatch reminders to both agent and vendor via unified EmailDispatchService
                app(\App\Services\EmailDispatchService::class)->dispatch('booking_reminder', $slot);

                DB::table('booking_reminders')->insert([
                    'order_slot_id' => $slot->id,
                    'type' => $type ,
                    'sent_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                Log::info('Reminder processed via dispatch service', [
                    'slot_id' => $slot->id,
                    'type' => $type,
                ]);

                $this->info("Processed {$type} reminder for Slot {$slot->id}");
            } catch (\Exception $e) {
                Log::error('Failed to send reminder emails', [
                    'slot_id' => $slot->id,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Failed to process {$type} reminder for Slot {$slot->id}: " . $e->getMessage());
            }
        }

        $this->info('Booking reminders check completed.');
    }
}
