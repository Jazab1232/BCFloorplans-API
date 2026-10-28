<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Agent;
use App\Exceptions\SettingNotFoundException;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    protected function cacheKey(?string $orgId, string $key): string
    {
        return 'settings.' . ($orgId ?? 'global') . '.' . $key;
    }

    public function get(?string $orgId, string $key): array
    {
        return Cache::rememberForever(
            $this->cacheKey($orgId, $key),
            function () use ($orgId, $key) {

                // 1️⃣ Org-specific
                if ($orgId) {
                    $setting = Setting::where('org_id', $orgId)
                        ->where('key', $key)
                        ->first();

                    if ($setting) {
                        return $setting->value;
                    }
                }

                // 2️⃣ Global fallback
                $global = Setting::whereNull('org_id')
                    ->where('key', $key)
                    ->first();

                if (!$global) {
                    throw new SettingNotFoundException("Setting '{$key}' not found");
                }

                return $global->value;
            }
        );
    }

    public function set(?string $orgId, string $key, array $value, ?string $adminUuid): Setting
    {
        $setting = Setting::updateOrCreate(
            [
                'org_id' => $orgId,
                'key' => $key
            ],
            [
                'value' => $value,
                'updated_by' => $adminUuid
            ]
        );

        Cache::forget($this->cacheKey($orgId, $key));

        return $setting;
    }

    public function delete(?string $orgId, string $key): bool
    {
        $setting = Setting::where('org_id', $orgId)
            ->where('key', $key)
            ->first();

        if (!$setting) {
            throw new SettingNotFoundException("Setting not found");
        }

        Cache::forget($this->cacheKey($orgId, $key));

        return $setting->delete();
    }

    /**
     * Resolve effective tour defaults hierarchically:
     * Built-in defaults -> Global platform settings -> Org settings -> Agent overrides
     */
    public function getEffectiveTourSettings(?string $orgUuid, ?string $agentUuid = null): array
    {
        // 1. Built-in system defaults
        $effective = [
            'music_enabled' => true,
            'default_song' => 'tell-me-what',
            'default_audio_uuid' => null,
            'transition_effect' => ['kenburns'],
            'layout_option' => 'standard',
            'video_slideshow_enabled' => true,
            'letterbox_correction' => true,
            'aspect_ratio' => '16:9',
            'autoplay_enabled' => true,
            'allow_print_download' => true,
            'allow_client_upload' => true,
            'require_payment_before_download' => false,
            'always_enable_sorting' => false,
        ];

        // 2. Global platform tour_settings
        try {
            $globalSetting = Setting::whereNull('org_id')->where('key', 'tour_settings')->first();
            if ($globalSetting && is_array($globalSetting->value)) {
                $effective = array_merge($effective, array_filter($globalSetting->value, fn($v) => !is_null($v)));
            }
        } catch (\Throwable $e) {}

        // 3. Organization tour_settings
        if ($orgUuid) {
            try {
                $orgSetting = Setting::where('org_id', $orgUuid)->where('key', 'tour_settings')->first();
                if ($orgSetting && is_array($orgSetting->value)) {
                    $effective = array_merge($effective, array_filter($orgSetting->value, fn($v) => !is_null($v)));
                }
            } catch (\Throwable $e) {}
        }

        // 4. Agent personal overrides
        if ($agentUuid) {
            $agent = Agent::where('uuid', $agentUuid)->first();
            if ($agent && is_array($agent->tour_settings)) {
                $effective = array_merge($effective, array_filter($agent->tour_settings, fn($v) => !is_null($v)));
            }
        }

        return $effective;
    }

    /**
     * Resolve effective tour appearance hierarchically:
     * Built-in defaults -> Global white_label_styles[tour] -> Org white_label_styles[tour] -> Agent tour_appearance
     */
    public function getEffectiveTourAppearance(?string $orgUuid, ?string $agentUuid = null): array
    {
        // 1. Built-in defaults
        $effective = [
            'pageBg' => '#EFEFEF',
            'pageText' => '#6D6D6D',
            'sidebarBg' => '#E4E4E4',
            'sidebarText' => '#6D6D6D',
            'sidebarHoverBg' => '#f4f4f5',
            'sidebarHoverText' => '#6D6D6D',
            'activeColor' => '#4290E9',
            'pageTabColor' => '#4290E9',
            'logo' => null,
            'logoWidth' => '32',
        ];

        // 2. Global white_label_styles
        try {
            $globalStyles = Setting::whereNull('org_id')->where('key', 'white_label_styles')->first();
            if ($globalStyles && is_array($globalStyles->value) && isset($globalStyles->value['tour']) && is_array($globalStyles->value['tour'])) {
                $effective = array_merge($effective, array_filter($globalStyles->value['tour'], fn($v) => !is_null($v) && $v !== ''));
            }
        } catch (\Throwable $e) {}

        // 3. Org white_label_styles
        if ($orgUuid) {
            try {
                $orgStyles = Setting::where('org_id', $orgUuid)->where('key', 'white_label_styles')->first();
                if ($orgStyles && is_array($orgStyles->value) && isset($orgStyles->value['tour']) && is_array($orgStyles->value['tour'])) {
                    $effective = array_merge($effective, array_filter($orgStyles->value['tour'], fn($v) => !is_null($v) && $v !== ''));
                }
            } catch (\Throwable $e) {}
        }

        // 4. Agent personal tour_appearance
        if ($agentUuid) {
            $agent = Agent::where('uuid', $agentUuid)->first();
            if ($agent) {
                if (is_array($agent->tour_appearance)) {
                    $effective = array_merge($effective, array_filter($agent->tour_appearance, fn($v) => !is_null($v) && $v !== ''));
                }
                // Fallback logo from agent profile if no tour logo is set
                if (empty($effective['logo'])) {
                    $effective['logo'] = $agent->company_logo_url ?: $agent->avatar_url;
                }
            }
        }

        return $effective;
    }

    /**
     * Set agent personal tour settings
     */
    public function setAgentTourSettings(string $agentUuid, array $value): Agent
    {
        $agent = Agent::where('uuid', $agentUuid)->firstOrFail();
        $agent->tour_settings = $value;
        $agent->save();

        return $agent;
    }

    /**
     * Set agent personal tour appearance
     */
    public function setAgentTourAppearance(string $agentUuid, array $value): Agent
    {
        $agent = Agent::where('uuid', $agentUuid)->firstOrFail();
        $agent->tour_appearance = $value;
        $agent->save();

        return $agent;
    }
}
