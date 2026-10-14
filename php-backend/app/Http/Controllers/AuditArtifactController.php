<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AksaraConfig;
use App\Services\JobStore;
use App\Services\SecurityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AuditArtifactController
{
    private const ARTIFACTS = [
        'reader' => ['file' => 'reader.html', 'type' => 'text/html; charset=utf-8'],
        'patched' => ['file' => 'patched.html', 'type' => 'text/html; charset=utf-8'],
        'report.pdf' => ['file' => 'report.pdf', 'type' => 'application/pdf'],
        'screenshot' => ['file' => 'after.png', 'type' => 'image/png'],
    ];

    public function __construct(
        private readonly AksaraConfig $config,
        private readonly SecurityService $security,
        private readonly JobStore $store
    ) {}

    public function show(Request $request, string $id, string $artifact): JsonResponse|BinaryFileResponse
    {
        $job = $this->store->getJob($id);
        if (!$job) {
            return response()->json([
                'error' => 'not-found',
                'message' => 'Audit tidak ditemukan.',
            ], 404);
        }

        $suppliedToken = (string) $request->query('token', '');
        $expected = $this->security->digest($suppliedToken, $this->config->secret);

        if ($suppliedToken === '' || !$this->security->safeEqualHex($job->shareTokenHash, $expected)) {
            return response()->json([
                'error' => 'not-found',
                'message' => 'Artefak tidak ditemukan.',
            ], 404);
        }

        $now = (int) (microtime(true) * 1000);
        if ($job->status !== 'completed' || $job->expiresAt <= $now) {
            return response()->json([
                'error' => 'expired',
                'message' => 'Artefak sudah kedaluwarsa.',
            ], 410);
        }

        $definition = self::ARTIFACTS[$artifact] ?? null;
        if (!$definition) {
            return response()->json([
                'error' => 'not-found',
                'message' => 'Artefak tidak ditemukan.',
            ], 404);
        }

        $filePath = $job->artifactDir . DIRECTORY_SEPARATOR . $definition['file'];
        if (!file_exists($filePath)) {
            return response()->json([
                'error' => 'not-found',
                'message' => 'Artefak tidak ditemukan.',
            ], 404);
        }

        $realJobDir = realpath($job->artifactDir);
        $realFilePath = realpath($filePath);
        if (!$realFilePath || !$realJobDir || !str_starts_with($realFilePath, $realJobDir)) {
            return response()->json([
                'error' => 'not-found',
                'message' => 'Artefak tidak ditemukan.',
            ], 404);
        }

        $response = response()->file($realFilePath, [
            'Content-Type' => $definition['type'],
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src https: http: data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'",
        ]);
        $response->setPrivate();
        $response->setMaxAge(300);

        return $response;
    }
}
