<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v1.0.1 multitenancy: every video, audio track and YouTube connection belongs to a user, and settings become per user.
 * Existing data (from the single-owner v1.0.0) is assigned to the first user, who becomes the administrator.
 */
return new class extends Migration
{
    /**
     * Settings that stay global (shared by the whole installation).
     *
     * @var list<string>
     */
    private array $globalKeys = ['deploy_branch', 'deploy_webhook_secret', 'quota_blocked_until'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email');
        });

        foreach (['audio_tracks', 'videos'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            });
        }

        $ownerId = DB::table('users')->min('id');

        if ($ownerId !== null) {
            DB::table('users')->where('id', $ownerId)->update(['is_admin' => true]);
            DB::table('audio_tracks')->whereNull('user_id')->update(['user_id' => $ownerId]);
            DB::table('videos')->whereNull('user_id')->update(['user_id' => $ownerId]);
        }

        foreach (['audio_tracks', 'videos'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable(false)->change();
            });
        }

        // Puzzle fingerprints and publishing slots are unique per user, not across the whole installation.
        Schema::table('videos', function (Blueprint $table) {
            $table->unique(['user_id', 'puzzle_hash'], 'videos_user_puzzle_unique');
            $table->unique(['user_id', 'scheduled_at'], 'videos_user_slot_unique');
        });

        Schema::table('videos', function (Blueprint $table) {
            $table->dropUnique('videos_puzzle_hash_unique');
            $table->dropUnique('videos_scheduled_at_unique');
        });

        Schema::table('youtube_connections', function (Blueprint $table) {
            $table->unique('user_id', 'youtube_connections_user_unique');
        });

        if (Schema::hasIndex('youtube_connections', 'youtube_connections_user_id_index')) {
            Schema::table('youtube_connections', function (Blueprint $table) {
                $table->dropIndex('youtube_connections_user_id_index');
            });
        }

        Schema::create('user_settings', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->timestamps();

            $table->primary(['user_id', 'key']);
        });

        if ($ownerId !== null) {
            $rows = DB::table('shorts_settings')->whereNotIn('key', $this->globalKeys)->get();

            foreach ($rows as $row) {
                DB::table('user_settings')->insert([
                    'user_id' => $ownerId,
                    'key' => $row->key,
                    'value' => $row->value,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            }

            DB::table('shorts_settings')->whereNotIn('key', $this->globalKeys)->delete();
        }
    }

    public function down(): void
    {
        $ownerId = DB::table('users')->where('is_admin', true)->min('id') ?? DB::table('users')->min('id');

        if ($ownerId !== null) {
            foreach (DB::table('user_settings')->where('user_id', $ownerId)->get() as $row) {
                DB::table('shorts_settings')->updateOrInsert(
                    ['key' => $row->key],
                    ['value' => $row->value, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at],
                );
            }
        }

        Schema::dropIfExists('user_settings');

        // MySQL will not drop an index that a foreign key still relies on, so keep a plain index first.
        Schema::table('youtube_connections', function (Blueprint $table) {
            $table->index('user_id', 'youtube_connections_user_id_index');
        });

        Schema::table('youtube_connections', function (Blueprint $table) {
            $table->dropUnique('youtube_connections_user_unique');
        });

        Schema::table('videos', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('videos', function (Blueprint $table) {
            $table->dropUnique('videos_user_puzzle_unique');
            $table->dropUnique('videos_user_slot_unique');
        });

        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('user_id');
            $table->unique('puzzle_hash', 'videos_puzzle_hash_unique');
            $table->unique('scheduled_at', 'videos_scheduled_at_unique');
        });

        Schema::table('audio_tracks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
