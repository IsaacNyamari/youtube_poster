<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('tags')->nullable();

            $table->string('puzzle_hash', 64)->unique();
            $table->string('pattern', 32);
            $table->string('base_number', 16);
            $table->string('odd_number', 16);
            $table->unsignedTinyInteger('grid_rows');
            $table->unsignedTinyInteger('grid_cols');
            $table->unsignedTinyInteger('answer_row');
            $table->unsignedTinyInteger('answer_col');
            $table->json('style')->nullable();

            $table->foreignId('audio_track_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->decimal('duration', 6, 2)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->string('render_status', 20)->default('pending')->index();
            $table->text('render_error')->nullable();

            $table->string('publish_status', 20)->default('pending')->index();
            $table->timestamp('scheduled_at')->nullable()->unique();
            $table->timestamp('published_at')->nullable();
            $table->string('youtube_video_id')->nullable()->index();
            $table->text('upload_session_uri')->nullable();
            $table->unsignedSmallInteger('upload_attempts')->default(0);
            $table->text('publish_error')->nullable();

            $table->timestamps();

            $table->index(['publish_status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
