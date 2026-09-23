<?php

namespace App\Services;

use App\Models\GlobalTourSetting;
use App\Models\Organization;
use App\Models\Order;
use App\Models\ProductOption;
use Illuminate\Support\Facades\Log;

class AreaPricingService
{
    /**
     * Calculate service square footage and separate Other Area charges.
     *
     * Finished/Sub Areas always contribute to service square footage. Other
     * Areas with a configured charge are fixed-price items and are excluded
     * from the allowance pool. The remaining Other Areas use the organization
     * allowance when enabled, or are all billable when it is disabled.
     */
    public function calculateAreaMetrics(array $areas, ?int $organizationId = null): array
    {
        // Fetch active global tour settings for charge rates
        $tourSettingsQuery = GlobalTourSetting::where('status', true);
        if ($organizationId) {
            $tourSettingsQuery->where('organization_id', $organizationId);
        } else {
            $tourSettingsQuery->whereNull('organization_id');
        }
        $tourSettings = $tourSettingsQuery->get()->keyBy(function ($setting) {
            return strtolower(trim($setting->area));
        });

        // Keep defaults compatible with existing organizations without settings.
        $allowanceEnabled = true;
        $freeAllowanceLimit = 2000;
        $rateAboveAllowance = 0.0;
        try {
            $orgUuid = $organizationId ? Organization::where('id', $organizationId)->value('uuid') : null;
            $portalSettings = app(SettingsService::class)->get($orgUuid, 'portal_settings');
            if (array_key_exists('other_areas_enable_allowance', $portalSettings)) {
                $allowanceEnabled = (bool) $portalSettings['other_areas_enable_allowance'];
            }
            if (array_key_exists('other_areas_free_allowance', $portalSettings)) {
                $freeAllowanceLimit = max(0, (float) $portalSettings['other_areas_free_allowance']);
            }
            if (array_key_exists('other_areas_rate_per_sq_ft', $portalSettings)) {
                $rateAboveAllowance = max(0, (float) $portalSettings['other_areas_rate_per_sq_ft']);
            }
        } catch (\Throwable $e) {
            // Fallback to defaults
        }

        $finishedSubFootage = 0;
        $zeroChargeOtherFootage = 0;
        $customOtherCharges = 0.0;
        $customOtherFootage = 0;

        foreach ($areas as $area) {
            $footage = (int) ($area['footage'] ?? 0);
            if ($footage <= 0) continue;

            $type = strtolower(trim($area['type'] ?? 'other'));
            $customTitle = strtolower(trim($area['custom_title'] ?? $area['type'] ?? ''));

            // Check if this area setting has a specific defined charge in GlobalTourSetting
            $setting = $tourSettings->get($customTitle) ?? $tourSettings->get($type);
            $definedCharge = $setting ? (float) $setting->charge : 0.0;

            if (
                str_contains($type, 'finished') ||
                str_contains($type, 'sub') ||
                str_contains($type, 'subtotal')
            ) {
                $finishedSubFootage += $footage;
            } else {
                // Category: Other Area
                if ($definedCharge > 0) {
                    // A configured Other Area charge is a fixed charge, not a
                    // per-square-foot rate, and never consumes allowance.
                    $customOtherCharges += $definedCharge;
                    $customOtherFootage += $footage;
                } else {
                    // Zero-charge Other Areas participate in the allowance pool.
                    $zeroChargeOtherFootage += $footage;
                }
            }
        }

        $freeAllowanceUsed = $allowanceEnabled
            ? min($zeroChargeOtherFootage, $freeAllowanceLimit)
            : 0;
        $excessOtherFootage = $allowanceEnabled
            ? max(0, $zeroChargeOtherFootage - $freeAllowanceLimit)
            : $zeroChargeOtherFootage;
        $excessOtherCharge = $allowanceEnabled
            ? $excessOtherFootage * $rateAboveAllowance
            : 0;

        // With allowance enabled, excess Other Areas are charged separately.
        // With allowance disabled, all zero-charge Other Areas use normal
        // service square-footage pricing.
        $totalBillableSqft = $allowanceEnabled
            ? $finishedSubFootage
            : $finishedSubFootage + $zeroChargeOtherFootage;

        // Total standalone area charges
        $totalAreaCharges = $customOtherCharges + $excessOtherCharge;

        return [
            'finished_sub_footage' => $finishedSubFootage,
            'zero_charge_other_footage' => $zeroChargeOtherFootage,
            'allowance_enabled' => $allowanceEnabled,
            'free_allowance_limit' => $freeAllowanceLimit,
            'free_allowance_used' => $freeAllowanceUsed,
            'excess_other_footage' => $excessOtherFootage,
            'excess_other_charge' => $excessOtherCharge,
            'custom_other_footage' => $customOtherFootage,
            'custom_other_charges' => $customOtherCharges,
            'total_billable_sqft' => $totalBillableSqft,
            'total_area_charges' => $totalAreaCharges,
        ];
    }

    /**
     * Calculate updated option and price given billable square footage.
     */
    public function getUpdatedOptionAndPrice(?ProductOption $option, int $billableSqft, float $currentAmount): array
    {
        if (!$option || $billableSqft <= 0) {
            return ['option' => $option, 'price' => $currentAmount];
        }

        // 1. Explicit sq_ft_rate
        if (!empty($option->sq_ft_rate) && (float)$option->sq_ft_rate > 0 && empty($option->sq_ft_range)) {
            $rate = (float)$option->sq_ft_rate;
            $minPrice = (float)($option->min_price ?? 0);
            return ['option' => $option, 'price' => max($billableSqft * $rate, $minPrice)];
        }

        // 2. Range tiers for service
        if ($option->service_id) {
            $allOptions = ProductOption::where('service_id', $option->service_id)->get();
            $rangeOptions = $allOptions->filter(fn($o) => !empty($o->sq_ft_range) && !empty($o->amount));
            
            if ($rangeOptions->isNotEmpty()) {
                foreach ($rangeOptions as $opt) {
                    $cleaned = str_replace(' ', '', $opt->sq_ft_range);
                    if (str_contains($cleaned, '+')) {
                        $min = (int) preg_replace('/[^0-9]/', '', $cleaned);
                        if ($billableSqft >= $min) {
                            return ['option' => $opt, 'price' => (float) $opt->amount];
                        }
                    } else {
                        $parts = explode('-', $cleaned);
                        $min = (int) ($parts[0] ?? 0);
                        $max = (int) ($parts[1] ?? 999999);
                        if ($billableSqft >= $min && $billableSqft <= $max) {
                            return ['option' => $opt, 'price' => (float) $opt->amount];
                        }
                    }
                }
            }
        }

        return ['option' => $option, 'price' => $currentAmount];
    }

    /**
     * Calculate price for a product option given billable square footage.
     */
    public function calculateOptionPrice(?ProductOption $option, int $billableSqft, float $currentAmount): float
    {
        return $this->getUpdatedOptionAndPrice($option, $billableSqft, $currentAmount)['price'];
    }

    /**
     * Recalculate order services amounts based on updated areas and trigger invoice sync.
     */
    public function recalculateOrderForAreaUpdate(Order $order): void
    {
        $order->load(['areas', 'services.option']);
        $areas = $order->areas->map(fn($a) => [
            'type' => $a->type,
            'footage' => $a->footage,
            'custom_title' => $a->custom_title,
        ])->toArray();

        $metrics = $this->calculateAreaMetrics($areas, $order->organization_id);
        $billableSqft = $metrics['total_billable_sqft'];
        $totalAreaCharges = (float)($metrics['total_area_charges'] ?? 0.0);

        $grandTotal = 0.0;
        foreach ($order->services as $os) {
            $isPerSqftRate = false;

            if ($os->payment_status === 'PAID') {
                $basePrice = (float)$os->amount;
                if ($os->option) {
                    $isPerSqftRate = !empty($os->option->sq_ft_rate) && (float)$os->option->sq_ft_rate > 0 && empty($os->option->sq_ft_range);
                }
            } else {
                if ($os->option) {
                    $res = $this->getUpdatedOptionAndPrice($os->option, $billableSqft, (float)$os->amount);
                    $basePrice = $res['price'];
                    $newOption = $res['option'];

                    $isPerSqftRate = !empty($newOption?->sq_ft_rate) && (float)$newOption->sq_ft_rate > 0 && empty($newOption?->sq_ft_range);

                    $updates = [];
                    if ((float)$os->amount != $basePrice) {
                        $updates['amount'] = $basePrice;
                    }
                    if ($newOption && $os->option_id != $newOption->id) {
                        $updates['option_id'] = $newOption->id;
                    }
                    if (!empty($updates)) {
                        $os->update($updates);
                    }
                } else {
                    $basePrice = (float)$os->amount;
                }
            }

            $serviceTotal = $isPerSqftRate ? ($basePrice + $totalAreaCharges) : $basePrice;
            $grandTotal += $serviceTotal;
        }

        $order->update(['amount' => $grandTotal]);

        \App\Models\Invoice::syncOrderInvoices($order);
    }
}
