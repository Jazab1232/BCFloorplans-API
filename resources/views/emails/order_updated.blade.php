@extends('emails.layouts.master')

@section('content')
    <h1 style="font-size: 20px; font-weight: normal; margin: 0 0 16px; border-bottom: 1px solid #CCCCCC; padding-bottom: 16px;">
        Order Updated
    </h1>

    <h2 style="font-size: 16px; font-weight: bold; margin: 20px 0 15px;">
        Dear {{ $recipientName }},
    </h2>

    <p style="font-size: 14px; margin-bottom: 20px;">
        Please note that order #{{ $order->id }} has been updated.
    </p>

    @if(!empty($changes))
    <div style="background-color: #FEF3C7; padding: 15px; border-radius: 8px; border: 1px solid #FDE68A; margin-bottom: 20px; font-size: 14px;">
        <strong style="color: #92400E;">Changes Summary:</strong>
        <ul style="margin: 8px 0 0 20px; padding: 0; color: #78350F;">
            @foreach($changes as $change)
                <li>{{ $change }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    <!-- Details -->
    <div style="background-color: #F9FAFB; padding: 20px; border-radius: 8px; border: 1px solid #E5E7EB; margin-bottom: 20px;">
        <h3 style="font-size: 15px; margin: 0 0 12px; color: #111827; border-bottom: 1px solid #E5E7EB; padding-bottom: 8px;">Current Order Status</h3>
        <table width="100%" style="font-size: 14px; border-collapse: collapse;">
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;" width="35%">Order ID:</td>
                <td style="padding: 6px 0; color: #111827;">#{{ $order->id }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Property Address:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $order->property_address ?? 'N/A' }}</td>
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

    @php
        $orderMeta = is_string($order->meta) ? json_decode($order->meta, true) : ($order->meta ?? []);
        $floorplanAreas = $orderMeta['floorplan_areas'] ?? [];
        $floorplanNotes = $orderMeta['floorplan_notes'] ?? '';
        $orderHasFloorplan = $order->services->contains(function ($s) {
            $sName = strtolower($s->service?->name ?? '');
            $cName = strtolower($s->service?->category?->name ?? '');
            return str_contains($sName, 'floor') || str_contains($cName, 'floor');
        });
    @endphp

    @if($orderHasFloorplan && (!empty($floorplanAreas) || !empty($floorplanNotes)))
    <!-- Floor Plan Areas to Measure -->
    <div style="background-color: #EFF6FF; padding: 18px; border-radius: 8px; border: 1px solid #BFDBFE; margin-bottom: 20px;">
        <h3 style="font-size: 15px; margin: 0 0 10px; color: #1E40AF; border-bottom: 1px solid #DBEAFE; padding-bottom: 8px;">
            📐 Floor Plan - Other Areas to Measure
        </h3>
        @if(!empty($floorplanAreas))
            <p style="margin: 0 0 6px; font-size: 14px; color: #1E3A8A;">
                <strong>Requested Areas:</strong> 
                {{ is_array($floorplanAreas) ? implode(', ', $floorplanAreas) : $floorplanAreas }}
            </p>
        @endif
        @if(!empty($floorplanNotes))
            <p style="margin: 0; font-size: 14px; color: #1E3A8A;">
                <strong>Special Instructions:</strong> {{ $floorplanNotes }}
            </p>
        @endif
    </div>
    @endif
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
