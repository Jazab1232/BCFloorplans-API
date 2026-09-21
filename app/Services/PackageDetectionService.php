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
    public function getBestPackage(\Illuminate\Support\Collection $packages): ?Package
    {
        if ($packages->isEmpty()) {
            return null;
        }

        // Return package with highest discount percentage
        return $packages->sortByDesc('discount')->first();
    }

    /**
     * Calculate package discount amount based on percentage and total
     *
     * @param Package $package
     * @param float $totalAmount Total order amount before discounts
     * @return float Discount amount
     */
    public function calculatePackageDiscount(Package $package, float $totalAmount): float
    {
        if (!$package || $package->discount <= 0) {
            return 0;
        }

        // Package discount is a percentage (e.g., 20 = 20%)
        $discountAmount = $totalAmount * ($package->discount / 100);

        return round($discountAmount, 2);
    }

    /**
     * Get package information for storing in order meta
     *
     * @param Package $package
     * @param array $matchedServiceIds
     * @param float $discountAmount
     * @return array
     */
    public function getPackageInfo(Package $package, array $matchedServiceIds, float $discountAmount): array
    {
        return [
            'package_id' => $package->id,
            'package_uuid' => $package->uuid,
            'package_name' => $package->name,
            'package_discount_percentage' => $package->discount,
            'package_discount_amount' => $discountAmount,
            'matched_service_ids' => $matchedServiceIds,
            'applied_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * Check if package should be applied based on service changes
     * 
     * @param array $newServiceIds Current service IDs in order
     * @param array|null $existingPackageInfo Existing package info from meta
     * @return array ['should_apply' => bool, 'package' => Package|null, 'changed' => bool]
     */
    public function evaluatePackageForUpdate(array $newServiceIds, ?array $existingPackageInfo): array
    {
        $applicablePackages = $this->detectApplicablePackages($newServiceIds);
        $bestPackage = $this->getBestPackage($applicablePackages);

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
