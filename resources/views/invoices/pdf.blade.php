<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice #{{ $invoice->invoice_number ?: $invoice->id }}</title>
    <style>
        @page {
            margin: 32px 36px 32px 36px;
            size: a4 portrait;
        }

        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #1F2937;
            font-size: 12px;
            line-height: 1.4;
            margin: 0;
            padding: 0;
            background-color: #FFFFFF;
        }

        /* Utilities */
        .w-100 { width: 100%; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .text-center { text-align: center; }
        .font-bold { font-weight: bold; }
        .uppercase { text-transform: uppercase; }
        .text-muted { color: #6B7280; }
        .text-dark { color: #111827; }

        /* Top Header */
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0px;
        }

        .header-table td {
            vertical-align: top;
            padding: 0;
        }

        .logo-img {
            max-height: 48px;
            max-width: 160px;
            object-fit: contain;
            vertical-align: middle;
        }

        .company-name {
            font-size: 22px;
            font-weight: 800;
            color: #111827;
            letter-spacing: -0.5px;
            vertical-align: middle;
            margin-left: 8px;
            display: inline-block;
        }

        .meta-list {
            margin-top: 10px;
        }

        .meta-list p {
            margin: 3px 0;
            font-size: 12px;
            color: #4B5563;
        }

        .meta-list p span {
            color: #111827;
            font-weight: 700;
        }

        /* BCF Signature 3-Tier Tab: Thin Bar (4px), Gap (6px), Medium Bar (12px), Gap (6px), Main Box */
        .tab-table {
            border-collapse: separate;
            border-spacing: 5px 0;
            float: right;
            margin-top: 2px;
        }

        .tab-table td {
            padding: 0;
            vertical-align: top;
        }

        .tab-stripe-1 {
            width: 4px;
            height: 52px;
            background-color: {{ $branding['tab_color'] ?? '#6BAE41' }};
        }

        .tab-stripe-2 {
            width: 12px;
            height: 52px;
            background-color: {{ $branding['tab_color'] ?? '#6BAE41' }};
        }

        .tab-stripe-main {
            height: 52px;
            background-color: {{ $branding['tab_color'] ?? '#6BAE41' }};
            padding: 0 32px !important;
            text-align: center;
            vertical-align: middle !important;
        }

        .tab-stripe-main span {
            color: #FFFFFF;
            font-size: 26px;
            font-weight: 800;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            line-height: 52px;
            display: inline-block;
        }

        /* Status Badge Pill matching frontend */
        .status-pill {
            display: inline-block;
            padding: 3px 10px;
            font-size: 9px;
            font-weight: 800;
            border-radius: 9999px;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            margin-top: 6px;
            line-height: 1.2;
        }

        @php
            $st = strtolower($invoice->status ?? 'unpaid');
            if ($st === 'paid') {
                $statusBg = '#DCFCE7';
                $statusColor = '#15803D';
            } elseif ($st === 'partially_paid' || $st === 'partial') {
                $statusBg = '#FEF9C3';
                $statusColor = '#A16207';
            } elseif ($st === 'draft') {
                $statusBg = '#FFEDD5';
                $statusColor = '#EA580C';
            } elseif ($st === 'refunded') {
                $statusBg = '#FEE2E2';
                $statusColor = '#DC2626';
            } elseif ($st === 'partially_refunded' || $st === 'partial_refunded') {
                $statusBg = '#FFFBEB';
                $statusColor = '#B45309';
            } elseif ($st === 'void') {
                $statusBg = '#F3F4F6';
                $statusColor = '#9CA3AF';
            } else {
                $statusBg = '#DBEAFE';
                $statusColor = '#1D4ED8';
            }
        @endphp

        .status-badge {
            background-color: {{ $statusBg }};
            color: {{ $statusColor }};
        }

        /* Full Width Divider */
        .accent-divider {
            height: 1px;
            background-color: {{ $branding['tab_color'] ?? '#6BAE41' }};
            opacity: 0.25;
            margin-top: 18px;
            margin-bottom: 22px;
        }

        /* Dual Cards: Bill From & Bill To */
        .cards-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 16px 0;
            margin-left: -16px;
            margin-right: -16px;
            margin-bottom: 24px;
        }

        .cards-table td {
            width: 50%;
            vertical-align: top;
            padding: 0;
        }

        .info-card {
            background-color: #FAFAFA;
            border: 1px solid #F3F4F6;
            border-radius: 8px;
            padding: 12px 16px;
        }

        .card-header {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: {{ $branding['tab_color'] ?? '#6BAE41' }};
            margin-bottom: 6px;
        }

        .card-title {
            font-size: 13px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 3px;
        }

        .card-row {
            font-size: 12px;
            color: #4B5563;
            line-height: 1.6;
        }

        /* Line Items Table (Matches Frontend 3-Column View Mode: Item Description, Quantity, Amount) */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }

        .items-table th {
            border-top: 2px solid {{ $branding['tab_color'] ?? '#6BAE41' }};
            border-bottom: 1px solid #F3F4F6;
            color: #6B7280;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 10px 10px;
        }

        .items-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #F3F4F6;
            font-size: 12px;
            vertical-align: top;
        }

        .item-name {
            font-weight: 600;
            color: #111827;
            font-size: 12px;
        }

        .item-desc {
            font-size: 11px;
            color: #6B7280;
            margin-top: 2px;
            line-height: 1.4;
        }

        /* Pill Badge for Options (Green tinted pill matching frontend) */
        .badge-option {
            display: inline-block;
            padding: 2px 8px;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-radius: 9999px;
            margin-top: 4px;
            color: {{ $branding['tab_color'] ?? '#6BAE41' }};
            background-color: #E8F5E9;
            border: 1px solid #C8E6C9;
            line-height: 1.2;
        }

        .badge-paid {
            display: inline-block;
            padding: 2px 7px;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            border-radius: 9999px;
            margin-top: 4px;
            margin-left: 4px;
            background-color: #DCFCE7;
            color: #15803D;
            line-height: 1.2;
        }

        .badge-refunded {
            display: inline-block;
            padding: 2px 7px;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            border-radius: 9999px;
            margin-top: 4px;
            margin-left: 4px;
            background-color: #FEE2E2;
            color: #DC2626;
            line-height: 1.2;
        }

        /* Split Allocation Box (if active) */
        .split-box {
            background-color: #F8F9FA;
            border: 1px solid #E5E7EB;
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 18px;
            page-break-inside: avoid;
        }

        .split-box-title {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: {{ $branding['tab_color'] ?? '#6BAE41' }};
            margin-bottom: 6px;
        }

        .split-table {
            width: 100%;
            border-collapse: collapse;
        }

        .split-card-cell {
            padding: 6px 8px;
            background-color: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 4px;
        }

        /* Summary & Totals */
        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
            margin-bottom: 16px;
            page-break-inside: avoid;
        }

        .summary-table td {
            vertical-align: top;
            padding: 0;
        }

        .notes-section {
            padding-right: 30px;
        }

        .notes-heading {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: {{ $branding['tab_color'] ?? '#6BAE41' }};
            margin-bottom: 5px;
        }

        .notes-body {
            font-size: 11px;
            color: #6B7280;
            font-style: italic;
            line-height: 1.5;
        }

        .totals-table {
            width: 100%;
            border-collapse: collapse;
        }

        .totals-table td {
            padding: 3px 0;
            font-size: 12px;
        }

        .totals-table .t-label {
            color: #6B7280;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
            text-align: left;
        }

        .totals-table .t-val {
            color: #111827;
            font-weight: 700;
            text-align: right;
            font-size: 12px;
        }

        /* Total Banner in brand tab color matching frontend line 1072 */
        .total-banner-row td {
            padding: 4px 0 !important;
        }

        .total-banner {
            background-color: {{ $branding['tab_color'] ?? '#6BAE41' }};
            color: #FFFFFF;
            padding: 9px 12px;
            border-radius: 3px;
        }

        .total-banner table {
            width: 100%;
            border-collapse: collapse;
        }

        .total-banner td {
            color: #FFFFFF !important;
            padding: 0 !important;
        }

        .total-banner .b-label {
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            text-align: left;
        }

        .total-banner .b-val {
            font-size: 15px;
            font-weight: 800;
            text-align: right;
        }

        .paid-row td {
            color: #15803D !important;
            font-weight: 700;
        }

        .refund-row td {
            color: #DC2626 !important;
            font-weight: 700;
        }

        .due-row .t-label {
            color: #111827;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .due-row .t-val {
            color: #DC2626;
            font-size: 14px;
            font-weight: 800;
        }

        .due-paid-row .t-val {
            color: #15803D;
            font-size: 13px;
            font-weight: 800;
        }

        /* Payment History Table */
        .payments-container {
            margin-top: 16px;
            page-break-inside: avoid;
        }

        .payments-header {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #374151;
            margin-bottom: 6px;
        }

        .payments-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }

        .payments-table th {
            background-color: #F9FAFB;
            color: #6B7280;
            padding: 6px 10px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            border-bottom: 1px solid #E5E7EB;
        }

        .payments-table td {
            padding: 6px 10px;
            border-bottom: 1px solid #F3F4F6;
            color: #374151;
        }

        /* Footer */
        .footer {
            margin-top: 24px;
            border-top: 1px solid #F3F4F6;
            padding-top: 10px;
            text-align: center;
            font-size: 10px;
            color: #9CA3AF;
        }
    </style>
</head>
<body>

    <!-- Header Section -->
    <table class="header-table">
        <tr>
            <td style="width: 55%;">
                <table style="border-collapse: collapse;">
                    <tr>
                        @if(!empty($branding['logo_base64']))
                            <td style="padding-right: 8px; vertical-align: middle;">
                                <img src="{{ $branding['logo_base64'] }}" class="logo-img" alt="Logo">
                            </td>
                        @endif
                        <td style="vertical-align: middle;">
                            <span class="company-name">{{ $branding['name'] }}</span>
                        </td>
                    </tr>
                </table>

                <div class="meta-list">
                    <p>Invoice Number: <span>#{{ $invoice->invoice_number ?: $invoice->id }}</span></p>
                    <p>Date: <span>{{ ($invoice->issued_at ?? $invoice->created_at) ? \Carbon\Carbon::parse($invoice->issued_at ?? $invoice->created_at)->format('n/j/Y') : 'N/A' }}</span></p>
                    @if($invoice->due_date)
                        <p>Due Date: <span>{{ \Carbon\Carbon::parse($invoice->due_date)->format('n/j/Y') }}</span></p>
                    @endif
                    <div>
                        <span class="status-pill status-badge">{{ str_replace('_', ' ', strtoupper($invoice->status ?? 'UNPAID')) }}</span>
                    </div>
                </div>
            </td>
            <td style="width: 45%;">
                <!-- Signature 3-Tier Tab: 4px line, 12px line, and solid INVOICE block -->
                <table class="tab-table">
                    <tr>
                        <td class="tab-stripe-1">&nbsp;</td>
                        <td class="tab-stripe-2">&nbsp;</td>
                        <td class="tab-stripe-main">
                            <span>INVOICE</span>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- Accent Divider Bar -->
    <div class="accent-divider"></div>

    <!-- Dual Cards: Bill From & Bill To -->
    <table class="cards-table">
        <tr>
            <!-- Bill From Card -->
            <td>
                <div class="info-card">
                    <div class="card-header">Bill From:</div>
                    <div class="card-title">{{ $branding['name'] }}</div>
                    @if(!empty($branding['email']))
                        <div class="card-row">
                            @if(!empty($branding['mail_icon_base64']))
                                <img src="{{ $branding['mail_icon_base64'] }}" width="11" height="11" style="vertical-align: -1px; margin-right: 4px;">
                            @endif
                            {{ $branding['email'] }}
                        </div>
                    @endif
                    @if(!empty($branding['phone']))
                        <div class="card-row">{{ $branding['phone'] }}</div>
                    @endif
                    @if(!empty($branding['address']))
                        <div class="card-row text-muted" style="font-size: 11px;">{{ $branding['address'] }}</div>
                    @endif
                </div>
            </td>

            <!-- Bill To Card -->
            <td>
                <div class="info-card">
                    <div class="card-header">
                        Bill To:
                        @if($client['is_vendor'])
                            <span style="font-size: 9px; font-weight: normal; color: #9CA3AF;">(vendor)</span>
                        @endif
                    </div>
                    <div class="card-title">
                        {{ $client['name'] }}
                    </div>
                    @if(!empty($client['company']))
                        <div class="card-row" style="font-weight: 500;">{{ $client['company'] }}</div>
                    @endif
                    @if(!empty($client['property_address']))
                        <div class="card-row">
                            @if(!empty($branding['pin_icon_base64']))
                                <img src="{{ $branding['pin_icon_base64'] }}" width="11" height="11" style="vertical-align: -1px; margin-right: 4px;">
                            @endif
                            {{ $client['property_address'] }}
                        </div>
                    @endif
                    @if(!empty($client['address']))
                        <div class="card-row text-muted">{{ $client['address'] }}</div>
                    @endif
                    @if(!empty($client['email']))
                        <div class="card-row">
                            @if(!empty($branding['mail_icon_base64']))
                                <img src="{{ $branding['mail_icon_base64'] }}" width="11" height="11" style="vertical-align: -1px; margin-right: 4px;">
                            @endif
                            {{ $client['email'] }}
                        </div>
                    @endif
                    @if(!empty($client['phone']))
                        <div class="card-row">{{ $client['phone'] }}</div>
                    @endif
                    @if(!empty($client['tax_number']))
                        <div class="card-row" style="margin-top: 3px; font-size: 11px;">
                            <strong>Tax ID:</strong> {{ $client['tax_number'] }}
                        </div>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <!-- Line Items Table (3 Columns matching frontend view mode: Item Description, Quantity, Amount) -->
    <table class="items-table">
        <thead>
            <tr>
                <th class="text-left" style="width: 55%;">Item Description</th>
                <th class="text-center" style="width: 15%;">Quantity</th>
                <th class="text-right" style="width: 30%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse($invoice->items as $item)
                @php
                    $serviceTitle = $item->orderService?->service?->name ?? $item->description ?? 'Service Item';
                    $qty = (float)($item->quantity ?? 1);
                    $rate = (float)($item->unit_price ?? $item->amount ?? 0);
                    $lineTotal = (float)($item->amount ?? ($qty * $rate));
                    $isPaidItem = !empty($item->item_status) ? $item->item_status === 'paid' : !empty($item->is_paid);
                    $isRefundedItem = ($item->item_status ?? '') === 'refunded';
                @endphp
                <tr>
                    <td>
                        <div class="item-name">{{ $serviceTitle }}</div>
                        @if(!empty($item->description) && $item->description !== $serviceTitle)
                            <div class="item-desc">{{ $item->description }}</div>
                        @endif
                        @if($isPaidItem)
                            <span class="badge-paid">Paid</span>
                        @endif
                        @if($isRefundedItem)
                            <span class="badge-refunded">Refunded</span>
                        @endif
                    </td>
                    <td class="text-center" style="color: #4B5563;">{{ $qty == (int)$qty ? (int)$qty : number_format($qty, 2) }}</td>
                    <td class="text-right font-bold" style="color: #111827;">
                        @if($lineTotal < 0)
                            -${{ number_format(abs($lineTotal), 2) }}
                        @else
                            ${{ number_format($lineTotal, 2) }}
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="text-center text-muted" style="padding: 18px;">
                        No line items recorded for this invoice.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <!-- Split Invoice Distribution (if active) -->
    @if(!empty($splitParticipants) && count($splitParticipants) > 0)
        <div class="split-box">
            <div class="split-box-title">
                {{ count($splitParticipants) === 1 ? 'Invoice Allocation' : 'Invoice Split Distribution' }}
            </div>
            <table class="split-table">
                <tr>
                    @foreach($splitParticipants as $part)
                        <td style="width: {{ round(100 / count($splitParticipants)) }}%; padding-right: 8px;">
                            <div class="split-card-cell">
                                <table style="width: 100%;">
                                    <tr>
                                        <td style="font-weight: bold; font-size: 10px; color: #111827;">{{ $part['name'] }}</td>
                                        <td style="text-align: right;">
                                            <span style="background: #EFF6FF; color: #1E40AF; padding: 1px 4px; border-radius: 3px; font-size: 9px; font-weight: bold;">{{ $part['percentage'] }}%</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan="2" style="font-size: 9px; color: #6B7280;">{{ $part['email'] }}</td>
                                    </tr>
                                    <tr>
                                        <td style="font-size: 9px; color: #9CA3AF; text-transform: uppercase;">{{ $part['role'] }}</td>
                                        <td style="text-align: right; font-weight: bold; font-size: 10px; color: #111827;">${{ number_format((float)$part['amount'], 2) }}</td>
                                    </tr>
                                </table>
                            </div>
                        </td>
                    @endforeach
                </tr>
            </table>
        </div>
    @endif

    <!-- Summary & Totals (Notes on left, Totals on right) -->
    <table class="summary-table">
        <tr>
            <!-- Notes (Left 50%) -->
            <td style="width: 50%;" class="notes-section">
                <div class="notes-heading">Notes:</div>
                <div class="notes-body">
                    {!! nl2br(e($invoice->notes ?: 'Payment is due within 30 days of issuance. Please include the invoice number with your payment. Thank you for your business!')) !!}
                </div>
            </td>

            <!-- Totals Card (Right 50%) -->
            <td style="width: 50%;">
                <table class="totals-table">
                    <tr>
                        <td class="t-label">Subtotal:</td>
                        <td class="t-val">${{ number_format($financials['subtotal'], 2) }}</td>
                    </tr>

                    @if(!empty($travelAmount) && $travelAmount > 0)
                        <tr>
                            <td class="t-label">Travel:</td>
                            <td class="t-val">${{ number_format($travelAmount, 2) }}</td>
                        </tr>
                    @endif

                    @if(!empty($financials['tax_breakdown']))
                        @foreach($financials['tax_breakdown'] as $taxLabel => $taxAmt)
                            <tr>
                                <td class="t-label">{{ $taxLabel }}:</td>
                                <td class="t-val">${{ number_format($taxAmt, 2) }}</td>
                            </tr>
                        @endforeach
                    @endif

                    <!-- Grand Total Banner in brand tab color -->
                    <tr class="total-banner-row">
                        <td colspan="2">
                            <div class="total-banner">
                                <table>
                                    <tr>
                                        <td class="b-label">Total ({{ strtoupper($invoice->currency ?? 'CAD') }})</td>
                                        <td class="b-val">${{ number_format($financials['total'], 2) }} {{ strtoupper($invoice->currency ?? 'CAD') }}</td>
                                    </tr>
                                </table>
                            </div>
                        </td>
                    </tr>

                    @if($financials['paid_amount'] > 0)
                        <tr class="paid-row">
                            <td class="t-label" style="color: #15803D !important;">Amount Paid:</td>
                            <td class="t-val" style="color: #15803D !important;">-${{ number_format($financials['paid_amount'], 2) }}</td>
                        </tr>
                    @endif

                    @if($financials['refunded_amount'] > 0)
                        <tr class="refund-row">
                            <td class="t-label" style="color: #DC2626 !important;">Amount Refunded:</td>
                            <td class="t-val" style="color: #DC2626 !important;">+${{ number_format($financials['refunded_amount'], 2) }}</td>
                        </tr>
                    @endif

                    @if($financials['refunded_amount'] > 0 && $financials['paid_amount'] > 0)
                        <tr>
                            <td class="t-label">Net Paid:</td>
                            <td class="t-val">${{ number_format($financials['net_paid'], 2) }}</td>
                        </tr>
                    @endif

                    @if($financials['balance_due'] > 0)
                        <tr class="due-row">
                            <td class="t-label">Balance Due:</td>
                            <td class="t-val">${{ number_format($financials['balance_due'], 2) }}</td>
                        </tr>
                    @else
                        <tr class="due-paid-row">
                            <td class="t-label">Balance Due:</td>
                            <td class="t-val">$0.00 (PAID)</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <!-- Recorded Payment History (if any) -->
    @if($invoice->payments && $invoice->payments->isNotEmpty())
        <div class="payments-container">
            <div class="payments-header">Transaction History</div>
            <table class="payments-table">
                <thead>
                    <tr>
                        <th style="width: 22%;">Date</th>
                        <th style="width: 14%;">Type</th>
                        <th style="width: 16%;">Method</th>
                        <th style="width: 30%;">Reference / ID</th>
                        <th class="text-right" style="width: 18%;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoice->payments as $payment)
                        @php
                            $isRef = $payment->payment_type === 'refund' || (float)$payment->amount < 0;
                            $amt = abs((float)$payment->amount);
                        @endphp
                        <tr>
                            <td>{{ $payment->paid_at ? \Carbon\Carbon::parse($payment->paid_at)->format('n/j/Y') : \Carbon\Carbon::parse($payment->created_at)->format('n/j/Y') }}</td>
                            <td>
                                @if($isRef)
                                    <span style="color: #DC2626; font-weight: 700;">Refund</span>
                                @else
                                    <span style="color: #15803D; font-weight: 700;">Payment</span>
                                @endif
                            </td>
                            <td>{{ ucfirst($payment->payment_method ?? 'Card') }}</td>
                            <td style="font-family: monospace; font-size: 9px; color: #6B7280;">{{ $payment->uuid }}</td>
                            <td class="text-right font-bold" style="color: {{ $isRef ? '#DC2626' : '#15803D' }};">
                                {{ $isRef ? '-' : '' }}${{ number_format($amt, 2) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <!-- Footer -->
    <div class="footer">
        Thank you for your business. For any questions regarding this invoice, please reach out to {{ $branding['email'] }}.
    </div>

</body>
</html>
