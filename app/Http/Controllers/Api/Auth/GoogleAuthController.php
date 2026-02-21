<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Socialite\Socialite;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller {
    public function login(Request $request) {
        try {
            // Validate the request
            $request->validate([
                'token' => 'required|string',
            ]);

            // Get Google user info using the access token
            // Correct method name: stateless() for API usage
            $googleUser = Socialite::driver('google')->user();

            if (!$googleUser) {
                return response()->json([
                    'isSuccess' => false,
                    'message' => 'Invalid Google token',
                ], 401);
            }

            // Find or create user
            $user = User::where('email', $googleUser->getEmail())->first();

            if (!$user) {
                // Create new user
                $user = User::create([
                    'name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'google_id' => $googleUser->getId(),
                    'password' => Hash::make(Str::random(24)), // Random password for Google users
                    'email_verified_at' => now(), // Google emails are verified
                ]);
            } else {
                // Update Google ID if not set
                if (!$user->google_id) {
                    $user->google_id = $googleUser->getId();
                    $user->save();
                }
            }

            // Generate JWT token - use Auth facade instead of auth() helper
            // Make sure you have JWT package installed (tymon/jwt-auth)
            $token = Auth::login($user);

            return response()->json([
                'isSuccess' => true,
                'authorisation' => [
                    'token' => $token,
                    'type' => 'bearer',
                    'expires_in' => Auth::factory()->getTTL() * 60 // Use Auth facade
                ],
                'user' => $user
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'isSuccess' => false,
                'message' => 'Google authentication failed',
                'error' => $e->getMessage()
            ], 401);
        }
    }
}
