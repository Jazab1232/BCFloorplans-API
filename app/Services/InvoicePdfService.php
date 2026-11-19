<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\VendorInvoice;
use App\Models\Organization;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class InvoicePdfService
{
    /**
     * Generate the DomPDF instance for the invoice.
     *
     * @param Invoice|VendorInvoice|mixed $invoice
     * @param array $options
     * @return \Barryvdh\DomPDF\PDF
     */
    public function generate($invoice, array $options = [])
    {
        // Support VendorInvoice as well as standard Invoice
        if ($invoice instanceof VendorInvoice) {
            $invoice->loadMissing([
                'organization',
                'vendor.organization',
                'lines.orderService.service',
                'lines.orderService.order.property',
            ]);

            if (empty($invoice->items)) {
                $invoice->items = $invoice->lines->map(function ($line) {
                    $desc = $line->description;
                    if (empty($desc) && $line->orderService?->service) {
                        $desc = $line->orderService->service->name;
                        if ($line->orderService->order?->property) {
                            $p = $line->orderService->order->property;
                            $desc .= ' - ' . implode(', ', array_filter([$p->address, $p->city]));
                        }
                    }
                    return (object)[
                        'description'  => $desc ?: 'Service Item',
                        'quantity'     => $line->quantity ?? 1,
                        'unit_price'   => $line->unit_price ?? $line->amount ?? 0,
                        'amount'       => $line->amount ?? 0,
                        'orderService' => $line->orderService,
                        'item_status'  => null,
                        'type'         => $line->type ?? 'service',
                    ];
                });
            }

            if (!isset($invoice->payments)) {
                $invoice->payments = collect();
            }
        } else {
            // Ensure relationships are loaded for standard Invoice
            $invoice->loadMissing([
                'order.property',
                'agent',
                'items.orderService.service',
                'items.orderService.option',
                'payments',
                'organization',
            ]);
        }

        $branding = $this->resolveBranding($invoice);
        $taxNumbers = $this->resolveTaxNumbers($invoice);
        $financials = $this->calculateFinancials($invoice);
        $client = $this->resolveClientDetails($invoice);
        $splitParticipants = $this->resolveSplitParticipants($invoice);
        $travelAmount = $this->resolveTravelAmount($invoice);

        $pdf = Pdf::loadView('invoices.pdf', [
            'invoice'           => $invoice,
            'branding'          => $branding,
            'taxNumbers'        => $taxNumbers,
            'financials'        => $financials,
            'client'            => $client,
            'splitParticipants' => $splitParticipants,
            'travelAmount'      => $travelAmount,
        ])
        ->setPaper('a4', 'portrait')
        ->setOptions([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled'      => true,
            'defaultFont'          => 'Helvetica',
            'dpi'                  => 120,
        ]);

        return $pdf;
    }

    /**
     * Get the raw PDF binary content.
     *
     * @param Invoice|VendorInvoice|mixed $invoice
     * @param array $options
     * @return string
     */
    public function getPdfBytes($invoice, array $options = []): string
    {
        return $this->generate($invoice, $options)->output();
    }

    /**
     * Build a suggested file name for the downloaded or attached invoice.
     *
     * @param Invoice|VendorInvoice|mixed $invoice
     * @return string
     */
    public function getFileName($invoice): string
    {
        $num = $invoice->invoice_number ?: ('INV-' . $invoice->id);
        $cleanNum = preg_replace('/[^A-Za-z0-9_\-]/', '_', $num);
        return "Invoice_{$cleanNum}.pdf";
    }

    /**
     * Resolve organization white-label branding, logo, and contact info.
     */
    protected function resolveBranding($invoice): array
    {
        $org = $invoice->organization ?? ($invoice->organization_id ? Organization::find($invoice->organization_id) : null);
        $orgDetails = $invoice->org_details ?? [];
        if (is_string($orgDetails)) {
            $orgDetails = json_decode($orgDetails, true) ?: [];
        }

        $orgName = $orgDetails['name'] ?? $org?->name ?? 'BC Floor plans';
        $orgEmail = $orgDetails['email'] ?? $org?->contact_email ?? $org?->from_email ?? 'info@bcfloorplans.com';
        $orgPhone = $orgDetails['phone'] ?? $org?->contact_phone ?? '';

        $addressParts = array_filter([
            $orgDetails['address'] ?? null,
            $org?->address_line_1,
            $org?->address_line_2,
            $org?->city,
            $org?->province,
            $org?->postal_code,
            $org?->country,
        ]);
        $orgAddress = !empty($orgDetails['address']) ? $orgDetails['address'] : implode(', ', $addressParts);

        // Fetch settings for white-label colors
        $primaryColor = '#1F2937';
        $tabColor = '#111827';
        $accentColor = '#6BAE41';

        if ($org) {
            $setting = Setting::where('org_id', $org->uuid)
                ->where('key', 'branding')
                ->first();
            if ($setting) {
                $val = is_string($setting->value) ? json_decode($setting->value, true) : $setting->value;
                if (is_array($val)) {
                    $primaryColor = $val['primary_color'] ?? $primaryColor;
                    $accentColor = $val['secondary_color'] ?? $accentColor;
                }
            }

            $wlsSetting = Setting::where('org_id', $org->uuid)
                ->where('key', 'white_label_styles')
                ->first();
            if ($wlsSetting) {
                $wlsVal = is_string($wlsSetting->value) ? json_decode($wlsSetting->value, true) : $wlsSetting->value;
                if (is_array($wlsVal)) {
                    $tabColor = $wlsVal['admin']['pageTabColor'] ?? $wlsVal['pageTabColor'] ?? $tabColor;
                }
            }
        }

        $logoDataUri = $this->resolveLogoDataUri($org);
        $mailIcon = $this->generateMailIcon($tabColor);
        $pinIcon = $this->generatePinIcon($tabColor);

        return [
            'name'              => $orgName,
            'email'             => $orgEmail,
            'phone'             => $orgPhone,
            'address'           => $orgAddress,
            'primary_color'     => $primaryColor,
            'tab_color'         => $tabColor,
            'accent_color'      => $accentColor,
            'logo_base64'       => $logoDataUri,
            'mail_icon_base64'  => $mailIcon,
            'pin_icon_base64'   => $pinIcon,
        ];
    }

    /**
     * Converts the organization logo to a base64 data URI to avoid external network calls during PDF rendering.
     */
    protected function resolveLogoDataUri(?Organization $org): ?string
    {
        try {
            // Check organization custom logo in company_logos
            if ($org && !empty($org->company_logos) && is_array($org->company_logos)) {
                $logoPath = null;
                foreach ($org->company_logos as $logo) {
                    if (is_array($logo) && !empty($logo['path'])) {
                        $logoPath = $logo['path'];
                        if (($logo['type'] ?? '') === 'primary_logo') {
                            break;
                        }
                    }
                }

                if ($logoPath && Storage::disk('s3')->exists($logoPath)) {
                    $content = Storage::disk('s3')->get($logoPath);
                    $mime = 'image/png';
                    if (str_ends_with(strtolower($logoPath), '.jpg') || str_ends_with(strtolower($logoPath), '.jpeg')) {
                        $mime = 'image/jpeg';
                    } elseif (str_ends_with(strtolower($logoPath), '.svg')) {
                        $mime = 'image/svg+xml';
                    }
                    return 'data:' . $mime . ';base64,' . base64_encode($content);
                }
            }

            // Fallback to local bcfloor.png
            $localLogo = public_path('bcfloor.png');
            if (file_exists($localLogo)) {
                $content = file_get_contents($localLogo);
                return 'data:image/png;base64,' . base64_encode($content);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to resolve logo for invoice PDF: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Generate base64 PNG icon for mail envelope in brand color.
     */
    protected function generateMailIcon(string $colorHex = '#6BAE41', int $size = 28): ?string
    {
        try {
            if (!extension_loaded('gd')) return null;
            $img = imagecreatetruecolor($size, $size);
            imagesavealpha($img, true);
            $trans = imagecolorallocatealpha($img, 0, 0, 0, 127);
            imagefill($img, 0, 0, $trans);

            $hex = ltrim($colorHex, '#');
            if (strlen($hex) == 3) {
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            }
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            $col = imagecolorallocate($img, $r, $g, $b);

            imagesetthickness($img, 2);
            imagerectangle($img, 2, 5, 25, 22, $col);
            imageline($img, 2, 5, 14, 14, $col);
            imageline($img, 25, 5, 14, 14, $col);

            ob_start();
            imagepng($img);
            $data = ob_get_clean();
            imagedestroy($img);
            return 'data:image/png;base64,' . base64_encode($data);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Generate base64 PNG icon for map pin in brand color.
     */
    protected function generatePinIcon(string $colorHex = '#6BAE41', int $size = 28): ?string
    {
        try {
            if (!extension_loaded('gd')) return null;
            $img = imagecreatetruecolor($size, $size);
            imagesavealpha($img, true);
            $trans = imagecolorallocatealpha($img, 0, 0, 0, 127);
            imagefill($img, 0, 0, $trans);

            $hex = ltrim($colorHex, '#');
            if (strlen($hex) == 3) {
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            }
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            $col = imagecolorallocate($img, $r, $g, $b);

            imagesetthickness($img, 2);
            imagearc($img, 14, 9, 14, 14, 0, 360, $col);
            imagefilledellipse($img, 14, 9, 4, 4, $col);
            imageline($img, 8, 12, 14, 25, $col);
            imageline($img, 20, 12, 14, 25, $col);

            ob_start();
            imagepng($img);
            $data = ob_get_clean();
            imagedestroy($img);
            return 'data:image/png;base64,' . base64_encode($data);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resolves tax registration numbers (GST/HST/PST) for display on the invoice.
     */
    protected function resolveTaxNumbers($invoice): array
    {
        $taxNumbers = [];

        try {
            $orgTaxSettings = TaxCalculationService::getOrgTaxSettings(
                $invoice->organization?->uuid,
                $invoice->organization_id
            );

            if (!empty($orgTaxSettings['origin_taxes'])) {
                foreach ($orgTaxSettings['origin_taxes'] as $tax) {
                    if (!empty($tax['registration_number'])) {
                        $name = $tax['name'] ?? 'Tax';
                        $taxNumbers[$name] = $tax['registration_number'];
                    }
                }
            }

            if (!empty($orgTaxSettings['fallback_tax']['registration_number'])) {
                $name = $orgTaxSettings['fallback_tax']['name'] ?? 'Tax';
                if (!isset($taxNumbers[$name])) {
                    $taxNumbers[$name] = $orgTaxSettings['fallback_tax']['registration_number'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed resolving tax numbers for invoice PDF: ' . $e->getMessage());
        }

        return $taxNumbers;
    }

    /**
     * Resolve recipient / Bill To details (Agent or Vendor).
     */
    protected function resolveClientDetails($invoice): array
    {
        $isVendor = ($invoice instanceof VendorInvoice) || !empty($invoice->vendor_id) || !empty($invoice->vendor);
        if ($isVendor) {
            $vendor = $invoice->vendor;
            $vendorDetails = $invoice->vendor_details ?? [];
            if (is_string($vendorDetails)) {
                $vendorDetails = json_decode($vendorDetails, true) ?: [];
            }

            $vendorName = $vendorDetails['name'] ?? (trim(($vendor?->first_name ?? '') . ' ' . ($vendor?->last_name ?? '')) ?: 'Vendor');
            $vendorCompany = $vendorDetails['company_name'] ?? $vendor?->company_name ?? '';
            $vendorEmail = $vendorDetails['email'] ?? $vendor?->email ?? '';
            $vendorPhone = $vendorDetails['phone'] ?? $vendor?->primary_phone ?? $vendor?->phone ?? '';
            $vendorAddress = $vendorDetails['address'] ?? ($vendor?->addresses[0]->address_line_1 ?? '');
            $taxNum = $invoice->tax_number ?? $vendorDetails['tax_number'] ?? $vendor?->tax_number ?? '';

            return [
                'is_vendor'        => true,
                'name'             => $vendorName,
                'company'          => $vendorCompany,
                'email'            => $vendorEmail,
                'phone'            => $vendorPhone,
                'address'          => $vendorAddress,
                'tax_number'       => $taxNum,
                'property_address' => '',
                'agent_type'       => null,
            ];
        }

        $agent = $invoice->agent;
        $agentName = $agent ? trim(($agent->first_name ?? '') . ' ' . ($agent->last_name ?? '')) : 'Valued Customer';
        $property = $invoice->order?->property;
        $propAddress = null;
        if ($property) {
            $propAddress = implode(', ', array_filter([
                $property->address,
                $property->city,
                $property->province,
            ]));
        } elseif (!empty($invoice->order?->property_address)) {
            $propAddress = $invoice->order->property_address;
        }

        return [
            'is_vendor'        => false,
            'name'             => $agentName,
            'company'          => $agent?->brokerage ?? $agent?->company_name ?? '',
            'email'            => $agent?->email ?? '',
            'phone'            => $agent?->phone ?? $agent?->mobile_number ?? '',
            'address'          => '',
            'tax_number'       => '',
            'property_address' => $propAddress ?? '',
            'agent_type'       => $invoice->agent_type,
        ];
    }

    /**
     * Resolves split invoice distribution matching frontend InvoiceDocument.tsx.
     */
    protected function resolveSplitParticipants($invoice): array
    {
        $grandTotal = (float)($invoice->total ?? $invoice->total_amount ?? 0);
        $splitDetails = $invoice->split_details ?? null;
        if (is_string($splitDetails)) {
            $splitDetails = json_decode($splitDetails, true);
        }

        $currentAgentId = $invoice->agent_id ?? $invoice->agent?->id;
        $currentAgentUuid = $invoice->agent?->uuid ?? $invoice->agent_uuid ?? null;
        $currentAgentEmail = strtolower($invoice->agent?->email ?? $invoice->agent_email ?? '');

        if (!empty($splitDetails['splits']) && is_array($splitDetails['splits'])) {
            $currentAllocation = null;
            foreach ($splitDetails['splits'] as $s) {
                $matchId = $currentAgentId !== null && isset($s['agent_id']) && (string)$s['agent_id'] === (string)$currentAgentId;
                $matchUuid = $currentAgentUuid && isset($s['agent_uuid']) && $s['agent_uuid'] === $currentAgentUuid;
                $matchEmail = $currentAgentEmail && isset($s['email']) && strtolower($s['email']) === $currentAgentEmail;
                if ($matchId || $matchUuid || $matchEmail) {
                    $currentAllocation = $s;
                    break;
                }
            }

            if ($currentAllocation) {
                $pct = (float)($currentAllocation['percentage'] ?? $currentAllocation['split'] ?? 0);
                return [[
                    'name'       => $currentAllocation['name'] ?? trim(($currentAllocation['first_name'] ?? '') . ' ' . ($currentAllocation['last_name'] ?? '')) ?: 'Primary Agent',
                    'email'      => $currentAllocation['email'] ?? '',
                    'role'       => ($currentAllocation['type'] ?? '') === 'primary' ? 'Primary Agent' : 'Co-Agent',
                    'percentage' => $pct,
                    'amount'     => $grandTotal,
                ]];
            }
        }

        // Check co_agents on order
        $rawCo = $invoice->order?->co_agents ?? $invoice->co_agents ?? null;
        if (is_string($rawCo)) {
            $rawCo = json_decode($rawCo, true);
        }

        if (is_array($rawCo) && count($rawCo) > 0) {
            $totalCoSplit = 0;
            foreach ($rawCo as $ca) {
                $totalCoSplit += (float)($ca['split'] ?? $ca['split_percentage'] ?? $ca['percentage'] ?? 0);
            }
            $primarySplit = max(0, 100 - $totalCoSplit);

            $participants = [];
            $primaryName = $invoice->agent ? trim(($invoice->agent->first_name ?? '') . ' ' . ($invoice->agent->last_name ?? '')) : 'Primary Agent';
            $participants[] = [
                'name'       => $primaryName ?: 'Primary Agent',
                'email'      => $invoice->agent?->email ?? '',
                'role'       => 'Primary Agent',
                'percentage' => $primarySplit,
                'agent_id'   => $currentAgentId,
                'agent_uuid' => $currentAgentUuid,
            ];

            foreach ($rawCo as $ca) {
                $pct = (float)($ca['split'] ?? $ca['split_percentage'] ?? $ca['percentage'] ?? 0);
                $caName = $ca['name'] ?? trim(($ca['first_name'] ?? '') . ' ' . ($ca['last_name'] ?? '')) ?: 'Co-Agent';
                $participants[] = [
                    'name'       => $caName,
                    'email'      => $ca['email'] ?? '',
                    'role'       => 'Co-Agent',
                    'percentage' => $pct,
                    'agent_id'   => $ca['agent_id'] ?? $ca['id'] ?? null,
                    'agent_uuid' => $ca['agent_uuid'] ?? $ca['uuid'] ?? null,
                ];
            }

            foreach ($participants as $p) {
                $matchId = $currentAgentId !== null && isset($p['agent_id']) && (string)$p['agent_id'] === (string)$currentAgentId;
                $matchUuid = $currentAgentUuid && isset($p['agent_uuid']) && $p['agent_uuid'] === $currentAgentUuid;
                $matchEmail = $currentAgentEmail && isset($p['email']) && strtolower($p['email']) === $currentAgentEmail;
                if ($matchId || $matchUuid || $matchEmail) {
                    return [[
                        'name'       => $p['name'],
                        'email'      => $p['email'],
                        'role'       => $p['role'],
                        'percentage' => $p['percentage'],
                        'amount'     => $grandTotal,
                    ]];
                }
            }
        }

        return [];
    }

    /**
     * Resolve travel amount if recorded on the invoice.
     */
    protected function resolveTravelAmount($invoice): float
    {
        if (isset($invoice->travel_amount) && (float)$invoice->travel_amount > 0) {
            return (float)$invoice->travel_amount;
        }

        if (!empty($invoice->items)) {
            foreach ($invoice->items as $item) {
                if (($item->type ?? '') === 'travel') {
                    return (float)($item->amount ?? 0);
                }
            }
        }

        return 0.0;
    }

    /**
     * Accurately calculate financials, balance due, and tax breakdowns.
     */
    protected function calculateFinancials($invoice): array
    {
        $subtotal = (float)($invoice->subtotal ?? 0);
        $total = (float)($invoice->total ?? $invoice->total_amount ?? 0);
        $paidAmount = (float)($invoice->paid_amount ?? 0);
        $refundedAmount = (float)($invoice->refunded_amount ?? 0);

        // For VendorInvoice, if status is 'paid' and paid_amount is 0, paid_amount is total
        if (($invoice->status ?? '') === 'paid' && $paidAmount == 0) {
            $paidAmount = $total;
        }

        $netPaid = max(0, $paidAmount - $refundedAmount);
        $balanceDue = max(0, $total - $netPaid);

        // Parse tax details if available
        $taxBreakdown = [];
        $taxDetails = $invoice->tax_details ?? null;
        if (is_string($taxDetails)) {
            $taxDetails = json_decode($taxDetails, true);
        }

        if (!empty($taxDetails) && is_array($taxDetails)) {
            foreach ($taxDetails as $key => $val) {
                if (is_array($val)) {
                    $rateStr = isset($val['rate']) ? ' (' . $val['rate'] . '%)' : '';
                    $label = strtoupper($key) . $rateStr;
                    $taxBreakdown[$label] = (float)($val['amount'] ?? 0);
                } elseif (is_numeric($val) && (float)$val > 0) {
                    $taxBreakdown[strtoupper($key)] = (float)$val;
                }
            }
        } elseif ((float)($invoice->tax_amount ?? 0) > 0) {
            $label = 'TAX' . ($invoice->tax_rate ? (' (' . round((float)$invoice->tax_rate, 2) . '%)') : '');
            $taxBreakdown[$label] = (float)$invoice->tax_amount;
        }

        return [
            'subtotal'        => $subtotal,
            'total'           => $total,
            'paid_amount'     => $paidAmount,
            'refunded_amount' => $refundedAmount,
            'net_paid'        => $netPaid,
            'balance_due'     => $balanceDue,
            'tax_breakdown'   => $taxBreakdown,
        ];
    }
}
