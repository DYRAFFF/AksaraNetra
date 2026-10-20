<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AuditCancelledException;
use App\Models\AuditJob;
use App\Services\Auditor;
use App\Services\ReportRenderer;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeBridgeClient;

class AuditorTest extends TestCase
{
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectoryRecursive($dir);
            }
        }
        parent::tearDown();
    }

    private function createTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . uniqid();
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDirectoryRecursive(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectoryRecursive($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    private function createJob(string $artifactDir): AuditJob
    {
        return new AuditJob(
            id: 'aud_test1234567890123456',
            tokenHash: str_repeat('a', 64),
            shareTokenHash: str_repeat('b', 64),
            ipHash: str_repeat('c', 64),
            targetUrl: 'https://example.com/',
            status: 'running',
            stage: 'Menyiapkan audit',
            progress: 5,
            attempts: 1,
            cancelRequested: false,
            result: null,
            errorCode: null,
            errorMessage: null,
            createdAt: 1000,
            updatedAt: 1000,
            startedAt: 1000,
            finishedAt: null,
            expiresAt: 61000,
            artifactDir: $artifactDir
        );
    }

    public function test_auditor_runs_full_lifecycle_and_generates_artifacts(): void
    {
        $dir = $this->createTempDir('auditor-test-');
        $job = $this->createJob($dir);

        $bridge = new FakeBridgeClient();
        $renderer = new ReportRenderer();
        $auditor = new Auditor($bridge, $renderer);

        $progressUpdates = [];
        $result = $auditor->audit(
            $job,
            onProgress: function ($update) use (&$progressUpdates) {
                $progressUpdates[] = $update;
            }
        );

        $this->assertNotEmpty($progressUpdates);
        $stages = array_column($progressUpdates, 'progress');
        $this->assertContains(96, $stages);
        $this->assertContains(99, $stages);

        $this->assertArrayHasKey('snapshot', $result);
        $this->assertArrayHasKey('artifacts', $result);
        $this->assertSame('reader.html', $result['artifacts']['reader']);
        $this->assertSame('patched.html', $result['artifacts']['patched']);
        $this->assertSame('report.pdf', $result['artifacts']['reportPdf']);
        $this->assertSame('after.png', $result['artifacts']['screenshot']);

        $this->assertFileExists($dir . DIRECTORY_SEPARATOR . 'report.html');
        $this->assertFileExists($dir . DIRECTORY_SEPARATOR . 'report.pdf');

        $html = (string) file_get_contents($dir . DIRECTORY_SEPARATOR . 'report.html');
        $this->assertStringStartsWith('<!doctype html><html lang="id">', $html);
        $this->assertStringContainsString('AksaraNetra · Engine', $html);
    }

    public function test_auditor_resumes_from_checkpoint_without_calling_bridge_audit(): void
    {
        $dir = $this->createTempDir('auditor-checkpoint-');
        $job = $this->createJob($dir);

        // Pre-create checkpoint files
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'snapshot.json', json_encode(['restored' => true]));
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'engine-result.json', json_encode(['score' => 100]));

        $bridgeCalled = false;
        $bridge = new FakeBridgeClient();
        $bridge->setAuditHandler(function () use (&$bridgeCalled) {
            $bridgeCalled = true;

            return ['type' => 'result', 'snapshot' => []];
        });

        $renderer = new ReportRenderer();
        $auditor = new Auditor($bridge, $renderer);

        $progressUpdates = [];
        $result = $auditor->audit(
            $job,
            onProgress: function ($update) use (&$progressUpdates) {
                $progressUpdates[] = $update;
            }
        );

        $this->assertFalse($bridgeCalled, 'Bridge audit should not be called when checkpoint exists');
        $stages = array_column($progressUpdates, 'progress');
        $this->assertContains(95, $stages);
        $this->assertSame(['restored' => true], $result['snapshot']);
    }

    public function test_auditor_handles_pdf_fallback_gracefully(): void
    {
        $dir = $this->createTempDir('auditor-pdf-fail-');
        $job = $this->createJob($dir);

        $bridge = new FakeBridgeClient();
        $bridge->setRenderPdfHandler(function () {
            throw new \RuntimeException('Chromium low memory');
        });

        $renderer = new ReportRenderer();
        $auditor = new Auditor($bridge, $renderer);

        $result = $auditor->audit($job);

        // Fallback should point reportPdf to report.html
        $this->assertSame('report.html', $result['artifacts']['reportPdf']);
        $this->assertFileExists($dir . DIRECTORY_SEPARATOR . 'report.html');
        $this->assertFileDoesNotExist($dir . DIRECTORY_SEPARATOR . 'report.pdf');
    }

    public function test_auditor_aborts_when_cancellation_signaled(): void
    {
        $dir = $this->createTempDir('auditor-cancel-');
        $job = $this->createJob($dir);

        $bridge = new FakeBridgeClient();
        $renderer = new ReportRenderer();
        $auditor = new Auditor($bridge, $renderer);

        $this->expectException(AuditCancelledException::class);
        $auditor->audit($job, shouldCancel: fn() => true);
    }

    public function test_auditor_returns_null_report_pdf_when_neither_pdf_nor_html_exists(): void
    {
        $dir = $this->createTempDir('auditor-no-html-');
        $job = $this->createJob($dir);

        $bridge = new FakeBridgeClient();
        $bridge->setAuditHandler(function ($id, $url, $artifactDir) {
            // Return empty files and snapshot, do not write engine-result.json
            return ['type' => 'result', 'snapshot' => ['empty' => true]];
        });

        $renderer = new ReportRenderer();
        $auditor = new Auditor($bridge, $renderer);

        $result = $auditor->audit($job);
        $this->assertNull($result['artifacts']['reportPdf']);
    }
}
