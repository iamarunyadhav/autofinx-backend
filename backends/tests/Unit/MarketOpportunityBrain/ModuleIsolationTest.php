<?php

namespace Tests\Unit\MarketOpportunityBrain;

use Tests\TestCase;

/**
 * Static source guard for PHASES_AND_TEST_GATES.md's
 * `test_opportunity_brain_cannot_submit_order_or_modify_execution_config` invariant. There are no
 * routes/jobs/bindings yet (Phase 1 is contracts/fixtures only), so this test cannot yet assert
 * against route tables -- it instead asserts the module's OWN source never references an
 * execution-capable class/method, so the guard is in place before any wiring is ever added.
 * This test must be revisited (not weakened) once Phase 2+ adds routes/jobs.
 */
class ModuleIsolationTest extends TestCase
{
    private const FORBIDDEN_SYMBOLS = [
        'FutureTradeBotService',
        'BinanceFuturesClient',
        'RatioBotController',
        'placeOrder',
        'placeConditionalOrder',
        'BinanceAlgoOrderApi',
        'ExchangeSecretVault',
        'pine_webhook',
        'prime_mirror',
    ];

    private function moduleFiles(): array
    {
        $dir = base_path('app/Domain/MarketOpportunityBrain');
        $this->assertDirectoryExists($dir);

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        $files = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** @test */
    public function module_source_never_references_an_execution_capable_class_or_method(): void
    {
        $files = $this->moduleFiles();
        $this->assertNotEmpty($files, 'Expected at least one MarketOpportunityBrain source file to scan.');

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            foreach (self::FORBIDDEN_SYMBOLS as $symbol) {
                $this->assertStringNotContainsString(
                    $symbol,
                    $contents,
                    "MarketOpportunityBrain must never reference {$symbol} ({$file})."
                );
            }
        }
    }

    /** @test */
    public function no_existing_route_file_mentions_the_new_module_yet(): void
    {
        foreach (['routes/api.php', 'routes/web.php', 'routes/console.php'] as $routeFile) {
            $path = base_path($routeFile);
            if (! file_exists($path)) {
                continue;
            }
            $this->assertStringNotContainsString(
                'MarketOpportunityBrain',
                file_get_contents($path),
                "{$routeFile} must not reference MarketOpportunityBrain yet -- Phase 1 is contracts/fixtures only."
            );
        }
    }

    // DecisionRecord's own forbidden-field rejection (quantity/leverage/api_key/order_type) is
    // covered by DecisionRecordContractTest::rejects_forbidden_order_fields -- not duplicated here,
    // since DecisionRecord's source legitimately contains those names as a rejection list.
}
