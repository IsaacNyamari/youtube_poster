<?php

namespace Tests\Feature;

use App\Jobs\GenerateVideosJob;
use App\Jobs\PublishVideoJob;
use App\Jobs\RenderVideoJob;
use App\Models\AudioTrack;
use App\Models\User;
use App\Models\Video;
use App\Models\YoutubeConnection;
use App\Services\Shorts\ShortsSettings;
use App\Services\Shorts\VideoGenerator;
use App\Support\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_only_see_their_own_videos_and_audio(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        Video::factory()->count(3)->for($alice)->create();
        Video::factory()->count(2)->for($bob)->create();
        AudioTrack::factory()->for($bob)->create();

        $this->actingAs($alice);

        $this->assertSame(3, Video::count());
        $this->assertSame(0, AudioTrack::count());

        $this->actingAs($bob);

        $this->assertSame(2, Video::count());
        $this->assertSame(1, AudioTrack::count());
    }

    public function test_new_records_belong_to_the_current_user(): void
    {
        $alice = User::factory()->create();

        $this->actingAs($alice);

        $video = Video::factory()->state(['user_id' => null])->create();

        $this->assertSame($alice->id, $video->fresh()->user_id);
    }

    public function test_media_of_another_user_cannot_be_opened(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $bobsVideo = Video::factory()->for($bob)->create();

        $this->actingAs($alice)->get(route('media.thumbnail', $bobsVideo))->assertNotFound();
        $this->actingAs($alice)->get(route('media.video', $bobsVideo))->assertNotFound();

        auth()->logout();

        $this->get(route('media.video', $bobsVideo))->assertRedirect(route('login'));
    }

    public function test_livewire_pages_cannot_act_on_another_users_video(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $bobsVideo = Video::factory()->for($bob)->create();

        Livewire::actingAs($alice)->test('pages::videos')->call('delete', $bobsVideo->id);

        $this->assertTrue(Video::withoutGlobalScopes()->whereKey($bobsVideo->id)->exists());
    }

    public function test_settings_are_stored_per_user(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice);
        ShortsSettings::set('timezone', 'Europe/Paris');
        ShortsSettings::set('post_times', ['08:00']);

        $this->actingAs($bob);

        $this->assertSame('Africa/Nairobi', ShortsSettings::get('timezone'));
        $this->assertSame(['09:00', '12:00', '15:00', '18:00', '21:00'], ShortsSettings::get('post_times'));

        $this->actingAs($alice);

        $this->assertSame('Europe/Paris', ShortsSettings::get('timezone'));
    }

    public function test_global_settings_are_shared_between_users(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice);
        ShortsSettings::set('quota_blocked_until', now()->addHour()->toIso8601String());

        $this->actingAs($bob);

        $this->assertNotNull(ShortsSettings::quotaBlockedUntil());
    }

    public function test_publishing_slots_may_repeat_across_users_but_not_within_one_user(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $slot = now()->addDay()->startOfHour()->toDateTimeString();

        Video::factory()->for($alice)->scheduledAt($slot)->create();
        Video::factory()->for($bob)->scheduledAt($slot)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Video::factory()->for($alice)->scheduledAt($slot)->create();
    }

    public function test_the_same_puzzle_may_exist_for_different_users_but_not_twice_for_one(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        Video::factory()->for($alice)->create(['puzzle_hash' => 'same-hash']);
        Video::factory()->for($bob)->create(['puzzle_hash' => 'same-hash']);

        $this->expectException(QueryException::class);

        Video::factory()->for($alice)->create(['puzzle_hash' => 'same-hash']);
    }

    public function test_auto_schedule_uses_each_users_own_posting_times(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        Tenant::run($alice->id, function (): void {
            ShortsSettings::set('post_times', ['10:00']);
            ShortsSettings::set('timezone', 'UTC');
            ShortsSettings::set('days_ahead', 5);
        });

        Tenant::run($bob->id, function (): void {
            ShortsSettings::set('post_times', ['14:00']);
            ShortsSettings::set('timezone', 'UTC');
            ShortsSettings::set('days_ahead', 5);
        });

        $aliceVideo = Video::factory()->for($alice)->create();
        $bobVideo = Video::factory()->for($bob)->create();

        $this->artisan('shorts:auto-schedule')->assertSuccessful();

        $this->assertSame('10:00', $aliceVideo->fresh()->scheduled_at->format('H:i'));
        $this->assertSame('14:00', $bobVideo->fresh()->scheduled_at->format('H:i'));
    }

    public function test_publish_due_only_queues_uploads_for_active_connected_users(): void
    {
        Queue::fake();

        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $carol = User::factory()->create();

        foreach ([$alice, $bob, $carol] as $user) {
            Video::factory()->for($user)->scheduledAt(now()->subMinute()->toDateTimeString())->create();
        }

        foreach ([$alice, $bob] as $user) {
            YoutubeConnection::create([
                'user_id' => $user->id,
                'channel_id' => 'UC'.$user->id,
                'channel_title' => 'Channel '.$user->id,
                'access_token' => 'token',
                'refresh_token' => 'refresh',
                'expires_at' => now()->addHour(),
            ]);
        }

        Tenant::run($bob->id, fn () => ShortsSettings::set('paused', true));

        $this->artisan('shorts:publish-due')->assertSuccessful();

        Queue::assertPushed(PublishVideoJob::class, 1);

        $this->assertSame(Video::PUBLISH_PUBLISHING, Video::withoutGlobalScopes()->where('user_id', $alice->id)->value('publish_status'));
        $this->assertSame(Video::PUBLISH_SCHEDULED, Video::withoutGlobalScopes()->where('user_id', $bob->id)->value('publish_status'));
        $this->assertSame(Video::PUBLISH_SCHEDULED, Video::withoutGlobalScopes()->where('user_id', $carol->id)->value('publish_status'));
    }

    public function test_generate_job_creates_videos_in_the_owners_workspace_only(): void
    {
        Queue::fake([RenderVideoJob::class]);

        $alice = User::factory()->create();
        $bob = User::factory()->create();

        (new GenerateVideosJob(3, $alice->id))->handle(app(VideoGenerator::class));

        $this->assertSame(3, Video::withoutGlobalScopes()->where('user_id', $alice->id)->count());
        $this->assertSame(0, Video::withoutGlobalScopes()->where('user_id', $bob->id)->count());
        Queue::assertPushed(RenderVideoJob::class, 3);
    }

    public function test_deployment_page_is_for_administrators_only(): void
    {
        $member = User::factory()->create();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($member)->get(route('deployment.edit'))->assertForbidden();
        $this->actingAs($admin)->get(route('deployment.edit'))->assertOk();
    }

    public function test_unverified_users_cannot_reach_the_dashboard(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_registration_is_closed_unless_enabled(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_deleting_a_user_removes_their_data(): void
    {
        $alice = User::factory()->create();
        Video::factory()->count(2)->for($alice)->create();
        AudioTrack::factory()->for($alice)->create();

        $alice->delete();

        $this->assertSame(0, Video::withoutGlobalScopes()->count());
        $this->assertSame(0, AudioTrack::withoutGlobalScopes()->count());
    }
}
