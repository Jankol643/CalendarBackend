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
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller {
    // Cache TTLs
    private const USER_CACHE_TTL = 300; // 5 minutes
    private const RATE_LIMIT_TTL = 60; // 1 minute

    // Max login attempts
    private const MAX_LOGIN_ATTEMPTS = 10;

    // Middleware to apply rate limiting based on environment
    public function __construct() {
        $this->middleware('auth:api', ['except' => ['login', 'register']]);

        if ($this->isLocalEnvironment()) {
            AppLogger::info('Running in local environment - relaxed rate limits');
            $this->middleware('throttle:30,1')->only(['login', 'register']);
        } else {
            $this->middleware('throttle:' . self::MAX_LOGIN_ATTEMPTS . ',' . self::RATE_LIMIT_TTL)->only('login');
            $this->middleware('throttle:10,1')->only('register');
        }
    }

    /**
     * Determine if current environment is local or testing
     */
    private function isLocalEnvironment(): bool {
        return in_array(app()->environment(), ['local', 'testing']);
    }

    /**
     * Log performance metrics
     */
    private function logPerformance(string $methodName, float $startTime, float $endTime): void {
        $executionTimeMs = ($endTime - $startTime) * 1000;
        $thresholdMs = $this->isLocalEnvironment() ? 2000 : 1000;

        if ($executionTimeMs > $thresholdMs) {
            AppLogger::warning("Performance Warning: {$methodName} took {$executionTimeMs} ms");
        } else {
            AppLogger::debug("Performance: {$methodName} executed in {$executionTimeMs} ms");
        }
    }

    /**
     * Register a new user
     */
    public function register(Request $request): JsonResponse {
        $startTime = microtime(true);
        AppLogger::info('Registration started', ['ip' => $request->ip()]);

        $passwordRules = $this->isLocalEnvironment()
            ? ['required', 'string', 'min:6']
            : [
                'required',
                'string',
                'min:8',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                'regex:/[^A-Za-z0-9]/',
            ];

        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255|unique:users',
            'password' => $passwordRules,
            'password_confirmation' => 'required|same:password',
        ]);

        if ($validator->fails()) {
            AppLogger::warning('Registration validation failed', [
                'errors' => $validator->errors()->all(),
                'ip' => $request->ip(),
                'email' => $request->input('email')
            ]);
            return response()->json([
                'isSuccess' => false,
                'error_message' => $validator->errors()
            ], 422);
        }

        $email = filter_var($request->input('email'), FILTER_SANITIZE_EMAIL);
        $password = $request->input('password');

        try {
            DB::beginTransaction();

            $user = User::create([
                'email' => $email,
                'password' => Hash::make($password),
                'activation_code' => Str::random(60),
                'activation_expiry' => now()->addMinutes(60),
            ]);

            if (!$this->isLocalEnvironment()) {
                // Queue verification email
                // Mail::to($user->email)->queue(new VerifyEmail($user));
            } else {
                // Auto-activate in local
                $user->update([
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]);
                AppLogger::info('Local environment: auto-activated user', ['user_id' => $user->id]);
            }

            DB::afterCommit(function () use ($user) {
                event(new Registered($user));
            });

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            AppLogger::error('User registration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'email' => $email,
                'ip' => $request->ip()
            ]);
            return response()->json([
                'isSuccess' => false,
                'error_message' => $this->isLocalEnvironment()
                    ? 'Registration failed: ' . $e->getMessage()
                    : 'Registration failed'
            ], 500);
        }

        AppLogger::info('User registered successfully', [
            'email' => $user->email,
            'user_id' => $user->id,
            'environment' => app()->environment()
        ]);

        $endTime = microtime(true);
        $this->logPerformance('register', $startTime, $endTime);

        return response()->json([
            'message' => $this->isLocalEnvironment()
                ? 'User registered successfully. Account auto-activated for local testing.'
                : 'User registered successfully. Please verify your email.',
            'user_id' => $user->id,
            'auto_activated' => $this->isLocalEnvironment()
        ], 201);
    }

    /**
     * Login user and generate JWT token
     */
    public function login(Request $request): JsonResponse {
        $startTime = microtime(true);
        AppLogger::info('Login attempt started', [
            'ip' => $request->ip(),
            'email' => $request->input('email'),
            'environment' => app()->environment()
        ]);

        $this->validateLogin($request);

        $credentials = $request->only('email', 'password');
        $email = $credentials['email'];
        $ip = $request->ip();

        if (!$this->isLocalEnvironment()) {
            $rateLimiterKey = 'login_attempts:' . md5($ip . '|' . $email);
            if ($this->hasTooManyLoginAttempts($rateLimiterKey)) {
                $retryAfter = Cache::get($rateLimiterKey . ':timer', self::RATE_LIMIT_TTL);
                AppLogger::warning('Rate limit exceeded', ['email' => $email, 'ip' => $ip, 'retry_after' => $retryAfter]);
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Too many login attempts. Please wait ' . $retryAfter . ' seconds.',
                    'retryAfter' => $retryAfter,
                    'availableRetries' => 0
                ], 429);
            }
        }

        // Check if user exists and cache result
        $user = Cache::remember('user_exists:' . md5($email), self::USER_CACHE_TTL, function () use ($email) {
            return User::where('email', $email)
                ->select('id', 'email', 'password', 'is_active', 'email_verified_at')
                ->first();
        });

        if (!$user) {
            if (!$this->isLocalEnvironment()) {
                $this->incrementLoginAttempts($rateLimiterKey);
            }
            AppLogger::warning('Login failed: user not found', ['email' => $email, 'ip' => $ip]);
            return response()->json([
                'isSuccess' => false,
                'message' => 'Unauthorized',
                'availableRetries' => $this->isLocalEnvironment() ? 'unlimited' : $this->retriesLeft($rateLimiterKey)
            ], 401);
        }

        if (isset($user->is_active) && !$user->is_active) {
            AppLogger::warning('Inactive account login attempt', ['email' => $email, 'user_id' => $user->id]);
            return response()->json(['isSuccess' => false, 'message' => 'Account is not active'], 403);
        }

        if (!$this->isLocalEnvironment() && !$user->email_verified_at) {
            AppLogger::warning('Unverified email login attempt', ['email' => $email, 'user_id' => $user->id]);
            return response()->json(['isSuccess' => false, 'message' => 'Please verify your email before logging in'], 403);
        }

        // Attempt JWT login
        if (!JWTAuth::attempt($credentials)) {
            if (!$this->isLocalEnvironment()) {
                $this->incrementLoginAttempts($rateLimiterKey);
            }
            Cache::forget('user_exists:' . md5($email));
            AppLogger::warning('Invalid credentials', ['email' => $email, 'ip' => $ip]);
            return response()->json([
                'isSuccess' => false,
                'message' => 'Unauthorized - Invalid credentials',
                'availableRetries' => $this->isLocalEnvironment() ? 'unlimited' : $this->retriesLeft($rateLimiterKey)
            ], 401);
        }

        if (!$this->isLocalEnvironment()) {
            $this->clearLoginAttempts($rateLimiterKey);
        }

        // Cache user info
        Cache::put('auth_user:' . $user->id, [
            'id' => $user->id,
            'email' => $user->email,
        ], self::USER_CACHE_TTL);

        AppLogger::info('Login successful', ['email' => $user->email, 'user_id' => $user->id]);

        $endTime = microtime(true);
        $this->logPerformance('login', $startTime, $endTime);

        return response()->json([
            'isSuccess' => true,
            'authorisation' => [
                'token' => JWTAuth::attempt($credentials),
                'type' => 'bearer',
                'expires_in' => JWTAuth::factory()->getTTL() * 60,
            ],
            'environment' => app()->environment()
        ]);
    }

    private function validateLogin(Request $request): void {
        $rules = [
            'email' => 'required|email|max:255',
            'password' => $this->isLocalEnvironment() ? 'required|string|min:6' : 'required|string|min:8'
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            AppLogger::warning('Login validation errors', [
                'errors' => $validator->errors()->all(),
                'email' => $request->input('email'),
                'ip' => $request->ip(),
                'environment' => app()->environment()
            ]);
            throw new HttpException(400, json_encode([
                'isSuccess' => false,
                'error_message' => $validator->errors()
            ]));
        }
    }

    /**
     * Get current authenticated user
     */
    public function me(): JsonResponse {
        $startTime = microtime(true);
        AppLogger::debug('Fetching current user');

        $user = JWTAuth::user();
        if (!$user) {
            AppLogger::warning('Unauthorized access to /me');
            throw new HttpException(401, 'Unauthorized');
        }

        $userData = Cache::remember('auth_user:' . $user->id, self::USER_CACHE_TTL, function () use ($user) {
            return [
                'id' => $user->id,
                'email' => $user->email,
                // Add more user fields here if needed
            ];
        });

        AppLogger::info('User profile retrieved', ['user_id' => $user->id]);

        $endTime = microtime(true);
        $this->logPerformance('me', $startTime, $endTime);

        return response()->json($userData);
    }

    /**
     * Logout user
     */
    public function logout(): JsonResponse {
        $startTime = microtime(true);
        $user = JWTAuth::user();

        if (!$user) {
            AppLogger::warning('Logout attempt with no authenticated user');
            return response()->json(['message' => 'No user to logout'], 400);
        }

        try {
            Cache::forget('auth_user:' . $user->id);
            Cache::forget('user_exists:' . md5($user->email));

            $token = JWTAuth::getToken();
            if ($token) {
                JWTAuth::invalidate($token);
            }
        } catch (\Exception $e) {
            AppLogger::warning('JWT invalidation error', ['error' => $e->getMessage()]);
        }

        JWTAuth::logout();

        AppLogger::info('User logged out', ['user_id' => $user->id]);
        $endTime = microtime(true);
        $this->logPerformance('logout', $startTime, $endTime);

        return response()->json(['message' => 'Successfully logged out']);
    }

    /**
     * Refresh JWT token
     */
    public function refresh(): JsonResponse {
        $startTime = microtime(true);
        AppLogger::debug('Token refresh attempt');

        try {
            $newToken = JWTAuth::refresh();
        } catch (\Exception $e) {
            AppLogger::error('Token refresh failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['isSuccess' => false, 'message' => 'Token refresh failed'], 401);
        }

        $user = JWTAuth::user();
        if ($user) {
            Cache::put('auth_user:' . $user->id, [
                'id' => $user->id,
                'email' => $user->email,
            ], self::USER_CACHE_TTL);
            AppLogger::info('Token refreshed', ['user_id' => $user->id]);
        }

        $endTime = microtime(true);
        $this->logPerformance('refresh', $startTime, $endTime);

        return response()->json([
            'isSuccess' => true,
            'authorisation' => [
                'token' => $newToken,
                'type' => 'bearer',
                'expires_in' => JWTAuth::factory()->getTTL() * 60,
            ],
        ]);
    }

    /**
     * Rate limiting helpers
     */
    private function hasTooManyLoginAttempts(string $key): bool {
        if ($this->isLocalEnvironment()) {
            return false;
        }
        return Cache::get($key, 0) >= self::MAX_LOGIN_ATTEMPTS;
    }

    private function incrementLoginAttempts(string $key): void {
        if ($this->isLocalEnvironment()) {
            return;
        }

        Cache::add($key, 0, self::RATE_LIMIT_TTL);
        Cache::increment($key);
        if (!Cache::has($key . ':timer')) {
            Cache::put($key . ':timer', self::RATE_LIMIT_TTL, self::RATE_LIMIT_TTL);
        }
    }

    private function retriesLeft(string $key): int {
        if ($this->isLocalEnvironment()) {
            return PHP_INT_MAX;
        }
        $attempts = Cache::get($key, 0);
        return max(0, self::MAX_LOGIN_ATTEMPTS - $attempts);
    }

    private function clearLoginAttempts(string $key): void {
        if ($this->isLocalEnvironment()) {
            return;
        }
        Cache::forget($key);
        Cache::forget($key . ':timer');
    }
}
