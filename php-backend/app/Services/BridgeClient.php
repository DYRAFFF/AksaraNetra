<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuditCancelledException;
use App\Exceptions\AuditFailedException;
use RuntimeException;
use Symfony\Component\Process\Process;

class BridgeClient implements BridgeClientInterface
{
    public function __construct(
        private readonly ?string $scriptPath = null,
        private readonly string $nodeBinary = 'node'
    ) {}

    private function buildEnv(): array
    {
        return array_filter(array_merge($_ENV, $_SERVER, [
            'SYSTEMROOT' => getenv('SYSTEMROOT') ?: (getenv('SystemRoot') ?: 'C:\\Windows'),
            'SystemRoot' => getenv('SystemRoot') ?: (getenv('SYSTEMROOT') ?: 'C:\\Windows'),
            'PATH' => getenv('PATH') ?: ($_SERVER['PATH'] ?? ''),
        ]), fn ($v) => is_string($v));
    }

    public function checkUrl(?string $url): array
    {
        $env = $this->buildEnv();
        $script = $this->scriptPath ?: base_path('bridge/audit-cli.mjs');
        $process = new Process([$this->nodeBinary, $script, 'check-url', (string) ($url ?? '')], base_path(), $env);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Bridge check-url failed: ' . $process->getErrorOutput());
        }

        $output = trim($process->getOutput());
        $data = json_decode($output, true);
        if (!is_array($data) || !isset($data['ok'])) {
            throw new RuntimeException('Invalid JSON output from bridge: ' . $output);
        }

        return $data;
    }

    public function audit(
        string $id,
        string $url,
        string $artifactDir,
        ?callable $onProgress = null,
        ?callable $shouldCancel = null,
        ?int $timeoutSeconds = null
    ): array {
        $env = $this->buildEnv();
        $script = $this->scriptPath ?: base_path('bridge/audit-cli.mjs');
        $input = json_encode([
            'id' => $id,
            'url' => $url,
            'artifactDir' => $artifactDir,
        ], JSON_THROW_ON_ERROR);

        $process = new Process(
            [$this->nodeBinary, $script, 'audit'],
            base_path(),
            $env,
            $input,
            $timeoutSeconds !== null ? (float) $timeoutSeconds : null
        );

        $buffer = '';
        $result = null;

        $parseLine = function (string $line) use (&$result, $onProgress): void {
            $line = trim($line);
            if ($line === '') {
                return;
            }
            $data = json_decode($line, true);
            if (!is_array($data)) {
                return;
            }
            $type = $data['type'] ?? '';
            if ($type === 'progress' && isset($data['progress'])) {
                if ($onProgress !== null) {
                    $onProgress([
                        'progress' => (int) $data['progress'],
                        'stage' => (string) ($data['stage'] ?? ''),
                    ]);
                }
            } elseif ($type === 'result' || $type === 'error') {
                $result = $data;
            }
        };

        $process->start(function ($type, $out) use (&$buffer, $parseLine): void {
            if ($type === Process::ERR) {
                return;
            }
            $buffer .= $out;
            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newlinePos);
                $buffer = substr($buffer, $newlinePos + 1);
                $parseLine($line);
            }
        });

        $lastCheck = microtime(true);
        while ($process->isRunning()) {
            if ($shouldCancel !== null && (microtime(true) - $lastCheck >= 0.5)) {
                $lastCheck = microtime(true);
                if ($shouldCancel()) {
                    $process->stop(1.0);
                    throw new AuditCancelledException('Audit dibatalkan.');
                }
            }
            usleep(50000); // 50ms
        }

        if ($buffer !== '') {
            $parseLine($buffer);
        }

        if ($shouldCancel !== null && $shouldCancel()) {
            throw new AuditCancelledException('Audit dibatalkan.');
        }

        if ($result === null) {
            if (!$process->isSuccessful()) {
                throw new AuditFailedException('bridge-failed', null, 'Bridge process exited unexpectedly: ' . $process->getErrorOutput());
            }
            throw new AuditFailedException('bridge-failed', null, 'Bridge process completed without emitting a result or error.');
        }

        if (($result['type'] ?? '') === 'error') {
            throw new AuditFailedException(
                (string) ($result['code'] ?? 'audit-failed'),
                $result['stage'] ?? null,
                (string) ($result['message'] ?? 'Audit gagal.')
            );
        }

        return $result;
    }

    public function renderPdf(
        string $htmlPath,
        string $outPath,
        ?callable $shouldCancel = null,
        ?int $timeoutSeconds = null
    ): bool {
        $env = $this->buildEnv();
        $script = $this->scriptPath ?: base_path('bridge/audit-cli.mjs');
        $process = new Process(
            [$this->nodeBinary, $script, 'render-pdf', $htmlPath, $outPath],
            base_path(),
            $env,
            null,
            $timeoutSeconds !== null ? (float) $timeoutSeconds : 60.0
        );

        $process->start();

        $lastCheck = microtime(true);
        while ($process->isRunning()) {
            if ($shouldCancel !== null && (microtime(true) - $lastCheck >= 0.5)) {
                $lastCheck = microtime(true);
                if ($shouldCancel()) {
                    $process->stop(1.0);
                    throw new AuditCancelledException('Pembuatan PDF dibatalkan.');
                }
            }
            usleep(50000);
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Bridge render-pdf failed: ' . $process->getErrorOutput());
        }

        $output = trim($process->getOutput());
        $data = json_decode($output, true);
        if (!is_array($data) || !($data['ok'] ?? false)) {
            throw new RuntimeException('Bridge render-pdf returned failure: ' . ($data['message'] ?? $output));
        }

        return true;
    }
}
