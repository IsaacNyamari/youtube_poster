<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Services\DeploymentRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeployJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $deploymentId) {}

    public function handle(DeploymentRunner $runner): void
    {
        $deployment = Deployment::query()->find($this->deploymentId);

        if ($deployment === null || $deployment->status !== Deployment::QUEUED) {
            return;
        }

        $runner->run($deployment);
    }

    public function failed(\Throwable $exception): void
    {
        Deployment::query()->whereKey($this->deploymentId)->update([
            'status' => Deployment::FAILED,
            'finished_at' => now(),
        ]);
    }
}
