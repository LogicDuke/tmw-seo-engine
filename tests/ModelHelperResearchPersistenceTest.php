<?php
/**
 * Focused tests for ModelHelper::run_research_now persistence hardening.
 */

namespace TMWSEO\Engine\Admin;

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Model\ModelContextAwareProvider;
use TMWSEO\Engine\Model\ModelResearchProvider;

$GLOBALS['_tmw_model_helper_meta'] = [];
$GLOBALS['_tmw_model_helper_title_by_post'] = [];
$GLOBALS['_tmw_model_helper_research_providers'] = [];
$GLOBALS['_tmw_model_helper_force_corrupt_proposed_save'] = false;
$GLOBALS['_tmw_model_helper_lock_options'] = [];

/**
 * Test-only post meta reader for ModelHelper namespace calls.
 */
function get_post_meta( int $id, string $key = '', bool $single = false ) {
    $value = $GLOBALS['_tmw_model_helper_meta'][ $id ][ $key ] ?? '';
    return $single ? $value : [ $value ];
}

/**
 * Test-only post meta writer that mirrors WP's string unslash behavior.
 */
function update_post_meta( int $id, string $key, $value, $prev_value = '' ): bool {
    if ( is_string( $value ) ) {
        $value = stripslashes( $value );
    }

    if (
        $key === ModelHelper::META_PROPOSED
        && ! empty( $GLOBALS['_tmw_model_helper_force_corrupt_proposed_save'] )
    ) {
        $value = '{bad json';
    }

    if ( ! isset( $GLOBALS['_tmw_model_helper_meta'][ $id ] ) ) {
        $GLOBALS['_tmw_model_helper_meta'][ $id ] = [];
    }

    $GLOBALS['_tmw_model_helper_meta'][ $id ][ $key ] = $value;
    return true;
}

/**
 * Test-only post meta delete.
 */
function delete_post_meta( int $id, string $key, $value = '' ): bool {
    if ( isset( $GLOBALS['_tmw_model_helper_meta'][ $id ][ $key ] ) ) {
        unset( $GLOBALS['_tmw_model_helper_meta'][ $id ][ $key ] );
        return true;
    }
    return false;
}

/**
 * Test-only title lookup for a model post.
 */
function get_the_title( $post = null ): string {
    $post_id = (int) $post;
    return (string) ( $GLOBALS['_tmw_model_helper_title_by_post'][ $post_id ] ?? '' );
}

/**
 * Deterministic timestamp for tests.
 */
function current_time( string $type = 'mysql', bool $gmt = false ): string {
    return '2026-04-15 00:00:00';
}

/**
 * Preserve WordPress-style fallback behavior when no providers are injected.
 */
function apply_filters( string $hook, $value, ...$args ) {
    if ( $hook === 'tmwseo_research_providers' ) {
        if ( ! empty( $GLOBALS['_tmw_model_helper_research_providers'] ) ) {
            return $GLOBALS['_tmw_model_helper_research_providers'];
        }
        return $value;
    }
    return $value;
}

/**
 * Test-only slash helper equivalent for JSON persistence calls.
 */
function wp_slash( $value ) {
    if ( is_string( $value ) ) {
        return addslashes( $value );
    }
    if ( is_array( $value ) ) {
        return array_map( __NAMESPACE__ . '\\wp_slash', $value );
    }
    return $value;
}

/**
 * Test-only option add with atomic-if-missing semantics.
 */
function add_option( string $key, $value = '', $deprecated = '', $autoload = 'yes' ): bool {
    if ( array_key_exists( $key, $GLOBALS['_tmw_model_helper_lock_options'] ) ) {
        return false;
    }
    $GLOBALS['_tmw_model_helper_lock_options'][ $key ] = $value;
    return true;
}

/**
 * Test-only option get.
 */
function get_option( string $key, $default = false ) {
    return array_key_exists( $key, $GLOBALS['_tmw_model_helper_lock_options'] )
        ? $GLOBALS['_tmw_model_helper_lock_options'][ $key ]
        : $default;
}

/**
 * Test-only option delete.
 */
function delete_option( string $key ): bool {
    unset( $GLOBALS['_tmw_model_helper_lock_options'][ $key ] );
    return true;
}

require_once __DIR__ . '/../includes/admin/class-model-helper.php';

final class StaticResultProvider implements ModelResearchProvider {
    /** @var array<string,mixed> */
    private array $result;

    /** @param array<string,mixed> $result */
    public function __construct( array $result ) {
        $this->result = $result;
    }

    public function provider_name(): string {
        return 'test_provider';
    }

    public function lookup( int $post_id, string $model_name ): array {
        return $this->result;
    }
}

final class SerpDiscoveryProvider implements ModelResearchProvider {
    public function provider_name(): string {
        return 'dataforseo_serp';
    }

    public function lookup( int $post_id, string $model_name ): array {
        return [
            'status'                        => 'ok',
            'display_name'                  => $model_name,
            'aliases'                       => [],
            'bio'                           => '',
            'platform_names'                => [ 'Chaturbate' ],
            'social_urls'                   => [],
            'platform_candidates'           => [],
            'field_confidence'              => [ 'platform_names' => 45 ],
            'research_diagnostics'          => [],
            'country'                       => '',
            'language'                      => '',
            'source_urls'                   => [],
            'confidence'                    => 45,
            'notes'                         => 'SERP',
            'discovered_handles_structured' => [
                [
                    'handle'          => 'anisyia',
                    'source_platform' => 'chaturbate',
                    'source_url'      => 'https://chaturbate.com/anisyia/',
                    'method'          => 'structured_platform',
                    'tier'            => 1,
                ],
            ],
        ];
    }
}

final class ContextAwareProbeRecorderProvider implements ModelResearchProvider, ModelContextAwareProvider {
    /** @var array<string,array> */
    public array $received_prior_results = [];

    public function provider_name(): string {
        return 'direct_probe';
    }

    public function set_prior_results( array $prior_results ): void {
        $this->received_prior_results = $prior_results;
    }

    public function lookup( int $post_id, string $model_name ): array {
        $serp_handles = (array) ( $this->received_prior_results['dataforseo_serp']['discovered_handles_structured'] ?? [] );
        $first_handle = (string) ( $serp_handles[0]['handle'] ?? '' );

        return [
            'status'               => 'ok',
            'display_name'         => $model_name,
            'aliases'              => [],
            'bio'                  => '',
            'platform_names'       => $first_handle !== '' ? [ 'Shared:' . $first_handle ] : [],
            'social_urls'          => [],
            'platform_candidates'  => [],
            'field_confidence'     => [ 'platform_names' => 20 ],
            'research_diagnostics' => [ 'shared_handles' => $serp_handles ],
            'country'              => '',
            'language'             => '',
            'source_urls'          => [],
            'confidence'           => 20,
            'notes'                => 'Probe',
        ];
    }
}

final class ModelHelperResearchPersistenceTest extends TestCase {
    /**
     * Reset deterministic test doubles for each test.
     */
    protected function setUp(): void {
        $GLOBALS['_tmw_model_helper_meta'] = [];
        $GLOBALS['_tmw_model_helper_title_by_post'] = [];
        $GLOBALS['_tmw_model_helper_research_providers'] = [];
        $GLOBALS['_tmw_model_helper_force_corrupt_proposed_save'] = false;
        $GLOBALS['_tmw_model_helper_lock_options'] = [];

        $reflection = new \ReflectionClass( ModelResearchPipeline::class );
        $providers = $reflection->getProperty( 'providers' );
        $providers->setAccessible( true );
        $providers->setValue( null, null );
    }

    /**
     * Valid JSON should survive persistence and set researched status.
     */
    public function test_valid_proposed_blob_round_trips_and_sets_researched_status(): void {
        $post_id = 101;
        $GLOBALS['_tmw_model_helper_title_by_post'][ $post_id ] = 'Model Alpha';
        $GLOBALS['_tmw_model_helper_research_providers'] = [
            new StaticResultProvider( [
                'status'       => 'ok',
                'display_name' => 'Model Alpha',
            ] ),
        ];

        ModelHelper::run_research_now( $post_id );

        $stored = (string) get_post_meta( $post_id, ModelHelper::META_PROPOSED, true );
        $this->assertNotSame( '', $stored );
        $this->assertIsArray( json_decode( $stored, true ) );
        $this->assertSame( 'researched', get_post_meta( $post_id, ModelHelper::META_STATUS, true ) );
    }

    /**
     * Corrupt persisted JSON should be removed and force error status.
     */
    public function test_corrupt_round_trip_deletes_blob_and_sets_error_status(): void {
        $post_id = 102;
        $GLOBALS['_tmw_model_helper_title_by_post'][ $post_id ] = 'Model Beta';
        $GLOBALS['_tmw_model_helper_research_providers'] = [
            new StaticResultProvider( [
                'status'       => 'ok',
                'display_name' => 'Model Beta',
            ] ),
        ];
        $GLOBALS['_tmw_model_helper_force_corrupt_proposed_save'] = true;

        ModelHelper::run_research_now( $post_id );

        $this->assertSame( '', get_post_meta( $post_id, ModelHelper::META_PROPOSED, true ) );
        $this->assertSame( 'error', get_post_meta( $post_id, ModelHelper::META_STATUS, true ) );
    }

    /**
     * Stale proposed data should not remain after a failed rerun.
     */
    public function test_stale_previous_blob_is_cleared_and_not_retained_on_failed_rerun(): void {
        $post_id = 103;
        $GLOBALS['_tmw_model_helper_title_by_post'][ $post_id ] = 'Model Gamma';
        $GLOBALS['_tmw_model_helper_meta'][ $post_id ][ ModelHelper::META_PROPOSED ] = '{"stale":true}';
        $GLOBALS['_tmw_model_helper_research_providers'] = [
            new StaticResultProvider( [
                'status'       => 'ok',
                'display_name' => 'Model Gamma',
            ] ),
        ];
        $GLOBALS['_tmw_model_helper_force_corrupt_proposed_save'] = true;

        ModelHelper::run_research_now( $post_id );

        $this->assertSame( '', get_post_meta( $post_id, ModelHelper::META_PROPOSED, true ) );
        $this->assertSame( 'error', get_post_meta( $post_id, ModelHelper::META_STATUS, true ) );
    }

    /**
     * Escaped characters in JSON payload should persist and decode cleanly.
     */
    public function test_escaped_json_payload_round_trips_without_false_corruption(): void {
        $post_id = 104;
        $GLOBALS['_tmw_model_helper_title_by_post'][ $post_id ] = 'Model "Slash\\Line"';
        $GLOBALS['_tmw_model_helper_research_providers'] = [
            new StaticResultProvider( [
                'status'       => 'ok',
                'display_name' => "Quote \" Backslash \\ Newline \\n",
                'notes'        => "Escaped \\\"text\\\" and path C:\\\\tmp\\\\file",
            ] ),
        ];

        ModelHelper::run_research_now( $post_id );

        $stored  = (string) get_post_meta( $post_id, ModelHelper::META_PROPOSED, true );
        $decoded = json_decode( $stored, true );

        $this->assertNotSame( '', $stored );
        $this->assertIsArray( $decoded );
        $this->assertSame( 'researched', get_post_meta( $post_id, ModelHelper::META_STATUS, true ) );
        $this->assertSame( "Quote \" Backslash \\ Newline \\n", (string) ( $decoded['merged']['display_name'] ?? '' ) );
    }

    /**
     * Empty provider injection should preserve default provider fallback behavior.
     */
    public function test_apply_filters_fallback_keeps_default_provider_when_none_injected(): void {
        $post_id = 105;
        $GLOBALS['_tmw_model_helper_title_by_post'][ $post_id ] = 'Model Delta';
        $GLOBALS['_tmw_model_helper_research_providers'] = [];

        ModelHelper::run_research_now( $post_id );

        $this->assertSame( 'not_researched', get_post_meta( $post_id, ModelHelper::META_STATUS, true ) );
        $stored  = (string) get_post_meta( $post_id, ModelHelper::META_PROPOSED, true );
        $decoded = json_decode( $stored, true );
        $this->assertIsArray( $decoded );
        $this->assertSame( 'no_provider', (string) ( $decoded['pipeline_status'] ?? '' ) );
    }

    /**
     * Existing lock should skip run safely and avoid writes.
     */
    public function test_run_research_now_skips_when_lock_is_already_held(): void {
        $post_id = 106;
        $GLOBALS['_tmw_model_helper_title_by_post'][ $post_id ] = 'Model Epsilon';
        $GLOBALS['_tmw_model_helper_research_providers'] = [
            new StaticResultProvider( [ 'status' => 'ok', 'display_name' => 'Model Epsilon' ] ),
        ];
        $GLOBALS['_tmw_model_helper_lock_options'][ 'tmwseo_research_lock_' . $post_id ] = time() + 300;

        ModelHelper::run_research_now( $post_id );

        $this->assertSame( '', get_post_meta( $post_id, ModelHelper::META_PROPOSED, true ) );
        $this->assertSame( '', get_post_meta( $post_id, ModelHelper::META_STATUS, true ) );
    }

    /**
     * Regression: pipeline must pass SERP context to direct probe providers.
     */
    public function test_pipeline_passes_serp_context_to_context_aware_probe_provider(): void {
        $post_id = 107;
        $GLOBALS['_tmw_model_helper_title_by_post'][ $post_id ] = 'Model Zeta';

        $probe_provider = new ContextAwareProbeRecorderProvider();
        $GLOBALS['_tmw_model_helper_research_providers'] = [
            new SerpDiscoveryProvider(),
            $probe_provider,
        ];

        $result = ModelResearchPipeline::run( $post_id );

        $this->assertArrayHasKey( 'dataforseo_serp', $probe_provider->received_prior_results );
        $this->assertSame(
            'anisyia',
            (string) ( $probe_provider->received_prior_results['dataforseo_serp']['discovered_handles_structured'][0]['handle'] ?? '' )
        );
        $this->assertContains( 'Shared:anisyia', (array) ( $result['merged']['platform_names'] ?? [] ) );
    }
}
