<?php

declare(strict_types=1);

namespace App\Services;

class StubReportRenderer implements ReportRendererInterface
{
    /**
     * Minimal placeholder for Phase 4. Phase 5 will implement the full byte-identical renderer.
     *
     * @param array $snapshot
     * @param array $report
     * @return string
     */
    public function render(array $snapshot, array $report): string
    {
        $title = htmlspecialchars((string) ($snapshot['page']['title'] ?? 'AksaraNetra Audit Report'), ENT_QUOTES, 'UTF-8');
        return "<!DOCTYPE html>\n<html lang=\"id\">\n<head>\n<meta charset=\"UTF-8\">\n<title>{$title}</title>\n</head>\n<body>\n<h1>{$title}</h1>\n</body>\n</html>";
    }
}
