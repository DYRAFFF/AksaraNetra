<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class BridgeClient implements BridgeClientInterface
{
    public function __construct(
        private readonly ?string $scriptPath = null,
        private readonly string $nodeBinary = 'node'
    ) {}

    public function checkUrl(?string $url): array
    {
        $env = array_filter(array_merge($_ENV, $_SERVER, [
            'SYSTEMROOT' => getenv('SYSTEMROOT') ?: (getenv('SystemRoot') ?: 'C:\\Windows'),
            'SystemRoot' => getenv('SystemRoot') ?: (getenv('SYSTEMROOT') ?: 'C:\\Windows'),
            'PATH' => getenv('PATH') ?: ($_SERVER['PATH'] ?? ''),
        ]), fn ($v) => is_string($v));

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
}
