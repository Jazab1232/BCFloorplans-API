<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice #{{ $invoice->invoice_number ?: $invoice->id }}</title>
    <style>
        @page {
            margin: 36px 36px 36px 36px;
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

        /* Helpers */
        .w-100 { width: 100%; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .text-center { text-align: center; }
        .font-bold { font-weight: bold; }
        .uppercase { text-transform: uppercase; }
        .text-muted { color: #6B7280; }
        .text-dark { color: #111827; }

        /* Header */
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }

        .header-table td {
            vertical-align: top;
        }

        .logo-img {
            max-height: 52px;
            max-width: 180px;
            object-fit: contain;
            vertical-align: middle;
        }

        .company-name {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
            margin-left: 8px;
            display: inline-block;
            vertical-align: middle;
        }

        .decorative-tab {
            text-align: right;
            padding-right: 0;
        }

        .tab-box {
            display: inline-block;
            background-color: {{ $branding['tab_color'] ?? '#111827' }};
            color: #FFFFFF;
            padding: 12px 24px;
            border-radius: 4px;
            text-align: center;
        }

        .tab-box .tab-title {
            font-size: 18px;
            font-weight: 800;
            letter-spacing: 2px;
            margin: 0;
            line-height: 1;
        }

        .invoice-meta {
            margin-top: 12px;
        }

        .invoice-meta p {
            margin: 2px 0;
            font-size: 11px;
            color: #4B5563;
        }

        .invoice-meta p span {
            color: #111827;
            font-weight: 600;
        }

        /* Status Pill */
        .status-pill {
            display: inline-block;
            padding: 3px 10px;
            font-size: 10px;
            font-weight: 700;
            border-radius: 9999px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-top: 6px;
        }

        @php
            $st = strtolower($invoice->status ?? 'unpaid');
            if ($st === 'paid') {
                $statusBg = '#DCFCE7';
                $statusColor = '#15803D';
            } elseif ($st === 'partially_paid' || $st === 'partial') {
                $statusBg = '#FEF3C7';
                $statusColor = '#B45309';
            } elseif ($st === 'draft') {
                $statusBg = '#FFEDD5';
                $statusColor = '#C2410C';
            } elseif ($st === 'refunded') {
                $statusBg = '#FEE2E2';
                $statusColor = '#B91C1C';
            } elseif ($st === 'void') {
                $statusBg = '#F3F4F6';
                $statusColor = '#4B5563';
            } else {
                $statusBg = '#DBEAFE';
                $statusColor = '#1D4ED8';
            }
        @endphp

        .status-badge {
            background-color: {{ $statusBg }};
            color: {{ $statusColor }};
        }

        /* Info Section (Billed To / From) */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
            background-color: #F9FAFB;
            border-radius: 6px;
            border: 1px solid #E5E7EB;
        }

        .info-table td {
            padding: 14px 16px;
            vertical-align: top;
            width: 50%;
        }

        .info-title {
            font-size: 10px;
            font-weight: 700;
            color: #6B7280;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 6px;
        }

        .info-content {
            font-size: 12px;
            line-height: 1.5;
            color: #1F2937;
        }

        .info-content strong {
            color: #111827;
            font-size: 13px;
        }

        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .items-table th {
            background-color: #F3F4F6;
            color: #374151;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 8px 12px;
            border-bottom: 2px solid #E5E7EB;
        }

        .items-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #E5E7EB;
            font-size: 11px;
            vertical-align: top;
        }

        .item-name {
            font-weight: 600;
            color: #111827;
        }

        .item-desc {
            font-size: 10px;
            color: #6B7280;
            margin-top: 2px;
        }

        /* Financials Section */
        .summary-container {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
            page-break-inside: avoid;
        }

        .summary-container td {
            vertical-align: top;
        }

        .notes-box {
            padding-right: 20px;
        }

        .notes-title {
            font-size: 11px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 4px;
        }

        .notes-content {
            font-size: 11px;
            color: #6B7280;
            background-color: #F9FAFB;
            border: 1px solid #E5E7EB;
            border-radius: 4px;
            padding: 10px;
            line-height: 1.4;
        }

        .totals-table {
            width: 100%;
            border-collapse: collapse;
        }

        .totals-table td {
            padding: 5px 8px;
            font-size: 11px;
        }

        .totals-table .label {
            color: #4B5563;
            text-align: right;
            width: 60%;
        }

        .totals-table .val {
            color: #111827;
            text-align: right;
            font-weight: 600;
            width: 40%;
        }

        .totals-table .grand-total td {
            font-size: 13px;
            font-weight: 700;
            color: #111827;
            border-top: 2px solid #E5E7EB;
            border-bottom: 2px solid #E5E7EB;
            padding: 8px 8px;
        }

        .totals-table .balance-due td {
            font-size: 13px;
            font-weight: 700;
            color: #DC2626;
            background-color: #FEF2F2;
            border-radius: 4px;
            padding: 8px 8px;
        }

        .totals-table .balance-paid td {
            font-size: 13px;
            font-weight: 700;
            color: #15803D;
            background-color: #F0FDF4;
            border-radius: 4px;
            padding: 8px 8px;
        }

        /* Payment History */
        .payments-box {
            margin-top: 16px;
            page-break-inside: avoid;
        }

        .payments-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }

        .payments-table th {
            background-color: #F3F4F6;
            color: #4B5563;
            padding: 6px 10px;
            text-align: left;
            border-bottom: 1px solid #E5E7EB;
        }

        .payments-table td {
            padding: 6px 10px;
            border-bottom: 1px solid #F3F4F6;
            color: #374151;
        }

        /* Footer */
        .footer {
            margin-top: 30px;
            border-top: 1px solid #E5E7EB;
            padding-top: 12px;
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
            <td style="width: 60%;">
                <div style="margin-bottom: 6px;">
                    @if(!empty($branding['logo_base64']))
                        <img src="{{ $branding['logo_base64'] }}" class="logo-img" alt="Logo">
                    @else
                        <span class="company-name">{{ $branding['name'] }}</span>
                    @endif
                </div>

                <div class="invoice-meta">
                    <p>Invoice Number: <span>#{{ $invoice->invoice_number ?: $invoice->id }}</span></p>
                    <p>Issue Date: <span>{{ ($invoice->issued_at ?? $invoice->created_at) ? \Carbon\Carbon::parse($invoice->issued_at ?? $invoice->created_at)->format('F j, Y') : 'N/A' }}</span></p>
                    @if($invoice->due_date)
                        <p>Due Date: <span>{{ \Carbon\Carbon::parse($invoice->due_date)->format('F j, Y') }}</span></p>
                    @endif
                    <div>
                        <span class="status-pill status-badge">{{ str_replace('_', ' ', strtoupper($invoice->status ?? 'UNPAID')) }}</span>
                    </div>
                </div>
            </td>
            <td style="width: 40%;" class="decorative-tab">
                <div class="tab-box">
                    <h1 class="tab-title">INVOICE</h1>
                </div>
                <div style="margin-top: 12px; font-size: 11px; color: #4B5563; line-height: 1.4;">
                    <div class="font-bold text-dark">{{ $branding['name'] }}</div>
                    @if(!empty($branding['address']))
                        <div>{{ $branding['address'] }}</div>
                    @endif
                    @if(!empty($branding['email']))
                        <div>{{ $branding['email'] }}</div>
                    @endif
                    @if(!empty($branding['phone']))
                        <div>{{ $branding['phone'] }}</div>
                    @endif
                    @if(!empty($taxNumbers))
                        <div style="margin-top: 4px; font-size: 10px; color: #6B7280;">
                            @foreach($taxNumbers as $tName => $tNum)
                                <span>{{ $tName }} Reg: <strong>{{ $tNum }}</strong></span>@if(!$loop->last) &bull; @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <!-- Info Section (Bill To & Order Details) -->
    <table class="info-table">
        <tr>
            <td>
                <div class="info-title">Billed To</div>
                <div class="info-content">
                    @php
                        $agent = $invoice->agent;
                        $agentName = $agent ? trim(($agent->first_name ?? '') . ' ' . ($agent->last_name ?? '')) : 'Valued Customer';
                    @endphp
                    <strong>{{ $agentName }}</strong><br>
                    @if($agent?->brokerage || $agent?->company_name)
                        <div>{{ $agent->brokerage ?? $agent->company_name }}</div>
                    @endif
                    @if($agent?->email)
                        <div>{{ $agent->email }}</div>
                    @endif
                    @if($agent?->phone || $agent?->mobile_number)
                        <div>{{ $agent->phone ?? $agent->mobile_number }}</div>
                    @endif
                </div>
            </td>
            <td>
                <div class="info-title">Order & Property Details</div>
                <div class="info-content">
                    @if($invoice->order)
                        <div>Order Ref: <strong>#{{ $invoice->order->id }}</strong> ({{ $invoice->order->uuid }})</div>
                    @endif

                    @php
                        $property = $invoice->order?->property;
                        $propAddress = null;
                        if ($property) {
                            $propAddress = implode(', ', array_filter([
                                $property->suite,
                                $property->address,
                                $property->city,
                                $property->province,
                                $property->postal_code,
                                $property->country
                            ]));
                        } elseif (!empty($invoice->order?->property_address)) {
                            $propAddress = $invoice->order->property_address;
                        }
                    @endphp

                    @if($propAddress)
                        <div style="margin-top: 4px;">Property Address:</div>
                        <strong>{{ $propAddress }}</strong>
                    @else
                        <div class="text-muted">No specific property address attached</div>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <!-- Line Items Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th class="text-left" style="width: 50%;">Description / Service</th>
                <th class="text-center" style="width: 12%;">Qty</th>
                <th class="text-right" style="width: 18%;">Unit Price</th>
                <th class="text-right" style="width: 20%;">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse($invoice->items as $item)
                @php
                    $serviceTitle = $item->orderService?->service?->name ?? $item->description ?? 'Service Item';
                    $qty = (int)($item->quantity ?? 1);
                    $rate = (float)($item->unit_price ?? $item->amount ?? 0);
                    $lineTotal = (float)($item->amount ?? ($qty * $rate));
                @endphp
                <tr>
                    <td>
                        <div class="item-name">{{ $serviceTitle }}</div>
                        @if(!empty($item->description) && $item->description !== $serviceTitle)
                            <div class="item-desc">{{ $item->description }}</div>
                        @endif
                    </td>
                    <td class="text-center">{{ $qty }}</td>
                    <td class="text-right">${{ number_format($rate, 2) }}</td>
                    <td class="text-right font-bold">${{ number_format($lineTotal, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center text-muted" style="padding: 18px;">
                        No line items recorded for this invoice.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <!-- Summary & Totals -->
    <table class="summary-container">
        <tr>
            <td style="width: 50%;" class="notes-box">
                @if(!empty($invoice->notes))
                    <div class="notes-title">Notes / Instructions:</div>
                    <div class="notes-content">
                        {!! nl2br(e($invoice->notes)) !!}
                    </div>
                @endif
            </td>
            <td style="width: 50%;">
                <table class="totals-table">
                    <tr>
                        <td class="label">Subtotal:</td>
                        <td class="val">${{ number_format($financials['subtotal'], 2) }}</td>
                    </tr>

                    @if(!empty($financials['tax_breakdown']))
                        @foreach($financials['tax_breakdown'] as $taxLabel => $taxAmt)
                            <tr>
                                <td class="label">{{ $taxLabel }}:</td>
                                <td class="val">${{ number_format($taxAmt, 2) }}</td>
                            </tr>
                        @endforeach
                    @endif

                    <tr class="grand-total">
                        <td class="label">Total Amount:</td>
                        <td class="val">${{ number_format($financials['total'], 2) }} {{ strtoupper($invoice->currency ?? 'CAD') }}</td>
                    </tr>

                    @if($financials['paid_amount'] > 0)
                        <tr>
                            <td class="label" style="color: #15803D;">Amount Paid:</td>
                            <td class="val" style="color: #15803D;">-${{ number_format($financials['paid_amount'], 2) }}</td>
                        </tr>
                    @endif

                    @if($financials['refunded_amount'] > 0)
                        <tr>
                            <td class="label" style="color: #B91C1C;">Refunded Amount:</td>
                            <td class="val" style="color: #B91C1C;">+${{ number_format($financials['refunded_amount'], 2) }}</td>
                        </tr>
                    @endif

                    @if($financials['balance_due'] > 0)
                        <tr class="balance-due">
                            <td class="label" style="color: #DC2626;">Balance Due:</td>
                            <td class="val">${{ number_format($financials['balance_due'], 2) }} {{ strtoupper($invoice->currency ?? 'CAD') }}</td>
                        </tr>
                    @else
                        <tr class="balance-paid">
                            <td class="label" style="color: #15803D;">Balance Due:</td>
                            <td class="val">$0.00 (PAID)</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <!-- Recorded Payment History (if any) -->
    @if($invoice->payments && $invoice->payments->isNotEmpty())
        <div class="payments-box">
            <div style="font-size: 11px; font-weight: 700; color: #374151; margin-bottom: 6px;">Payment History</div>
            <table class="payments-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Reference / ID</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoice->payments as $payment)
                        <tr>
                            <td>{{ $payment->paid_at ? \Carbon\Carbon::parse($payment->paid_at)->format('M d, Y g:i A') : \Carbon\Carbon::parse($payment->created_at)->format('M d, Y') }}</td>
                            <td>{{ ucfirst($payment->payment_method ?? 'Card') }}</td>
                            <td><span style="font-weight: 600;">{{ strtoupper($payment->status ?? 'PAID') }}</span></td>
                            <td>{{ $payment->uuid }}</td>
                            <td class="text-right font-bold">${{ number_format((float)$payment->amount, 2) }}</td>
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
