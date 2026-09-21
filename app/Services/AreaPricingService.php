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
     * Calculate billable square footage and area charges for an array of order areas.
     *
     * Rules:
     * 1. Finished & Sub Areas: Always included in billable sqft.
     * 2. Other Areas ($0 defined charge): Pooled into Free Allowance limit (default 2000 sq ft).
     *    - Footage up to limit = $0 (free)
     *    - Excess footage above limit = Added to billable sqft / charged at rate above allowance.
     * 3. Other Areas (> $0 defined charge): Excluded from Free Allowance pool.
     *    - Charged directly per sq. ft. from sq. ft. 1 based on defined charge rate.
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

        // Default free allowance settings (fallback 2000 limit, 0 rate above)
        $freeAllowanceLimit = 2000;
        $rateAboveAllowance = 0.0;
        try {
            $orgUuid = $organizationId ? Organization::where('id', $organizationId)->value('uuid') : null;
            $portalSettings = app(SettingsService::class)->get($orgUuid, 'portal_settings');
            if (isset($portalSettings['free_allowance_limit'])) {
                $freeAllowanceLimit = (int) $portalSettings['free_allowance_limit'];
            }
            if (isset($portalSettings['rate_above_allowance'])) {
                $rateAboveAllowance = (float) $portalSettings['rate_above_allowance'];
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
                    // Explicitly charged Other Area (e.g. Servant Room, Barn)
                    // Does NOT count towards free allowance pool, always charged from sq ft 1
                    $customOtherCharges += ($footage * $definedCharge);
                    $customOtherFootage += $footage;
                } else {
                    // $0 defined charge Other Area (e.g. standard Garage, Deck)
                    $zeroChargeOtherFootage += $footage;
                }
            }
        }

        // Calculate free allowance excess for $0 Other Areas
        $excessOtherFootage = max(0, $zeroChargeOtherFootage - $freeAllowanceLimit);
        $excessOtherCharge = $excessOtherFootage * $rateAboveAllowance;

        // Billable square footage for services (e.g., 2D Floor Plan)
        $totalBillableSqft = $finishedSubFootage + $excessOtherFootage;

        // Total standalone area charges
        $totalAreaCharges = $customOtherCharges + $excessOtherCharge;

        return [
            'finished_sub_footage' => $finishedSubFootage,
            'zero_charge_other_footage' => $zeroChargeOtherFootage,
            'free_allowance_limit' => $freeAllowanceLimit,
            'free_allowance_used' => min($zeroChargeOtherFootage, $freeAllowanceLimit),
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

        $totalServiceAmount = 0.0;
        foreach ($order->services as $os) {
            if ($os->payment_status === 'PAID') {
                $totalServiceAmount += (float)$os->amount;
                continue;
            }

            if ($os->option) {
                $res = $this->getUpdatedOptionAndPrice($os->option, $billableSqft, (float)$os->amount);
                $newPrice = $res['price'];
                $newOption = $res['option'];

                $updates = [];
                if ((float)$os->amount != $newPrice) {
                    $updates['amount'] = $newPrice;
                }
                if ($newOption && $os->option_id != $newOption->id) {
                    $updates['option_id'] = $newOption->id;
                }
                if (!empty($updates)) {
                    $os->update($updates);
                }
                $totalServiceAmount += $newPrice;
            } else {
                $totalServiceAmount += (float)$os->amount;
            }
        }

        $grandTotal = $totalServiceAmount + $metrics['total_area_charges'];
        $order->update(['amount' => $grandTotal]);

        // Sync master and partial invoices
        \App\Models\Invoice::syncOrderInvoices($order);
    }
}
