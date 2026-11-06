@extends('emails.layouts.master')

@section('content')
    <h1 style="font-size: 20px; font-weight: normal; margin: 0 0 16px; border-bottom: 1px solid #CCCCCC; padding-bottom: 16px;">
        Appointment Scheduled
    </h1>

    <h2 style="font-size: 16px; font-weight: bold; margin: 20px 0 15px;">
        Dear {{ $recipientName }},
    </h2>

    <p style="font-size: 14px; margin-bottom: 20px;">
        An appointment has been successfully scheduled for the service in order #{{ $slot->order->id }}.
    </p>

    <div style="background-color: #F9FAFB; padding: 20px; border-radius: 8px; border: 1px solid #E5E7EB; margin-bottom: 25px;">
        <table width="100%" style="font-size: 14px; border-collapse: collapse;">
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;" width="35%">Service:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $slot->service ? $slot->service->name : 'Service' }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Date:</td>
                <td style="padding: 6px 0; color: #111827;">{{ \Carbon\Carbon::parse($slot->date)->format('F j, Y') }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Time:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $slot->start_time }} - {{ $slot->end_time }}</td>
            </tr>
            <tr>
                <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Property Address:</td>
                <td style="padding: 6px 0; color: #111827;">{{ $slot->order->property_address ?? 'N/A' }}</td>
            </tr>
            @if($recipientRole !== 'vendor')
                @if($slot->vendor)
                    <tr>
                        <td style="padding: 6px 0; color: #4B5563; font-weight: bold;">Vendor:</td>
                        <td style="padding: 6px 0; color: #111827;">{{ $slot->vendor->first_name }} {{ $slot->vendor->last_name }}</td>
                    </tr>
                @endif
            @endif
        </table>
    </div>

    @php
        $orderMeta = is_string($slot->order?->meta) ? json_decode($slot->order->meta, true) : ($slot->order?->meta ?? []);
        $floorplanAreas = $orderMeta['floorplan_areas'] ?? [];
        $floorplanNotes = $orderMeta['floorplan_notes'] ?? '';
        $sName = strtolower($slot->service->name ?? '');
        $cName = strtolower($slot->service->category?->name ?? '');
        $isFloorplanSlot = str_contains($sName, 'floor') || str_contains($cName, 'floor');
    @endphp

    @if($isFloorplanSlot && (!empty($floorplanAreas) || !empty($floorplanNotes)))
    <!-- Floor Plan Areas to Measure -->
    <div style="background-color: #EFF6FF; padding: 18px; border-radius: 8px; border: 1px solid #BFDBFE; margin-bottom: 25px;">
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
@endsection
