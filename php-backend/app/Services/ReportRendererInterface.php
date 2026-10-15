<?php

declare(strict_types=1);

namespace App\Services;

interface ReportRendererInterface
{
    /**
     * Render the HTML report from snapshot and engine-result report data.
     *
     * @param array $snapshot
     * @param array $report
     * @return string
     */
    public function render(array $snapshot, array $report): string;
}
