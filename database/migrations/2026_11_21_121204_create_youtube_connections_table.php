<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('youtube_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel_id');
            $table->string('channel_title');
            $table->string('channel_thumbnail')->nullable();
            $table->string('channel_handle')->nullable();
            $table->unsignedBigInteger('subscriber_count')->nullable();
            $table->unsignedBigInteger('video_count')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('needs_reauth')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_connections');
    }
};
