<?php

namespace App\Services;

use App\Models\Setting;
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
}

