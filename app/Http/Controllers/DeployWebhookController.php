<?php

namespace App\Http\Controllers;

use App\Jobs\DeployJob;
use App\Models\Deployment;
use App\Services\Shorts\ShortsSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeployWebhookController extends Controller
{
    /**
     * GitHub push webhook: deploys when the configured branch is pushed.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) ShortsSettings::get('deploy_webhook_secret', '');

        abort_if($secret === '', 404);

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        abort_unless(hash_equals($expected, (string) $request->header('X-Hub-Signature-256')), 403);

        if ($request->header('X-GitHub-Event') === 'ping') {
            return response()->json(['status' => 'pong']);
        }

        $branch = (string) ShortsSettings::get('deploy_branch', config('deploy.branch'));

        if ($request->input('ref') !== 'refs/heads/'.$branch) {
            return response()->json(['status' => 'ignored']);
        }

        $deployment = Deployment::create(['status' => Deployment::QUEUED, 'trigger' => 'webhook', 'branch' => $branch]);
        DeployJob::dispatch($deployment->id);

        return response()->json(['status' => 'queued', 'deployment' => $deployment->id], 202);
    }
}
