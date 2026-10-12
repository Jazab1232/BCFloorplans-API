<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Stripe\StripeClient;
use App\Models\Vendor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class VendorStripeConnectController extends Controller
{
    /**
     * Create or get Stripe Express account and return onboarding link.
     */
    public function createOrGetAccountLink(Request $request)
    {   
        $validator = Validator::make($request->all(), [
            'vendor_id' => 'nullable|string',
            'return_url' => 'nullable|url',
            'refresh_url' => 'nullable|url',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => $validator->errors()->first(),
                'message' => $validator->errors()->first(),
            ], 400);
        }

        $user = auth()->user();

        if ($user instanceof Vendor) {
            // Vendor connecting their own Stripe account
            if ($request->filled('vendor_id') && $request->vendor_id !== $user->uuid) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized to connect Stripe for another vendor.',
                    'message' => 'Unauthorized to connect Stripe for another vendor.',
                ], 403);
            }
            $vendor = $user;
        } elseif ($user) {
            // Admin (or other authorized staff) connecting on behalf of a vendor
            if (!$request->filled('vendor_id')) {
                return response()->json([
                    'success' => false,
                    'error' => 'vendor_id is required when connecting Stripe on behalf of a vendor.',
                    'message' => 'vendor_id is required when connecting Stripe on behalf of a vendor.',
                ], 400);
            }

            $vendor = is_numeric($request->vendor_id)
                ? Vendor::find((int) $request->vendor_id)
                : Vendor::where('uuid', $request->vendor_id)->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'error' => 'Vendor not found.',
                    'message' => 'Vendor not found.',
                ], 404);
            }
        } else {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized.',
                'message' => 'Unauthorized.',
            ], 401);
        }

        $stripeKey = config('services.stripe.secret');
        if (empty($stripeKey)) {
            Log::error('Stripe secret key is not configured in services.stripe.secret.');
            return response()->json([
                'success' => false,
                'error' => 'Stripe secret key is not configured.',
                'message' => 'Stripe secret key is not configured.',
            ], 500);
        }

        $stripe = new StripeClient($stripeKey);

        try {
            // Create a new Stripe Express account if not exists
            if (empty($vendor->stripe_account_id)) {
                $accountParams = [
                    'type' => 'express',
                    'business_type' => 'individual', // or company
                    'capabilities' => [
                        'transfers' => ['requested' => true],
                    ],
                ];

                if (!empty($vendor->email)) {
                    $accountParams['email'] = $vendor->email;
                }

                $account = $stripe->accounts->create($accountParams);

                $vendor->stripe_account_id = $account->id;
                $vendor->save();
            }

            $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000'));
            $returnUrl = $request->get('return_url') ?: (rtrim($frontendUrl, '/') . '/dashboard/global-settings?stripe=success');
            $refreshUrl = $request->get('refresh_url') ?: url('/api/stripe/onboard/refresh');

            // Generate an onboarding link
            $accountLink = $stripe->accountLinks->create([
                'account' => $vendor->stripe_account_id,
                'refresh_url' => $refreshUrl,
                'return_url' => $returnUrl,
                'type' => 'account_onboarding',
            ]);

            return response()->json([
                'success' => true,
                'url' => $accountLink->url,
            ]);

        } catch (\Exception $e) {
            Log::error('Stripe onboarding error for vendor ' . $vendor->uuid . ': ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Stripe onboarding failed: ' . $e->getMessage(),
                'message' => 'Stripe onboarding failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Stripe redirects here after successful onboarding.
     */
    public function onboardingSuccess()
    {
        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')) . '/dashboard/global-settings?stripe=success';

        return redirect()->away($frontendUrl);
    }

    public function onboardingRefresh()
    {
        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000')) . '/dashboard/global-settings?stripe=refresh';

        return redirect()->away($frontendUrl);
    }
}
