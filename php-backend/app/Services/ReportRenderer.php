<?php

declare(strict_types=1);

namespace App\Services;

class ReportRenderer implements ReportRendererInterface
{
    /**
     * Escape special HTML characters matching Node.js esc() helper in report.mjs:
     * String(value ?? "")
     *   .replace(/&/g, "&amp;")
     *   .replace(/</g, "&lt;")
     *   .replace(/>/g, "&gt;")
     *   .replace(/"/g, "&quot;")
     */
    public static function esc(mixed $value): string
    {
        if (is_bool($value)) {
            $str = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $str = '';
        } else {
            $str = (string) $value;
        }

        $str = str_replace('&', '&amp;', $str);
        $str = str_replace('<', '&lt;', $str);
        $str = str_replace('>', '&gt;', $str);

        return str_replace('"', '&quot;', $str);
    }

    /**
     * Render table rows matching rows() helper in report.mjs:
     * if (!records.length) return "<p>Tidak ada.</p>";
     * return `<table><thead><tr><th>Rule</th><th>Elemen</th><th>Status</th><th>Confidence</th></tr></thead><tbody>...</tbody></table>`;
     */
    public static function rows(mixed $records = []): string
    {
        if (!is_array($records) || count($records) === 0) {
            return '<p>Tidak ada.</p>';
        }

        $itemsHtml = '';
        foreach ($records as $item) {
            $rule = self::esc($item['rule'] ?? null);
            $selector = self::esc($item['selector'] ?? null);
            $status = self::esc($item['status'] ?? null);
            $confidence = self::esc($item['confidence'] ?? null);
            $itemsHtml .= "<tr><td>{$rule}</td><td><code>{$selector}</code></td><td>{$status}</td><td>{$confidence}</td></tr>";
        }

        return "<table><thead><tr><th>Rule</th><th>Elemen</th><th>Status</th><th>Confidence</th></tr></thead><tbody>{$itemsHtml}</tbody></table>";
    }

    /**
     * Format regression list as 2-space indented JSON matching JSON.stringify(regression, null, 2).
     */
    public static function stringifyRegression(array $regression): string
    {
        $json = json_encode($regression, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return '[]';
        }

        // Convert 4-space indentation to 2-space indentation per nesting level
        return preg_replace_callback('/^( +)/m', function ($matches) {
            return str_repeat(' ', (int) (strlen($matches[1]) / 2));
        }, $json) ?? $json;
    }

    /**
     * Render the standalone audit HTML report byte-identically to backend/src/report.mjs:renderAuditReport.
     */
    public function render(array $snapshot, array $report): string
    {
        return self::renderAuditReport([
            'snapshot' => $snapshot,
            'report' => $report,
        ]);
    }

    /**
     * Static entrypoint accepting ['snapshot' => ..., 'report' => ...] matching Node API signature.
     */
    public static function renderAuditReport(array $args): string
    {
        $snapshot = $args['snapshot'] ?? [];
        $report = $args['report'] ?? [];

        $summary = $snapshot['summary'] ?? [];
        $rawTitle = $snapshot['source']['title'] ?? null;
        $title = ($rawTitle !== null && $rawTitle !== '') ? (string) $rawTitle : 'Laporan audit AksaraNetra';
        $regression = $snapshot['warnings']['worsenedRules'] ?? [];
        if (!is_array($regression)) {
            $regression = [];
        }

        $escTitle = self::esc($title);
        $escEngineVersion = self::esc($snapshot['engineVersion'] ?? null);
        $escDisclaimer = self::esc($snapshot['disclaimer'] ?? null);
        $escFinalUrl = self::esc($snapshot['source']['finalUrl'] ?? null);
        $escCapturedAt = self::esc($snapshot['capturedAt'] ?? null);
        $escBeforeTotal = self::esc($summary['beforeTotal'] ?? null);
        $escAfterTotal = self::esc($summary['afterTotal'] ?? null);
        $escFixedCount = self::esc($snapshot['counts']['fixed'] ?? null);
        $escReductionPercent = self::esc($summary['reductionPercent'] ?? null);

        $wcagClean = !empty($snapshot['warnings']['wcagRegressionClean']);
        $wcagClass = $wcagClean ? 'ok' : 'warn';
        $wcagText = $wcagClean ? 'Tidak ada rule WCAG luas yang memburuk.' : 'Ada perubahan yang perlu ditinjau pada pemindaian WCAG luas.';

        $regressionBlock = '';
        if (count($regression) > 0) {
            $regressionBlock = '<pre>' . self::esc(self::stringifyRegression($regression)) . '</pre>';
        }

        $rowsFixed = self::rows($report['fixed'] ?? []);
        $rowsReview = self::rows($report['review'] ?? []);
        $rowsRolledBack = self::rows($report['rolledBackRecords'] ?? []);

        return '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>' . $escTitle . '</title><style>
@page{size:A4;margin:18mm}*{box-sizing:border-box}body{font:11pt/1.55 Arial,sans-serif;color:#172033}h1{font-size:22pt}h2{margin-top:22pt;border-bottom:2px solid #294f91;padding-bottom:4pt}a{color:#0b3d91}code{font:9pt Consolas,monospace;overflow-wrap:anywhere}.meta,.cards{display:grid;grid-template-columns:repeat(2,1fr);gap:10pt}.card{border:1px solid #aab4c5;border-radius:6pt;padding:10pt}.number{font-size:20pt;font-weight:700}table{border-collapse:collapse;width:100%;font-size:9pt}th,td{border:1px solid #aab4c5;padding:6pt;text-align:left;vertical-align:top}th{background:#edf2fb}.ok{color:#176b37}.warn{color:#8a4b08}footer{margin-top:24pt;border-top:1px solid #aab4c5;padding-top:8pt;font-size:9pt}
</style></head><body><header><p>AksaraNetra · Engine ' . $escEngineVersion . '</p><h1>' . $escTitle . '</h1><p>' . $escDisclaimer . '</p></header>
<section class="meta"><div><strong>Sumber</strong><br>' . $escFinalUrl . '</div><div><strong>Waktu audit</strong><br>' . $escCapturedAt . '</div></section>
<h2>Ringkasan</h2><section class="cards"><div class="card"><div class="number">' . $escBeforeTotal . '</div>Temuan target sebelum</div><div class="card"><div class="number">' . $escAfterTotal . '</div>Temuan target setelah</div><div class="card"><div class="number">' . $escFixedCount . '</div>Patch terverifikasi</div><div class="card"><div class="number">' . $escReductionPercent . '%</div>Pengurangan</div></section>
<h2>Status verifikasi</h2><p class="' . $wcagClass . '">' . $wcagText . '</p>' . $regressionBlock . '
<h2>Patch terverifikasi</h2>' . $rowsFixed . '
<h2>Perlu review manusia</h2>' . $rowsReview . '
<h2>Patch yang dibatalkan</h2>' . $rowsRolledBack . '
<footer>Laporan dibuat otomatis. Hasil ini tidak menyatakan situs asli telah diperbaiki dan tidak menggantikan pengujian manual dengan teknologi bantu.</footer></body></html>';
    }
}
