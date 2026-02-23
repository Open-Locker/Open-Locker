<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SendPasswordResetRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\ApiErrorResource;
use App\Http\Resources\TokenResponseResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\JsonResponse;

class AuthController extends Controller
{
    public function __construct(public readonly AuthService $authService) {}

    /**
     * Register
     *
     * @throws \Exception
     */
    public function register(Request $request): TokenResponseResource
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));

        return new TokenResponseResource($user);
    }

    /**
     * Verify Email Address
     *
     * @throws \Exception
     */
    public function verifyEmail(EmailVerificationRequest $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return (new ApiErrorResource([
                'message' => __('Email already verified'),
            ]))->response();
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        return response()->json([
            'message' => __('Email verified'),
        ]);
    }

    /**
     * Login
     *
     * @unauthenticated
     */
    public function login(LoginRequest $request): TokenResponseResource
    {
        $validated = $request->validated();

        if (! Auth::attempt($validated)) {
            throw ValidationException::withMessages(['email' => [__('The provided credentials are incorrect.')]]);
        }

        return new TokenResponseResource($request->user());

    }

    /**
     * Logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => __('Logged out successfully'),
        ]);
    }

    /**
     * Get current User
     */
    public function user(Request $request): UserResource
    {

        $user = $request->user();

        return new UserResource($user);
    }

    /**
     * Send Email Verification Notification
     */
    public function sendVerificationEmail(Request $request): JsonResponse
    {

        if ($request->user()->hasVerifiedEmail()) {
            return (new ApiErrorResource([
                'message' => __('Email already verified'),
            ]))->response();
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json([
            'message' => __('Email verification link sent'),
        ]);
    }

    /**
     * Send Password E-Mail
     */
    public function sendPasswordEmail(SendPasswordResetRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $status = $this->authService->sendResetLink($validated['email']);

        if ($status == Password::RESET_LINK_SENT) {
            return response()->json([
                'message' => __('Password reset link sent'),
            ]);
        }

        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }

    /**
     * Reset Password with Token
     */
    public function storeNewPassword(ResetPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = Password::reset(
            [
                'email' => $validated['email'],
                'password' => $validated['password'],
                'password_confirmation' => $validated['password_confirmation'],
                'token' => $validated['token'],
            ],
            function ($user) use ($validated) {
                $user->forceFill([
                    'password' => Hash::make($validated['password']),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        if ($status == Password::PASSWORD_RESET) {
            return response()->json([
                'message' => __($status),
            ]);
        }

        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }

    /**
     * Update the authenticated user's profile.
     */
    public function updateProfile(UpdateProfileRequest $request): UserResource
    {
        $validated = $request->validated();

        $user = $request->user();
        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);

        $emailChanged = $user->isDirty('email');
        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return new UserResource($user->fresh());
    }

    /**
     * Change the authenticated user's password.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $request->user();
        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'remember_token' => Str::random(60),
        ])->save();

        return response()->json([
            'message' => __('Password updated successfully'),
        ]);
    }
}
