<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthController extends Controller {
    public function __construct() {
        $this->middleware('auth:api', ['except' => ['login', 'register']]);
    }

    private function logPerformance($methodName, $startTime, $endTime) {
        $executionTime = $endTime - $startTime;
        Log::info("Performance: Method '$methodName' executed in $executionTime seconds.");
    }

    public function register(Request $request): JsonResponse {
        $startTime = microtime(true);
        Log::info('User registration request received for email: ' . $request->email);

        $request->validate([
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        Log::info('User registered successfully for email: ' . $user->email);

        $endTime = microtime(true);
        $this->logPerformance('register', $startTime, $endTime);

        return response()->json(['message' => 'User registered successfully'], 201);
    }

    public function login(Request $request): JsonResponse {
        $startTime = microtime(true);
        // Minimal logging for speed
        // $this->validateLogin($request); // Keep validation

        $credentials = $request->only('email', 'password');

        // Use attempt() directly with a timeout or lockout mechanism
        if (!$token = Auth::guard('api')->attempt($credentials)) {
            // Optional: implement rate limiting here
            $endTime = microtime(true);
            $this->logPerformance('login', $startTime, $endTime);
            return response()->json(['isSuccess' => false, 'message' => 'Unauthorized'], 401);
        }

        $endTime = microtime(true);
        $this->logPerformance('login', $startTime, $endTime);
        // Return token immediately
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
            Log::warning('Login validation errors', $validator->errors()->all());
            throw new HttpException(400, json_encode(['isSuccess' => false, 'error_message' => $validator->errors()]));
        }
    }

    public function me(): JsonResponse {
        $startTime = microtime(true);
        $user = auth()->user();
        Log::info('Retrieved user data', ['user_id' => $user->id]);

        $endTime = microtime(true);
        $this->logPerformance('me', $startTime, $endTime);

        return response()->json($user);
    }

    public function logout(): JsonResponse {
        $startTime = microtime(true);
        Log::info('Logging out user: ' . auth()->user()->email);
        Auth::logout();

        $endTime = microtime(true);
        $this->logPerformance('logout', $startTime, $endTime);

        return response()->json(['message' => 'Successfully logged out']);
    }

    public function refresh(string $token): JsonResponse {
        $startTime = microtime(true);
        if (!Auth::check()) { // User is not logged in
            Log::warning('Unauthorized refresh attempt from IP: ' . request()->ip());
            return response()->json(['isSuccess' => false, 'message' => 'Unauthorized'], 401);
        }
        if (Auth()->user->token_expired($token)) {
            Log::warning('Token expired for user: ' . auth()->user()->email);
            return response()->json(['isSuccess' => false, 'message' => 'Token expired'], 401);
        }

        $newToken = Auth::refresh(true);
        Log::info('Token refreshed successfully for user ID: ' . auth()->id());

        $endTime = microtime(true);
        $this->logPerformance('refresh', $startTime, $endTime);

        return response()->json([
            'isSuccess' => true,
            'authorisation' => ['token' => $newToken, 'type' => 'bearer'],
        ]);
    }
}
