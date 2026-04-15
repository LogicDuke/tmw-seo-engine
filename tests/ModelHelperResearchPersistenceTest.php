<?php
/**
 * Focused tests for ModelHelper::run_research_now persistence hardening.
 */

namespace TMWSEO\Engine\Admin;

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Model\ModelResearchProvider;

$GLOBALS['_tmw_model_helper_meta'] = [];
$GLOBALS['_tmw_model_helper_title_by_post'] = [];
$GLOBALS['_tmw_model_helper_research_providers'] = [];
$GLOBALS['_tmw_model_helper_force_corrupt_proposed_save'] = false;

function get_post_meta( int $id, string $key = '', bool $single = false ) {
    $value = $GLOBALS['_tmw_model_helper_meta'][ $id ][ $key ] ?? '';
    return $single ? $value : [ $value ];
}

function update_post_meta( int $id, string $key, $value, $prev_value = '' ): bool {
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

function delete_post_meta( int $id, string $key, $value = '' ): bool {
    if ( isset( $GLOBALS['_tmw_model_helper_meta'][ $id ][ $key ] ) ) {
        unset( $GLOBALS['_tmw_model_helper_meta'][ $id ][ $key ] );
        return true;
    }
    return false;
}

function get_the_title( $post = null ): string {
    $post_id = (int) $post;
    return (string) ( $GLOBALS['_tmw_model_helper_title_by_post'][ $post_id ] ?? '' );
}

function current_time( string $type = 'mysql', bool $gmt = false ): string {
    return '2026-04-15 00:00:00';
}

function apply_filters( string $hook, $value, ...$args ) {
    if ( $hook === 'tmwseo_research_providers' ) {
        return $GLOBALS['_tmw_model_helper_research_providers'];
    }
    return $value;
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

final class ModelHelperResearchPersistenceTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['_tmw_model_helper_meta'] = [];
        $GLOBALS['_tmw_model_helper_title_by_post'] = [];
        $GLOBALS['_tmw_model_helper_research_providers'] = [];
        $GLOBALS['_tmw_model_helper_force_corrupt_proposed_save'] = false;

        $reflection = new \ReflectionClass( ModelResearchPipeline::class );
        $providers = $reflection->getProperty( 'providers' );
        $providers->setAccessible( true );
        $providers->setValue( null, null );
    }

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
}
