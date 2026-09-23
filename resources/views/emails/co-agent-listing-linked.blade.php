@extends('emails.layouts.master')

@section('title', 'Added as Co-Agent on Listing')

@section('content')
    <h1 style="font-size: 20px; font-weight: bold; margin: 0 0 16px; color: #111827; border-bottom: 1px solid #E5E7EB; padding-bottom: 16px;">
        New Co-Listing Added
    </h1>

    <p style="font-size: 15px; margin-bottom: 16px;">
        Hello <strong>{{ $coAgent->first_name }}</strong>,
    </p>

    <p style="font-size: 14px; margin-bottom: 16px; color: #374151; line-height: 1.6;">
        @if($primaryAgent)
            <strong>{{ $primaryAgent->first_name }} {{ $primaryAgent->last_name }}</strong> has added you as a co-listing agent
            @if($propertyAddress) for the property at <strong>{{ $propertyAddress }}</strong>@endif.
        @else
            You have been added as a co-listing agent
            @if($propertyAddress) for the property at <strong>{{ $propertyAddress }}</strong>@endif.
        @endif
    </p>

    <p style="font-size: 14px; margin-bottom: 24px; color: #374151; line-height: 1.6;">
        This listing is now visible in your agent dashboard. You can view property details, download media, and view billing information directly.
    </p>

    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ $portalUrl }}" style="display: inline-block; background-color: #DC9600; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 6px; font-weight: bold; font-size: 15px;">
            View in Agent Portal
        </a>
    </div>
@endsection
