<?php

namespace App\Http\Controllers;

use App\Services\Shorts\YouTubeApiException;
use App\Services\Shorts\YouTubeClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class YouTubeOAuthController extends Controller
{
    public function redirect(Request $request, YouTubeClient $youtube): RedirectResponse
    {
        if (! $youtube->isConfigured()) {
            return redirect()->route('youtube.edit')->with('youtube_error', 'Set GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET and GOOGLE_REDIRECT_URI in .env first.');
        }

        $state = Str::random(40);
        $request->session()->put('youtube_oauth_state', $state);

        return redirect()->away($youtube->authorizationUrl($state));
    }

    public function callback(Request $request, YouTubeClient $youtube): RedirectResponse
    {
        $expected = $request->session()->pull('youtube_oauth_state');

        if ($request->filled('error')) {
            return redirect()->route('youtube.edit')->with('youtube_error', 'Google authorisation was cancelled or denied.');
        }

        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect()->route('youtube.edit')->with('youtube_error', 'Invalid OAuth state. Please try connecting again.');
        }

        try {
            $youtube->connect($request->user(), (string) $request->query('code'));
        } catch (YouTubeApiException $e) {
            return redirect()->route('youtube.edit')->with('youtube_error', 'Could not connect: '.$e->getMessage());
        }

        return redirect()->route('youtube.edit')->with('youtube_status', 'YouTube channel connected.');
    }
}
