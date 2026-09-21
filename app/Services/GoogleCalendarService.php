<?php

namespace App\Services;

use App\Models\Vendor;
use App\Models\Agent;
use Illuminate\Database\Eloquent\Model;
use Google\Client;
use Google\Service\Calendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use DateTimeZone;


class GoogleCalendarService
{
    protected Client $client;
    protected string $canadaTz = 'America/Toronto';

    public function __construct()
    {
        $this->client = new Client();
        $this->client->setClientId(config('google.client_id'));
        $this->client->setClientSecret(config('google.client_secret'));
        $this->client->setRedirectUri(config('google.redirect_uri'));
        $this->client->setScopes([Calendar::CALENDAR]); // Changed to read/write
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
    }

    /**
     * Get Google OAuth URL for vendor authorization
     */
    public function getAuthUrl(string $state = null): string
    {
        if ($state) {
            $this->client->setState($state);
        }
        return $this->client->createAuthUrl();
    }

    /**
     * Handle OAuth callback and store tokens
     */
    public function handleCallback(string $code, Model $model): bool
    {
        try {
            $token = $this->client->fetchAccessTokenWithAuthCode($code);

            if (isset($token['error'])) {
                Log::error('Google Calendar Auth Error', $token);
                return false;
            }
            
            $modelType = class_basename($model);
            Log::info("Google Calendar Token fetched successfully for {$modelType} ID: " . $model->id);
            
            $model->update([
                'google_access_token' => $token['access_token'] ?? $model->google_access_token,
                'google_refresh_token' => $token['refresh_token'] ?? $model->google_refresh_token,
                'google_token_expires_at' => Carbon::now()->addSeconds($token['expires_in']),
                'sync_google_calendar' => true,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Google Calendar Callback Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Set client token from vendor data
     */
    // protected function setClientToken(Vendor $vendor): void
    // {
    //     $token = [
    //         'access_token' => $vendor->google_access_token,
    //         'refresh_token' => $vendor->google_refresh_token,
    //         'expires_in' => Carbon::parse($vendor->google_token_expires_at)->diffInSeconds(now()),
    //         'created' => Carbon::parse($vendor->google_token_expires_at)->subSeconds(3600)->timestamp,
    //     ];

    //     $this->client->setAccessToken($token);

    //     // Refresh token if expired
    //     if ($this->client->isAccessTokenExpired() && $vendor->google_refresh_token) {
    //         $newToken = $this->client->fetchAccessTokenWithRefreshToken($vendor->google_refresh_token);

    //         $vendor->update([
    //             'google_access_token' => $newToken['access_token'],
    //             'google_token_expires_at' => Carbon::now()->addSeconds($newToken['expires_in']),
    //         ]);
    //     }
    // }
//     protected function setClientToken(Vendor $vendor): void
// {
//     if (!$vendor->google_refresh_token && !$vendor->google_access_token) {
//         throw new \Exception('Google account not connected');
//     }

    //     // If access token missing → generate using refresh token
//     if (!$vendor->google_access_token && $vendor->google_refresh_token) {
//         $newToken = $this->client->fetchAccessTokenWithRefreshToken(
//             $vendor->google_refresh_token
//         );

    //         if (!isset($newToken['access_token'])) {
//             throw new \Exception('Failed to obtain Google access token');
//         }

    //         $vendor->update([
//             'google_access_token' => $newToken['access_token'],
//             'google_token_expires_at' => now()->addSeconds($newToken['expires_in']),
//         ]);
//     }

    //     // Set token in REQUIRED format
//     $this->client->setAccessToken([
//         'access_token' => $vendor->google_access_token,
//         'expires_in'   => 3600,
//         'created'      => time() - 60,
//     ]);

    //     // Refresh if expired
//     if (
//         $vendor->google_refresh_token &&
//         $vendor->google_token_expires_at &&
//         now()->greaterThanOrEqualTo($vendor->google_token_expires_at)
//     ) {
//         $newToken = $this->client->fetchAccessTokenWithRefreshToken(
//             $vendor->google_refresh_token
//         );

    //         if (!isset($newToken['access_token'])) {
//             throw new \Exception('Failed to refresh Google access token');
//         }

    //         $vendor->update([
//             'google_access_token' => $newToken['access_token'],
//             'google_token_expires_at' => now()->addSeconds($newToken['expires_in']),
//         ]);

    //         $this->client->setAccessToken([
//             'access_token' => $newToken['access_token'],
//             'expires_in'   => 3600,
//             'created'      => time() - 60,
//         ]);
//     }
// }
    protected function setClientToken(Model $model): void
    {
        if (!$model->google_access_token || !$model->google_refresh_token) {
            throw new \Exception('Google account not connected');
        }

        $modelType = class_basename($model);
        Log::info("Setting Google Client Token for {$modelType} ID: " . $model->id);

        $expiresAt = $model->google_token_expires_at ? Carbon::parse($model->google_token_expires_at) : null;

        // ✅ Check if expired or within 2 minutes of expiration
        if (!$expiresAt || $expiresAt->isPast() || now()->addMinutes(2)->gte($expiresAt)) {
            Log::info("Google access token expired for {$modelType}. Refreshing...", [
                'id' => $model->id,
                'expired_at' => $model->google_token_expires_at,
            ]);

            $newToken = $this->client->fetchAccessTokenWithRefreshToken($model->google_refresh_token);

            if (isset($newToken['error'])) {
                Log::error('Google token refresh error', [
                    'id' => $model->id,
                    'model' => $modelType,
                    'error' => $newToken
                ]);

                if (($newToken['error'] ?? '') === 'invalid_grant') {
                    $errorMessage = 'Google refresh token is invalid or has been revoked. User must re-authorize.';
                    Log::warning("{$modelType} {$model->id} Google connection broken: {$errorMessage}");

                    // Clear tokens and disable sync given the connection is dead
                    $model->update([
                        'google_access_token' => null,
                        'google_refresh_token' => null,
                        'google_token_expires_at' => null,
                        'sync_google_calendar' => false,
                    ]);
                } else {
                    $errorMessage = $newToken['error_description'] ?? $newToken['error'];
                }

                throw new \Exception('Failed to refresh Google access token: ' . $errorMessage);
            }

            $model->update([
                'google_access_token' => $newToken['access_token'],
                'google_token_expires_at' => now()->addSeconds($newToken['expires_in'] ?? 3600),
                'google_refresh_token' => $newToken['refresh_token'] ?? $model->google_refresh_token,
            ]);

            Log::info('Google token refreshed successfully', [
                'id' => $model->id,
                'model' => $modelType,
                'new_expiry' => now()->addSeconds($newToken['expires_in'] ?? 3600),
            ]);

            // Set updated token on client
            $this->client->setAccessToken($newToken);
        } else {
            // Token is still valid
            $createdAt = $expiresAt->copy()->subHour()->timestamp;
            $this->client->setAccessToken([
                'access_token' => $model->google_access_token,
                'refresh_token' => $model->google_refresh_token,
                'expires_in' => max(1, $expiresAt->timestamp - time()),
                'created' => $createdAt,
            ]);
        }
    }






    /**
     * Get vendor's busy time slots for a date range
     * Returns array of busy periods
     */
    public function getVendorBusySlots(Vendor $vendor, Carbon $startDate, Carbon $endDate): array
    {
        if (!$vendor->google_refresh_token && !$vendor->google_access_token) {
            return [];
        }


        try {
            $this->setClientToken($vendor);
            $calendarService = new Calendar($this->client);
            Log::info('Fetching events for vendor ID: ' . $vendor->id . ' from ' . $startDate->toDateTimeString() . ' to ' . $endDate->toDateTimeString());
            $params = [
                'timeMin' => $startDate->toRfc3339String(),
                'timeMax' => $endDate->toRfc3339String(),
                'singleEvents' => true,
                'orderBy' => 'startTime',
            ];

            $calendarId = $vendor->google_calendar_id ?? 'primary';
            $events = $calendarService->events->listEvents($calendarId, $params);
            Log::info('Fetched ' . count($events->getItems()) . ' events from Google Calendar for vendor ID: ' . $vendor->id);
            $busySlots = [];
            foreach ($events->getItems() as $event) {
                // Skip declined or cancelled events
                if (isset($event->status) && $event->status === 'cancelled') {
                    continue;
                }

                if (isset($event->start->date)) {
                    // All-day event
                    $start = Carbon::parse($event->start->date)->startOfDay();
                    $end = Carbon::parse($event->end->date)->endOfDay();
                } else {
                    $start = Carbon::parse($event->start->dateTime);
                    $end = Carbon::parse($event->end->dateTime);
                }


                $busySlots[] = [
                    'summary' => $event->getSummary() ?? 'Busy',
                    'start' => Carbon::parse($start),
                    'end' => Carbon::parse($end),
                    'all_day' => isset($event->start->date),
                ];
            }

            return $busySlots;
        } catch (\Exception $e) {
            Log::error('Error fetching calendar events: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if vendor is available for a specific date and time
     */
    public function isVendorAvailable(Vendor $vendor, Carbon $dateTime, int $durationMinutes = 60): bool
    {
        $startDate = $dateTime->copy()->startOfDay();
        $endDate = $dateTime->copy()->endOfDay();

        $busySlots = $this->getVendorBusySlots($vendor, $startDate, $endDate);

        $requestedStart = $dateTime;
        $requestedEnd = $dateTime->copy()->addMinutes($durationMinutes);
        Log::info('Requested time from ' . $requestedStart->toDateTimeString() . ' to ' . $requestedEnd->toDateTimeString());
        foreach ($busySlots as $slot) {
            Log::info('Checking against busy slot from ' . $slot['start']->toDateTimeString() . ' to ' . $slot['end']->toDateTimeString());
            // Check for overlap
            // Check for overlap (inclusive)
            if (
                $requestedStart->lt($slot['end']) &&
                $requestedEnd->gt($slot['start'])
            ) {
                Log::info('Requested time overlaps with busy slot.');
                return false;
            }

        }

        return true;
    }

    /**
     * Get all unavailable dates for vendor in a month
     */
    public function getUnavailableDates(Vendor $vendor, int $year, int $month): array
    {
        $startDate = Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        $busySlots = $this->getVendorBusySlots($vendor, $startDate, $endDate);
        Log::info('Calculating unavailable dates for ' . $year . '-' . $month . '. Found ' . count($busySlots) . ' busy slots.');
        Log::info('Busy Slots: ' . print_r($busySlots, true));
        $unavailableDates = [];
        foreach ($busySlots as $slot) {
            if ($slot['all_day']) {
                // Full day event
                $unavailableDates[] = $slot['start']->format('Y-m-d');
            }
        }

        return array_unique($unavailableDates);
    }

    /**
     * Disconnect vendor's Google Calendar
     */
    public function disconnect(Model $model): bool
    {
        try {
            if ($model->google_access_token) {
                $this->setClientToken($model);
                $this->client->revokeToken();
            }

            $model->update([
                'google_access_token' => null,
                'google_refresh_token' => null,
                'google_token_expires_at' => null,
                'google_calendar_id' => null,
                'sync_google_calendar' => false,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Error disconnecting calendar: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Map Canadian province or US state to IANA Timezone ID
     */
    public static function resolveTimezoneFromProvince($province = null, $defaultTz = 'America/Vancouver'): string
    {
        $prov = strtoupper(trim((string)$province));
        
        if (str_contains($prov, '/')) {
            return $prov;
        }

        return match ($prov) {
            // Canadian Provinces
            'BC', 'BRITISH COLUMBIA' => 'America/Vancouver',
            'AB', 'ALBERTA' => 'America/Edmonton',
            'SK', 'SASKATCHEWAN' => 'America/Regina',
            'MB', 'MANITOBA' => 'America/Winnipeg',
            'ON', 'ONTARIO', 'QC', 'QUEBEC' => 'America/Toronto',
            'NB', 'NEW BRUNSWICK', 'NS', 'NOVA SCOTIA', 'PE', 'PRINCE EDWARD ISLAND', 'NL', 'NEWFOUNDLAND' => 'America/Halifax',

            // US States - Pacific
            'CA', 'CALIFORNIA', 'WA', 'WASHINGTON', 'OR', 'OREGON', 'NV', 'NEVADA' => 'America/Los_Angeles',

            // US States - Mountain
            'AZ', 'ARIZONA' => 'America/Phoenix',
            'CO', 'COLORADO', 'UT', 'UTAH', 'NM', 'NEW MEXICO', 'WY', 'WYOMING', 'MT', 'MONTANA', 'ID', 'IDAHO' => 'America/Denver',

            // US States - Central
            'TX', 'TEXAS', 'IL', 'ILLINOIS', 'MO', 'MISSOURI', 'TN', 'TENNESSEE', 'WI', 'WISCONSIN', 'MN', 'MINNESOTA', 'AL', 'ALABAMA', 'LA', 'LOUISIANA', 'KS', 'KANSAS', 'OK', 'OKLAHOMA', 'AR', 'ARKANSAS', 'IA', 'IOWA', 'MS', 'MISSISSIPPI', 'ND', 'NORTH DAKOTA', 'SD', 'SOUTH DAKOTA', 'NE', 'NEBRASKA' => 'America/Chicago',

            // US States - Eastern
            'NY', 'NEW YORK', 'FL', 'FLORIDA', 'PA', 'PENNSYLVANIA', 'OH', 'OHIO', 'GA', 'GEORGIA', 'NC', 'NORTH CAROLINA', 'VA', 'VIRGINIA', 'MA', 'MASSACHUSETTS', 'NJ', 'NEW JERSEY', 'MI', 'MICHIGAN', 'MD', 'MARYLAND', 'SC', 'SOUTH CAROLINA', 'CT', 'CONNECTICUT', 'KY', 'KENTUCKY', 'IN', 'INDIANA', 'ME', 'MAINE', 'NH', 'NEW HAMPSHIRE', 'RI', 'RHODE ISLAND', 'VT', 'VERMONT', 'DE', 'DELAWARE', 'WV', 'WEST VIRGINIA', 'DC', 'DISTRICT OF COLUMBIA' => 'America/New_York',

            // US States - Alaska & Hawaii
            'AK', 'ALASKA' => 'America/Anchorage',
            'HI', 'HAWAII' => 'Pacific/Honolulu',

            default => $defaultTz,
        };
    }

    /**
     * Create a new event/booking in vendor's calendar
     */
    public function createEvent(Model $model, array $eventData): ?string
    {
        if (!$model->google_access_token) {
            Log::error('Cannot create event: user not connected to Google Calendar');
            return null;
        }

        try {
            $this->setClientToken($model);
            $calendarService = new Calendar($this->client);

            $tz = $eventData['timezone'] ?? 'America/Vancouver';

            $startObj = ($eventData['start'] instanceof Carbon)
                ? $eventData['start']
                : Carbon::createFromFormat('Y-m-d H:i:s', Carbon::parse($eventData['start'])->format('Y-m-d H:i:s'), $tz);

            $endObj = ($eventData['end'] instanceof Carbon)
                ? $eventData['end']
                : Carbon::createFromFormat('Y-m-d H:i:s', Carbon::parse($eventData['end'])->format('Y-m-d H:i:s'), $tz);

            $event = new \Google\Service\Calendar\Event([
                'summary' => $eventData['summary'] ?? 'Booking',
                'description' => $eventData['description'] ?? '',
                'start' => [
                    'dateTime' => $startObj->toRfc3339String(),
                    'timeZone' => $tz,
                ],
                'end' => [
                    'dateTime' => $endObj->toRfc3339String(),
                    'timeZone' => $tz,
                ],
                'attendees' => $eventData['attendees'] ?? [],
                'reminders' => [
                    'useDefault' => false,
                    'overrides' => [
                        ['method' => 'email', 'minutes' => 24 * 60],
                        ['method' => 'popup', 'minutes' => 30],
                    ],
                ],
            ]);

            $calendarId = $model->google_calendar_id ?? 'primary';
            $createdEvent = $calendarService->events->insert($calendarId, $event);

            return $createdEvent->getId();
        } catch (\Google\Service\Exception $e) {
            $errors = $e->getErrors();
            Log::error('Google Calendar API error creating event', [
                'model_type' => class_basename($model),
                'model_id' => $model->id,
                'status_code' => $e->getCode(),
                'errors' => $errors,
                'message' => $e->getMessage(),
                'event_summary' => $eventData['summary'] ?? 'N/A',
                'calendar_id' => $model->google_calendar_id ?? 'primary',
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('Error creating calendar event', [
                'model_type' => class_basename($model),
                'model_id' => $model->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Update an existing event in vendor's calendar
     */
    public function updateEvent(Model $model, string $eventId, array $eventData): bool
    {
        if (!$model->google_access_token) {
            Log::error('Cannot update event: user not connected to Google Calendar');
            return false;
        }

        try {
            $this->setClientToken($model);
            $calendarService = new Calendar($this->client);

            $calendarId = $model->google_calendar_id ?? 'primary';
            $event = $calendarService->events->get($calendarId, $eventId);
            Log::info('Updating event ID: ' . $eventId . ' for ' . class_basename($model) . ' ID: ' . $model->id);
            if (isset($eventData['summary'])) {
                $event->setSummary($eventData['summary']);
            }
            if (isset($eventData['description'])) {
                $event->setDescription($eventData['description']);
            }
            if (isset($eventData['start'])) {
                $start = new \Google\Service\Calendar\EventDateTime();
                $start->setDateTime(Carbon::parse($eventData['start'])->toRfc3339String());
                $start->setTimeZone($eventData['timezone'] ?? 'UTC');
                $event->setStart($start);
            }
            if (isset($eventData['end'])) {
                $end = new \Google\Service\Calendar\EventDateTime();
                $end->setDateTime(Carbon::parse($eventData['end'])->toRfc3339String());
                $end->setTimeZone($eventData['timezone'] ?? 'UTC');
                $event->setEnd($end);
            }

            $calendarService->events->update($calendarId, $eventId, $event);
            return true;
        } catch (\Google\Service\Exception $e) {
            Log::error('Google Calendar API error updating event', [
                'model_type' => class_basename($model),
                'model_id' => $model->id,
                'event_id' => $eventId,
                'status_code' => $e->getCode(),
                'errors' => $e->getErrors(),
                'message' => $e->getMessage(),
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error('Error updating calendar event', [
                'model_type' => class_basename($model),
                'model_id' => $model->id,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Delete an event from vendor's calendar
     */
    public function deleteEvent(Model $model, string $eventId): bool
    {
        if (!$model->google_access_token) {
            Log::error('Cannot delete event: user not connected to Google Calendar');
            return false;
        }

        try {
            $this->setClientToken($model);
            $calendarService = new Calendar($this->client);

            $calendarId = $model->google_calendar_id ?? 'primary';
            $calendarService->events->delete($calendarId, $eventId);

            return true;
        } catch (\Exception $e) {
            Log::error('Error deleting calendar event: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Block a time slot (create a "Blocked" event)
     */
    public function blockTimeSlot(Vendor $vendor, Carbon $start, Carbon $end, string $reason = 'Blocked'): ?string
    {
        return $this->createEvent($vendor, [
            'summary' => $reason,
            'description' => 'Time slot blocked - not available for bookings',
            'start' => $start,
            'end' => $end,
            'timezone' => config('app.timezone', 'UTC'),
        ]);
    }

    /**
     * Get all events for vendor with flexible filtering
     */
    public function getEvents(Vendor $vendor, Carbon $startDate, Carbon $endDate, array $options = []): array
    {
        if (!$vendor->google_refresh_token && !$vendor->google_access_token) {
            return [];
        }

        try {
            $this->setClientToken($vendor);
            $calendarService = new Calendar($this->client);

            Log::info('Fetching ALL events for vendor ID: ' . $vendor->id . ' from ' . $startDate->toDateTimeString() . ' to ' . $endDate->toDateTimeString());

            $params = [
                'timeMin' => $startDate->toRfc3339String(),
                'timeMax' => $endDate->toRfc3339String(),
                'singleEvents' => true,
                'orderBy' => 'startTime',
                'maxResults' => $options['max_results'] ?? 2500,
            ];

            // Optional: Filter by search query
            if (isset($options['search'])) {
                $params['q'] = $options['search'];
            }

            $calendarId = $vendor->google_calendar_id ?? 'primary';
            $events = $calendarService->events->listEvents($calendarId, $params);

            $eventsList = [];
            foreach ($events->getItems() as $event) {
                // Optional: Filter by status
                if (isset($options['include_cancelled']) && !$options['include_cancelled']) {
                    if (isset($event->status) && $event->status === 'cancelled') {
                        continue;
                    }
                }

                // Parse start and end times
                if (isset($event->start->date)) {
                    $start = Carbon::parse($event->start->date)->startOfDay();
                    $end = Carbon::parse($event->end->date)->endOfDay();
                    $isAllDay = true;
                } else {
                    $start = Carbon::parse($event->start->dateTime);
                    $end = Carbon::parse($event->end->dateTime);
                    $isAllDay = false;
                }

                // Optional: Filter by event type
                if (isset($options['all_day_only']) && $options['all_day_only'] && !$isAllDay) {
                    continue;
                }
                if (isset($options['timed_only']) && $options['timed_only'] && $isAllDay) {
                    continue;
                }

                $eventData = [
                    'id' => $event->getId(),
                    'summary' => $event->getSummary() ?? 'Untitled Event',
                    'description' => $event->getDescription() ?? '',
                    'start' => $start,
                    'end' => $end,
                    'start_formatted' => $start->toDateTimeString(),
                    'end_formatted' => $end->toDateTimeString(),
                    'all_day' => $isAllDay,
                    'status' => $event->getStatus() ?? 'confirmed',
                    'location' => $event->getLocation() ?? '',
                    'created' => $event->getCreated() ? Carbon::parse($event->getCreated())->toDateTimeString() : null,
                    'updated' => $event->getUpdated() ? Carbon::parse($event->getUpdated())->toDateTimeString() : null,
                ];

                // Optional: Include attendees
                if (isset($options['include_attendees']) && $options['include_attendees']) {
                    $attendees = [];
                    if ($event->getAttendees()) {
                        foreach ($event->getAttendees() as $attendee) {
                            $attendees[] = [
                                'email' => $attendee->getEmail(),
                                'display_name' => $attendee->getDisplayName() ?? '',
                                'response_status' => $attendee->getResponseStatus() ?? 'needsAction',
                            ];
                        }
                    }
                    $eventData['attendees'] = $attendees;
                }

                $eventsList[] = $eventData;
            }

            Log::info('Fetched ' . count($eventsList) . ' events from Google Calendar for vendor ID: ' . $vendor->id);

            return $eventsList;
        } catch (\Exception $e) {
            Log::error('Error fetching calendar events: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * List all calendars for the vendor
     */
    public function listCalendars(Model $model): array
    {
        if (!$model->google_access_token) {
            return [];
        }

        try {
            $this->setClientToken($model);
            $calendarService = new Calendar($this->client);

            $calendarList = $calendarService->calendarList->listCalendarList();

            $calendars = [];
            foreach ($calendarList->getItems() as $calendar) {
                $calendars[] = [
                    'id' => $calendar->getId(),
                    'summary' => $calendar->getSummary(),
                    'description' => $calendar->getDescription(),
                    'primary' => $calendar->getPrimary() ?? false,
                ];
            }

            return $calendars;
        } catch (\Exception $e) {
            Log::error('Error listing calendars: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get events statistics for a date range
     */
    public function getEventsStats(Model $model, Carbon $startDate, Carbon $endDate): array
    {
        $events = $this->getEvents($model, $startDate, $endDate);

        $allDayEvents = 0;
        $timedEvents = 0;
        $totalDuration = 0;
        $busyDays = [];

        foreach ($events as $event) {
            if ($event['all_day']) {
                $allDayEvents++;
            } else {
                $timedEvents++;
                $duration = $event['start']->diffInMinutes($event['end']);
                $totalDuration += $duration;
            }

            $dayKey = $event['start']->format('Y-m-d');
            $busyDays[$dayKey] = true;
        }

        return [
            'total_events' => count($events),
            'all_day_events' => $allDayEvents,
            'timed_events' => $timedEvents,
            'total_busy_days' => count($busyDays),
            'total_busy_hours' => round($totalDuration / 60, 2),
            'average_event_duration_minutes' => $timedEvents > 0 ? round($totalDuration / $timedEvents) : 0,
            'date_range' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
                'days' => $startDate->diffInDays($endDate) + 1,
            ],
        ];
    }

}