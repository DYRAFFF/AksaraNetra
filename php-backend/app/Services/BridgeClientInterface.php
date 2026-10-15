<?php

declare(strict_types=1);

namespace App\Services;

interface BridgeClientInterface
{
    /**
     * Check a URL against SSRF and safety policy via Node bridge.
     *
     * @param string|null $url
     * @return array{ok: bool, code: ?string, message: ?string, url?: string, hostname?: string, protocol?: string}
     */
    public function checkUrl(?string $url): array;

    /**
     * Run an audit via the Node bridge audit subcommand.
     *
     * @param string $id
     * @param string $url
     * @param string $artifactDir
     * @param (callable(array{progress: int, stage: string}): void)|null $onProgress
     * @param (callable(): bool)|null $shouldCancel
     * @param int|null $timeoutSeconds
     * @return array{type: string, snapshot: array, files?: array}
     */
    public function audit(
        string $id,
        string $url,
        string $artifactDir,
        ?callable $onProgress = null,
        ?callable $shouldCancel = null,
        ?int $timeoutSeconds = null
    ): array;

    /**
     * Render an HTML report to PDF via the Node bridge render-pdf subcommand.
     *
     * @param string $htmlPath
     * @param string $outPath
     * @param (callable(): bool)|null $shouldCancel
     * @param int|null $timeoutSeconds
     * @return bool
     */
    public function renderPdf(
        string $htmlPath,
        string $outPath,
        ?callable $shouldCancel = null,
        ?int $timeoutSeconds = null
    ): bool;
}
