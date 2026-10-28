<?php

namespace App\Services;

use App\Models\Invoice;
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
     * @param Invoice $invoice
     * @param array $options
     * @return \Barryvdh\DomPDF\PDF
     */
    public function generate(Invoice $invoice, array $options = [])
    {
        // Ensure relationships are loaded
        $invoice->loadMissing([
            'order.property',
            'agent',
            'items.orderService.service',
            'payments',
            'organization',
        ]);

        $branding = $this->resolveBranding($invoice);
        $taxNumbers = $this->resolveTaxNumbers($invoice);
        $financials = $this->calculateFinancials($invoice);

        $pdf = Pdf::loadView('invoices.pdf', [
            'invoice'    => $invoice,
            'branding'   => $branding,
            'taxNumbers' => $taxNumbers,
            'financials' => $financials,
        ])
        ->setPaper('a4', 'portrait')
        ->setOptions([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled'      => true,
            'defaultFont'          => 'sans-serif',
            'dpi'                  => 120,
        ]);

        return $pdf;
    }

    /**
     * Get the raw PDF binary content.
     *
     * @param Invoice $invoice
     * @param array $options
     * @return string
     */
    public function getPdfBytes(Invoice $invoice, array $options = []): string
    {
        return $this->generate($invoice, $options)->output();
    }

    /**
     * Build a suggested file name for the downloaded or attached invoice.
     *
     * @param Invoice $invoice
     * @return string
     */
    public function getFileName(Invoice $invoice): string
    {
        $num = $invoice->invoice_number ?: ('INV-' . $invoice->id);
        // Replace slashes or invalid filename characters
        $cleanNum = preg_replace('/[^A-Za-z0-9_\-]/', '_', $num);
        return "Invoice_{$cleanNum}.pdf";
    }

    /**
     * Resolve organization white-label branding, logo, and contact info.
     */
    protected function resolveBranding(Invoice $invoice): array
    {
        $org = $invoice->organization ?? ($invoice->organization_id ? Organization::find($invoice->organization_id) : null);
        $orgName = $org?->name ?? 'BC Floor plans';
        $orgEmail = $org?->contact_email ?? $org?->from_email ?? 'info@bcfloorplans.com';
        $orgPhone = $org?->contact_phone ?? '';

        $addressParts = array_filter([
            $org?->address_line_1,
            $org?->address_line_2,
            $org?->city,
            $org?->province,
            $org?->postal_code,
            $org?->country,
        ]);
        $orgAddress = implode(', ', $addressParts);

        // Fetch settings for white-label colors
        $primaryColor = '#1F2937'; // Default dark neutral
        $tabColor = '#111827';
        $accentColor = '#6BAE41'; // BCF default green

        if ($org) {
            $setting = Setting::where('org_id', $org->uuid)
                ->where('key', 'branding')
                ->first();
            if ($setting && is_array($setting->value)) {
                $primaryColor = $setting->value['primary_color'] ?? $primaryColor;
                $accentColor = $setting->value['secondary_color'] ?? $accentColor;
            }

            $wlsSetting = Setting::where('org_id', $org->uuid)
                ->where('key', 'white_label_styles')
                ->first();
            if ($wlsSetting && is_array($wlsSetting->value)) {
                $tabColor = $wlsSetting->value['admin']['pageTabColor'] ?? $wlsSetting->value['pageTabColor'] ?? $primaryColor;
            }
        }

        $logoDataUri = $this->resolveLogoDataUri($org);

        return [
            'name'          => $orgName,
            'email'         => $orgEmail,
            'phone'         => $orgPhone,
            'address'       => $orgAddress,
            'primary_color' => $primaryColor,
            'tab_color'     => $tabColor,
            'accent_color'  => $accentColor,
            'logo_base64'   => $logoDataUri,
        ];
    }

    /**
     * Converts the organization logo to a base64 data URI to avoid external network calls during PDF rendering.
     */
    protected function resolveLogoDataUri(?Organization $org): ?string
    {
        try {
            // Check organization custom logo
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
     * Resolves tax registration numbers (GST/HST/PST) for display on the invoice.
     */
    protected function resolveTaxNumbers(Invoice $invoice): array
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
     * Accurately calculate financials, balance due, and tax breakdowns.
     */
    protected function calculateFinancials(Invoice $invoice): array
    {
        $subtotal = (float)($invoice->subtotal ?? 0);
        $total = (float)($invoice->total ?? 0);
        $paidAmount = (float)($invoice->paid_amount ?? 0);
        $refundedAmount = (float)($invoice->refunded_amount ?? 0);
        $netPaid = max(0, $paidAmount - $refundedAmount);
        $balanceDue = max(0, $total - $netPaid);

        // Parse tax details if available
        $taxBreakdown = [];
        if (!empty($invoice->tax_details) && is_array($invoice->tax_details)) {
            foreach ($invoice->tax_details as $key => $val) {
                if (is_numeric($val) && (float)$val > 0) {
                    $taxBreakdown[strtoupper($key)] = (float)$val;
                }
            }
        } elseif ((float)($invoice->tax_amount ?? 0) > 0) {
            $label = 'TAX' . ($invoice->tax_rate ? (' (' . round((float)$invoice->tax_rate, 2) . '%)') : '');
            $taxBreakdown[$label] = (float)$invoice->tax_amount;
        }

        return [
            'subtotal'       => $subtotal,
            'total'          => $total,
            'paid_amount'    => $paidAmount,
            'refunded_amount'=> $refundedAmount,
            'net_paid'       => $netPaid,
            'balance_due'    => $balanceDue,
            'tax_breakdown'  => $taxBreakdown,
        ];
    }
}
