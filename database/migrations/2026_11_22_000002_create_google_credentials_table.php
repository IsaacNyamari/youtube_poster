<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each user supplies their own Google Cloud OAuth client, so every user has their own API quota.
 * The single shared client that v1.0.0 read from .env is copied to the first user so their channel keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('client_id');
            $table->text('client_secret');
            $table->string('project_id')->nullable();
            $table->timestamps();
        });

        $ownerId = DB::table('users')->where('is_admin', true)->min('id') ?? DB::table('users')->min('id');
        $clientId = config('services.google.client_id');
        $clientSecret = config('services.google.client_secret');

        if ($ownerId !== null && filled($clientId) && filled($clientSecret)) {
            DB::table('google_credentials')->insert([
                'user_id' => $ownerId,
                'client_id' => $clientId,
                'client_secret' => encrypt($clientSecret, false),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // The YouTube quota now belongs to each user's own Google project, so the shared quota flag is obsolete.
        DB::table('shorts_settings')->where('key', 'quota_blocked_until')->delete();
    }

    public function down(): void
    {
        Schema::dropIfExists('google_credentials');
    }
};
