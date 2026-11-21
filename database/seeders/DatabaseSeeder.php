<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Creates (or updates) the installation's administrator from ADMIN_NAME / ADMIN_EMAIL / ADMIN_PASSWORD.
     * Every other user signs up (REGISTRATION_ENABLED=true) and gets an empty workspace; per-user settings
     * fall back to the defaults, so no further seeding is needed.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');

        if (! $email) {
            $this->command?->warn('ADMIN_EMAIL is not set; no administrator was created.');

            return;
        }

        $admin = User::query()->firstOrNew(['email' => $email]);

        $admin->name = env('ADMIN_NAME', 'Administrator');

        if (! $admin->exists && env('ADMIN_PASSWORD')) {
            $admin->password = env('ADMIN_PASSWORD');
        }

        $admin->email_verified_at ??= now();
        $admin->is_admin = true;
        $admin->save();

        $this->command?->info("Administrator ready: {$email}");
    }
}
