<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Auth;

class User extends Authenticatable implements JWTSubject {
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'email',
        'password',
        'is_admin',
        'is_active',
        'activation_code',
        'activation_expiry',
        'activated_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'activation_expiry' => 'datetime',
        'activated_at' => 'datetime',
    ];

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier() {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims() {
        return [];
    }

    /**
     * Check if the user's token has expired.
     *
     * @return bool
     */
    public function isTokenExpired(?string $token = null): bool {
        // Get the current token expiration time
        if (!$token) {
            return true; // No token means it is considered expired
        }

        try {
            // Decode the token to get its payload
            $payload = (array) JWTAuth::setToken($token)->getPayload(); // Use JWTAuth to decode

            // Check if 'exp' (expiration time) exists and compare with current time
            return isset($payload['exp']) && $payload['exp'] < time();
        } catch (\Exception $e) {
            return true; // If decoding fails, consider token expired
        }
    }
}
