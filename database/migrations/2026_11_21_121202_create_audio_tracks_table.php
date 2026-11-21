<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audio_tracks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('original_name');
            $table->string('path')->unique();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->decimal('duration', 8, 2)->nullable();
            $table->string('license_type')->default('licensed');
            $table->string('license_note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('times_used')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_tracks');
    }
};
