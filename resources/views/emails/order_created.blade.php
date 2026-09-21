@extends('emails.layouts.master')

@section('content')
    <h1 style="font-size: 20px; font-weight: normal; margin: 0 0 16px; border-bottom: 1px solid #CCCCCC; padding-bottom: 16px;">
        New Order Created
    </h1>

    <h2 style="font-size: 16px; font-weight: bold; margin: 20px 0 15px;">
        Dear {{ $recipientName }},
    </h2>

    <p style="font-size: 14px; margin-bottom: 20px;">
        A new order has been created in the system.
    </p>

    <!-- Order Details -->
    <div style="background-color: #F9FAFB; padding: 20px; border-radius: 8px; border: 1px solid #E5E7EB; margin-bottom: 20px;">
        <h3 style="font-size: 15px; margin: 0 0 12px; color: #111827; border-bottom: 1px solid #E5E7EB; padding-bottom: 8px;">Order Details</h3>
        <table width="100%" style="font-size: 14px; border-collapse: collapse;">
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;" width="35%">Order ID:</td>
                <td style="padding: 6px 0; color: #111827;">#{{ $order->id }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Property Address:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $order->property_address ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Property Location:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $order->property_location ?? 'N/A' }}</td>
            </tr>
            @if($recipientRole !== 'vendor')
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Order Status:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $order->order_status ?? 'Processing' }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Payment Status:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $order->payment_status ?? 'UNPAID' }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Total Amount:</td>
                <td style="padding: 6px 0; color: #111827; font-weight: bold;">${{ number_format($order->amount, 2) }}</td>
            </tr>
            @endif
        </table>
    </div>

    @if($recipientRole !== 'vendor' && $order->agent)
    <!-- Agent Details -->
    <div style="background-color: #F9FAFB; padding: 20px; border-radius: 8px; border: 1px solid #E5E7EB; margin-bottom: 20px;">
        <h3 style="font-size: 15px; margin: 0 0 12px; color: #111827; border-bottom: 1px solid #E5E7EB; padding-bottom: 8px;">Agent Information</h3>
        <table width="100%" style="font-size: 14px; border-collapse: collapse;">
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;" width="35%">Name:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $order->agent->first_name }} {{ $order->agent->last_name }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Email:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $order->agent->email }}</td>
            </tr>
            @if($order->agent->primary_phone)
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Phone:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $order->agent->primary_phone }}</td>
            </tr>
            @endif
        </table>
    </div>
    @endif

    @if(!empty($services) && count($services) > 0)
    <!-- Services Details -->
    <div style="background-color: #F9FAFB; padding: 20px; border-radius: 8px; border: 1px solid #E5E7EB; margin-bottom: 20px;">
        <h3 style="font-size: 15px; margin: 0 0 12px; color: #111827; border-bottom: 1px solid #E5E7EB; padding-bottom: 8px;">Services Ordered</h3>
        <table width="100%" style="font-size: 14px; border-collapse: collapse;">
            @foreach($services as $service)
            <tr>
                <td style="padding: 8px 0; border-bottom: 1px solid #E5E7EB; color: #111827;">
                    {{ $service->service->name ?? 'Service' }}
                    @if($service->option)
                        - {{ $service->option->name }}
                    @endif
                </td>
                @if($recipientRole !== 'vendor')
                <td style="padding: 8px 0; border-bottom: 1px solid #E5E7EB; text-align: right; color: #111827;">
                    ${{ number_format($service->amount, 2) }}
                </td>
                @endif
            </tr>
            @endforeach
        </table>
    </div>
    @endif

    @if($recipientRole !== 'vendor' && $order->slots && $order->slots->count() > 0)
    <!-- Scheduled Slots -->
    <div style="background-color: #F9FAFB; padding: 20px; border-radius: 8px; border: 1px solid #E5E7EB; margin-bottom: 20px;">
        <h3 style="font-size: 15px; margin: 0 0 12px; color: #111827; border-bottom: 1px solid #E5E7EB; padding-bottom: 8px;">Scheduled Appointments</h3>
        @foreach($order->slots as $slot)
        <div style="padding: 8px 0; border-bottom: 1px solid #E5E7EB; font-size: 14px;">
            <p style="margin: 0 0 4px; font-weight: bold; color: #111827;">{{ $slot->service->name ?? 'Service' }}</p>
            <p style="margin: 0 0 2px; color: #4B5563;">Date: {{ \Carbon\Carbon::parse($slot->date)->format('F j, Y') }}</p>
            <p style="margin: 0 0 2px; color: #4B5563;">Time: {{ $slot->start_time }} - {{ $slot->end_time }}</p>
            @if($slot->vendor)
            <p style="margin: 0; color: #4B5563;">Vendor: {{ $slot->vendor->first_name }} {{ $slot->vendor->last_name }}</p>
            @endif
        </div>
        @endforeach
    </div>
    @endif

    @if($showInternalNotes && $order->notes)
    <!-- Internal Notes (Admin Only) -->
    <div style="background-color: #FEF3C7; padding: 20px; border-radius: 8px; border: 1px solid #FDE68A; margin-bottom: 20px;">
        <h3 style="font-size: 15px; margin: 0 0 12px; color: #92400E; border-bottom: 1px solid #FDE68A; padding-bottom: 8px;">Order Notes (Internal)</h3>
        <div style="font-size: 13px; color: #78350F;">
            @php
                $notes = is_string($order->notes) ? json_decode($order->notes, true) : $order->notes;
            @endphp
            @if(is_array($notes))
                @foreach($notes as $note)
                    <div style="margin-bottom: 8px; padding-bottom: 4px; border-bottom: 1px solid rgba(146, 64, 14, 0.1);">
                        @if(isset($note['name']))
                            <strong>{{ $note['name'] }}:</strong>
                        @endif
                        {{ $note['note'] ?? '' }}
                        @if(isset($note['date']))
                            <br><small style="color: #B45309;">{{ \Carbon\Carbon::parse($note['date'])->format('M j, Y') }}</small>
                        @endif
                    </div>
                @endforeach
            @else
                {{ $order->notes }}
            @endif
        </div>
    </div>
    @endif
@endsection
