<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Str;

class UserSeeder extends Seeder {
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run() {
        User::create([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_admin' => 0,
            'is_active' => 1,
            'activation_code' => Str::random(40),
            'activation_expiry' => now()->addDays(7),
            'activated_at' => now(),
        ]);
    }
}
