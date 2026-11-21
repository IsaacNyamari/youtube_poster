<?php

namespace App\Services\Shorts;

use App\Models\GoogleCredential;
use App\Models\User;
use App\Models\Video;
use App\Models\YoutubeConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class YouTubeClient
{
    public const SCOPES = [
        'https://www.googleapis.com/auth/youtube.upload',
        'https://www.googleapis.com/auth/youtube.readonly',
    ];

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    private const API_URL = 'https://www.googleapis.com/youtube/v3';

    private const UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos';

    /**
     * The one callback address every user registers in their own Google Cloud OAuth client.
     */
    public static function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').route('youtube.callback', [], false);
    }

    /**
     * Whether the user has saved their own Google OAuth client.
     */
    public function isConfigured(User $user): bool
    {
        return GoogleCredential::query()->withoutGlobalScopes()->where('user_id', $user->id)->exists();
    }

    /**
     * The user's own OAuth client. Every user has their own Google project, and therefore their own API quota.
     */
    private function credentialsFor(int $userId): GoogleCredential
    {
        return GoogleCredential::query()->withoutGlobalScopes()->where('user_id', $userId)->first()
            ?? throw new YouTubeApiException('Add your Google OAuth client ID and secret on the YouTube page first.', 400, 'noCredentials');
    }

    public function authorizationUrl(User $user, string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $this->credentialsFor($user->id)->client_id,
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * Exchange an authorization code for tokens and store the channel connection.
     */
    public function connect(User $user, string $code): YoutubeConnection
    {
        $credentials = $this->credentialsFor($user->id);

        $response = $this->send(fn () => Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $credentials->client_id,
            'client_secret' => $credentials->client_secret,
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
        ]));

        $tokens = $response->json();
        $channel = $this->fetchChannel($tokens['access_token']);

        $existing = YoutubeConnection::query()->withoutGlobalScopes()->where('user_id', $user->id)->first();

        $connection = YoutubeConnection::query()->withoutGlobalScopes()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'user_id' => $user->id,
                'channel_id' => $channel['id'],
                'channel_title' => $channel['snippet']['title'] ?? 'Unknown channel',
                'channel_thumbnail' => $channel['snippet']['thumbnails']['default']['url'] ?? null,
                'channel_handle' => $channel['snippet']['customUrl'] ?? null,
                'subscriber_count' => $channel['statistics']['subscriberCount'] ?? null,
                'video_count' => $channel['statistics']['videoCount'] ?? null,
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? $existing?->refresh_token,
                'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
                'scopes' => $tokens['scope'] ?? implode(' ', self::SCOPES),
                'needs_reauth' => false,
            ],
        );

        return $connection;
    }

    /**
     * Re-read channel title and statistics.
     */
    public function refreshChannelInfo(YoutubeConnection $connection): void
    {
        $channel = $this->fetchChannel($this->accessToken($connection));

        $connection->update([
            'channel_title' => $channel['snippet']['title'] ?? $connection->channel_title,
            'channel_thumbnail' => $channel['snippet']['thumbnails']['default']['url'] ?? $connection->channel_thumbnail,
            'channel_handle' => $channel['snippet']['customUrl'] ?? $connection->channel_handle,
            'subscriber_count' => $channel['statistics']['subscriberCount'] ?? null,
            'video_count' => $channel['statistics']['videoCount'] ?? null,
        ]);
    }

    public function disconnect(YoutubeConnection $connection): void
    {
        $token = $connection->refresh_token ?: $connection->access_token;

        try {
            Http::asForm()->timeout(15)->post(self::REVOKE_URL, ['token' => $token]);
        } catch (\Throwable) {
            // Revocation is best effort; the local credentials are removed regardless.
        }

        $connection->delete();
    }

    /**
     * A valid access token, refreshed when it is about to expire.
     */
    public function accessToken(YoutubeConnection $connection): string
    {
        if ($connection->needs_reauth) {
            throw new YouTubeApiException('The YouTube connection needs to be re-authorised.', 401, 'invalid_grant');
        }

        if ($connection->expires_at && $connection->expires_at->gt(now()->addSeconds(60))) {
            return $connection->access_token;
        }

        if (! $connection->refresh_token) {
            $connection->update(['needs_reauth' => true]);

            throw new YouTubeApiException('No refresh token stored. Please reconnect your channel.', 401, 'invalid_grant');
        }

        try {
            $credentials = $this->credentialsFor($connection->user_id);

            $response = $this->send(fn () => Http::asForm()->post(self::TOKEN_URL, [
                'client_id' => $credentials->client_id,
                'client_secret' => $credentials->client_secret,
                'refresh_token' => $connection->refresh_token,
                'grant_type' => 'refresh_token',
            ]));
        } catch (YouTubeApiException $e) {
            if ($e->isAuth()) {
                $connection->update(['needs_reauth' => true]);
            }

            throw $e;
        }

        $connection->update([
            'access_token' => $response->json('access_token'),
            'expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
        ]);

        return $connection->access_token;
    }

    /**
     * Upload a rendered video using the resumable protocol and return the YouTube video id.
     * An interrupted upload is resumed from the stored session URI so retries never create duplicates.
     */
    public function upload(Video $video, YoutubeConnection $connection): string
    {
        $token = $this->accessToken($connection);
        $path = Storage::disk(config('shorts.disk'))->path($video->file_path);
        $size = filesize($path);

        $sessionUri = $video->upload_session_uri;
        $offset = 0;

        if ($sessionUri) {
            $status = $this->queryUploadStatus($token, $sessionUri, $size);

            if (is_string($status)) {
                return $status;
            }

            if ($status === null) {
                $sessionUri = null;
            } else {
                $offset = $status;
            }
        }

        if (! $sessionUri) {
            $sessionUri = $this->startSession($token, $video, $size);
            $video->update(['upload_session_uri' => $sessionUri]);
        }

        $handle = fopen($path, 'rb');
        fseek($handle, $offset);
        $body = stream_get_contents($handle);
        fclose($handle);

        $response = $this->send(fn () => Http::withToken($token)
            ->withHeaders(['Content-Range' => 'bytes '.$offset.'-'.($size - 1).'/'.$size])
            ->timeout(900)
            ->withBody($body, 'video/mp4')
            ->put($sessionUri));

        $id = $response->json('id');

        if (! $id) {
            throw new YouTubeApiException('YouTube did not return a video id.', $response->status(), null, true);
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchChannel(string $accessToken): array
    {
        $response = $this->send(fn () => Http::withToken($accessToken)->get(self::API_URL.'/channels', [
            'part' => 'snippet,statistics',
            'mine' => 'true',
        ]));

        $channel = $response->json('items.0');

        if (! $channel) {
            throw new YouTubeApiException('No YouTube channel found for this Google account.', 404, 'noChannel');
        }

        return $channel;
    }

    private function startSession(string $token, Video $video, int $size): string
    {
        $settings = ShortsSettings::all();
        $nativeSchedule = $settings['upload_mode'] === 'native_schedule' && $video->scheduled_at !== null;

        $status = [
            'privacyStatus' => $nativeSchedule ? 'private' : $settings['privacy'],
            'selfDeclaredMadeForKids' => (bool) $settings['made_for_kids'],
        ];

        if ($nativeSchedule) {
            $status['publishAt'] = $video->scheduled_at->utc()->format('Y-m-d\TH:i:s.000\Z');
        }

        $description = $video->description ?? '';

        if (! str_contains(strtolower($description), '#shorts')) {
            $description .= "\n\n#Shorts";
        }

        $response = $this->send(fn () => Http::withToken($token)
            ->withHeaders([
                'X-Upload-Content-Length' => (string) $size,
                'X-Upload-Content-Type' => 'video/mp4',
            ])
            ->post(self::UPLOAD_URL.'?uploadType=resumable&part=snippet,status', [
                'snippet' => [
                    'title' => str_replace(['<', '>'], '', mb_substr($video->title, 0, 100)),
                    'description' => str_replace(['<', '>'], '', $description),
                    'tags' => array_values($video->tags ?? []),
                    'categoryId' => (string) $settings['category_id'],
                ],
                'status' => $status,
            ]));

        $location = $response->header('Location');

        if (! $location) {
            throw new YouTubeApiException('YouTube did not return an upload session.', $response->status(), null, true);
        }

        return $location;
    }

    /**
     * @return string|int|null Video id when finished, byte offset to resume from, or null when the session is gone.
     */
    private function queryUploadStatus(string $token, string $sessionUri, int $size): string|int|null
    {
        try {
            $response = Http::withToken($token)
                ->withHeaders(['Content-Range' => 'bytes */'.$size])
                ->timeout(60)
                ->withBody('', 'video/mp4')
                ->put($sessionUri);
        } catch (ConnectionException $e) {
            throw new YouTubeApiException('Network error: '.$e->getMessage(), 0, null, true);
        }

        if ($response->successful() && $response->json('id')) {
            return $response->json('id');
        }

        if ($response->status() === 308) {
            $range = $response->header('Range');

            return $range && preg_match('/bytes=0-(\d+)/', $range, $matches) ? ((int) $matches[1]) + 1 : 0;
        }

        if (in_array($response->status(), [404, 410], true)) {
            return null;
        }

        throw $this->exceptionFor($response);
    }

    /**
     * @param  callable(): Response  $request
     */
    private function send(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new YouTubeApiException('Network error: '.$e->getMessage(), 0, null, true);
        }

        if ($response->failed()) {
            throw $this->exceptionFor($response);
        }

        return $response;
    }

    private function exceptionFor(Response $response): YouTubeApiException
    {
        $reason = $response->json('error.errors.0.reason')
            ?? (is_string($response->json('error')) ? $response->json('error') : null);

        $message = $response->json('error.message')
            ?? $response->json('error_description')
            ?? 'YouTube API request failed with status '.$response->status().'.';

        $retryable = $response->status() >= 500 || $response->status() === 429;

        return new YouTubeApiException($message, $response->status(), $reason, $retryable);
    }
}
