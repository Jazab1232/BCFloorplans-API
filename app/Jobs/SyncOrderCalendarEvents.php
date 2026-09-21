<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderSlot;
use App\Services\GoogleCalendarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SyncOrderCalendarEvents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120;
    public $backoff = [5, 15, 30];

    public function __construct(
        public int $orderId,
        public array $deletedSlotIds = []
    ) {}

    public function handle(): void
    {
        $calendarService = app(GoogleCalendarService::class);

        Log::info('Calendar sync job started', [
            'order_id' => $this->orderId,
            'deleted_slots' => $this->deletedSlotIds
        ]);

        try {
            $order = Order::with([
                'slots.vendor',
                'slots.vendor.workHours',
                'agent',
                'property',
                'slots.service',
            ])->find($this->orderId);

            if (!$order) {
                Log::warning('Calendar sync aborted: order not found', [
                    'order_id' => $this->orderId
                ]);
                return;
            }

            if ($order->order_status === 'Cancelled') {
                Log::info('Order is cancelled. Cleaning up calendar events.', [
                    'order_id' => $this->orderId
                ]);
                $this->handleCancellation($calendarService, $order);
                return;
            }

            Log::info('Fetched order for calendar sync', [
                'order_id' => $this->orderId,
                'slots_count' => $order->slots->count(),
                'property_address' => $order->property->address ?? 'N/A',
            ]);

            // Handle deleted slots first
            if (!empty($this->deletedSlotIds)) {
                $this->handleDeletedSlots($calendarService, $this->deletedSlotIds);
            }

            // If order has no remaining slots, exit early
            if ($order->slots->isEmpty()) {
                Log::info('Order has no remaining slots to sync to calendar', [
                    'order_id' => $this->orderId,
                ]);
                return;
            }

            // Group slots by vendor and sync
            $slotsByVendor = $order->slots->groupBy('vendor_id');
            
            Log::info('Grouped slots by vendor', [
                'order_id' => $this->orderId,
                'vendors_count' => $slotsByVendor->count(),
            ]);

            foreach ($slotsByVendor as $vendorId => $slots) {
                $this->syncOrderForVendor($calendarService, $order, $slots);
            }

            // Sync for agent
            $this->syncOrderForAgent($calendarService, $order);

        } catch (\Throwable $jobError) {
            Log::critical('Calendar sync job crashed', [
                'order_id' => $this->orderId,
                'error' => $jobError->getMessage(),
                'trace' => $jobError->getTraceAsString(),
            ]);
            
            throw $jobError;
        }

        Log::info('Calendar sync job finished', [
            'order_id' => $this->orderId
        ]);
    }

    /**
     * Sync order for a specific vendor (one event per order/vendor)
     */
    
    protected function syncOrderForVendor(
        GoogleCalendarService $calendarService,
        Order $order,
        $slots
    ): void {
        $vendor = $slots->first()->vendor;

        if (!$vendor) {
            Log::warning('Vendor not found for slots', [
                'order_id' => $order->id,
            ]);
            return;
        }

        if (!$vendor->google_access_token) {
            Log::info('Vendor skipped (no Google calendar token)', [
                'vendor_id' => $vendor->id,
                'order_id' => $order->id,
            ]);
            return;
        }

        Log::info('Syncing order slots for vendor', [
            'order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'vendor_name' => $vendor->first_name . ' ' . $vendor->last_name,
            'slots_count' => $slots->count(),
        ]);

        try {
            foreach ($slots as $slot) {
                // Ensure service is loaded
                $slot->loadMissing('service');

                $province = $order->property->state ?? $order->property->province ?? $order->province ?? 'BC';
                $tz = GoogleCalendarService::resolveTimezoneFromProvince($province, $vendor->workHours->first()?->timezone ?? 'America/Vancouver');

                $start = Carbon::createFromFormat('Y-m-d H:i:s', "{$slot->date} {$slot->start_time}", $tz);
                $end = Carbon::createFromFormat('Y-m-d H:i:s', "{$slot->date} {$slot->end_time}", $tz);

                $serviceName = $slot->service->name ?? 'Service';

                $eventData = [
                    'summary' => "Order #{$order->id} – {$order->property->postal_code}, {$order->property->address}, {$order->property->city} - Service: {$serviceName}",
                    'description' => $this->buildVendorInstructionDescriptionForSlot($order, $slot, $vendor),
                    'start' => $start,
                    'end' => $end,
                    'timezone' => $tz,
                    'location' => $order->property->address ?? '',
                ];

                $existingEventId = $slot->google_event_id;

                if ($existingEventId) {
                    Log::info('Updating existing calendar event for slot', [
                        'order_id' => $order->id,
                        'vendor_id' => $vendor->id,
                        'slot_id' => $slot->id,
                        'event_id' => $existingEventId,
                    ]);

                    $success = $calendarService->updateEvent($vendor, $existingEventId, $eventData);

                    if (!$success) {
                        Log::warning('Event update failed, creating new event for slot', [
                            'order_id' => $order->id,
                            'vendor_id' => $vendor->id,
                            'slot_id' => $slot->id,
                            'old_event_id' => $existingEventId,
                        ]);

                        $newEventId = $calendarService->createEvent($vendor, $eventData);

                        if ($newEventId) {
                            $slot->update(['google_event_id' => $newEventId]);
                        }
                    }
                } else {
                    Log::info('Creating new calendar event for slot', [
                        'order_id' => $order->id,
                        'vendor_id' => $vendor->id,
                        'slot_id' => $slot->id,
                    ]);

                    $eventId = $calendarService->createEvent($vendor, $eventData);

                    if ($eventId) {
                        $slot->update(['google_event_id' => $eventId]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to sync order for vendor', [
                'order_id' => $order->id,
                'vendor_id' => $vendor->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Sync order for the agent (separate event per slot)
     */
    protected function syncOrderForAgent(
        GoogleCalendarService $calendarService,
        Order $order
    ): void {
        $agent = $order->agent;

        if (!$agent) {
            Log::warning('Agent not found for order', [
                'order_id' => $order->id,
            ]);
            return;
        }

        if (!$agent->google_access_token) {
            Log::info('Agent skipped (no Google calendar token)', [
                'agent_id' => $agent->id,
                'order_id' => $order->id,
            ]);
            return;
        }

        Log::info('Syncing order slots for agent', [
            'order_id' => $order->id,
            'agent_id' => $agent->id,
            'agent_name' => $agent->first_name . ' ' . $agent->last_name,
            'slots_count' => $order->slots->count(),
        ]);

        try {
            foreach ($order->slots as $slot) {
                // Ensure service and vendor are loaded
                $slot->loadMissing(['service', 'vendor']);

                $province = $order->property->state ?? $order->property->province ?? $order->province ?? 'BC';
                $tz = GoogleCalendarService::resolveTimezoneFromProvince($province, 'America/Vancouver');

                $start = Carbon::createFromFormat('Y-m-d H:i:s', "{$slot->date} {$slot->start_time}", $tz);
                $end = Carbon::createFromFormat('Y-m-d H:i:s', "{$slot->date} {$slot->end_time}", $tz);

                $serviceName = $slot->service->name ?? 'Service';
                $vendorName = $slot->vendor ? "{$slot->vendor->first_name} {$slot->vendor->last_name}" : 'N/A';

                $eventData = [
                    'summary' => "Order #{$order->id} – {$order->property->address}, {$order->property->city} - Service: {$serviceName} (Vendor: {$vendorName})",
                    'description' => $this->buildAgentInstructionDescriptionForSlot($order, $slot),
                    'start' => $start,
                    'end' => $end,
                    'timezone' => $tz,
                    'location' => $order->property->address ?? '',
                ];

                $existingEventId = $slot->agent_google_event_id;

                if ($existingEventId) {
                    Log::info('Updating existing agent calendar event for slot', [
                        'order_id' => $order->id,
                        'agent_id' => $agent->id,
                        'slot_id' => $slot->id,
                        'event_id' => $existingEventId,
                    ]);

                    $success = $calendarService->updateEvent($agent, $existingEventId, $eventData);

                    if (!$success) {
                        Log::warning('Agent event update failed, creating new event for slot', [
                            'order_id' => $order->id,
                            'agent_id' => $agent->id,
                            'slot_id' => $slot->id,
                            'old_event_id' => $existingEventId,
                        ]);

                        $newEventId = $calendarService->createEvent($agent, $eventData);

                        if ($newEventId) {
                            $slot->update(['agent_google_event_id' => $newEventId]);
                        }
                    }
                } else {
                    Log::info('Creating new agent calendar event for slot', [
                        'order_id' => $order->id,
                        'agent_id' => $agent->id,
                        'slot_id' => $slot->id,
                    ]);

                    $eventId = $calendarService->createEvent($agent, $eventData);

                    if ($eventId) {
                        $slot->update(['agent_google_event_id' => $eventId]);
                    }
                }
            }

            // Cleanup old legacy order-level agent event if it exists
            if ($order->agent_google_event_id) {
                try {
                    $calendarService->deleteEvent($agent, $order->agent_google_event_id);
                    $order->update(['agent_google_event_id' => null]);
                } catch (\Throwable $e) {
                    // Ignore failure to delete legacy order-level event
                }
            }

        } catch (\Throwable $e) {
            Log::error('Failed to sync order slots for agent', [
                'order_id' => $order->id,
                'agent_id' => $agent->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build agent instruction description for a single slot
     */
    protected function buildAgentInstructionDescriptionForSlot(Order $order, OrderSlot $slot): string
    {
        $lines = [];

        $lines[] = "📋 ORDER DETAILS";
        $lines[] = "Order ID: #{$order->id}";
        $lines[] = "";
        $lines[] = "🏠 PROPERTY";
        $lines[] = "Address: {$order->property->address}, {$order->property->city}, {$order->property->province}";
        $lines[] = "";
        $lines[] = "⏰ SCHEDULED SERVICE";
        $lines[] = sprintf(
            "• %s | %s - %s | %s (Vendor: %s %s)",
            Carbon::parse($slot->date)->format('M d, Y'),
            Carbon::parse($slot->start_time)->format('g:i A'),
            Carbon::parse($slot->end_time)->format('g:i A'),
            $slot->service->name ?? 'Service',
            $slot->vendor->first_name ?? 'N/A',
            $slot->vendor->last_name ?? ''
        );

        $lines[] = "";
        $lines[] = "🔗 View Order: " . config('app.frontend_url') . "/orders/" . $order->uuid;

        return implode("\n", $lines);
    }

    /**
     * Build agent instruction description (legacy wrapper)
     */
    protected function buildAgentInstructionDescription(Order $order): string
    {
        return $this->buildAgentInstructionDescriptionForSlot($order, $order->slots->first() ?? new OrderSlot());
    }

    /**
     * Build vendor instruction description for a single slot
     */
    protected function buildVendorInstructionDescriptionForSlot(
        Order $order,
        OrderSlot $slot,
        $vendor
    ): string {
        $lines = [];

        $lines[] = "📋 ORDER DETAILS";
        $lines[] = "Order ID: #{$order->id}";
        $lines[] = "";
        $lines[] = "👤 AGENT";
        $lines[] = "Name: {$order->agent->first_name} {$order->agent->last_name}";
        $lines[] = "Email: {$order->agent->email}";
        $lines[] = "";
        $lines[] = "🏠 PROPERTY";
        $lines[] = "Address: {$order->property->address}, {$order->property->city}, {$order->property->province}, {$order->property->country}";
        $lines[] = "";
        $lines[] = "⏰ SCHEDULED SERVICE";
        $lines[] = sprintf(
            "• %s | %s - %s | %s",
            Carbon::parse($slot->date)->format('M d, Y'),
            Carbon::parse($slot->start_time)->format('g:i A'),
            Carbon::parse($slot->end_time)->format('g:i A'),
            $slot->service->name ?? 'Service'
        );

        $lines[] = "";
        $lines[] = "📍 Property Location: {$order->property->address}, {$order->property->city}";
        
        if ($slot->distance) {
            $lines[] = "📏 Distance: {$slot->distance} km";
        }

        $lines[] = "";
        $lines[] = "🔗 View Order: " . config('app.frontend_url') . "/orders/" . $order->uuid;

        return implode("\n", $lines);
    }

    /**
     * Legacy vendor instruction description wrapper
     */
    protected function buildVendorInstructionDescription(
        Order $order,
        $slots,
        $vendor
    ): string {
        return $this->buildVendorInstructionDescriptionForSlot($order, $slots->first() ?? new OrderSlot(), $vendor);
    }

    /**
     * Handle deleted slots
     */
    protected function handleDeletedSlots(GoogleCalendarService $calendarService, array $slotIds): void
    {
        // Handle Agent event deletion if whole order is effectively deleted or affected
        // Since we are grouping by slots, if all slots of an order are deleted, we should probably delete agent event too.
        // But usually this job is called per order. 
        
        $deletedSlots = OrderSlot::with(['vendor', 'order.agent'])
            ->whereIn('id', $slotIds)
            ->get();

        foreach ($deletedSlots as $slot) {
            try {
                $vendor = $slot->vendor;

                if (!$vendor || !$vendor->google_access_token) {
                    continue;
                }

                // If slot has google_event_id for vendor, delete it
                if ($slot->google_event_id) {
                    $success = $calendarService->deleteEvent($vendor, $slot->google_event_id);
                    if ($success) {
                        $slot->update(['google_event_id' => null]);
                        Log::info('Vendor calendar event deleted on slot removal', [
                            'slot_id' => $slot->id,
                            'vendor_id' => $vendor->id,
                            'event_id' => $slot->google_event_id,
                        ]);
                    }
                }

                // If slot has agent_google_event_id for agent, delete it
                if ($slot->agent_google_event_id && $slot->order && $slot->order->agent) {
                    $success = $calendarService->deleteEvent($slot->order->agent, $slot->agent_google_event_id);
                    if ($success) {
                        $slot->update(['agent_google_event_id' => null]);
                        Log::info('Agent calendar event deleted on slot removal', [
                            'slot_id' => $slot->id,
                            'agent_id' => $slot->order->agent->id,
                            'event_id' => $slot->agent_google_event_id,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Failed to delete calendar event', [
                    'slot_id' => $slot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Handle calendar event cleanup on booking cancellation.
     */
    protected function handleCancellation(GoogleCalendarService $calendarService, Order $order): void
    {
        // 1. Delete Vendor & Slot-level Agent Calendar Events
        foreach ($order->slots as $slot) {
            if ($slot->google_event_id && $slot->vendor) {
                try {
                    $success = $calendarService->deleteEvent($slot->vendor, $slot->google_event_id);
                    if ($success) {
                        $slot->update(['google_event_id' => null]);
                        Log::info('Deleted vendor calendar event on cancellation', [
                            'order_id' => $order->id,
                            'vendor_id' => $slot->vendor->id,
                            'event_id' => $slot->google_event_id
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::error('Failed to delete vendor calendar event on cancel', [
                        'slot_id' => $slot->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            if ($slot->agent_google_event_id && $order->agent) {
                try {
                    $success = $calendarService->deleteEvent($order->agent, $slot->agent_google_event_id);
                    if ($success) {
                        $slot->update(['agent_google_event_id' => null]);
                        Log::info('Deleted slot agent calendar event on cancellation', [
                            'order_id' => $order->id,
                            'agent_id' => $order->agent->id,
                            'event_id' => $slot->agent_google_event_id
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::error('Failed to delete slot agent calendar event on cancel', [
                        'slot_id' => $slot->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        // 2. Delete Legacy Order-level Agent Calendar Event if present
        if ($order->agent_google_event_id && $order->agent) {
            try {
                $success = $calendarService->deleteEvent($order->agent, $order->agent_google_event_id);
                if ($success) {
                    $order->update(['agent_google_event_id' => null]);
                    Log::info('Deleted order agent calendar event on cancellation', [
                        'order_id' => $order->id,
                        'agent_id' => $order->agent->id,
                        'event_id' => $order->agent_google_event_id
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('Failed to delete order agent calendar event on cancel', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('Calendar sync job failed permanently', [
            'order_id' => $this->orderId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
