<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\Agent;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PaymentMethodController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            $user = auth()->user();

            // Use the scope methods from our updated model
            if ($user instanceof Agent) {
                $paymentMethods = PaymentMethod::forAgent($user->id)->get();
            } else if ($user instanceof Vendor) {
                $paymentMethods = PaymentMethod::forVendor($user->id)->get();
            } else if ($user instanceof User) {
                $paymentMethods = PaymentMethod::forUser($user->id)->get();
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized user type'
                ], 403);
            }
            
            return response()->json([
                'status' => true,
                'message' => 'Payment methods retrieved successfully',
                'data' => $paymentMethods
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving payment methods: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve payment methods',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            $validator = Validator::make($request->all(), [
                'type' => 'required|in:visa,mastercard,amex',
                'last_four' => 'required|digits:16',
                'cvv' => 'required|digits_between:3,4',
                'cardholder_name' => 'required|string|max:255',
                'is_primary' => 'sometimes|boolean',
                'expiry_date' => 'required|date_format:Y-m',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();

            if ($user instanceof Agent) {
                $data['payable_type'] = Agent::class;
                $data['payable_id'] = $user->id;
            } else if ($user instanceof Vendor) {
                $data['payable_type'] = Vendor::class;
                $data['payable_id'] = $user->id;
            } else if ($user instanceof User) {
                $data['payable_type'] = User::class;
                $data['payable_id'] = $user->id;
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized user type'
                ], 403);
            }

            // If setting as primary, remove primary status from other payment methods
            if (isset($data['is_primary']) && $data['is_primary']) {
                PaymentMethod::where('payable_type', $data['payable_type'])
                            ->where('payable_id', $data['payable_id'])
                            ->where('is_primary', true)
                            ->update(['is_primary' => false]);
            }

            $paymentMethod = PaymentMethod::create($data);

            return response()->json([
                'status' => true,
                'message' => 'Payment method created successfully',
                'data' => $paymentMethod
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating payment method: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to create payment method',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function show($uuid): JsonResponse
    {
        try {
            $user = auth()->user();
            $paymentMethod = PaymentMethod::where('uuid', $uuid)->firstOrFail();

            if (($user instanceof Agent && $paymentMethod->payable_type !== Agent::class) ||
                ($user instanceof Vendor && $paymentMethod->payable_type !== Vendor::class) ||
                ($user instanceof User && $paymentMethod->payable_type !== User::class) ||
                $paymentMethod->payable_id !== $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized to view this payment method'
                ], 403);
            }

            return response()->json([
                'status' => true,
                'message' => 'Payment method retrieved successfully',
                'data' => $paymentMethod
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Payment method not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error retrieving payment method: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve payment method',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $uuid): JsonResponse
    {
        try {
            $user = auth()->user();
            $paymentMethod = PaymentMethod::where('uuid', $uuid)->firstOrFail();

            if (($user instanceof Agent && $paymentMethod->payable_type !== Agent::class) ||
                ($user instanceof Vendor && $paymentMethod->payable_type !== Vendor::class) ||
                ($user instanceof User && $paymentMethod->payable_type !== User::class) ||
                $paymentMethod->payable_id !== $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized to update this payment method'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'type' => 'sometimes|in:visa,mastercard,amex',
                'last_four' => 'sometimes|digits:16',
                'cvv' => 'sometimes',
                'cardholder_name' => 'sometimes|string|max:255',
                'is_primary' => 'sometimes|boolean',
                'expiry_date' => 'sometimes|date_format:Y-m',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();
            
            // If setting as primary, remove primary status from other payment methods
            if (isset($data['is_primary']) && $data['is_primary']) {
                PaymentMethod::where('payable_type', $paymentMethod->payable_type)
                            ->where('payable_id', $paymentMethod->payable_id)
                            ->where('is_primary', true)
                            ->where('uuid', '!=', $uuid)
                            ->update(['is_primary' => false]);
            }

            $paymentMethod->update($data);

            return response()->json([
                'status' => true,
                'message' => 'Payment method updated successfully',
                'data' => $paymentMethod
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Payment method not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating payment method: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to update payment method',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function destroy($uuid): JsonResponse
    {
        try {
            $user = auth()->user();
            $paymentMethod = PaymentMethod::where('uuid', $uuid)->firstOrFail();

            if (($user instanceof Agent && $paymentMethod->payable_type !== Agent::class) ||
                ($user instanceof Vendor && $paymentMethod->payable_type !== Vendor::class) ||
                ($user instanceof User && $paymentMethod->payable_type !== User::class) ||
                $paymentMethod->payable_id !== $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized to delete this payment method'
                ], 403);
            }
            
            $paymentMethod->delete();

            return response()->json([
                'status' => true,
                'message' => 'Payment method deleted successfully'
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Payment method not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting payment method: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete payment method',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }
}