<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Setting;
use App\Http\Controllers\Api\InvoiceController;
use Illuminate\Support\Facades\Log;

class TaxCalculationService
{
    public const SETTING_KEY = 'tax_settings';

    /**
     * Normalize province / state names and abbreviations.
     */
    public static function normalizeProvince(?string $province): string
    {
        if (!$province) {
            return '';
        }
        $p = strtoupper(trim($province));
        $map = [
            'ONTARIO' => 'ON',
            'BRITISH COLUMBIA' => 'BC',
            'ALBERTA' => 'AB',
            'QUEBEC' => 'QC',
            'MANITOBA' => 'MB',
            'SASKATCHEWAN' => 'SK',
            'NOVA SCOTIA' => 'NS',
            'NEW BRUNSWICK' => 'NB',
            'NEWFOUNDLAND' => 'NL',
            'NEWFOUNDLAND AND LABRADOR' => 'NL',
            'PRINCE EDWARD ISLAND' => 'PE',
            'NORTHWEST TERRITORIES' => 'NT',
            'YUKON' => 'YT',
            'NUNAVUT' => 'NU',
        ];
        return $map[$p] ?? $p;
    }

    /**
     * Retrieve tax settings for an organization.
     */
    public static function getOrgTaxSettings(?string $orgUuid = null, ?int $orgId = null): array
    {
        if (!$orgUuid && $orgId) {
            $org = Organization::find($orgId);
            $orgUuid = $org?->uuid;
        }

        if (!$orgUuid) {
            $user = auth()->user();
            $orgUuid = match (true) {
                $user instanceof \App\Models\User       => $user->organization?->uuid,
                $user instanceof \App\Models\Agent      => $user->organization?->uuid,
                $user instanceof \App\Models\SubAccount => $user->organization?->uuid,
                $user instanceof \App\Models\Vendor     => $user->organization?->uuid,
                default => null,
            };
        }

        $setting = null;
        if ($orgUuid) {
            $setting = Setting::where('org_id', $orgUuid)
                ->where('key', self::SETTING_KEY)
                ->first();
        }

        if ($setting && is_array($setting->value)) {
            $data = $setting->value;
            $data['is_configured'] = true;
            return $data;
        }

        // Return unconfigured default structure (triggers legacy fallback on calculation)
        return [
            'is_configured' => false,
            'is_enabled' => true,
            'calculation_basis' => 'destination',
            'origin_taxes' => [
                [
                    'name' => 'GST',
                    'rate' => 5.0,
                    'registration_number' => '',
                    'is_enabled' => true,
                ]
            ],
            'destination_rules' => [],
            'unmatched_destination_policy' => 'fallback_rate',
            'fallback_tax' => [
                'name' => 'GST',
                'rate' => 5.0,
                'registration_number' => '',
            ],
        ];
    }

    /**
     * Save tax settings for an organization.
     */
    public static function saveOrgTaxSettings(string $orgUuid, array $payload, ?string $updatedByUuid = null): array
    {
        $cleanData = [
            'is_configured' => true,
            'is_enabled' => (bool)($payload['is_enabled'] ?? true),
            'calculation_basis' => in_array($payload['calculation_basis'] ?? '', ['origin', 'destination'], true)
                ? $payload['calculation_basis']
                : 'origin',
            'origin_taxes' => [],
            'destination_rules' => [],
            'unmatched_destination_policy' => in_array($payload['unmatched_destination_policy'] ?? '', ['zero_tax', 'fallback_rate'], true)
                ? $payload['unmatched_destination_policy']
                : 'fallback_rate',
            'fallback_tax' => [
                'name' => trim($payload['fallback_tax']['name'] ?? 'Tax') ?: 'Tax',
                'rate' => (float)($payload['fallback_tax']['rate'] ?? 0.0),
                'registration_number' => trim($payload['fallback_tax']['registration_number'] ?? ''),
            ],
        ];

        // Sanitize origin_taxes
        if (!empty($payload['origin_taxes']) && is_array($payload['origin_taxes'])) {
            foreach ($payload['origin_taxes'] as $tax) {
                $name = trim($tax['name'] ?? '');
                if ($name !== '') {
                    $cleanData['origin_taxes'][] = [
                        'name' => $name,
                        'rate' => round((float)($tax['rate'] ?? 0.0), 4),
                        'registration_number' => trim($tax['registration_number'] ?? ''),
                        'is_enabled' => isset($tax['is_enabled']) ? (bool)$tax['is_enabled'] : true,
                    ];
                }
            }
        }

        // Sanitize destination_rules
        if (!empty($payload['destination_rules']) && is_array($payload['destination_rules'])) {
            foreach ($payload['destination_rules'] as $rule) {
                $stateProv = trim($rule['state_province'] ?? '');
                if ($stateProv !== '') {
                    $ruleTaxes = [];
                    if (!empty($rule['taxes']) && is_array($rule['taxes'])) {
                        foreach ($rule['taxes'] as $rt) {
                            $rName = trim($rt['name'] ?? '');
                            if ($rName !== '') {
                                $ruleTaxes[] = [
                                    'name' => $rName,
                                    'rate' => round((float)($rt['rate'] ?? 0.0), 4),
                                    'registration_number' => trim($rt['registration_number'] ?? ''),
                                    'is_enabled' => isset($rt['is_enabled']) ? (bool)$rt['is_enabled'] : true,
                                ];
                            }
                        }
                    }

                    $cleanData['destination_rules'][] = [
                        'state_province' => strtoupper($stateProv),
                        'country' => strtoupper(trim($rule['country'] ?? 'CA')),
                        'taxes' => $ruleTaxes,
                    ];
                }
            }
        }

        $setting = Setting::updateOrCreate(
            ['org_id' => $orgUuid, 'key' => self::SETTING_KEY],
            ['value' => $cleanData, 'updated_by' => $updatedByUuid]
        );

        $saved = $setting->value;
        $saved['is_configured'] = true;
        return $saved;
    }

    /**
     * Calculate taxes for an order or set of items.
     *
     * @param mixed $organization Organization model, org_id (int), org_uuid (string), or null
     * @param iterable|array $items List of items (each having amount, and optional service / tax flags)
     * @param string|null $province Destination province / state (e.g. "BC", "ON")
     * @param string|null $country Destination country
     * @return array
     */
    public static function calculateTaxes($organization, $items, ?string $province = null, ?string $country = 'CA'): array
    {
        $orgModel = null;
        $orgUuid = null;
        $orgId = null;

        if ($organization instanceof Organization) {
            $orgModel = $organization;
            $orgUuid = $organization->uuid;
            $orgId = $organization->id;
        } elseif (is_numeric($organization)) {
            $orgModel = Organization::find($organization);
            $orgUuid = $orgModel?->uuid;
            $orgId = $orgModel?->id;
        } elseif (is_string($organization)) {
            $orgModel = Organization::where('uuid', $organization)->first();
            $orgUuid = $organization;
            $orgId = $orgModel?->id;
        }

        $settings = self::getOrgTaxSettings($orgUuid, $orgId);
        $normProvince = self::normalizeProvince($province);

        // Calculate Subtotal first
        $subtotal = 0.0;
        foreach ($items as $item) {
            $amount = (float)($item['amount'] ?? $item->amount ?? 0);
            $subtotal += $amount;
        }
        $subtotal = round($subtotal, 2);

        // 1. Fallback: If organization has NEVER configured custom tax settings, use legacy Canada logic
        if (empty($settings['is_configured'])) {
            return self::calculateLegacyCanadianTaxes($subtotal, $items, $normProvince, $orgId);
        }

        // 2. If tax is explicitly disabled by the organization
        if (empty($settings['is_enabled'])) {
            return [
                'subtotal' => $subtotal,
                'total_tax_amount' => 0.0,
                'effective_tax_rate' => 0.0,
                'tax_details' => [],
                'tax_numbers' => '',
                'total' => $subtotal,
                'calculation_basis' => $settings['calculation_basis'] ?? 'origin',
                'jurisdiction' => 'Exempt / Disabled',
                'items' => self::formatZeroTaxItems($items),
            ];
        }

        // 3. Resolve active taxes based on calculation basis
        $basis = $settings['calculation_basis'] ?? 'origin';
        $applicableTaxes = [];
        $jurisdiction = '';

        if ($basis === 'origin') {
            $jurisdiction = 'Origin: ' . ($orgModel?->province ?: 'HQ');
            foreach ($settings['origin_taxes'] ?? [] as $tax) {
                if (($tax['is_enabled'] ?? true) && !empty($tax['name'])) {
                    $applicableTaxes[] = $tax;
                }
            }
        } else {
            // Destination-based
            $jurisdiction = 'Destination: ' . ($normProvince ?: 'Unspecified');
            $matchedRule = null;

            foreach ($settings['destination_rules'] ?? [] as $rule) {
                $ruleProvince = self::normalizeProvince($rule['state_province'] ?? '');
                if ($ruleProvince === $normProvince && $ruleProvince !== '') {
                    $matchedRule = $rule;
                    break;
                }
            }

            if ($matchedRule) {
                foreach ($matchedRule['taxes'] ?? [] as $tax) {
                    if (($tax['is_enabled'] ?? true) && !empty($tax['name'])) {
                        $applicableTaxes[] = $tax;
                    }
                }
            } else {
                // Destination unmatched -> check policy
                $policy = $settings['unmatched_destination_policy'] ?? 'fallback_rate';
                if ($policy === 'fallback_rate') {
                    $fallback = $settings['fallback_tax'] ?? null;
                    if ($fallback && !empty($fallback['name']) && (float)($fallback['rate'] ?? 0) > 0) {
                        $applicableTaxes[] = $fallback;
                    }
                }
            }
        }

        // 4. Compute taxes per line item
        $taxTally = [];
        $taxNumbers = [];
        $calculatedItems = [];
        $totalTaxAmount = 0.0;

        foreach ($applicableTaxes as $tax) {
            $name = $tax['name'];
            $rate = (float)($tax['rate'] ?? 0.0);
            $regNo = trim($tax['registration_number'] ?? '');
            $taxTally[$name] = [
                'rate' => $rate,
                'amount' => 0.0,
                'registration_number' => $regNo,
            ];
            if ($regNo !== '') {
                $taxNumbers[$name] = "{$name}: {$regNo}";
            }
        }

        foreach ($items as $idx => $item) {
            $amount = (float)($item['amount'] ?? $item->amount ?? 0);
            $isTaxable = self::isItemTaxable($item, $orgId);

            $itemTaxTotal = 0.0;
            $itemTaxBreakdown = [];

            if ($isTaxable) {
                foreach ($applicableTaxes as $tax) {
                    $name = $tax['name'];
                    $rate = (float)($tax['rate'] ?? 0.0);
                    $itemTax = round($amount * ($rate / 100), 2);
                    $taxTally[$name]['amount'] += $itemTax;
                    $itemTaxTotal += $itemTax;
                    $itemTaxBreakdown[$name] = $itemTax;
                }
            }

            $totalTaxAmount += $itemTaxTotal;

            $calculatedItems[] = [
                'index' => $idx,
                'amount' => $amount,
                'is_taxable' => $isTaxable,
                'tax_amount' => round($itemTaxTotal, 2),
                'tax_breakdown' => $itemTaxBreakdown,
            ];
        }

        $totalTaxAmount = round($totalTaxAmount, 2);
        $effectiveTaxRate = $subtotal > 0 ? round(($totalTaxAmount / $subtotal) * 100, 2) : 0.0;

        // Clean tax details for final output
        $taxDetails = [];
        foreach ($taxTally as $name => $data) {
            $taxDetails[$name] = [
                'rate' => $data['rate'],
                'amount' => round($data['amount'], 2),
                'registration_number' => $data['registration_number'],
            ];
        }

        return [
            'subtotal' => $subtotal,
            'total_tax_amount' => $totalTaxAmount,
            'effective_tax_rate' => $effectiveTaxRate,
            'tax_details' => $taxDetails,
            'tax_numbers' => implode(', ', array_values($taxNumbers)),
            'total' => round($subtotal + $totalTaxAmount, 2),
            'calculation_basis' => $basis,
            'jurisdiction' => $jurisdiction,
            'items' => $calculatedItems,
        ];
    }

    /**
     * Determine if a given line item is taxable.
     */
    protected static function isItemTaxable($item, ?int $orgId = null): bool
    {
        // 1. Explicit boolean flag passed on the item
        if (isset($item['is_taxable'])) {
            return (bool)$item['is_taxable'];
        }
        if (is_object($item) && isset($item->is_taxable)) {
            return (bool)$item->is_taxable;
        }

        // 2. Check service model if present
        $service = null;
        if (isset($item['service'])) {
            $service = $item['service'];
        } elseif (is_object($item) && isset($item->service)) {
            $service = $item->service;
        }

        if ($service) {
            [$gst, $pst] = InvoiceController::resolveServiceTaxFlags($service, $orgId);
            return $gst || $pst;
        }

        // Default to taxable
        return true;
    }

    /**
     * Legacy Canadian Tax calculation fallback to ensure 100% backward compatibility.
     */
    protected static function calculateLegacyCanadianTaxes(float $subtotal, $items, string $province, ?int $orgId = null): array
    {
        $totalGst = 0.0;
        $totalPst = 0.0;
        $totalHst = 0.0;
        $totalTax = 0.0;
        $calculatedItems = [];

        foreach ($items as $idx => $item) {
            $amount = (float)($item['amount'] ?? $item->amount ?? 0);
            $service = $item['service'] ?? (is_object($item) ? ($item->service ?? null) : null);
            [$gstEnabled, $pstEnabled] = InvoiceController::resolveServiceTaxFlags($service, $orgId);

            $taxCalc = InvoiceController::calculateLineItemTax($province, $amount, $gstEnabled, $pstEnabled);

            $totalGst += $taxCalc['gst_amount'];
            $totalPst += $taxCalc['pst_amount'];
            $totalHst += $taxCalc['hst_amount'];
            $totalTax += $taxCalc['total_tax_amount'];

            $calculatedItems[] = [
                'index' => $idx,
                'amount' => $amount,
                'is_taxable' => ($gstEnabled || $pstEnabled),
                'tax_amount' => $taxCalc['total_tax_amount'],
                'gst_amount' => $taxCalc['gst_amount'],
                'pst_amount' => $taxCalc['pst_amount'],
                'hst_amount' => $taxCalc['hst_amount'],
            ];
        }

        $taxDetails = [];
        if ($totalHst > 0) {
            $taxDetails['HST'] = ['rate' => 13.0, 'amount' => round($totalHst, 2), 'registration_number' => ''];
        }
        if ($totalGst > 0) {
            $taxDetails['GST'] = ['rate' => 5.0, 'amount' => round($totalGst, 2), 'registration_number' => ''];
        }
        if ($totalPst > 0) {
            $taxDetails['PST'] = ['rate' => 7.0, 'amount' => round($totalPst, 2), 'registration_number' => ''];
        }
        if (empty($taxDetails)) {
            $canadaDefault = (new InvoiceController)->getCanadaTaxRate($province);
            $taxDetails[$canadaDefault['name'] ?? 'GST'] = [
                'rate' => $canadaDefault['rate'] ?? 0.0,
                'amount' => 0.0,
                'registration_number' => ''
            ];
        }

        $totalTax = round($totalTax, 2);
        $effectiveRate = $subtotal > 0 ? round(($totalTax / $subtotal) * 100, 2) : 0.0;

        return [
            'subtotal' => $subtotal,
            'total_tax_amount' => $totalTax,
            'effective_tax_rate' => $effectiveRate,
            'tax_details' => $taxDetails,
            'tax_numbers' => '',
            'total' => round($subtotal + $totalTax, 2),
            'calculation_basis' => 'destination',
            'jurisdiction' => 'Legacy Canadian (' . ($province ?: 'ON') . ')',
            'items' => $calculatedItems,
        ];
    }

    /**
     * Helper for zero-tax line items.
     */
    protected static function formatZeroTaxItems($items): array
    {
        $res = [];
        foreach ($items as $idx => $item) {
            $res[] = [
                'index' => $idx,
                'amount' => (float)($item['amount'] ?? $item->amount ?? 0),
                'is_taxable' => false,
                'tax_amount' => 0.0,
                'tax_breakdown' => [],
            ];
        }
        return $res;
    }
}
