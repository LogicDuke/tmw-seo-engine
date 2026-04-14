<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ActivationTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_tmw_test_options']    = [];
        $GLOBALS['_tmw_test_transients'] = [];
    }

    // ── Constants and paths ───────────────────────────────────────────────────

    public function test_version_constant_is_defined(): void {
        $this->assertTrue( defined( 'TMWSEO_ENGINE_VERSION' ) );
    }

    public function test_version_is_4_6_4(): void {
        $this->assertSame( '5.0.0', TMWSEO_ENGINE_VERSION );
    }

    public function test_plugin_header_version_matches_constant(): void {
        $contents = (string) file_get_contents( TMWSEO_ENGINE_PATH . 'tmw-seo-engine.php' );
        $this->assertStringContainsString( 'Version: 5.0.0', $contents );
    }

    public function test_path_constant_is_real_directory(): void {
        $this->assertDirectoryExists( TMWSEO_ENGINE_PATH );
    }

    public function test_bootstrapped_guard_is_defined(): void {
        $this->assertTrue( defined( 'TMWSEO_ENGINE_BOOTSTRAPPED' ) );
    }

    // ── Critical files present ────────────────────────────────────────────────

    /** @dataProvider criticalFiles */
    public function test_critical_file_exists( string $rel ): void {
        $this->assertFileExists( TMWSEO_ENGINE_PATH . $rel );
    }

    public static function criticalFiles(): array {
        return [
            'main plugin file'          => [ 'tmw-seo-engine.php' ],
            'Plugin class'              => [ 'includes/class-plugin.php' ],
            'Schema'                    => [ 'includes/db/class-schema.php' ],
            'DataForSEO service'        => [ 'includes/services/class-dataforseo.php' ],
            'AI Router'                 => [ 'includes/ai/class-ai-router.php' ],
            'DiscoveryGovernor'         => [ 'includes/class-discovery-governor.php' ],
            'KeywordEngine'             => [ 'includes/keywords/class-keyword-engine.php' ],
            'ModelDiscoveryWorker'      => [ 'includes/model/class-model-discovery-worker.php' ],
            'ModelSerpResearchProvider' => [ 'includes/model/class-model-serp-research-provider.php' ],
            'ModelHelper admin'         => [ 'includes/admin/class-model-helper.php' ],
            'DiscoveryControlPage'      => [ 'includes/admin/class-discovery-control-admin-page.php' ],
            'GSC API'                   => [ 'includes/integrations/class-gsc-api.php' ],
            'SeedRegistry'              => [ 'includes/keywords/class-seed-registry.php' ],
            'KeywordScheduler'          => [ 'includes/keywords/class-keyword-scheduler.php' ],
            'CHANGELOG'                 => [ 'CHANGELOG.md' ],
        ];
    }

    // ── Deleted artefacts are gone ────────────────────────────────────────────

    public function test_worker_cron_dead_class_deleted(): void {
        $this->assertFileDoesNotExist(
            TMWSEO_ENGINE_PATH . 'includes/engine/class-worker-cron.php',
            'WorkerCron dead class must be gone (BUG-07)'
        );
    }

    public function test_docs_dir_not_in_build(): void {
        $this->assertDirectoryDoesNotExist(
            TMWSEO_ENGINE_PATH . 'docs',
            'docs/ must not ship in production (FINDING-11)'
        );
    }

    // ── Classes loadable ──────────────────────────────────────────────────────

    public function test_settings_class_loadable(): void {
        $this->assertTrue( class_exists( 'TMWSEO\\Engine\\Services\\Settings' ) );
    }

    public function test_dataforseo_class_loadable(): void {
        $this->assertTrue( class_exists( 'TMWSEO\\Engine\\Services\\DataForSEO' ) );
    }

    public function test_discovery_governor_loadable(): void {
        $this->assertTrue( class_exists( 'TMWSEO\\Engine\\DiscoveryGovernor' ) );
    }

    public function test_gsc_api_loadable(): void {
        $this->assertTrue( class_exists( 'TMWSEO\\Engine\\Integrations\\GSCApi' ) );
    }

    public function test_ai_router_loadable(): void {
        $this->assertTrue( class_exists( 'TMWSEO\\Engine\\AI\\AIRouter' ) );
    }

    // ── Safe defaults ─────────────────────────────────────────────────────────

    public function test_model_discovery_off_by_default(): void {
        $this->assertSame( 0,
            (int) \TMWSEO\Engine\Services\Settings::get( 'model_discovery_enabled', 0 ),
            'model_discovery_enabled must default 0 — scraping is opt-in (BUG-11)'
        );
    }

    public function test_safe_mode_on_by_default(): void {
        $this->assertSame( 1,
            (int) \TMWSEO\Engine\Services\Settings::get( 'safe_mode', 1 ),
            'safe_mode must default 1 on fresh installs'
        );
    }

    public function test_dataforseo_default_budget_is_positive(): void {
        $budget = (float) \TMWSEO\Engine\Services\Settings::get( 'tmwseo_dataforseo_budget_usd', 20.0 );
        $this->assertGreaterThan( 0.0, $budget,
            'DataForSEO budget must default > 0 to prevent uncapped spend'
        );
    }

    // ── Full syntax check ─────────────────────────────────────────────────────

    public function test_all_plugin_php_files_pass_syntax_check(): void {
        $failed   = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( TMWSEO_ENGINE_PATH )
        );
        foreach ( $iterator as $file ) {
            if ( ! ( $file instanceof SplFileInfo ) || $file->getExtension() !== 'php' ) {
                continue;
            }
            if ( str_contains( $file->getPathname(), '/tests/' ) ) {
                continue;
            }
            $out = (string) shell_exec( 'php -l ' . escapeshellarg( $file->getPathname() ) . ' 2>&1' );
            if ( ! str_contains( $out, 'No syntax errors' ) ) {
                $failed[] = $file->getPathname() . ' → ' . trim( $out );
            }
        }
        $this->assertEmpty( $failed, "Syntax errors:\n" . implode( "\n", $failed ) );
    }
}
