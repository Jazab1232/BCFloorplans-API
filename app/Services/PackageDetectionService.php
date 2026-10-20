<?php

namespace App\Services;

use App\Models\Package;
use Illuminate\Support\Facades\Log;

class PackageDetectionService
{
    /**
     * Detect applicable packages based on service IDs
     * Returns packages where ALL package services are present in the order
     *
     * @param array $orderServiceIds Array of service IDs in the order
     * @return \Illuminate\Support\Collection
     */
    public function detectApplicablePackages(array $orderServiceIds, $orgId = null): \Illuminate\Support\Collection
    {
        $orgId = $orgId ?: (app()->bound('current_organization_id') ? app('current_organization_id') : null);

        $query = Package::with('services')
            ->where('status', true);

        if ($orgId) {
            $query->where('organization_id', $orgId);
        }

        $packages = $query->get();

        $applicablePackages = collect();

        foreach ($packages as $package) {
            $packageServiceIds = $package->services->pluck('id')->toArray();

            // Check if ALL package services are present in order services
            // Order can have MORE services (superset), but must contain ALL package services
            $hasAllServices = empty(array_diff($packageServiceIds, $orderServiceIds));

            if ($hasAllServices && !empty($packageServiceIds)) {
                $applicablePackages->push($package);
            }
        }

        return $applicablePackages;
    }

    /**
     * Get the best package (highest discount) from multiple matching packages
     *
     * @param \Illuminate\Support\Collection $packages
     * @return Package|null
     */
    /**
     * Calculate total amount of only services that belong to the package
     *
     * @param Package $package
     * @param array $serviceAmounts Map of service_id => amount
     * @return float
     */
    public function calculateEligiblePackageAmount(Package $package, array $serviceAmounts): float
    {
        $package->loadMissing('services');
        $pkgServiceIds = $package->services->pluck('id')->toArray();
        $total = 0.0;
        foreach ($pkgServiceIds as $sid) {
            if (isset($serviceAmounts[$sid])) {
                $total += (float)$serviceAmounts[$sid];
            }
        }
        return $total;
    }

    /**
     * Get the best package (highest discount) from multiple matching packages
     *
     * @param \Illuminate\Support\Collection $packages
     * @param array|float $amounts Service amounts map [service_id => amount] or total amount float
     * @return Package|null
     */
    public function getBestPackage(\Illuminate\Support\Collection $packages, array|float $amounts = 0): ?Package
    {
        if ($packages->isEmpty()) {
            return null;
        }

        if (is_array($amounts)) {
            return $packages->sortByDesc(function ($pkg) use ($amounts) {
                $eligible = $this->calculateEligiblePackageAmount($pkg, $amounts);
                return $this->calculatePackageDiscount($pkg, $eligible);
            })->first();
        }

        if (is_numeric($amounts) && $amounts > 0) {
            return $packages->sortByDesc(function ($pkg) use ($amounts) {
                return $this->calculatePackageDiscount($pkg, (float)$amounts);
            })->first();
        }

        // Return package with highest discount
        return $packages->sortByDesc('discount')->first();
    }

    /**
     * Calculate package discount amount based on discount_type (dollar or percentage)
     * strictly applied to the eligible package services amount.
     *
     * @param Package $package
     * @param float $eligibleAmount Total amount of only the services in the package
     * @return float Discount amount
     */
    public function calculatePackageDiscount(Package $package, float $eligibleAmount): float
    {
        if (!$package || $package->discount <= 0 || $eligibleAmount <= 0) {
            return 0;
        }

        if ($package->discount_type === 'dollar') {
            $discountAmount = min($eligibleAmount, (float) $package->discount);
        } else {
            // Package discount is a percentage applied strictly to eligible package services
            $discountAmount = $eligibleAmount * ($package->discount / 100);
        }

        return round($discountAmount, 2);
    }

    /**
     * Get package information for storing in order meta.
     * Ensures matched_service_ids strictly contains only the package's services.
     *
     * @param Package $package
     * @param array $matchedServiceIds
     * @param float $discountAmount
     * @return array
     */
    public function getPackageInfo(Package $package, array $matchedServiceIds, float $discountAmount): array
    {
        $package->loadMissing('services');
        $actualPkgServiceIds = $package->services->pluck('id')->toArray();
        $filteredMatchedIds = !empty($actualPkgServiceIds)
            ? array_values(array_intersect($matchedServiceIds, $actualPkgServiceIds))
            : array_values($matchedServiceIds);

        return [
            'package_id' => $package->id,
            'package_uuid' => $package->uuid,
            'package_name' => $package->name,
            'package_discount' => $package->discount,
            'package_discount_type' => $package->discount_type ?? 'percentage',
            'package_discount_percentage' => ($package->discount_type === 'dollar') ? null : $package->discount,
            'package_discount_amount' => $discountAmount,
            'matched_service_ids' => $filteredMatchedIds,
            'applied_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * Check if package should be applied based on service changes
     * 
     * @param array $newServiceIds Current service IDs in order
     * @param array|null $existingPackageInfo Existing package info from meta
     * @param int|null $orgId
     * @param array|float $amounts Service amounts map or total
     * @return array ['should_apply' => bool, 'package' => Package|null, 'changed' => bool]
     */
    public function evaluatePackageForUpdate(array $newServiceIds, ?array $existingPackageInfo, $orgId = null, array|float $amounts = 0): array
    {
        $applicablePackages = $this->detectApplicablePackages($newServiceIds, $orgId);
        $bestPackage = $this->getBestPackage($applicablePackages, $amounts);

        $oldPackageId = $existingPackageInfo['package_id'] ?? null;
        $newPackageId = $bestPackage?->id;

        return [
            'should_apply' => !is_null($bestPackage),
            'package' => $bestPackage,
            'changed' => $oldPackageId != $newPackageId,
            'old_package_id' => $oldPackageId,
            'new_package_id' => $newPackageId,
        ];
    }

    /**
     * Log package detection for debugging
     *
     * @param array $serviceIds
     * @param Package|null $selectedPackage
     * @return void
     */
    public function logPackageDetection(array $serviceIds, ?Package $selectedPackage): void
    {
        Log::info('Package detection completed', [
            'service_ids' => $serviceIds,
            'package_found' => !is_null($selectedPackage),
            'package_name' => $selectedPackage?->name,
            'package_discount' => $selectedPackage?->discount,
        ]);
    }
}
