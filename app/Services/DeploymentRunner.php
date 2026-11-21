<?php

namespace App\Services;

use App\Models\Deployment;
use Symfony\Component\Process\Process;

class DeploymentRunner
{
    /**
     * Run every deployment step in order and record the output. Stops at the first failing step.
     */
    public function run(Deployment $deployment): Deployment
    {
        $deployment->update([
            'status' => Deployment::RUNNING,
            'started_at' => now(),
            'commit_before' => $this->currentCommit(),
            'output' => '',
        ]);

        $success = true;

        foreach ($this->steps($deployment->branch) as $label => $command) {
            $this->append($deployment, "\n$ {$label}\n");

            $process = new Process($command, base_path(), $this->environment(), null, (float) config('deploy.timeout'));
            $process->run(fn (string $type, string $buffer) => $this->append($deployment, $buffer));

            if (! $process->isSuccessful()) {
                $this->append($deployment, "\nStep failed with exit code {$process->getExitCode()}.\n");
                $success = false;

                break;
            }
        }

        $deployment->update([
            'status' => $success ? Deployment::SUCCEEDED : Deployment::FAILED,
            'commit_after' => $this->currentCommit(),
            'finished_at' => now(),
        ]);

        return $deployment->refresh();
    }

    /**
     * @return array<string, list<string>>
     */
    public function steps(string $branch): array
    {
        $php = config('deploy.php');

        $steps = [
            'git fetch' => [config('deploy.git'), 'fetch', '--prune', 'origin', $branch],
            'git reset' => [config('deploy.git'), 'reset', '--hard', 'origin/'.$branch],
            'composer install' => [config('deploy.composer'), 'install', '--no-dev', '--prefer-dist', '--optimize-autoloader', '--no-interaction'],
            'migrate' => [$php, 'artisan', 'migrate', '--force', '--no-interaction'],
        ];

        if (config('deploy.build_assets')) {
            $steps['npm ci'] = [config('deploy.npm'), 'ci', '--no-audit', '--no-fund'];
            $steps['npm run build'] = [config('deploy.npm'), 'run', 'build'];
        }

        $steps['optimize'] = [$php, 'artisan', 'optimize'];
        $steps['restart queue workers'] = [$php, 'artisan', 'queue:restart'];

        return $steps;
    }

    public function currentCommit(): ?string
    {
        $process = new Process([config('deploy.git'), 'rev-parse', 'HEAD'], base_path());
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    public function remoteUrl(): ?string
    {
        $process = new Process([config('deploy.git'), 'remote', 'get-url', 'origin'], base_path());
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        return preg_replace('#//[^@/]+@#', '//', trim($process->getOutput()));
    }

    /**
     * @return array<string, string>
     */
    private function environment(): array
    {
        return [
            'HOME' => getenv('HOME') ?: base_path(),
            'COMPOSER_HOME' => storage_path('app/composer'),
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_SSH_COMMAND' => 'ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new',
        ];
    }

    private function append(Deployment $deployment, string $text): void
    {
        $deployment->output = mb_substr($deployment->output.$text, -60000);
        $deployment->save();
    }
}
