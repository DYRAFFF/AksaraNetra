<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Exceptions\AuditCancelledException;
use App\Services\BridgeClientInterface;

class FakeBridgeClient implements BridgeClientInterface
{
    /** @var array<string, array> */
    private array $customVerdicts = [];

    /** @var (callable(string, string, string, ?callable, ?callable): array)|null */
    private $auditHandler = null;

    /** @var (callable(string, string, ?callable): bool)|null */
    private $renderPdfHandler = null;

    public function setVerdict(string $url, array $verdict): void
    {
        $this->customVerdicts[$url] = $verdict;
    }

    public function setAuditHandler(?callable $handler): void
    {
        $this->auditHandler = $handler;
    }

    public function setRenderPdfHandler(?callable $handler): void
    {
        $this->renderPdfHandler = $handler;
    }

    public function checkUrl(?string $url): array
    {
        $raw = trim((string) ($url ?? ''));

        if (isset($this->customVerdicts[$raw])) {
            return $this->customVerdicts[$raw];
        }

        if ($raw === '') {
            return [
                'ok' => false,
                'code' => 'empty-url',
                'message' => 'URL kosong.',
            ];
        }

        $parts = parse_url($raw);
        if (!$parts || !isset($parts['scheme'], $parts['host'])) {
            return [
                'ok' => false,
                'code' => 'unparseable-url',
                'message' => 'URL tidak dapat dibaca.',
            ];
        }

        if (!in_array($parts['scheme'], ['http', 'https'], true)) {
            return [
                'ok' => false,
                'code' => 'protocol-not-allowed',
                'message' => "Hanya http dan https yang didukung, bukan {$parts['scheme']}.",
            ];
        }

        $host = strtolower($parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === '127.0.0.1' || filter_var($host, FILTER_VALIDATE_IP)) {
            return [
                'ok' => false,
                'code' => 'local-host-name',
                'message' => "Nama {$host} mengarah ke mesin lokal.",
            ];
        }

        return [
            'ok' => true,
            'code' => null,
            'message' => null,
            'url' => $raw,
            'hostname' => $host,
            'protocol' => $parts['scheme'] . ':',
        ];
    }

    public function audit(
        string $id,
        string $url,
        string $artifactDir,
        ?callable $onProgress = null,
        ?callable $shouldCancel = null,
        ?int $timeoutSeconds = null
    ): array {
        if ($shouldCancel !== null && $shouldCancel()) {
            throw new AuditCancelledException('Audit dibatalkan.');
        }

        if ($this->auditHandler !== null) {
            return ($this->auditHandler)($id, $url, $artifactDir, $onProgress, $shouldCancel);
        }

        if ($onProgress !== null) {
            $onProgress(['progress' => 50, 'stage' => 'Sedang audit']);
        }

        if (!is_dir($artifactDir)) {
            mkdir($artifactDir, 0777, true);
        }

        file_put_contents($artifactDir . DIRECTORY_SEPARATOR . 'snapshot.json', json_encode(['ok' => true]));
        file_put_contents($artifactDir . DIRECTORY_SEPARATOR . 'engine-result.json', json_encode(['ok' => true]));

        return [
            'type' => 'result',
            'snapshot' => ['ok' => true],
            'files' => [],
        ];
    }

    public function renderPdf(
        string $htmlPath,
        string $outPath,
        ?callable $shouldCancel = null,
        ?int $timeoutSeconds = null
    ): bool {
        if ($shouldCancel !== null && $shouldCancel()) {
            throw new AuditCancelledException('Pembuatan PDF dibatalkan.');
        }

        if ($this->renderPdfHandler !== null) {
            return ($this->renderPdfHandler)($htmlPath, $outPath, $shouldCancel);
        }

        file_put_contents($outPath, '%PDF-1.4 Fake PDF');
        return true;
    }
}
