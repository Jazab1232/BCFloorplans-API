@extends('emails.layouts.master')

@section('title', 'Welcome as a Co-Agent')

@section('content')
    <h1 style="font-size: 20px; font-weight: bold; margin: 0 0 16px; color: #111827; border-bottom: 1px solid #E5E7EB; padding-bottom: 16px;">
        Welcome to the Agent Portal
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
        An account has been created for you. Set your password now to access property media downloads (high-res photos, floor plans, virtual tours), view listing details, and manage split invoices.
    </p>

    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ $setupUrl }}" style="display: inline-block; background-color: #DC9600; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 6px; font-weight: bold; font-size: 15px;">
            Set Your Password &amp; Get Started
        </a>
    </div>

    <div style="background-color: #F9FAFB; padding: 16px 20px; border-radius: 8px; border: 1px solid #E5E7EB; margin-bottom: 25px;">
        <p style="font-size: 13px; color: #6B7280; margin: 0 0 8px;">
            If the button above does not work, copy and paste this link into your browser:
        </p>
        <p style="font-size: 12px; color: #2563EB; word-break: break-all; margin: 0;">
            <a href="{{ $setupUrl }}" style="color: #2563EB;">{{ $setupUrl }}</a>
        </p>
    </div>
@endsection
