<?php
/**
 * TMW SEO Engine — Model Title & Meta Rollback v1.0.2
 *
 * Standalone eval-file script. Delegates to the existing Rollback class
 * (`includes/model/class-rollback.php`) which restores the pre-repair
 * snapshot written by `wp tmwseo repair-model-title-meta`.
 *
 * Usage:
 *   wp eval-file tools/tmw-seo-title-meta-rollback-v1.0.2.php -- --post_id=<id>
 *   wp eval-file tools/tmw-seo-title-meta-rollback-v1.0.2.php -- --all
 *
 * Preferred usage (same result, uses registered CLI command):
 *   wp tmwseo rollback --post_id=<id>
 *
 * The snapshot is written by Rollback::snapshot() inside repair_model_title_meta()
 * and stored as post meta under the key _tmwseo_pre_generation_snapshot.
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Run via WP-CLI: wp eval-file tools/tmw-seo-title-meta-rollback-v1.0.2.php' );
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    die( 'This script requires WP-CLI.' );
}

if ( ! class_exists( 'TMWSEO\Engine\Model\Rollback' ) ) {
    \WP_CLI::error( 'TMWSEO\Engine\Model\Rollback class not found. Ensure tmw-seo-engine plugin is active.' );
    exit( 1 );
}

// ── Parse args ────────────────────────────────────────────────────────────

$tmw_v102_post_id = 0;
$tmw_v102_all     = false;

if ( isset( $args ) && is_array( $args ) ) {
    foreach ( $args as $arg ) {
        if ( strpos( $arg, '--post_id=' ) === 0 ) {
            $tmw_v102_post_id = (int) substr( $arg, strlen( '--post_id=' ) );
        }
        if ( $arg === '--all' ) {
            $tmw_v102_all = true;
        }
    }
}

// ── Single post rollback ──────────────────────────────────────────────────

if ( $tmw_v102_post_id > 0 ) {
    $result = \TMWSEO\Engine\Model\Rollback::restore( $tmw_v102_post_id );
    if ( $result['ok'] ) {
        \WP_CLI::success( "[TMW-V102-ROLLBACK] post_id={$tmw_v102_post_id}: " . $result['message'] );
    } else {
        \WP_CLI::error( "[TMW-V102-ROLLBACK] post_id={$tmw_v102_post_id}: " . $result['message'] );
    }
    exit;
}

// ── Rollback all v1.0.2-stamped posts ─────────────────────────────────────

if ( $tmw_v102_all ) {
    $stamped_posts = get_posts( [
        'post_type'      => [ 'model', 'page' ],
        'post_status'    => 'any',
        'posts_per_page' => 50,
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'     => '_tmwseo_title_meta_repair_v102',
                'value'   => '1',
                'compare' => '=',
            ],
        ],
    ] );

    if ( empty( $stamped_posts ) ) {
        \WP_CLI::success( '[TMW-V102-ROLLBACK] No posts stamped with _tmwseo_title_meta_repair_v102=1 found.' );
        exit;
    }

    \WP_CLI::log( '[TMW-V102-ROLLBACK] Rolling back ' . count( $stamped_posts ) . ' post(s)...' );

    $restored = 0;
    $failed   = 0;
    foreach ( $stamped_posts as $pid ) {
        $pid    = (int) $pid;
        $result = \TMWSEO\Engine\Model\Rollback::restore( $pid );
        if ( $result['ok'] ) {
            delete_post_meta( $pid, '_tmwseo_title_meta_repair_v102' );
            \WP_CLI::log( "[TMW-V102-ROLLBACK] post_id={$pid}: " . $result['message'] );
            $restored++;
        } else {
            \WP_CLI::warning( "[TMW-V102-ROLLBACK] post_id={$pid}: " . $result['message'] );
            $failed++;
        }
    }

    \WP_CLI::success( "[TMW-V102-ROLLBACK] Done. Restored={$restored} Failed={$failed}" );
    exit;
}

// ── No valid flag provided ─────────────────────────────────────────────────

\WP_CLI::error( implode( "\n", [
    'Usage:',
    '  wp eval-file tools/tmw-seo-title-meta-rollback-v1.0.2.php -- --post_id=<id>',
    '  wp eval-file tools/tmw-seo-title-meta-rollback-v1.0.2.php -- --all',
    '',
    'Or use the registered command:',
    '  wp tmwseo rollback --post_id=<id>',
] ) );
