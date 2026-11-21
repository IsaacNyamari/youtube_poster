<?php

use App\Http\Controllers\DeployWebhookController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\YouTubeOAuthController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::post('deploy/webhook', DeployWebhookController::class)
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->name('deploy.webhook');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('videos', 'pages::videos')->name('videos.index');
    Route::livewire('audio', 'pages::audio')->name('audio.index');
    Route::livewire('publishing', 'pages::publishing')->name('publishing.index');
    Route::livewire('youtube', 'pages::youtube')->name('youtube.edit');

    Route::get('youtube/connect', [YouTubeOAuthController::class, 'redirect'])->name('youtube.connect');
    Route::get('youtube/callback', [YouTubeOAuthController::class, 'callback'])->name('youtube.callback');

    Route::get('media/videos/{video}', [MediaController::class, 'video'])->name('media.video');
    Route::get('media/thumbnails/{video}', [MediaController::class, 'thumbnail'])->name('media.thumbnail');
    Route::get('media/audio/{track}', [MediaController::class, 'audio'])->name('media.audio');
});

require __DIR__.'/settings.php';
