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
}
