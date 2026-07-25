<?php

namespace Modules\Auth\Http\Controllers;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Log;
use Illuminate\Auth\Notifications\ResetPassword;
use Modules\User\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Stripe\Stripe;
use Stripe\Customer;
use Modules\AccessControl\Models\Role;




class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100|min:3',
            'phone' => 'required|string|max:40|min:2|unique:users',
            'email' => 'required|string|email|max:100|unique:users',
            'password' => 'required|string|min:8|max:18|confirmed',
        ]);


        Stripe::setApiKey(config('services.stripe.secret'));

        $stripeCustomer = null;
        
        try {
            $stripeCustomer = Customer::create([
                'email' => $request->email,
                'name' => $request->name,
                'phone' => $request->phone,
                'metadata' => [
                    'user_type' => 'registered_user'
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Stripe customer creation failed during registration', [
                'email' => $request->email,
                'error' => $e->getMessage()
            ]);
        }

        $user = User::create([
            'name' => $request->name,
            'phone' => $request->phone,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'stripe_customer_id' => $stripeCustomer ? $stripeCustomer->id : null,
        ]);

        $userRole = Role::where('slug', 'user')->first();

        // Assign user role
        $user->roles()->sync([$userRole->id]);

       


        return response()->json([
            'status' => true,
            'verification_required' => false,
            'user' => $user,
            'access_token' => $user->createToken('auth_token')->plainTextToken,
            'token_type' => 'Bearer',
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email|max:100',
            'password' => 'required|string|min:8|max:18',
        ]);

        $loginData = [
            'email' => $request->email,
            'password' => $request->password,
        ];

        $loginSuccessful = Auth::attempt(['email' => $request->email, 'password' => $request->password]);
        
        // Log the login attempt
        // $this->logLoginAttempt($request, $user, $loginSuccessful);
        
        // Get user by email to log the attempt even if login fails
        $user = User::where('email', $request->email)->first();

        if (!$loginSuccessful) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        $user = Auth::user();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => true,
            'message' => 'User logged in successfully',
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }


    protected function logLoginAttempt(Request $request, $user, $successful)
    {
        try {
            $ip = $request->ip();
            
            // Simple location detection using IP-API (no package required)
            $location = null;
            if (config('app.env') === 'production') {
                $ipInfo = @json_decode(file_get_contents("http://ip-api.com/json/{$ip}?fields=status,message,country,city"));
                if ($ipInfo && $ipInfo->status === 'success') {
                    $location = $ipInfo->country;
                    if (!empty($ipInfo->city)) {
                        $location .= ', ' . $ipInfo->city;
                    }
                }
            }

            LoginLog::create([
                'user_id' => $user ? $user->id : null,
                'ip_address' => $ip,
                'user_agent' => $request->userAgent(),
                'login_at' => now(),
                'login_successful' => $successful,
                'session_id' => session()->getId(),
                'remember' => false,
                'location' => $location,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log login attempt: ' . $e->getMessage());
        }
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $user->load('roles:id,slug,name');
        // Expose an admin flag so the frontend can show admin-only settings.
        $user->setAttribute('is_admin', $user->hasRole('admin'));

        return $user;
    }

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        return response()->json(['message' => 'Logged out']);
    }

    /**
     * Email a password reset link. Always responds with a generic success
     * message so the endpoint can't be used to discover which emails exist.
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email|max:100',
        ]);

        // Point the reset link at the SPA (not the API), e.g.
        // https://frontend/reset-password?token=...&email=...
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $frontend = rtrim(config('app.frontend_url') ?: 'http://localhost:3000', '/');

            return $frontend . '/reset-password?token=' . $token
                . '&email=' . urlencode($user->getEmailForPasswordReset());
        });

        try {
            Password::sendResetLink($request->only('email'));
        } catch (\Throwable $e) {
            // Never surface mail/transport errors to the client — just log them.
            Log::error('Password reset link failed to send', [
                'email' => $request->email,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'If an account exists for that email, a password reset link is on its way.',
        ]);
    }

    /**
     * Reset the password using the emailed token.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|string|email|max:100',
            'password' => 'required|string|min:8|max:18|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'status' => true,
                'message' => 'Your password has been reset. You can now sign in.',
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => __($status),
        ], 422);
    }
}
