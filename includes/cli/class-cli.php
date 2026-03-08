<?php
/**
 * WP-CLI commands for TMW SEO Engine.
 *
 * Usage examples:
 *   wp tmwseo rollback --post_id=123
 *   wp tmwseo keyword-library status
 *   wp tmwseo keyword-library discover
 *   wp tmwseo keyword-library refresh-metrics
 *   wp tmwseo image-meta --post_type=model --limit=50
 *
 * @package TMWSEO\Engine\CLI
 */
namespace TMWSEO\Engine\CLI;

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

/**
 * Manage TMW SEO Engine from the command line.
 */
class TMWSEOCommand extends \WP_CLI_Command {

    // ── Rollback ──────────────────────────────────────────────────────────

    /**
     * Rollback a post to its pre-generation snapshot.
     *
     * ## OPTIONS
     *
     * --post_id=<id>
     * : The post ID to roll back.
     *
     * ## EXAMPLES
     *
     *     wp tmwseo rollback --post_id=123
     *
     * @subcommand rollback
     */
    public function rollback( $args, $assoc ) {
        $post_id = (int) ( $assoc['post_id'] ?? 0 );
        if ( $post_id <= 0 ) {
            \WP_CLI::error( 'Please provide --post_id=<id>' );
        }

        $result = \TMWSEO\Engine\Model\Rollback::restore( $post_id );
        if ( $result['ok'] ) {
            \WP_CLI::success( $result['message'] );
        } else {
            \WP_CLI::error( $result['message'] );
        }
    }

    // ── Keyword library ────────────────────────────────────────────────────

    /**
     * Manage the curated keyword library.
     *
     * ## SUBCOMMANDS
     *
     *   status           — Show library statistics.
     *   discover         — Run weekly Google Suggest discovery now.
     *   refresh-metrics  — Run monthly DataForSEO metric refresh now.
     *
     * ## EXAMPLES
     *
     *     wp tmwseo keyword-library status
     *     wp tmwseo keyword-library discover
     *     wp tmwseo keyword-library refresh-metrics
     *
     * @subcommand keyword-library
     */
    public function keyword_library( $args, $assoc ) {
        $sub = strtolower( trim( $args[0] ?? 'status' ) );

        switch ( $sub ) {
            case 'status':
                $this->keyword_library_status();
                break;
            case 'discover':
                \WP_CLI::log( 'Running keyword discovery...' );
                \TMWSEO\Engine\Keywords\KeywordScheduler::discover_new_keywords();
                $state = get_option( 'tmwseo_last_keyword_discovery', [] );
                \WP_CLI::success( 'Discovery complete. Discovered: ' . ( $state['discovered'] ?? '?' ) . ' new keywords.' );
                break;
            case 'refresh-metrics':
                \WP_CLI::log( 'Running keyword metric refresh...' );
                \TMWSEO\Engine\Keywords\KeywordScheduler::refresh_keyword_metrics();
                $state = get_option( 'tmwseo_keyword_metrics_refresh_state', [] );
                \WP_CLI::success( 'Metrics refresh complete. Processed: ' . ( $state['processed'] ?? '?' ) . ' keywords.' );
                break;
            default:
                \WP_CLI::error( "Unknown subcommand: {$sub}. Use status, discover, or refresh-metrics." );
        }
    }

    private function keyword_library_status(): void {
        $categories = \TMWSEO\Engine\Keywords\CuratedKeywordLibrary::categories();
        \WP_CLI::log( 'Keyword library categories: ' . count( $categories ) );

        $total = 0;
        foreach ( $categories as $cat ) {
            foreach ( [ 'extra', 'longtail', 'competitor' ] as $type ) {
                $count  = count( \TMWSEO\Engine\Keywords\CuratedKeywordLibrary::load( $cat, $type ) );
                $total += $count;
            }
        }
        \WP_CLI::log( 'Total library keywords: ' . $total );

        $usage_stats = \TMWSEO\Engine\Keywords\KeywordUsage::get_stats();
        \WP_CLI::log( 'Keywords tracked as used: ' . $usage_stats['total_used'] );
        \WP_CLI::log( 'Usage log entries: ' . $usage_stats['log_entries'] );

        $last_discovery = get_option( 'tmwseo_last_keyword_discovery', [] );
        if ( ! empty( $last_discovery['timestamp'] ) ) {
            \WP_CLI::log( 'Last discovery run: ' . $last_discovery['timestamp'] );
        }

        $last_metrics = get_option( 'tmwseo_keyword_metrics_refresh_state', [] );
        if ( ! empty( $last_metrics['timestamp'] ) ) {
            \WP_CLI::log( 'Last metrics refresh: ' . $last_metrics['timestamp'] );
        }
    }

    // ── Image meta ─────────────────────────────────────────────────────────

    /**
     * Backfill image ALT / title / caption for existing posts.
     *
     * ## OPTIONS
     *
     * [--post_type=<type>]
     * : Post type to target. Default: model
     *
     * [--limit=<n>]
     * : Max posts to process. Default: 100
     *
     * [--dry-run]
     * : Show what would happen without making changes.
     *
     * ## EXAMPLES
     *
     *     wp tmwseo image-meta --post_type=model --limit=50
     *     wp tmwseo image-meta --dry-run
     *
     * @subcommand image-meta
     */
    public function image_meta( $args, $assoc ) {
        $post_type = sanitize_key( $assoc['post_type'] ?? 'model' );
        $limit     = max( 1, (int) ( $assoc['limit'] ?? 100 ) );
        $dry_run   = ! empty( $assoc['dry-run'] );

        $posts = get_posts( [
            'post_type'      => $post_type,
            'posts_per_page' => $limit,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ] );

        if ( empty( $posts ) ) {
            \WP_CLI::warning( "No published posts found for post type: {$post_type}" );
            return;
        }

        $processed = 0;
        foreach ( $posts as $post_id ) {
            $thumb_id = (int) get_post_thumbnail_id( $post_id );
            if ( $thumb_id <= 0 ) {
                continue;
            }

            // Skip if already generated (unless dry-run just reports)
            $already = get_post_meta( $thumb_id, '_tmwseo_image_meta_generated', true );
            if ( $already && ! $dry_run ) {
                continue;
            }

            $post = get_post( $post_id );
            if ( ! $post instanceof \WP_Post ) {
                continue;
            }

            if ( $dry_run ) {
                \WP_CLI::log( "Would generate image meta for post #{$post_id} ({$post->post_title}), attachment #{$thumb_id}" );
            } else {
                // Force regenerate by clearing flag
                delete_post_meta( $thumb_id, '_tmwseo_image_meta_generated' );
                \TMWSEO\Engine\Media\Image_Meta_Generator::generate_for_featured_image( $thumb_id, $post );
                $processed++;
            }
        }

        if ( $dry_run ) {
            \WP_CLI::success( 'Dry run complete.' );
        } else {
            \WP_CLI::success( "Image meta generated for {$processed} posts." );
        }
    }
}

\WP_CLI::add_command( 'tmwseo', 'TMWSEO\Engine\CLI\TMWSEOCommand' );
