@extends('emails.layouts.master')

@section('content')
@php
    $role = $recipientRole ?? 'agent';
    $amountFormatted = '$' . number_format((float)$payment->amount, 2) . ' ' . strtoupper($payment->currency ?? 'USD');
    $scopeDescription = $data['payment_scope_description'] ?? ($payment->payment_type === 'partial' ? 'Partial Service Payment' : 'Full Order Payment');
    $invoiceNum = $data['invoice_number'] ?? ($payment->invoice ? ($payment->invoice->invoice_number ?? ('INV-' . $payment->invoice->id)) : ($payment->invoice_id ? ('INV-' . $payment->invoice_id) : null));
    $propertyAddress = $data['property_address'] ?? ($order ? $order->property_address : 'N/A');
    $paidByAdmin = !empty($data['paid_by_admin']);
    $creatorName = $data['creator_name'] ?? 'Administrator';
    $agentName = $data['agent_name'] ?? ($payment->agent ? trim($payment->agent->first_name . ' ' . $payment->agent->last_name) : 'Agent');
    $agentEmail = $data['agent_email'] ?? ($payment->agent ? $payment->agent->email : null);
    $servicesList = $data['paid_services'] ?? [];
@endphp

    @if($role === 'admin')
        <!-- Admin Header -->
        <h1 style="font-size: 20px; font-weight: normal; margin: 0 0 16px; border-bottom: 1px solid #CCCCCC; padding-bottom: 16px; color: #16A34A;">
            ✓ Payment Received: {{ $amountFormatted }}
        </h1>

        <h2 style="font-size: 16px; font-weight: bold; margin: 20px 0 15px;">
            Hello {{ $recipientName }},
        </h2>

        <p style="font-size: 14px; margin-bottom: 20px; color: #374151; line-height: 1.5;">
            @if($paidByAdmin)
                A payment of <strong>{{ $amountFormatted }}</strong> was recorded and processed by <strong>{{ $creatorName }}</strong> on behalf of agent <strong>{{ $agentName }}</strong>.
            @else
                A payment of <strong>{{ $amountFormatted }}</strong> has been received from agent <strong>{{ $agentName }}</strong>.
            @endif
        </p>

        <!-- Payment Details for Admin -->
        <div style="background-color: #DCFCE7; padding: 20px; border-radius: 8px; border: 1px solid #BBF7D0; margin-bottom: 20px; color: #14532D;">
            <h3 style="font-size: 15px; margin: 0 0 12px; color: #14532D; border-bottom: 1px solid #BBF7D0; padding-bottom: 8px;">Payment Breakdown</h3>
            <table width="100%" style="font-size: 14px; border-collapse: collapse;">
                <tr>
                    <td style="padding: 6px 0; color: #166534; font-weight: bold;" width="35%">Amount Received:</td>
                    <td style="padding: 6px 0; color: #14532D; font-weight: bold;">{{ $amountFormatted }}</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #166534; font-weight: bold;">Payment Scope:</td>
                    <td style="padding: 6px 0; color: #14532D; font-weight: bold;">{{ $scopeDescription }}</td>
                </tr>
                @if($invoiceNum)
                <tr>
                    <td style="padding: 6px 0; color: #166534; font-weight: bold;">Invoice Number:</td>
                    <td style="padding: 6px 0; color: #14532D;">#{{ $invoiceNum }}</td>
                </tr>
                @endif
                @if($order)
                <tr>
                    <td style="padding: 6px 0; color: #166534; font-weight: bold;">Order ID:</td>
                    <td style="padding: 6px 0; color: #14532D;">#{{ $order->id }}</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #166534; font-weight: bold;">Property Address:</td>
                    <td style="padding: 6px 0; color: #14532D;">{{ $propertyAddress }}</td>
                </tr>
                @endif
                <tr>
                    <td style="padding: 6px 0; color: #166534; font-weight: bold;">Agent:</td>
                    <td style="padding: 6px 0; color: #14532D;">{{ $agentName }} {{ $agentEmail ? "({$agentEmail})" : '' }}</td>
                </tr>
                @if($paidByAdmin)
                <tr>
                    <td style="padding: 6px 0; color: #166534; font-weight: bold;">Processed By:</td>
                    <td style="padding: 6px 0; color: #14532D;">{{ $creatorName }}</td>
                </tr>
                @endif
                <tr>
                    <td style="padding: 6px 0; color: #166534; font-weight: bold;">Payment Method:</td>
                    <td style="padding: 6px 0; color: #14532D;">{{ ucfirst($payment->payment_method ?? 'Card') }}</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #166534; font-weight: bold;">Payment Date:</td>
                    <td style="padding: 6px 0; color: #14532D;">
                        {{ $payment->paid_at ? \Carbon\Carbon::parse($payment->paid_at)->format('F j, Y g:i A') : 'Just now' }}
                    </td>
                </tr>
                @if($payment->stripe_receipt_url)
                <tr>
                    <td style="padding: 6px 0; color: #166534; font-weight: bold;">Stripe Receipt:</td>
                    <td style="padding: 6px 0; color: #14532D;">
                        <a href="{{ $payment->stripe_receipt_url }}" style="color: #15803d; text-decoration: underline;">View Receipt</a>
                    </td>
                </tr>
                @endif
            </table>
        </div>

        @if(!empty($servicesList))
        <!-- Services Included -->
        <div style="background-color: #F9FAFB; padding: 16px 20px; border-radius: 8px; border: 1px solid #E5E7EB; margin-bottom: 20px;">
            <h3 style="font-size: 14px; margin: 0 0 8px; color: #111827; font-weight: bold;">Paid Services</h3>
            <ul style="margin: 0; padding-left: 20px; font-size: 14px; color: #4B5563;">
                @foreach($servicesList as $s)
                    <li style="margin-bottom: 4px;">{{ $s }}</li>
                @endforeach
            </ul>
        </div>
        @endif

    @else
        <!-- Agent Header -->
        <h1 style="font-size: 20px; font-weight: normal; margin: 0 0 16px; border-bottom: 1px solid #CCCCCC; padding-bottom: 16px; color: #16A34A;">
            ✓ Payment Received Successfully
        </h1>

        <h2 style="font-size: 16px; font-weight: bold; margin: 20px 0 15px;">
            Dear {{ $recipientName }},
        </h2>

        <p style="font-size: 14px; margin-bottom: 20px; color: #374151; line-height: 1.5;">
            @if($paidByAdmin)
                A payment has been successfully recorded and processed for your order by administration. Thank you!
            @else
                We have successfully received your payment. Thank you for your business!
            @endif
        </p>

        <!-- Payment Details for Agent -->
        <div style="background-color: #DCFCE7; padding: 20px; border-radius: 8px; border: 1px solid #BBF7D0; margin-bottom: 20px; color: #14532D;">
            <h3 style="font-size: 15px; margin: 0 0 12px; color: #14532D; border-bottom: 1px solid #BBF7D0; padding-bottom: 8px;">Payment Information</h3>
            <table width="100%" style="font-size: 14px; border-collapse: collapse;">
                <tr>
                    <td style="padding: 6px 0; color: #166534; font-weight: bold;" width="35%">Amount Paid:</td>
                    <td style="padding: 6px 0; color: #14532D; font-weight: bold;">{{ $amountFormatted }}</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #166534; font-weight: bold;">Payment For:</td>
                    <td style="padding: 6px 0; color: #14532D; font-weight: bold;">{{ $scopeDescription }}</td>
                </tr>
                @if($invoiceNum)
                <tr>
                    <td style="padding: 6px 0; color: #166534; font-weight: bold;">Invoice Number:</td>
                    <td style="padding: 6px 0; color: #14532D;">#{{ $invoiceNum }}</td>
                </tr>
                @endif
                @if($order)
                <tr>
                    <td style="padding: 6px 0; color: #166534; font-weight: bold;">Order ID:</td>
                    <td style="padding: 6px 0; color: #14532D;">#{{ $order->id }}</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #166534; font-weight: bold;">Property Address:</td>
                    <td style="padding: 6px 0; color: #14532D;">{{ $propertyAddress }}</td>
                </tr>
                @endif
                <tr>
                    <td style="padding: 6px 0; color: #166534; font-weight: bold;">Payment Method:</td>
                    <td style="padding: 6px 0; color: #14532D;">{{ ucfirst($payment->payment_method ?? 'Card') }}</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #166534; font-weight: bold;">Payment Date:</td>
                    <td style="padding: 6px 0; color: #14532D;">
                        {{ $payment->paid_at ? \Carbon\Carbon::parse($payment->paid_at)->format('F j, Y g:i A') : 'Just now' }}
                    </td>
                </tr>
                @if($payment->stripe_receipt_url)
                <tr>
                    <td style="padding: 6px 0; color: #166534; font-weight: bold;">Receipt Link:</td>
                    <td style="padding: 6px 0; color: #14532D;">
                        <a href="{{ $payment->stripe_receipt_url }}" style="color: #15803d; text-decoration: underline;">View Stripe Receipt</a>
                    </td>
                </tr>
                @endif
            </table>
        </div>

        @if(!empty($servicesList))
        <!-- Services Included -->
        <div style="background-color: #F9FAFB; padding: 16px 20px; border-radius: 8px; border: 1px solid #E5E7EB; margin-bottom: 20px;">
            <h3 style="font-size: 14px; margin: 0 0 8px; color: #111827; font-weight: bold;">Services Covered</h3>
            <ul style="margin: 0; padding-left: 20px; font-size: 14px; color: #4B5563;">
                @foreach($servicesList as $s)
                    <li style="margin-bottom: 4px;">{{ $s }}</li>
                @endforeach
            </ul>
        </div>
        @endif
    @endif
@endsection
