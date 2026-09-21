<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Stripe\StripeClient;
use App\Models\Vendor;
use Illuminate\Support\Facades\Log;
use App\Models\Agent;
use Illuminate\Support\Facades\Validator;

class VendorStripeConnectController extends Controller
{
    /**
     * Create or get Stripe Express account and return onboarding link.
     */
    public function createOrGetAccountLink(Request $request)
    {   
        $validator = Validator::make($request->all(), [
            'vendor_id' => 'nullable|exists:vendors,uuid',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }
       
        // find vendor by uuid if provided
        if ($request->has('vendor_id')) {
            $vendor = Vendor::where('uuid', $request->vendor_id)->first();
        } else {
            return response()->json(['error' => 'vendor_id is required.'], 400);
        }

        $stripe = new StripeClient(config('services.stripe.secret'));

        try {
            //  Create a new account if not exists
            if (empty($vendor->stripe_account_id)) {
                $account = $stripe->accounts->create([
                    'type' => 'express',
                    'email' => $vendor->email,
                    'business_type' => 'individual', // or company
                    'capabilities' => [
                        'transfers' => ['requested' => true],
                    ],
                ]);

                $vendor->stripe_account_id = $account->id;
                $vendor->save();
            }

            //  Generate an onboarding link
            $accountLink = $stripe->accountLinks->create([
                'account' => $vendor->stripe_account_id,
                'refresh_url' => url('/api/stripe/onboard/refresh'),
                'return_url' => env("FRONTEND_URL", "http://localhost:3000") . "/dashboard/global-settings?stripe=success",
                'type' => 'account_onboarding',
            ]);

            return response()->json([
                'success' => true,
                'url' => $accountLink->url,
            ]);

        } catch (\Exception $e) {
            Log::error('Stripe onboarding error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Stripe onboarding failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Stripe redirects here after successful onboarding.
     */
    public function onboardingSuccess()
{
    $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000') . '/dashboard/global-settings?stripe=success';

    return redirect()->away($frontendUrl);
}

public function onboardingRefresh()
{
    $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000') . '/dashboard/global-settings?stripe=refresh';

    return redirect()->away($frontendUrl);
}

}
