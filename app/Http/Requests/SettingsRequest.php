<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        Log::info('Authorizing settings update request', [
            'user' => $this->user() ? $this->user()->uuid : 'guest'
        ]);
        
        $user = $this->user();

        if (!$user) {
            return false;
        }

        if ($user instanceof \App\Models\Agent) {
            return false;
        }

        if ($user instanceof \App\Models\Vendor) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        Log::info('Determining validation rules for settings update', [
            'key' => $this->route('key')
        ]);
        
        $key = $this->route('key');

        return match ($key) {
            'media_settings', 'media_rules' => $this->mediaRules(),
            'feature_flags'                 => $this->featureFlags(),
            'upload_limits'                 => $this->uploadLimits(),
            'white_label_styles'            => $this->whiteLabelStyles(),
            'tour_settings'                 => $this->tourSettings(),
            'portal_settings'               => $this->portalSettings(),
            default                          => $this->unknownKey(),
        };
    }

    /**
     * MEDIA SETTINGS RULES - with 'value' wrapper
     */
    protected function mediaRules(): array
    {
        return [
            'value' => 'required|array',
            
            'value.photos' => 'required|array',
            'value.photos.original' => 'required|array',
            'value.photos.original.width' => 'required|integer|min:1',
            'value.photos.original.height' => 'required|integer|min:1',

            'value.photos.small' => 'required|array',
            'value.photos.small.width' => 'required|integer|min:1',
            'value.photos.small.height' => 'required|integer|min:1',

            'value.photos.large' => 'required|array',
            'value.photos.large.width' => 'required|integer|min:1',
            'value.photos.large.height' => 'required|integer|min:1',

            'value.photos.mls' => 'required|array',
            'value.photos.mls.width' => 'required|integer|min:1',
            'value.photos.mls.height' => 'required|integer|min:1',

            'value.videos' => 'required|array',
            'value.videos.original' => 'required|array',
            'value.videos.original.width' => 'required|integer|min:1',
            'value.videos.original.height' => 'required|integer|min:1',

            'value.videos.small' => 'required|array',
            'value.videos.small.width' => 'required|integer|min:1',
            'value.videos.small.height' => 'required|integer|min:1',

            'value.videos.large' => 'required|array',
            'value.videos.large.width' => 'required|integer|min:1',
            'value.videos.large.height' => 'required|integer|min:1',

            'value.videos.mls' => 'required|array',
            'value.videos.mls.width' => 'required|integer|min:1',
            'value.videos.mls.height' => 'required|integer|min:1',
        ];
    }

    /**
     * FEATURE FLAGS
     */
    protected function featureFlags(): array
    {
        return [
            'value' => 'required|array',
            'value.*' => 'boolean'
        ];
    }

    /**
     * UPLOAD LIMITS
     */
    protected function uploadLimits(): array
    {
        return [
            'value' => 'required|array',
            'value.max_photos' => 'required|integer|min:1',
            'value.max_videos' => 'required|integer|min:1',
            'value.max_file_size_mb' => 'required|integer|min:1',
        ];
    }


    /**
 * WHITE LABEL / GLOBAL STYLE SETTINGS
 */
protected function whiteLabelStyles(): array
{
    return [
        'value' => 'required|array',

        /*
        |--------------------------------------------------------------------------
        | ADMIN STYLES
        |--------------------------------------------------------------------------
        */
        'value.admin' => 'required|array',
        'value.admin.pageBg' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'value.admin.pageText' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'value.admin.sidebarBg' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'value.admin.sidebarText' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'value.admin.sidebarHoverBg' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'value.admin.sidebarHoverText' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'value.admin.activeColor' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'value.admin.pageTabColor' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',

        /*
        |--------------------------------------------------------------------------
        | VENDOR STYLES
        |--------------------------------------------------------------------------
        */
        'value.vendor' => 'required|array',
        'value.vendor.pageBg' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'value.vendor.pageText' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'value.vendor.sidebarBg' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'value.vendor.sidebarText' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'value.vendor.sidebarHoverBg' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'value.vendor.sidebarHoverText' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'value.vendor.activeColor' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'value.vendor.pageTabColor' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',

        /*
        |--------------------------------------------------------------------------
        | AGENT STYLES
        |--------------------------------------------------------------------------
        */
        'value.agent' => 'required|array',
        'value.agent.pageBg' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'value.agent.pageText' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'value.agent.sidebarBg' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'value.agent.sidebarText' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'value.agent.sidebarHoverBg' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'value.agent.sidebarHoverText' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'value.agent.activeColor' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'value.agent.pageTabColor' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
    ];
}

/**
 * TOUR SETTINGS
 */
protected function tourSettings(): array
{
    return [
        'value' => 'required|array',
        'value.music_enabled' => 'required|boolean',
        'value.default_song' => 'nullable|string',
        'value.default_audio_uuid' => 'nullable|string',
        'value.transition_effect' => 'required|array',
        'value.layout_option' => 'required|string',
        'value.video_slideshow_enabled' => 'required|boolean',
        'value.letterbox_correction' => 'required|boolean',
        'value.aspect_ratio' => 'required|string',
        'value.autoplay_enabled' => 'required|boolean',
        'value.allow_print_download' => 'required|boolean',
        'value.allow_client_upload' => 'required|boolean',
        'value.require_payment_before_download' => 'nullable|boolean',
        'value.enable_matterport_default_expiry' => 'nullable|boolean',
        'value.matterport_default_expiry_days' => 'nullable|integer|min:0',
        'value.matterport_renewal_plans' => 'nullable|array',
        'value.matterport_renewal_plans.*.id' => 'nullable|string',
        'value.matterport_renewal_plans.*.months' => 'nullable|numeric',
        'value.matterport_renewal_plans.*.label' => 'nullable|string',
        'value.matterport_renewal_plans.*.price' => 'nullable|numeric',
        'value.matterport_auto_invoice_enabled' => 'nullable|boolean',
        'value.matterport_auto_invoice_days' => 'nullable|integer|min:0',
        'value.matterport_reminder_intervals' => 'nullable|array',
    ];
}





    /**
     * PORTAL SETTINGS
     */
    protected function portalSettings(): array
    {
        return [
            'value' => 'required|array',
            'value.show_org_details_on_empty_schedule' => 'required|boolean',
            'value.disable_next_day_booking' => 'required|boolean',
            'value.booking_cutoff_time' => 'required|string|regex:/^\d{2}:\d{2}$/',
            'value.allow_print_request' => 'required|boolean',
            'value.other_areas_free_allowance' => 'nullable|numeric|min:0',
            'value.other_areas_rate_per_sq_ft' => 'nullable|numeric|min:0',
            'value.other_areas_enable_allowance' => 'nullable|boolean',
            'value.cancellation_threshold_hours' => 'required|integer|min:0|max:720',
            'value.cancellation_fee_percentage' => 'required|numeric|min:0|max:100',
            'value.allow_cancel_after_threshold' => 'required|boolean',
        ];
    }

    /**
     * Handle unknown/invalid setting keys
     */
    protected function unknownKey(): array
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Invalid setting key',
                'error' => 'The setting key "' . $this->route('key') . '" is not supported',
                'supported_keys' => [
                    'media_settings',
                    'media_rules',
                    'feature_flags',
                    'upload_limits',
                    'white_label_styles',
                    'tour_settings',
                    'portal_settings',
                ]
            ], 422)
        );
    }

    /**
     * Force JSON response on validation failure
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
                'key' => $this->route('key')
            ], 422)
        );
    }

    /**
     * Force JSON response on authorization failure
     */
    protected function failedAuthorization()
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Unauthorized',
                'error' => 'You do not have permission to update settings'
            ], 403)
        );
    }

    /**
     * Custom error messages
     */
    public function messages(): array
    {
        return [
            'value.required' => 'The value field is required',
            'value.array' => 'The value must be an array',
            'value.photos.required' => 'Photos configuration is required',
            'value.videos.required' => 'Videos configuration is required',
            'value.photos.*.width.required' => 'Width is required for all photo sizes',
            'value.photos.*.height.required' => 'Height is required for all photo sizes',
            'value.videos.*.width.required' => 'Width is required for all video sizes',
            'value.videos.*.height.required' => 'Height is required for all video sizes',
        ];
    }
}