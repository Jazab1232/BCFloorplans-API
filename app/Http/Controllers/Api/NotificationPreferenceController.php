<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class NotificationPreferenceController extends Controller
{
    /**
     * Resolve active organization UUID
     */
    protected function resolveOrgUuid(): ?string
    {
        $user = auth()->user();
        if (!$user) {
            return null;
        }

        return match(true) {
            $user instanceof \App\Models\User       => $user->organization?->uuid,
            $user instanceof \App\Models\Agent      => $user->organization?->uuid,
            $user instanceof \App\Models\SubAccount => $user->organization?->uuid,
            $user instanceof \App\Models\Vendor     => $user->organization?->uuid,
            default => null,
        };
    }

    /**
     * Get all notification preferences for the organization
     */
    public function index(Request $request): JsonResponse
    {
        $orgUuid = $this->resolveOrgUuid();
        if (!$orgUuid) {
            return response()->json(['success' => false, 'message' => 'Organization context not found'], 400);
        }

        $org = Organization::where('uuid', $orgUuid)->firstOrFail();

        $preferences = NotificationPreference::where('organization_id', $org->id)
            ->whereNull('user_id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $preferences
        ]);
    }

    /**
     * Get list of all registered email events and their defaults
     */
    public function events(): JsonResponse
    {
        $events = config('email-events', []);
        
        $formattedEvents = [];
        foreach ($events as $eventType => $details) {
            $formattedEvents[] = [
                'event_type' => $eventType,
                'label' => $details['label'],
                'description' => $details['description'] ?? '',
                'recipients' => $details['recipients'] ?? [],
                'defaults' => $details['defaults'] ?? [],
                'always_send' => $details['always_send'] ?? false,
                'has_timing' => $details['has_timing'] ?? false,
                'supported_units' => $details['supported_units'] ?? [],
                'default_intervals' => $details['default_intervals'] ?? [],
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $formattedEvents
        ]);
    }

    /**
     * Bulk update notification preferences for the organization
     */
    public function update(Request $request): JsonResponse
    {
        $orgUuid = $this->resolveOrgUuid();
        if (!$orgUuid) {
            return response()->json(['success' => false, 'message' => 'Organization context not found'], 400);
        }

        $org = Organization::where('uuid', $orgUuid)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'preferences' => 'required|array',
            'preferences.*.role' => 'required|string|in:admin,agent,vendor',
            'preferences.*.event_type' => 'required|string',
            'preferences.*.email_enabled' => 'required|boolean',
            'preferences.*.intervals' => 'nullable|array',
            'preferences.*.intervals.*.value' => 'required_with:preferences.*.intervals|integer|min:1',
            'preferences.*.intervals.*.unit' => 'required_with:preferences.*.intervals|string|in:minutes,hours,days,weeks',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $preferencesData = $validator->validated()['preferences'];

        foreach ($preferencesData as $pref) {
            $updateData = [
                'email_enabled' => $pref['email_enabled'],
            ];

            if (array_key_exists('intervals', $pref)) {
                $updateData['intervals'] = $pref['intervals'];
            }

            $existing = NotificationPreference::where([
                'organization_id' => $org->id,
                'role' => $pref['role'],
                'event_type' => $pref['event_type'],
                'user_id' => null,
            ])->first();

            if ($existing) {
                $existing->update($updateData);
            } else {
                NotificationPreference::create(array_merge([
                    'organization_id' => $org->id,
                    'role' => $pref['role'],
                    'event_type' => $pref['event_type'],
                    'user_id' => null,
                    'uuid' => (string) Str::uuid(),
                ], $updateData));
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification preferences updated successfully'
        ]);
    }
}
