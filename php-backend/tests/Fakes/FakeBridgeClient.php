<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Services\BridgeClientInterface;

class FakeBridgeClient implements BridgeClientInterface
{
    /** @var array<string, array> */
    private array $customVerdicts = [];

    public function setVerdict(string $url, array $verdict): void
    {
        $this->customVerdicts[$url] = $verdict;
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
}
