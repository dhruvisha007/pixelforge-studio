<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Local development only: a demo admin, password "password" (from UserFactory).
     */
    public function run(): void
    {
        $admin = User::factory()->create([
            'name' => 'Demo Admin',
            'email' => 'demo@example.com',
        ]);

        $admin->forceFill(['is_admin' => true])->save();
    }
}
