<?php

namespace Tests\Feature;

use App\Jobs\GenerateVideosJob;
use App\Jobs\PublishVideoJob;
use App\Jobs\RenderVideoJob;
use App\Models\AudioTrack;
use App\Models\GoogleCredential;
use App\Models\User;
use App\Models\Video;
use App\Models\YoutubeConnection;
use App\Services\Shorts\ShortsSettings;
use App\Services\Shorts\VideoGenerator;
use App\Services\Shorts\YouTubeApiException;
use App\Services\Shorts\YouTubeClient;
use App\Support\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        ShortsSettings::set('deploy_branch', 'release/1.0.1');

        $this->actingAs($bob);

        $this->assertSame('release/1.0.1', ShortsSettings::get('deploy_branch'));
    }

    public function test_the_youtube_quota_block_belongs_to_each_users_own_google_project(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        Tenant::run($alice->id, fn () => ShortsSettings::set('quota_blocked_until', now()->addHour()->toIso8601String()));

        $this->assertNotNull(Tenant::run($alice->id, fn () => ShortsSettings::quotaBlockedUntil()));
        $this->assertNull(Tenant::run($bob->id, fn () => ShortsSettings::quotaBlockedUntil()));
    }

    public function test_each_user_has_their_own_google_credentials(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        GoogleCredential::create(['user_id' => $alice->id, 'client_id' => '111-alice.apps.googleusercontent.com', 'client_secret' => 'alice-secret-value']);
        GoogleCredential::create(['user_id' => $bob->id, 'client_id' => '222-bob.apps.googleusercontent.com', 'client_secret' => 'bob-secret-value']);

        $this->actingAs($alice);

        $this->assertSame(1, GoogleCredential::count());
        $this->assertSame('111-alice.apps.googleusercontent.com', GoogleCredential::first()->client_id);
        $this->assertSame('alice-secret-value', GoogleCredential::first()->client_secret);
        $this->assertArrayNotHasKey('client_secret', GoogleCredential::first()->toArray());

        $stored = DB::table('google_credentials')->where('user_id', $alice->id)->value('client_secret');
        $this->assertStringNotContainsString('alice-secret-value', $stored);
    }

    public function test_the_authorisation_url_uses_the_users_own_client_id(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $carol = User::factory()->create();

        GoogleCredential::create(['user_id' => $alice->id, 'client_id' => '111-alice.apps.googleusercontent.com', 'client_secret' => 'alice-secret-value']);
        GoogleCredential::create(['user_id' => $bob->id, 'client_id' => '222-bob.apps.googleusercontent.com', 'client_secret' => 'bob-secret-value']);

        $client = app(YouTubeClient::class);
        $url = $client->authorizationUrl($alice, 'state123');

        $this->assertStringContainsString('client_id=111-alice.apps.googleusercontent.com', $url);
        $this->assertStringNotContainsString('222-bob', $url);
        $this->assertStringContainsString(rawurlencode(YouTubeClient::redirectUri()), $url);
        $this->assertTrue($client->isConfigured($alice));
        $this->assertFalse($client->isConfigured($carol));

        $this->expectException(YouTubeApiException::class);
        $client->authorizationUrl($carol, 'state123');
    }

    public function test_connecting_requires_the_users_own_credentials(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        GoogleCredential::create(['user_id' => $bob->id, 'client_id' => '222-bob.apps.googleusercontent.com', 'client_secret' => 'bob-secret-value']);

        $this->actingAs($alice)->get(route('youtube.connect'))
            ->assertRedirect(route('youtube.edit'))
            ->assertSessionHas('youtube_error');

        $this->actingAs($bob)->get(route('youtube.connect'))
            ->assertRedirectContains('accounts.google.com')
            ->assertRedirectContains('222-bob.apps.googleusercontent.com');
    }

    public function test_credentials_can_be_saved_from_a_downloaded_json_file(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $json = json_encode(['web' => [
            'client_id' => '333-alice.apps.googleusercontent.com',
            'client_secret' => 'GOCSPX-alice-secret',
            'project_id' => 'alice-project',
            'redirect_uris' => [YouTubeClient::redirectUri()],
        ]]);

        Livewire::actingAs($alice)->test('pages::youtube')
            ->set('credentialsJson', $json)
            ->call('saveCredentials')
            ->assertHasNoErrors();

        $saved = GoogleCredential::withoutGlobalScopes()->where('user_id', $alice->id)->first();

        $this->assertSame('333-alice.apps.googleusercontent.com', $saved->client_id);
        $this->assertSame('alice-project', $saved->project_id);
        $this->assertNull(GoogleCredential::withoutGlobalScopes()->where('user_id', $bob->id)->first());
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $alice = User::factory()->create();

        Livewire::actingAs($alice)->test('pages::youtube')
            ->set('credentialsJson', '{"installed":{"client_id":"x"}}')
            ->call('saveCredentials')
            ->assertHasErrors('credentialsJson');

        Livewire::actingAs($alice)->test('pages::youtube')
            ->set('clientId', 'not-a-client-id')
            ->set('clientSecret', 'some-long-secret')
            ->call('saveCredentials')
            ->assertHasErrors('clientId');
    }

    public function test_switching_to_a_different_google_project_drops_the_old_connection(): void
    {
        $alice = User::factory()->create();
        GoogleCredential::create(['user_id' => $alice->id, 'client_id' => '111-old.apps.googleusercontent.com', 'client_secret' => 'old-secret-value']);
        YoutubeConnection::create(['user_id' => $alice->id, 'channel_id' => 'UC1', 'channel_title' => 'Old', 'access_token' => 't', 'refresh_token' => 'r']);

        Livewire::actingAs($alice)->test('pages::youtube')
            ->set('clientId', '999-new.apps.googleusercontent.com')
            ->set('clientSecret', 'new-secret-value')
            ->call('saveCredentials')
            ->assertHasNoErrors();

        $this->assertSame(0, YoutubeConnection::withoutGlobalScopes()->where('user_id', $alice->id)->count());
        $this->assertSame('999-new.apps.googleusercontent.com', GoogleCredential::withoutGlobalScopes()->where('user_id', $alice->id)->value('client_id'));
    }

    public function test_publish_due_skips_a_user_whose_own_quota_is_used_up(): void
    {
        Queue::fake();

        $alice = User::factory()->create();
        $bob = User::factory()->create();

        foreach ([$alice, $bob] as $user) {
            Video::factory()->for($user)->scheduledAt(now()->subMinute()->toDateTimeString())->create();
            YoutubeConnection::create(['user_id' => $user->id, 'channel_id' => 'UC'.$user->id, 'channel_title' => 'C', 'access_token' => 't', 'refresh_token' => 'r', 'expires_at' => now()->addHour()]);
        }

        Tenant::run($alice->id, fn () => ShortsSettings::set('quota_blocked_until', now()->addHours(5)->toIso8601String()));

        $this->artisan('shorts:publish-due')->assertSuccessful();

        Queue::assertPushed(PublishVideoJob::class, 1);
        $this->assertSame(Video::PUBLISH_SCHEDULED, Video::withoutGlobalScopes()->where('user_id', $alice->id)->value('publish_status'));
        $this->assertSame(Video::PUBLISH_PUBLISHING, Video::withoutGlobalScopes()->where('user_id', $bob->id)->value('publish_status'));
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
