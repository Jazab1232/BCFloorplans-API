<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Agent;
use App\Models\SubAccount;
use App\Models\Vendor;
use App\Models\Organization;
use App\Models\OrganizationDomain;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users,email',
                'password' => 'required|string|min:8|confirmed',
            ]);

            $data['password'] = bcrypt($data['password']);
            $user = User::create($data);

            return response()->json([
                'status' => true,
                'message' => 'User registered successfully',
                'data' => $user
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Registration error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Registration failed',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function loginForm(): JsonResponse
    {
        return response()->json([
            'status' => true,
            'expired' => true,
            'message' => 'Invalid token'
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        try {
            $credentials = $request->validate([
                'email' => 'required|string|email',
                'password' => 'required|string',
                'role' => 'nullable|in:admin,agent,vendor',
                'organization_id' => 'nullable',
                'domain' => 'nullable|string'
            ]);

            // Try User login first
            if ($credentials['role'] === 'admin' || !isset($credentials['role'])) {
                $user = User::where('email', $credentials['email'])->first();
                if ($user && Hash::check($credentials['password'], $user->password)) {
                    if (!$this->validateOrganizationAccess($user, $request)) {
                        return response()->json([
                            'status' => false,
                            'message' => 'Unauthorized: You do not have access to this portal.'
                        ], 401);
                    }

                    $token = $user->createToken('user_auth_token')->accessToken;

                    return response()->json([
                        'status' => true,
                        'message' => 'User login successful',
                        'data' => [
                            'token' => $token,
                            'user' => $user->load('organization'),
                            'type' => 'user'
                        ]
                    ]);
                }
            }

            // Try Agent login
            if ($credentials['role'] === 'agent' || !isset($credentials['role'])) {
                $agent = Agent::where('email', $credentials['email'])->first();
                $isCoAgent = false;
                if (!$agent) {
                    $agent = SubAccount::where('primary_email', $credentials['email'])->first();
                    $isCoAgent = (bool) $agent;
                }
                if ($agent && Hash::check($credentials['password'], $agent->password)) {
                    if (!$this->validateOrganizationAccess($agent, $request)) {
                        return response()->json([
                            'status' => false,
                            'message' => 'Unauthorized: You do not have access to this portal.'
                        ], 401);
                    }

                    $token = $agent->createToken('agent_auth_token')->accessToken;

                    return response()->json([
                        'status' => true,
                        'message' => $isCoAgent ? 'Co-Agent login successful' : 'Agent login successful',
                        'data' => [
                            'token' => $token,
                            'user' => $agent->load('organization'),
                            'type' => $isCoAgent ? 'co_agent' : 'agent'
                        ]
                    ]);
                }
            }

            // Try Vendor login
            if ($credentials['role'] === 'vendor' || !isset($credentials['role'])) {
                $vendor = Vendor::where('email', $credentials['email'])->first();
                if ($vendor && Hash::check($credentials['password'], $vendor->password)) {
                    if (!$this->validateOrganizationAccess($vendor, $request)) {
                        return response()->json([
                            'status' => false,
                            'message' => 'Unauthorized: You do not have access to this portal.'
                        ], 401);
                    }

                    $token = $vendor->createToken('vendor_auth_token')->accessToken;

                    return response()->json([
                        'status' => true,
                        'message' => 'Vendor login successful',
                        'data' => [
                            'token' => $token,
                            'user' => $vendor->load('organization'),
                            'type' => 'vendor'
                        ]
                    ]);
                }
            }
            // If no match
            return response()->json([
                'status' => false,
                'message' => 'Invalid credentials'
            ], 401);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Login failed',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'role' => 'nullable|in:admin,agent,vendor'
            ]);

            $email = $request->email;
            $role = $request->role;
            $user = null;
            $userType = null;

            // Try to find user in different tables based on role or search all
            if ($role === 'admin' || !$role) {
                $user = User::where('email', $email)->first();
                if ($user) {
                    $userType = 'admin';
                }
            }

            if (!$user && ($role === 'agent' || !$role)) {
                $user = Agent::where('email', $email)->first();
                if (!$user) {
                    $user = SubAccount::where('primary_email', $email)->first();
                }
                if ($user) {
                    $userType = 'agent';
                }
            }

            if (!$user && ($role === 'vendor' || !$role)) {
                $user = Vendor::where('email', $email)->first();
                if ($user) {
                    $userType = 'vendor';
                }
            }

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found with this email address',
                ], 404);
            }

            // Generate token and send notification with user type
            $token = Password::createToken($user);
            $user->notify(new ResetPasswordNotification($token, $userType));

            return response()->json([
                'status' => true,
                'message' => 'Password reset link sent to your email',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Forgot password error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to send reset link',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function resetPassword(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'email' => 'required|string|email',
                'password' => 'required|string|min:8|confirmed',
                'token' => 'required|string',
                'role' => 'nullable|in:admin,agent,vendor'
            ]);

            $email = $data['email'];
            $password = $data['password'];
            $token = $data['token'];
            $role = $data['role'] ?? null;
            $user = null;

            // Find user in appropriate table based on role
            if ($role === 'admin') {
                $user = User::where('email', $email)->first();
            } elseif ($role === 'agent') {
                $user = Agent::where('email', $email)->first() ?? SubAccount::where('primary_email', $email)->first();
            } elseif ($role === 'vendor') {
                $user = Vendor::where('email', $email)->first();
            } else {
                // If no role provided, search all tables in sequence
                $user = User::where('email', $email)->first();
                if (!$user) {
                    $user = Agent::where('email', $email)->first() ?? SubAccount::where('primary_email', $email)->first();
                }
                if (!$user) {
                    $user = Vendor::where('email', $email)->first();
                }
            }

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found',
                ], 404);
            }

            // Verify token and reset password
            $tokenValid = Password::getRepository()->exists($user, $token);

            if (!$tokenValid) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid or expired token',
                ], 400);
            }

            // Update password
            $user->password = bcrypt($password);
            $user->save();

            // Delete the token
            Password::getRepository()->delete($user);

            $token = $user->createToken('auth_token')->accessToken;

            return response()->json([
                'status' => true,
                'message' => 'Password reset successfully',
                'token' => $token,
                'data' => [
                    'token' => $token,
                    'user' => $user->load('organization'),
                    'type' => $role ?? ($user instanceof Agent ? 'agent' : ($user instanceof Vendor ? 'vendor' : 'user'))
                ]
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation error',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Reset password error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Password reset failed',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function profile(): JsonResponse
    {
        try {
            $user = auth()->user();
            return response()->json([
                'status' => true,
                'message' => 'Profile retrieved successfully',
                'data' => $user
            ]);
        } catch (\Exception $e) {
            Log::error('Profile error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve profile',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function refreshToken(): JsonResponse
    {
        try {
            $user = auth()->user();
            $user->tokens()->delete();
            $token = $user->createToken('auth_token')->accessToken;

            return response()->json([
                'status' => true,
                'message' => 'Token refreshed successfully',
                'data' => [
                    'token' => $token,
                    'user' => $user
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Refresh token error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Token refresh failed',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    public function logout(): JsonResponse
    {
        try {
            auth()->user()->tokens()->delete();
            return response()->json([
                'status' => true,
                'message' => 'User logged out successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Logout error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Logout failed',
                'error' => config('app.env') === 'production' ? null : $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validate if the user has access to the requested organization and domain.
     */
    private function validateOrganizationAccess($user, Request $request): bool
    {
        // Exception for Super Admin
        if ($user->organization_id === null) {
            return true;
        }

        $requestedOrgId = $request->input('organization_id');
        $requestedDomain = $request->input('domain');

        // FALLBACK: If no organization or domain is specified in the request,
        // allow the login to proceed through the default/system portal.
        // This provides a resilient fallback path if their whitelabel portal domain routing fails.
        if (!$requestedOrgId && !$requestedDomain) {
            return true;
        }

        // If it's not a super admin, they MUST provide an organization_id if logging in through an org portal
        if (!$requestedOrgId) {
            return false;
        }

        // Find the requested organization
        // Use conditional query to avoid Postgres UUID comparison errors
        $organization = Organization::when(is_numeric($requestedOrgId), function ($q) use ($requestedOrgId) {
            return $q->where('id', $requestedOrgId);
        }, function ($q) use ($requestedOrgId) {
            // Only check UUID if it looks like a UUID to avoid SQL errors in Postgres
            if (is_string($requestedOrgId) && \Illuminate\Support\Str::isUuid($requestedOrgId)) {
                return $q->where('uuid', $requestedOrgId);
            }
            // Return a query that will find nothing if it's neither numeric nor UUID
            return $q->whereRaw('1 = 0');
        })->first();

        if (!$organization) {
            return false;
        }

        // Verify the user belongs to the requested organization
        if ($user->organization_id != $organization->id) {
            return false;
        }

        // Verify domain if provided (optional for non-whitelabel orgs as per feedback)
        if ($requestedDomain) {
            $domainExists = OrganizationDomain::where('organization_id', $organization->id)
                ->where('domain', $requestedDomain)
                ->exists();

            if (!$domainExists) {
                // Also check if it's the organization's main domain field
                if ($organization->domain !== $requestedDomain) {
                    // Extract host if it's a full URL
                    $parsedUrl = parse_url($requestedDomain);
                    $host = $parsedUrl['host'] ?? $requestedDomain;

                    if ($organization->domain !== $host) {
                        // One last check in organization_domains with host
                        $domainExistsWithHost = OrganizationDomain::where('organization_id', $organization->id)
                            ->where('domain', $host)
                            ->exists();

                        if (!$domainExistsWithHost) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }
}