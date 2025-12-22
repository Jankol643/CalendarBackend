<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Services\AppLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Auth\Events\Registered; // Import Registered event
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Cache\RateLimiter;

class AuthController extends Controller {
    public function __construct() {
        $this->middleware('auth:api', ['except' => ['login', 'register']]);
        // Maximum of 10 requests within a 1-minute window
        $this->middleware('throttle:10,1')->only('login', 'register');
    }

    private function logPerformance($methodName, $startTime, $endTime) {
        $executionTime = ($endTime - $startTime) * 1000; // Convert to milliseconds
        AppLogger::info("Performance: Method '$methodName' executed in {$executionTime} ms.");
    }

    public function register(Request $request): JsonResponse {
        $startTime = microtime(true);

        // Sanitize email and password inputs explicitly
        $email = filter_var($request->input('email'), FILTER_SANITIZE_EMAIL);
        $password = $request->input('password');

        // Validate inputs
        $validator = Validator::make($request->all(), [
            'email' => 'required|string|email|max:255|unique:users',
            'password' => [
                'required',
                'string',
                'min:8',
                // password complexity validation rules
                // TODO: Add messages to frontend so users know the rules before choosing a password
                'regex:/[A-Z]/', // at least one uppercase
                'regex:/[a-z]/', // at least one lowercase
                'regex:/[0-9]/', // at least one number
                'regex:/[^A-Za-z0-9]/', // at least one special char
            ],
            'password_confirmation' => 'required|same:password',
        ]);

        if ($validator->fails()) {
            AppLogger::warning('Registration validation errors', $validator->errors()->all());
            return response()->json([
                'isSuccess' => false,
                'error_message' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::create([
                'email' => $email,
                'password' => Hash::make($password),
                'activation_code' => Str::random(60), // Generate activation code
                'activation_expiry' => now()->addMinutes(60), // Set expiry time
            ]);

            // Generate email verification token and send email
            $verificationUrl = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(60),
                ['id' => $user->id, 'hash' => sha1($user->email)]
            );

            //Mail::to($user->email)->send(new VerifyEmail($user, $verificationUrl));

            event(new Registered($user)); // Dispatch the Registered event
        } catch (\Exception $e) {
            // Log exception details for debugging
            AppLogger::error('User registration failed: ' . $e->getMessage());
            return response()->json(['isSuccess' => false, 'error_message' => 'Registration failed'], 500);
        }

        // Optionally generate token or send confirmation email
        AppLogger::info('User registered successfully for email: ' . $user->email);

        $endTime = microtime(true);
        $this->logPerformance('register', $startTime, $endTime);

        return response()->json(['message' => 'User registered successfully. Please check your email for verification.', 'user_id' => $user->id], 201);
    }

    public function login(Request $request): JsonResponse {
        $startTime = microtime(true);
        $this->validateLogin($request); // Validate input data before attempt

        $credentials = $request->only('email', 'password');
        $rateLimiter = app(RateLimiter::class);
        $key = 'login_attempts:' . $request->ip();

        // Check if user has exceeded the rate limit
        if ($rateLimiter->tooManyAttempts($key, 5)) {
            // Calculate retry after time
            $retryAfter = $rateLimiter->availableIn($key);
            return response()->json([
                'isSuccess' => false,
                'message' => 'Too many login attempts. Please wait ' . $retryAfter . ' seconds before retrying.',
                'retryAfter' => $retryAfter,
                'availableRetries' => 0 // No retries allowed until cooldown
            ], 429);
        }

        // Attempt login
        if (!$token = Auth::guard('api')->attempt($credentials)) {
            $rateLimiter->hit($key); // Increment attempted logins

            AppLogger::warning('Failed login attempt for email: ' . $credentials['email'] . ' from IP address ' . $_SERVER['REMOTE_ADDR']);

            $endTime = microtime(true);
            $this->logPerformance('login', $startTime, $endTime);

            // Calculate remaining retries
            $retryCount = 5 - $rateLimiter->attempts($key);
            return response()->json([
                'isSuccess' => false,
                'message' => 'Unauthorized',
                'availableRetries' => $retryCount
            ], 401);
        }

        $rateLimiter->clear($key); // Clear the attempts on successful login

        $endTime = microtime(true);
        $this->logPerformance('login', $startTime, $endTime);

        return response()->json([
            'isSuccess' => true,
            'authorisation' => ['token' => $token, 'type' => 'bearer'],
        ]);
    }


    private function validateLogin(Request $request) {
        $rules = [
            'email' => 'required|string|email',
            'password' => 'required|string',
        ];

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            AppLogger::warning('Login validation errors', $validator->errors()->all());
            throw new HttpException(400, json_encode(['isSuccess' => false, 'error_message' => $validator->errors()]));
        }
    }

    public function me(): JsonResponse {
        $startTime = microtime(true);
        $user = auth()->user();

        if (!$user) {
            throw new HttpException(401, 'Unauthorized');
        }

        $userData = [
            'id' => $user->id,
            'email' => $user->email,
        ];

        AppLogger::info('Retrieved user data', ['user_id' => $user->id]);

        $endTime = microtime(true);
        $this->logPerformance('me', $startTime, $endTime);

        return response()->json($userData);
    }

    public function logout(): JsonResponse {
        $startTime = microtime(true);
        $user = auth()->user();

        if (!$user) {
            return response()->json(['message' => 'No user to logout'], 400);
        }

        try {
            JWTAuth::invalidate(JWTAuth::getToken());
        } catch (\Exception $e) {
            AppLogger::error('JWT invalidation failed: ' . $e->getMessage());
            return response()->json(['message' => 'Logout failed'], 500);
        }

        AppLogger::info('Logging out user: ' . $user->email);
        Auth::logout();

        $endTime = microtime(true);
        $this->logPerformance('logout', $startTime, $endTime);

        return response()->json(['message' => 'Successfully logged out']);
    }

    public function refresh(): JsonResponse {
        $startTime = microtime(true);
        $token = JWTAuth::getToken();

        if (!$token) {
            return response()->json(['isSuccess' => false, 'message' => 'Token not provided'], 401);
        }

        try {
            if (JWTAuth::hasExpired()) {
                AppLogger::warning('Token expired, cannot refresh');
                return response()->json(['isSuccess' => false, 'message' => 'Token expired'], 401);
            }

            $newToken = JWTAuth::refresh($token);
        } catch (\Exception $e) {
            AppLogger::error('Token refresh failed: ' . $e->getMessage());
            return response()->json(['isSuccess' => false, 'message' => 'Token refresh failed'], 500);
        }

        $user = auth()->user();
        if (!$user) {
            return response()->json(['isSuccess' => false, 'message' => 'User not found'], 404);
        }

        AppLogger::info('Token refreshed successfully for user ID: ' . $user->id);

        $endTime = microtime(true);
        $this->logPerformance('refresh', $startTime, $endTime);

        return response()->json([
            'isSuccess' => true,
            'authorisation' => ['token' => $newToken, 'type' => 'bearer'],
        ]);
    }
}
