<?php

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! function_exists( 'remove_accents' ) ) { function remove_accents( $v ) { return $v; } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $type ) { return '2026-05-22 00:00:00'; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v ) { return json_encode( $v ); } }

$GLOBALS['tmw_test_model_posts'] = [];

if ( ! function_exists( 'get_posts' ) ) {
    function get_posts( $args = [] ) {
        return array_keys( $GLOBALS['tmw_test_model_posts'] );
    }
}
if ( ! function_exists( 'get_the_title' ) ) {
    function get_the_title( $id ) {
        return $GLOBALS['tmw_test_model_posts'][ $id ]['title'] ?? '';
    }
}
if ( ! function_exists( 'get_post_field' ) ) {
    function get_post_field( $field, $id ) {
        if ( $field !== 'post_name' ) { return ''; }
        return $GLOBALS['tmw_test_model_posts'][ $id ]['slug'] ?? '';
    }
}

require_once __DIR__ . '/../includes/seo-engine/opportunities/class-model-opportunity-normalizer.php';
require_once __DIR__ . '/../includes/seo-engine/opportunities/class-model-keyword-role-classifier.php';
require_once __DIR__ . '/../includes/seo-engine/opportunities/class-model-opportunity-scorer.php';
require_once __DIR__ . '/../includes/seo-engine/opportunities/class-model-opportunity-import-service.php';

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Opportunities\ModelOpportunityImportService;

final class ModelOpportunityImportServiceSingleFamilyTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['tmw_test_model_posts'] = [];
    }

    public function test_matches_existing_model_post_and_sets_existing_model_optimization(): void {
        $GLOBALS['tmw_test_model_posts'] = [
            4457 => [ 'title' => 'Anisyia', 'slug' => 'anisyia' ],
        ];

        $rows = [
            [ 'keyword' => 'anisyia', 'volume' => '12100', 'traffic_value' => '100', 'seo_score' => '50', 'competition' => '10' ],
            [ 'keyword' => 'Total Volume', 'volume' => '19950', 'traffic_value' => '0', 'seo_score' => '', 'competition' => '' ],
        ];

        $result = ModelOpportunityImportService::apply_rows( 99, 'kws_single_model_family', $rows, [ 'model_entity' => 'Anisyia' ], false );

        $this->assertSame( 1, $result['created_count'] );
        $opp = $GLOBALS['wpdb']->inserted_opp_rows[0] ?? [];
        $this->assertSame( 4457, (int) ( $opp['matched_post_id'] ?? 0 ) );
        $this->assertSame( 'existing_model_optimization', $opp['opportunity_type'] ?? '' );
    }

    public function test_unmatched_model_keeps_missing_model_acquisition(): void {
        $rows = [
            [ 'keyword' => 'anisyia', 'volume' => '12100', 'traffic_value' => '100', 'seo_score' => '50', 'competition' => '10' ],
        ];

        $result = ModelOpportunityImportService::apply_rows( 100, 'kws_single_model_family', $rows, [ 'model_entity' => 'Anisyia' ], false );

        $this->assertSame( 1, $result['created_count'] );
        $opp = $GLOBALS['wpdb']->inserted_opp_rows[0] ?? [];
        $this->assertSame( 0, (int) ( $opp['matched_post_id'] ?? 0 ) );
        $this->assertSame( 'missing_model_acquisition', $opp['opportunity_type'] ?? '' );
    }
}

final class FakeWpdb {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public string $last_error = '';
    public array $inserted_opp_rows = [];

    public function prepare( $query, ...$args ) {
        foreach ( $args as $arg ) {
            $query = preg_replace( '/%s/', "'" . addslashes( (string) $arg ) . "'", $query, 1 );
        }
        return $query;
    }

    public function get_var( $query ) {
        return null;
    }

    public function get_row( $query, $output = null ) {
        return null;
    }

    public function update( $table, $data, $where ) {
        return 1;
    }

    public function insert( $table, $data ) {
        $this->insert_id++;
        if ( str_contains( $table, 'tmwseo_model_opportunities' ) ) {
            $this->inserted_opp_rows[] = $data;
        }
        return 1;
    }
}
