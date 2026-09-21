<?php

namespace App\Http\Controllers\Api;

use App\Models\Vendor;
use App\Models\Agent;
use App\Services\GoogleCalendarService;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Carbon\Carbon;

class GoogleCalendarController extends Controller
{
    protected GoogleCalendarService $calendarService;

    public function __construct(GoogleCalendarService $calendarService)
    {
        $this->calendarService = $calendarService;
    }

    /**
     * Get the authenticated model (Vendor or Agent)
     */
    protected function getAuthenticatedModel(Request $request)
    {
        // 1. Prioritize explicit UUID parameters passed via payload or query string
        $agentUuid = $request->input('agent_uuid') ?? $request->query('agent_uuid') ?? $request->input('agent_id') ?? $request->query('agent_id');
        if ($agentUuid) {
            $agent = Agent::where('uuid', $agentUuid)->first();
            if ($agent) return $agent;
        }

        $vendorUuid = $request->input('vendor_uuid') ?? $request->query('vendor_uuid') ?? $request->input('vendor_id') ?? $request->query('vendor_id');
        if ($vendorUuid) {
            $vendor = Vendor::where('uuid', $vendorUuid)->first();
            if ($vendor) return $vendor;
        }

        // 2. Fallback to logged-in user guard resolution
        $user = $request->user();
        
        if ($user) {
            if ($user instanceof Vendor || $user instanceof Agent) {
                return $user;
            }

            // Try to resolve based on common pattern if instance check fails
            if (!empty($user->uuid)) {
                $vendor = Vendor::where('uuid', $user->uuid)->first();
                if ($vendor) return $vendor;

                $agent = Agent::where('uuid', $user->uuid)->first();
                if ($agent) return $agent;
            }

            if (!empty($user->email)) {
                $vendor = Vendor::where('email', $user->email)->first();
                if ($vendor) return $vendor;

                $agent = Agent::where('email', $user->email)->first();
                if ($agent) return $agent;
            }

            return $user;
        }

        throw new \Exception('Could not resolve authenticated user model');
    }

    /**
     * Get Google OAuth URL for frontend to redirect
     */
    public function redirectToGoogle(Request $request)
    {
        $model = $this->getAuthenticatedModel($request);
        $userUuid = $model->uuid;
        $userType = class_basename($model);
        $redirectBackUrl = $request->query('redirect_back_url') ?? $request->header('referer') ?? config('app.frontend_url');
        
        // Generate a clean, short unique state token
        $state = (string) \Illuminate\Support\Str::uuid();
        
        Cache::put("google_oauth_state_{$state}", [
            'id' => $userUuid,
            'type' => $userType,
            'redirect_back_url' => $redirectBackUrl
        ], now()->addMinutes(10));
        
        $authUrl = $this->calendarService->getAuthUrl($state);
        
        return response()->json([
            'success' => true,
            'auth_url' => $authUrl,
            'state' => $state,
            'message' => 'Redirect user to this URL to authorize Google Calendar'
        ]);
    }

    /**
     * Handle Google OAuth callback
     */
    public function handleGoogleCallback(Request $request)
    {
        $state = $request->state;
        $cachedData = Cache::get("google_oauth_state_{$state}");
        $redirectBackUrl = $cachedData['redirect_back_url'] ?? config('app.frontend_url');

        if ($request->has('error')) {
            $separator = parse_url($redirectBackUrl, PHP_URL_QUERY) ? '&' : '?';
            return redirect()->to($redirectBackUrl . $separator . 'google_calendar_error=' . urlencode($request->error));
        }

        if (!$cachedData) {
            $separator = parse_url($redirectBackUrl, PHP_URL_QUERY) ? '&' : '?';
            return redirect()->to($redirectBackUrl . $separator . 'google_calendar_error=invalid_state');
        }

        $userId = $cachedData['id'];
        $userType = $cachedData['type'];

        $model = null;
        if ($userType === 'Agent') {
            $model = Agent::where('uuid', $userId)->first();
        } elseif ($userType === 'Vendor') {
            $model = Vendor::where('uuid', $userId)->first();
        } else {
            // Robust fallback if type is generic "User" or any other type
            $model = Agent::where('uuid', $userId)->first() ?? Vendor::where('uuid', $userId)->first();
        }

        if (!$model) {
            Log::error("Google OAuth callback error: User not found in DB", [
                'user_id' => $userId,
                'user_type' => $userType
            ]);
            $separator = parse_url($redirectBackUrl, PHP_URL_QUERY) ? '&' : '?';
            return redirect()->to($redirectBackUrl . $separator . 'google_calendar_error=user_not_found');
        }

        $success = $this->calendarService->handleCallback(
            $request->code,
            $model
        );

        // Clean up cache
        Cache::forget("google_oauth_state_{$state}");

        $separator = parse_url($redirectBackUrl, PHP_URL_QUERY) ? '&' : '?';
        if ($success) {
            return redirect()->to($redirectBackUrl . $separator . 'google_calendar_success=true');
        }

        return redirect()->to($redirectBackUrl . $separator . 'google_calendar_error=failed');
    }

    /**
     * Check vendor availability for a specific datetime
     */
    public function checkAvailability(Request $request, $vendorId)
    {   
        Log::info('Checking availability for vendor ID: ' . $vendorId);
        // return response()->json(['message' => 'Debugging availability check']);
        try {
            $request->validate([
                'datetime' => 'required|date',
                'duration' => 'nullable|integer|min:1',
            ]);

            
             $vendor = Vendor::where('uuid', $vendorId)->firstOrFail();
            //  if (!$vendor->google_calendar_connected) {
            //      return response()->json([
            //          'available' => false,
            //          'message' => 'Vendor has not connected their Google Calendar.'
            //      ], 400);
            //  }
            
        
            $dateTime = Carbon::parse($request->datetime);
            $duration = $request->duration ?? 60; // minutes

            $isAvailable = $this->calendarService->isVendorAvailable(
                $vendor,
                $dateTime,
                $duration
            );
            // Log::info('Availability for ' . $dateTime->toDateTimeString() . ' is ' . ($isAvailable ? 'available' : 'unavailable'));

            return response()->json([
                'available' => $isAvailable,
                'datetime' => $dateTime->toDateTimeString(),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }
       
    }

    /**
     * Get vendor's unavailable dates for a month (for calendar UI)
     */
    public function getUnavailableDates(Request $request, $vendorId)
    {
        try {
            $request->validate([
                'year' => 'nullable|integer|min:2000|max:2100',
                'month' => 'nullable|integer|min:1|max:12',
            ]);

            $vendor = Vendor::where('uuid', $vendorId)->firstOrFail();
        
            $year = $request->year ?? now()->year;
            $month = $request->month ?? now()->month;
            Log::info("Fetching unavailable dates for vendor ID: {$vendorId}, Year: {$year}, Month: {$month}");

            $unavailableDates = $this->calendarService->getUnavailableDates(
                $vendor,
                $year,
                $month
            );

            return response()->json([
                'unavailable_dates' => $unavailableDates,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }
        
    }

    /**
     * Get vendor's busy slots for a date range
     */
    public function getBusySlots(Request $request, $vendorId)
    {
        $vendor = Vendor::findOrFail($vendorId);
        
        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        $busySlots = $this->calendarService->getVendorBusySlots(
            $vendor,
            $startDate,
            $endDate
        );

        return response()->json([
            'busy_slots' => $busySlots,
        ]);
    }

    /**
     * Disconnect Google Calendar
     */
    public function disconnect(Request $request)
    {
        $model = $this->getAuthenticatedModel($request);
        
        $success = $this->calendarService->disconnect($model);

        if ($success) {
            return response()->json([
                'success' => true,
                'message' => 'Google Calendar disconnected successfully'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to disconnect Google Calendar'
        ], 500);
    }

    /**
     * Create a new event in vendor's calendar
     */
    public function createEvent(Request $request)
    {
        $request->validate([
            'summary' => 'required|string|max:255',
            'start' => 'required|date',
            'end' => 'required|date|after:start',
            'description' => 'nullable|string',
            'attendees' => 'nullable|array',
            'attendees.*.email' => 'required_with:attendees|email',
        ]);

        $model = $this->getAuthenticatedModel($request);

        $eventId = $this->calendarService->createEvent($model, [
            'summary' => $request->summary,
            'description' => $request->description,
            'start' => $request->start,
            'end' => $request->end,
            'attendees' => $request->attendees ?? [],
            'timezone' => $request->timezone ?? config('app.timezone', 'UTC'),
        ]);

        if ($eventId) {
            return response()->json([
                'success' => true,
                'message' => 'Event created successfully',
                'event_id' => $eventId
            ], 201);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to create event'
        ], 500);
    }

    /**
     * Update an existing event
     */
    public function updateEvent(Request $request, $eventId)
    {
        $request->validate([
            'summary' => 'nullable|string|max:255',
            'start' => 'nullable|date',
            'end' => 'nullable|date|after:start',
            'description' => 'nullable|string',
        ]);

        $model = $this->getAuthenticatedModel($request);

        $success = $this->calendarService->updateEvent($model, $eventId, $request->only([
            'summary', 'description', 'start', 'end', 'timezone'
        ]));

        if ($success) {
            return response()->json([
                'success' => true,
                'message' => 'Event updated successfully'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to update event'
        ], 500);
    }

    /**
     * Delete an event
     */
    public function deleteEvent(Request $request, $eventId)
    {
        $model = $this->getAuthenticatedModel($request);

        $success = $this->calendarService->deleteEvent($model, $eventId);

        if ($success) {
            return response()->json([
                'success' => true,
                'message' => 'Event deleted successfully'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to delete event'
        ], 500);
    }

    /**
     * Block a time slot
     */
    public function blockTimeSlot(Request $request)
    {
        $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after:start',
            'reason' => 'nullable|string|max:255',
        ]);

        $model = $this->getAuthenticatedModel($request);

        $eventId = $this->calendarService->blockTimeSlot(
            $model,
            Carbon::parse($request->start),
            Carbon::parse($request->end),
            $request->reason ?? 'Blocked'
        );

        if ($eventId) {
            return response()->json([
                'success' => true,
                'message' => 'Time slot blocked successfully',
                'event_id' => $eventId
            ], 201);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to block time slot'
        ], 500);
    }


    public function getEvents(Request $request)
    {
        try {
            $request->validate([
                'period' => 'nullable|string|in:day,week,month,year,custom',
                'start_date' => 'required_if:period,custom|date',
                'end_date' => 'required_if:period,custom|date|after_or_equal:start_date',
                'date' => 'nullable|date',
                'search' => 'nullable|string|max:255',
                'include_cancelled' => 'nullable|boolean',
                'include_attendees' => 'nullable|boolean',
                'all_day_only' => 'nullable|boolean',
                'timed_only' => 'nullable|boolean',
                'max_results' => 'nullable|integer|min:1|max:2500',
            ]);

            $model = $this->getAuthenticatedModel($request);

            $referenceDate = $request->date ? Carbon::parse($request->date) : Carbon::now();
            $period = $request->period ?? 'month';

            switch ($period) {
                case 'day':
                    $startDate = $referenceDate->copy()->startOfDay();
                    $endDate = $referenceDate->copy()->endOfDay();
                    break;
                case 'week':
                    $startDate = $referenceDate->copy()->startOfWeek();
                    $endDate = $referenceDate->copy()->endOfWeek();
                    break;
                case 'month':
                    $startDate = $referenceDate->copy()->startOfMonth();
                    $endDate = $referenceDate->copy()->endOfMonth();
                    break;
                case 'year':
                    $startDate = $referenceDate->copy()->startOfYear();
                    $endDate = $referenceDate->copy()->endOfYear();
                    break;
                case 'custom':
                    $startDate = Carbon::parse($request->start_date)->startOfDay();
                    $endDate = Carbon::parse($request->end_date)->endOfDay();
                    break;
                default:
                    $startDate = $referenceDate->copy()->startOfMonth();
                    $endDate = $referenceDate->copy()->endOfMonth();
            }

            $options = [
                'search' => $request->search,
                'include_cancelled' => $request->boolean('include_cancelled', false),
                'include_attendees' => $request->boolean('include_attendees', false),
                'all_day_only' => $request->boolean('all_day_only', false),
                'timed_only' => $request->boolean('timed_only', false),
                'max_results' => $request->max_results ?? 2500,
            ];

            $events = $this->calendarService->getEvents($model, $startDate, $endDate, $options);

            return response()->json([
                'success' => true,
                'events' => $events,
                'total_events' => count($events),
                'period' => $period,
                'date_range' => [
                    'start' => $startDate->toDateTimeString(),
                    'end' => $endDate->toDateTimeString(),
                ],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error fetching events: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch events',
                'error' => $e->getMessage()
            ], 500);
        }
    }

        /**
     * List all available calendars for the vendor
     */
    public function listCalendars(Request $request)
    {
        $model = $this->getAuthenticatedModel($request);

        $calendars = $this->calendarService->listCalendars($model);

        return response()->json([
            'success' => true,
            'calendars' => $calendars
        ]);
    }

    /**
     * Set which calendar to use for bookings
     */
    public function setCalendar(Request $request)
    {
        $request->validate([
            'calendar_id' => 'required|string',
        ]);

        $model = $this->getAuthenticatedModel($request);

        $model->update([
            'google_calendar_id' => $request->calendar_id,
            'sync_google_calendar' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Calendar set successfully',
            'calendar_id' => $request->calendar_id
        ]);
    }

    /**
 * Get events statistics
 */
    public function getEventsStats(Request $request)
    {
        try {
            $request->validate([
                'period' => 'nullable|string|in:day,week,month,year,custom',
                'start_date' => 'required_if:period,custom|date',
                'end_date' => 'required_if:period,custom|date|after_or_equal:start_date',
                'date' => 'nullable|date',
            ]);

            $model = $this->getAuthenticatedModel($request);

            $referenceDate = $request->date ? Carbon::parse($request->date) : Carbon::now();
            $period = $request->period ?? 'month';

            switch ($period) {
                case 'day':
                    $startDate = $referenceDate->copy()->startOfDay();
                    $endDate = $referenceDate->copy()->endOfDay();
                    break;
                case 'week':
                    $startDate = $referenceDate->copy()->startOfWeek();
                    $endDate = $referenceDate->copy()->endOfWeek();
                    break;
                case 'month':
                    $startDate = $referenceDate->copy()->startOfMonth();
                    $endDate = $referenceDate->copy()->endOfMonth();
                    break;
                case 'year':
                    $startDate = $referenceDate->copy()->startOfYear();
                    $endDate = $referenceDate->copy()->endOfYear();
                    break;
                case 'custom':
                    $startDate = Carbon::parse($request->start_date)->startOfDay();
                    $endDate = Carbon::parse($request->end_date)->endOfDay();
                    break;
                default:
                    $startDate = $referenceDate->copy()->startOfMonth();
                    $endDate = $referenceDate->copy()->endOfMonth();
            }

            $stats = $this->calendarService->getEventsStats($model, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'stats' => $stats,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }
    }

    /**
     * Get unsynced bookings status for the logged in Agent or Vendor (Window: today - 7 days to today + 100 days)
     */
    public function getSyncStatus(Request $request)
    {
        try {
            $model = $this->getAuthenticatedModel($request);
            $isAgent = $model instanceof Agent;
            $isVendor = $model instanceof Vendor;

            if (!$model->google_access_token) {
                return response()->json([
                    'success' => true,
                    'connected' => false,
                    'unsynced_count' => 0,
                    'unsynced_orders' => []
                ]);
            }

            if ($request->has('month') && $request->has('year')) {
                $monthStr = $request->query('month');
                $yearStr = $request->query('year');
                $dateObj = Carbon::parse("1 {$monthStr} {$yearStr}");
                $startDate = $dateObj->copy()->startOfMonth()->startOfDay();
                $endDate = $dateObj->copy()->endOfMonth()->endOfDay();
            } elseif ($request->has('start_date') && $request->has('end_date')) {
                $startDate = Carbon::parse($request->query('start_date'))->startOfDay();
                $endDate = Carbon::parse($request->query('end_date'))->endOfDay();
            } else {
                $startDate = Carbon::now()->startOfMonth()->startOfDay();
                $endDate = Carbon::now()->endOfMonth()->endOfDay();
            }

            $unsyncedOrdersList = [];

            if ($isAgent) {
                // Find agent orders where any slot in date window lacks agent_google_event_id
                $orders = \App\Models\Order::with(['property', 'slots' => function ($q) use ($startDate, $endDate) {
                        $q->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()]);
                    }, 'slots.service', 'slots.vendor'])
                    ->where('agent_id', $model->id)
                    ->where('order_status', '!=', 'Cancelled')
                    ->whereHas('slots', function ($q) use ($startDate, $endDate) {
                        $q->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
                          ->whereNull('agent_google_event_id');
                    })
                    ->get();

                foreach ($orders as $order) {
                    $unsyncedSlots = $order->slots->filter(fn($s) => empty($s->agent_google_event_id));
                    if ($unsyncedSlots->isEmpty()) continue;

                    $earliestStart = $unsyncedSlots->map(fn($s) => Carbon::parse($s->date . ' ' . $s->start_time))->min();
                    $latestEnd = $unsyncedSlots->map(fn($s) => Carbon::parse($s->date . ' ' . $s->end_time))->max();
                    $serviceNames = $unsyncedSlots->pluck('service.name')->filter()->unique()->implode(', ');

                    $unsyncedOrdersList[] = [
                        'order_id' => $order->id,
                        'order_uuid' => $order->uuid,
                        'address' => $order->property ? ($order->property->address . ', ' . $order->property->city) : 'N/A',
                        'date' => $earliestStart ? $earliestStart->format('Y-m-d') : 'N/A',
                        'start_time' => $earliestStart ? $earliestStart->format('g:i A') : 'N/A',
                        'end_time' => $latestEnd ? $latestEnd->format('g:i A') : 'N/A',
                        'services' => $serviceNames,
                        'type' => 'Agent Booking'
                    ];
                }
            } elseif ($isVendor) {
                // Find vendor slots in date window where google_event_id is null
                $orders = \App\Models\Order::with(['property', 'slots' => function ($q) use ($model, $startDate, $endDate) {
                        $q->where('vendor_id', $model->id)
                          ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()]);
                    }, 'slots.service'])
                    ->where('order_status', '!=', 'Cancelled')
                    ->whereHas('slots', function ($q) use ($model, $startDate, $endDate) {
                        $q->where('vendor_id', $model->id)
                          ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
                          ->whereNull('google_event_id');
                    })
                    ->get();

                foreach ($orders as $order) {
                    $vendorSlots = $order->slots->filter(fn($s) => $s->vendor_id == $model->id && empty($s->google_event_id));
                    if ($vendorSlots->isEmpty()) continue;

                    $earliestStart = $vendorSlots->map(fn($s) => Carbon::parse($s->date . ' ' . $s->start_time))->min();
                    $latestEnd = $vendorSlots->map(fn($s) => Carbon::parse($s->date . ' ' . $s->end_time))->max();
                    $serviceNames = $vendorSlots->pluck('service.name')->filter()->unique()->implode(', ');

                    $unsyncedOrdersList[] = [
                        'order_id' => $order->id,
                        'order_uuid' => $order->uuid,
                        'address' => $order->property ? ($order->property->address . ', ' . $order->property->city) : 'N/A',
                        'date' => $earliestStart ? $earliestStart->format('Y-m-d') : 'N/A',
                        'start_time' => $earliestStart ? $earliestStart->format('g:i A') : 'N/A',
                        'end_time' => $latestEnd ? $latestEnd->format('g:i A') : 'N/A',
                        'services' => $serviceNames,
                        'type' => 'Vendor Assignment'
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'connected' => true,
                'sync_enabled' => (bool) $model->sync_google_calendar,
                'unsynced_count' => count($unsyncedOrdersList),
                'unsynced_orders' => $unsyncedOrdersList,
                'date_range' => [
                    'start' => $startDate->toDateString(),
                    'end' => $endDate->toDateString()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting calendar sync status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve calendar sync status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Trigger manual retrospective sync for selected order IDs
     */
    public function syncManual(Request $request)
    {
        try {
            $request->validate([
                'order_ids' => 'required|array',
                'order_ids.*' => 'integer|exists:orders,id',
            ]);

            $model = $this->getAuthenticatedModel($request);
            if (!$model->google_access_token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Google Calendar account not connected.'
                ], 400);
            }

            // Make sure sync_google_calendar is set to true
            if (!$model->sync_google_calendar) {
                $model->update(['sync_google_calendar' => true]);
            }

            $orderIds = array_unique($request->order_ids);
            $totalOrders = count($orderIds);
            $syncResults = [];
            $failedOrders = [];

            if ($totalOrders <= 5) {
                // Immediate synchronous execution for small batches
                foreach ($orderIds as $orderId) {
                    try {
                        \App\Jobs\SyncOrderCalendarEvents::dispatchSync($orderId);

                        // Verify the sync actually worked by checking if google_event_id was set
                        $order = \App\Models\Order::with('slots')->find($orderId);
                        $isVendor = $model instanceof Vendor;
                        $isAgent = $model instanceof Agent;
                        $synced = false;
                        if ($isVendor && $order) {
                            $vendorSlots = $order->slots->where('vendor_id', $model->id);
                            $synced = $vendorSlots->isNotEmpty() && $vendorSlots->every(fn($s) => !empty($s->google_event_id));
                        } elseif ($isAgent && $order) {
                            $synced = $order->slots->isNotEmpty() && $order->slots->every(fn($s) => !empty($s->agent_google_event_id));
                        }

                        $syncResults[$orderId] = $synced ? 'synced' : 'failed';
                        if (!$synced) {
                            $failedOrders[] = $orderId;
                        }
                    } catch (\Throwable $e) {
                        Log::error("Manual sync failed for order {$orderId}: " . $e->getMessage(), [
                            'order_id' => $orderId,
                            'trace' => $e->getTraceAsString(),
                        ]);
                        $syncResults[$orderId] = 'error: ' . $e->getMessage();
                        $failedOrders[] = $orderId;
                    }
                }

                $successCount = count($orderIds) - count($failedOrders);
                if (empty($failedOrders)) {
                    $message = "Successfully synchronized {$totalOrders} booking(s) to Google Calendar.";
                } else {
                    $message = "Synchronized {$successCount}/{$totalOrders} booking(s). " . count($failedOrders) . " failed — check server logs for details.";
                }
            } else {
                // Background queueing on default queue for large batches to avoid HTTP timeouts & rate limits
                foreach ($orderIds as $index => $orderId) {
                    dispatch(new \App\Jobs\SyncOrderCalendarEvents($orderId))
                        ->delay(now()->addSeconds((int) floor($index / 5))); // 5 jobs per second max
                }
                $message = "Queued {$totalOrders} booking(s) for background Google Calendar synchronization.";
            }

            return response()->json([
                'success' => empty($failedOrders),
                'message' => $message,
                'synced_order_ids' => $orderIds,
                'sync_results' => $syncResults,
                'failed_order_ids' => $failedOrders,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error executing manual calendar sync: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to execute manual calendar sync',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}