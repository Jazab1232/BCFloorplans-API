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

    public int $orderId;
    public array $deletedSlotIds;

    public function __construct(
        int|Order $orderId,
        array $deletedSlotIds = []
    ) {
        $this->orderId = $orderId instanceof Order ? (int)$orderId->id : (int)$orderId;
        $this->deletedSlotIds = $deletedSlotIds;
    }

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
                'slots.service',
                'services.service',
                'services.option',
                'services.vendor',
                'areas',
                'agent',
                'property',
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

            // Filter out cancelled slots so they are not synced as active bookings
            $activeSlots = $order->slots->filter(fn($s) => ($s->status ?? 'booked') !== 'cancelled');

            // If order has no remaining slots, exit early
            if ($activeSlots->isEmpty()) {
                Log::info('Order has no remaining active slots to sync to calendar', [
                    'order_id' => $this->orderId,
                ]);
                return;
            }

            // Group slots by vendor and sync
            $slotsByVendor = $activeSlots->groupBy('vendor_id');
            
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

        if (!$vendor->sync_google_calendar) {
            Log::info('Vendor skipped (sync_google_calendar disabled in settings)', [
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

                $eventData = [
                    'summary' => $this->resolveVendorJobTitle($order, $slot),
                    'description' => $this->buildVendorInstructionDescriptionForSlot($order, $slot, $vendor),
                    'start' => $start,
                    'end' => $end,
                    'timezone' => $tz,
                    'location' => $this->resolveEventLocation($order),
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

        if (!$agent->sync_google_calendar) {
            Log::info('Agent skipped (sync_google_calendar disabled in settings)', [
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
                    'location' => $this->resolveEventLocation($order),
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
     * Resolve job title for vendor appointment (e.g. 2D Floor Plan, 35 HDR Photos, HD Video)
     */
    protected function resolveVendorJobTitle(Order $order, OrderSlot $slot): string
    {
        $matchingService = $order->services->first(function ($os) use ($slot) {
            if ($os->service_id == $slot->service_id) {
                if ($slot->vendor_id && $os->vendor_id) {
                    return $os->vendor_id == $slot->vendor_id;
                }
                return true;
            }
            return false;
        }) ?: $order->services->firstWhere('service_id', $slot->service_id);

        $sName = trim($slot->service->name ?? $matchingService?->service?->name ?? 'Service');
        $optTitle = trim($matchingService?->option?->title ?? '');

        if (!$optTitle) {
            return $sName;
        }
        if (!$sName) {
            return $optTitle;
        }
        if (stripos($optTitle, $sName) !== false) {
            return $optTitle;
        }
        if (preg_match('/photo|hdr|video|tour|drone|aerial|matterport|floor\s*plan|measure/i', $optTitle)) {
            return $optTitle;
        }

        return "{$sName} ({$optTitle})";
    }

    /**
     * Format full property location suitable for maps navigation in Google Calendar
     */
    protected function resolveEventLocation(Order $order): string
    {
        $prop = $order->property;
        $addressParts = [];

        $street = $prop->address ?? $order->property_address ?? '';
        if (!empty($street)) {
            $addressParts[] = trim($street);
        }
        if (!empty($prop->city)) {
            $addressParts[] = trim($prop->city);
        }

        $provPostal = array_filter([
            $prop->province ?? $prop->state ?? $order->province ?? '',
            $prop->postal_code ?? ''
        ]);
        if (!empty($provPostal)) {
            $addressParts[] = implode(' ', $provPostal);
        }

        $country = !empty($prop->country) ? trim($prop->country) : 'Canada';
        $addressParts[] = $country;

        return implode(', ', array_filter($addressParts));
    }

    /**
     * Build vendor instruction description for a single slot matching the mobile appointment view
     */
    protected function buildVendorInstructionDescriptionForSlot(
        Order $order,
        OrderSlot $slot,
        $vendor
    ): string {
        $lines = [];

        // 1. Agent Details (Name and Contact Phone - Email omitted for vendor)
        $agentName = $order->agent
            ? trim("{$order->agent->first_name} {$order->agent->last_name}")
            : '';
        $agentPhone = $order->agent->primary_phone ?? $order->agent->secondary_phone ?? '';

        if (!empty($agentName)) {
            $lines[] = "Agent Name: {$agentName}";
        }
        if (!empty($agentPhone)) {
            $lines[] = "Phone: {$agentPhone}";
        }

        // 2. Additional Details (Job & Appointment Notes)
        $prop = $order->property;
        $orderMeta = is_array($order->meta) ? $order->meta : (json_decode($order->meta ?? '', true) ?: []);
        $additionalLines = [];

        // Suite Number (if any)
        $suite = trim((string)($prop->suite ?? $order->suite ?? $orderMeta['suite'] ?? ''));
        if (!empty($suite)) {
            $cleanSuite = preg_replace('/^(suite|unit|#)\s*/i', '', $suite);
            $additionalLines[] = "Suite Number: {$cleanSuite}";
        }

        // Square Footage
        $measuredSqFt = $order->areas ? $order->areas->sum('footage') : 0;
        $propSqFt = (int)($prop->square_footage ?? 0);
        $totalSqFt = $measuredSqFt > 0 ? $measuredSqFt : $propSqFt;
        if ($totalSqFt > 0) {
            $additionalLines[] = "Square Footage: {$totalSqFt} sqft";
        }

        // Matching Service & Photos
        $matchingService = $order->services->first(function ($os) use ($slot) {
            if ($os->service_id == $slot->service_id) {
                if ($slot->vendor_id && $os->vendor_id) {
                    return $os->vendor_id == $slot->vendor_id;
                }
                return true;
            }
            return false;
        }) ?: $order->services->firstWhere('service_id', $slot->service_id);

        $sName = $slot->service->name ?? $matchingService?->service?->name ?? '';
        $optTitle = $matchingService?->option?->title ?? '';

        if (preg_match('/photo|hdr|aerial|drone/i', $sName) || preg_match('/photo|hdr|aerial|drone/i', $optTitle)) {
            if (preg_match('/(\d+)\s*(?:HDR\s*)?Photos?/i', $optTitle, $m)) {
                $additionalLines[] = "Photos: {$m[1]} Photos";
            } elseif (!empty($matchingService?->option?->quantity) && (int)$matchingService->option->quantity > 0) {
                $additionalLines[] = "Photos: {$matchingService->option->quantity} Photos";
            }
        }

        // Floor Plan Details (Other areas to measure & special notes)
        $fpAreas = $orderMeta['floorplan_areas'] ?? [];
        $fpNotes = $orderMeta['floorplan_notes'] ?? '';
        $isFloorPlan = preg_match('/floor\s*plan|measure/i', $sName) || preg_match('/floor\s*plan|measure/i', $optTitle);
        if ($isFloorPlan || !empty($fpAreas) || !empty($fpNotes)) {
            if (!empty($fpAreas)) {
                $areasStr = is_array($fpAreas) ? implode(', ', $fpAreas) : (string)$fpAreas;
                $additionalLines[] = "Other Areas to Measure: {$areasStr}";
            }
            if (!empty($fpNotes)) {
                $additionalLines[] = "Floor Plan Notes: {$fpNotes}";
            }
        }

        // Service custom notes
        if (!empty($matchingService?->custom)) {
            $additionalLines[] = "Service Note: {$matchingService->custom}";
        }

        // Agent Note (notes about the agent)
        $agentNotes = trim((string)($order->agent->notes ?? ''));
        if (!empty($agentNotes)) {
            $additionalLines[] = "Agent Note: {$agentNotes}";
        }

        // Site Contact (from meta, co-agents, or default to agent)
        $siteContactName = $orderMeta['site_contact_name'] ?? $orderMeta['site_contact'] ?? null;
        $siteContactPhone = $orderMeta['site_contact_phone'] ?? null;
        if (!$siteContactName && !empty($prop->co_agents) && is_array($prop->co_agents) && count($prop->co_agents) > 0) {
            $co = $prop->co_agents[0];
            $siteContactName = $co['name'] ?? trim(($co['first_name'] ?? '') . ' ' . ($co['last_name'] ?? ''));
            $siteContactPhone = $co['phone'] ?? $co['primary_phone'] ?? null;
        }
        if (!$siteContactName && !empty($agentName)) {
            $siteContactName = $agentName;
            $siteContactPhone = $agentPhone;
        }
        if (!empty($siteContactName)) {
            $additionalLines[] = "Site Contact: {$siteContactName}";
            if (!empty($siteContactPhone)) {
                $additionalLines[] = "Phone: {$siteContactPhone}";
            }
        }

        // Appointment Notes (Notes about the appointment)
        if (!empty($order->notes)) {
            $rawNotes = is_array($order->notes) ? $order->notes : (json_decode($order->notes, true) ?: []);
            foreach ($rawNotes as $n) {
                if (is_array($n) && !empty($n['note'])) {
                    $additionalLines[] = "Appointment Note: " . trim($n['note']);
                } elseif (is_string($n) && !empty(trim($n))) {
                    $additionalLines[] = "Appointment Note: " . trim($n);
                }
            }
        }

        // Property Access / Instructions
        $access = trim((string)($prop->media_creator_access ?? ''));
        $instructions = trim((string)($prop->instructions ?? ''));
        if (!empty($access)) {
            $additionalLines[] = "Property Access: {$access}";
        }
        if (!empty($instructions) && $instructions !== $access) {
            $additionalLines[] = "Instructions: {$instructions}";
        }

        if (!empty($additionalLines)) {
            $lines[] = "Additional Details:";
            foreach ($additionalLines as $detail) {
                $lines[] = $detail;
            }
        }

        // 3. Other Services on this order
        $otherSlots = $order->slots->filter(function ($s) use ($slot) {
            return $s->id != $slot->id && ($s->status ?? 'booked') !== 'cancelled';
        });

        if ($otherSlots->isNotEmpty()) {
            $lines[] = "";
            $lines[] = "Other Services:";
            foreach ($otherSlots as $os) {
                $osServiceName = $os->service->name ?? 'Service';
                $osVendorName = $os->vendor
                    ? trim("{$os->vendor->first_name} {$os->vendor->last_name}")
                    : 'Unassigned';
                $osTime = Carbon::parse($os->start_time)->format('g:i A');
                $osDate = Carbon::parse($os->date)->format('m/d/Y');
                $lines[] = "Service: {$osServiceName}, Who: {$osVendorName}, Time: {$osTime}, Date: {$osDate}";
            }
        }

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
        $rawSlotIds = [];

        foreach ($slotIds as $item) {
            if (is_array($item)) {
                try {
                    if (!empty($item['google_event_id'])) {
                        $vendor = !empty($item['vendor'])
                            ? ($item['vendor'] instanceof \App\Models\Vendor ? $item['vendor'] : \App\Models\Vendor::withoutGlobalScopes()->find($item['vendor']))
                            : null;
                        if ($vendor && $vendor->google_access_token) {
                            $calendarService->deleteEvent($vendor, $item['google_event_id']);
                            Log::info('Vendor calendar event deleted via pre-captured slot info', [
                                'vendor_id' => $vendor->id,
                                'event_id' => $item['google_event_id'],
                            ]);
                        }
                    }
                    if (!empty($item['agent_google_event_id'])) {
                        $agent = !empty($item['agent'])
                            ? ($item['agent'] instanceof \App\Models\Agent ? $item['agent'] : \App\Models\Agent::withoutGlobalScopes()->find($item['agent']))
                            : null;
                        if ($agent && $agent->google_access_token) {
                            $calendarService->deleteEvent($agent, $item['agent_google_event_id']);
                            Log::info('Agent calendar event deleted via pre-captured slot info', [
                                'agent_id' => $agent->id,
                                'event_id' => $item['agent_google_event_id'],
                            ]);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error('Failed to delete calendar event from pre-captured slot info: ' . $e->getMessage());
                }
            } elseif (is_numeric($item)) {
                $rawSlotIds[] = (int)$item;
            }
        }

        if (empty($rawSlotIds)) {
            return;
        }

        $deletedSlots = OrderSlot::with(['vendor', 'order.agent'])
            ->whereIn('id', $rawSlotIds)
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
