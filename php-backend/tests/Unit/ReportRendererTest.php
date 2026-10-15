<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ReportRenderer;
use PHPUnit\Framework\TestCase;

class ReportRendererTest extends TestCase
{
    public function test_esc_escapes_only_html_special_chars_matching_node(): void
    {
        // Ampersand, less-than, greater-than, double-quote
        $this->assertSame(
            '&amp;&lt;&gt;&quot;',
            ReportRenderer::esc('&<>"')
        );

        // Single quote is NOT escaped in Node report.mjs
        $this->assertSame(
            "It's 'AksaraNetra'",
            ReportRenderer::esc("It's 'AksaraNetra'")
        );

        // Mixed content (single quote remains unescaped)
        $this->assertSame(
            'Nilai &quot;aria-expanded&quot; &amp; \'test\' &lt;invalid&gt;',
            ReportRenderer::esc('Nilai "aria-expanded" & \'test\' <invalid>')
        );

        // Null and empty string become empty string
        $this->assertSame('', ReportRenderer::esc(null));
        $this->assertSame('', ReportRenderer::esc(''));

        // Booleans
        $this->assertSame('true', ReportRenderer::esc(true));
        $this->assertSame('false', ReportRenderer::esc(false));

        // Numbers including zero
        $this->assertSame('0', ReportRenderer::esc(0));
        $this->assertSame('100', ReportRenderer::esc(100));
        $this->assertSame('0.95', ReportRenderer::esc(0.95));
    }

    public function test_rows_renders_empty_notice_when_empty(): void
    {
        $this->assertSame('<p>Tidak ada.</p>', ReportRenderer::rows([]));
        $this->assertSame('<p>Tidak ada.</p>', ReportRenderer::rows(null));
    }

    public function test_rows_renders_table_with_escaped_cells(): void
    {
        $records = [
            [
                'rule' => 'link-name',
                'selector' => 'a[href*="&"]',
                'status' => 'verified-fixed',
                'confidence' => 0.9,
            ],
            [
                'rule' => 'image-alt',
                'selector' => '<img class="logo">',
                'status' => 'needs-review',
                'confidence' => 1,
            ],
        ];

        $expected = '<table><thead><tr><th>Rule</th><th>Elemen</th><th>Status</th><th>Confidence</th></tr></thead><tbody>' .
            '<tr><td>link-name</td><td><code>a[href*=&quot;&amp;&quot;]</code></td><td>verified-fixed</td><td>0.9</td></tr>' .
            '<tr><td>image-alt</td><td><code>&lt;img class=&quot;logo&quot;&gt;</code></td><td>needs-review</td><td>1</td></tr>' .
            '</tbody></table>';

        $this->assertSame($expected, ReportRenderer::rows($records));
    }

    public function test_stringify_regression_matches_node_two_space_json(): void
    {
        $regression = [
            [
                'ruleId' => 'color-contrast',
                'before' => 0,
                'after' => 2,
                'delta' => 2,
                'tags' => ['wcag2aa', 'wcag143'],
            ],
        ];

        $expected = "[\n  {\n    \"ruleId\": \"color-contrast\",\n    \"before\": 0,\n    \"after\": 2,\n    \"delta\": 2,\n    \"tags\": [\n      \"wcag2aa\",\n      \"wcag143\"\n    ]\n  }\n]";
        $this->assertSame($expected, ReportRenderer::stringifyRegression($regression));
    }

    public function test_byte_identical_parity_against_all_node_fixtures(): void
    {
        $fixturesDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'reports';
        $this->assertDirectoryExists($fixturesDir);

        $renderer = new ReportRenderer();

        for ($i = 1; $i <= 5; $i++) {
            $inputFile = $fixturesDir . DIRECTORY_SEPARATOR . "fixture_{$i}_input.json";
            $expectedFile = $fixturesDir . DIRECTORY_SEPARATOR . "fixture_{$i}_expected.html";

            $this->assertFileExists($inputFile, "Fixture input {$i} should exist");
            $this->assertFileExists($expectedFile, "Fixture expected {$i} should exist");

            $inputData = json_decode((string) file_get_contents($inputFile), true);
            $expectedHtml = (string) file_get_contents($expectedFile);

            $snapshot = $inputData['snapshot'] ?? [];
            $report = $inputData['report'] ?? [];

            // Test both instance render() and static renderAuditReport()
            $actualHtmlFromRender = $renderer->render($snapshot, $report);
            $actualHtmlFromStatic = ReportRenderer::renderAuditReport($inputData);

            $this->assertSame($actualHtmlFromRender, $actualHtmlFromStatic);

            // Byte-for-byte assertions
            $this->assertSame(
                strlen($expectedHtml),
                strlen($actualHtmlFromRender),
                "Fixture {$i} HTML length must match byte-for-byte"
            );

            $this->assertSame(
                md5($expectedHtml),
                md5($actualHtmlFromRender),
                "Fixture {$i} MD5 hash must match byte-for-byte"
            );

            $this->assertSame(
                $expectedHtml,
                $actualHtmlFromRender,
                "Fixture {$i} string content must match byte-for-byte"
            );
        }
    }
}
