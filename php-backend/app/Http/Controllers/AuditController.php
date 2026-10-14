<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditJob;
use App\Services\AksaraConfig;
use App\Services\AuditWorkerInterface;
use App\Services\BridgeClientInterface;
use App\Services\JobStore;
use App\Services\SecurityService;
use FilesystemIterator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class AuditController
{
    public function __construct(
        private readonly ?AksaraConfig $config = null,
        private readonly ?SecurityService $security = null,
        private readonly ?JobStore $store = null,
        private readonly ?BridgeClientInterface $bridge = null,
        private readonly ?AuditWorkerInterface $worker = null
    ) {}

    private function getConfig(): AksaraConfig { return app(AksaraConfig::class); }
    private function getSecurity(): SecurityService { return app(SecurityService::class); }
    private function getStore(): JobStore { return app(JobStore::class); }
    private function getBridge(): BridgeClientInterface { return app(BridgeClientInterface::class); }
    private function getWorker(): AuditWorkerInterface { return app(AuditWorkerInterface::class); }

    public function store(Request $request): JsonResponse
    {
        $rawContent = $request->getContent();
        $body = $rawContent !== '' ? json_decode($rawContent, true) : [];
        if (!is_array($body)) {
            $body = [];
        }

        $url = $body['url'] ?? null;
        $verdict = $this->getBridge()->checkUrl(is_string($url) ? $url : null);
        if (!$verdict['ok']) {
            return response()->json([
                'error' => $verdict['code'],
                'message' => $verdict['message'],
            ], 400);
        }

        $config = $this->getConfig();
        $security = $this->getSecurity();
        $store = $this->getStore();

        $ip = $security->clientIp($request, $config);
        $ipHash = $security->digest($ip, $config->secret);
        $now = (int) (microtime(true) * 1000);
        $since = $now - $config->rateWindowMs;
        $used = $store->countRecentByIp($ipHash, $since);

        if ($used >= $config->rateLimit) {
            return response()->json([
                'error' => 'rate-limit',
                'message' => "Batas {$config->rateLimit} audit per jam sudah tercapai.",
            ], 429, [
                'Retry-After' => '3600',
            ]);
        }

        $id = $security->randomJobId();
        $accessToken = $security->randomToken();
        $shareToken = $security->deriveShareToken([
            'jobId' => $id,
            'accessToken' => $accessToken,
            'secret' => $config->secret,
        ]);

        $artifactDir = $config->artifactsDir . DIRECTORY_SEPARATOR . $id;

        $job = $store->createJob([
            'id' => $id,
            'tokenHash' => $security->digest($accessToken, $config->secret),
            'shareTokenHash' => $security->digest($shareToken, $config->secret),
            'ipHash' => $ipHash,
            'targetUrl' => $verdict['url'],
            'createdAt' => $now,
            'expiresAt' => $now + $config->retentionMs,
            'artifactDir' => $artifactDir,
        ]);

        $reuseExisting = ($body['reuseExisting'] ?? false) === true;
        $cached = $reuseExisting ? $store->findReusable($verdict['url'], $now) : null;

        if ($cached && $cached->id !== $job->id && is_dir($cached->artifactDir)) {
            $this->copyDirectory($cached->artifactDir, $job->artifactDir);
            $store->completeQueued($job->id, $cached->result);
        } else {
            $this->getWorker()->kick();
        }

        $current = $store->getJob($job->id);
        $statusCode = $current->status === 'completed' ? 200 : 202;

        return response()->json([
            'jobId' => $id,
            'accessToken' => $accessToken,
            'status' => $current->status,
            'statusPath' => "/audits/{$id}",
            'expiresAt' => AuditJob::formatIsoMilliseconds($current->expiresAt),
        ], $statusCode, [
            'Location' => "/audits/{$id}",
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $token = $this->getSecurity()->bearerToken($request);
        $job = $this->authorizedJob($id, $token);

        if (!$job) {
            return response()->json([
                'error' => 'not-found',
                'message' => 'Audit tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'job' => $job->toPublic($this->getStore()->queuePosition($job->id)),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $token = $this->getSecurity()->bearerToken($request);
        $job = $this->authorizedJob($id, $token);

        if (!$job) {
            return response()->json([
                'error' => 'not-found',
                'message' => 'Audit tidak ditemukan.',
            ], 404);
        }

        $updated = $this->getWorker()->cancel($job->id) ?? $this->getStore()->getJob($job->id);

        return response()->json([
            'job' => $updated->toPublic($this->getStore()->queuePosition($updated->id)),
        ], 202);
    }

    private function authorizedJob(string $id, ?string $token): ?AuditJob
    {
        $job = $this->getStore()->getJob($id);
        if (!$job || empty($token)) {
            return null;
        }

        $expected = $this->getSecurity()->digest($token, $this->getConfig()->secret);
        return $this->getSecurity()->safeEqualHex($job->tokenHash, $expected) ? $job : null;
    }

    private function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathname();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }
}
