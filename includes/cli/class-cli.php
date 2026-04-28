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
 *   wp tmwseo image-meta --force --roles=front,back --limit=200
 *   wp tmwseo image-meta --force --roles=front,back --dry-run
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
     * Backfill or upgrade image ALT / title / caption for existing posts.
     *
     * Processes ALL image roles attached to each post (primary, banner, front,
     * back, secondary), not only the featured image.
     *
     * ## OPTIONS
     *
     * [--post_type=<type>]
     * : Post type to target. Default: model
     *
     * [--limit=<n>]
     * : Max posts to process per run. Default: 100
     *
     * [--dry-run]
     * : Print what would happen without writing any data.
     *
     * [--force]
     * : Clear the _tmwseo_image_meta_generated, _tmwseo_image_meta_version,
     *   and _tmwseo_image_role flags for targeted roles before regenerating.
     *   Required to upgrade v1-generated metadata on existing front/back images.
     *   Primary images are excluded from force-clear unless --roles=primary is
     *   explicitly specified (safety guard to avoid touching profile photos).
     *
     * [--roles=<roles>]
     * : Comma-separated list of roles to target.
     *   Valid values: primary, banner, front, back, secondary
     *   Default: all roles.
     *   Example: --roles=front,back
     *
     * ## EXAMPLES
     *
     *     # Backfill any images that have never been processed.
     *     wp tmwseo image-meta --post_type=model --limit=100
     *
     *     # Dry-run a force-upgrade of all front and back images.
     *     wp tmwseo image-meta --force --roles=front,back --dry-run
     *
     *     # Run the force-upgrade for real, 200 posts at a time.
     *     wp tmwseo image-meta --force --roles=front,back --limit=200
     *
     *     # Force-upgrade everything including primary (use with caution).
     *     wp tmwseo image-meta --force --roles=primary,banner,front,back --limit=50
     *
     * @subcommand image-meta
     */
    public function image_meta( $args, $assoc ) {
        $post_type   = sanitize_key( $assoc['post_type'] ?? 'model' );
        $limit       = max( 1, (int) ( $assoc['limit'] ?? 100 ) );
        $dry_run     = ! empty( $assoc['dry-run'] );
        $force       = ! empty( $assoc['force'] );

        // Parse optional roles filter.
        $valid_roles = [ 'primary', 'banner', 'front', 'back', 'secondary' ];
        $role_filter = [];
        if ( isset( $assoc['roles'] ) && $assoc['roles'] !== '' ) {
            foreach ( explode( ',', $assoc['roles'] ) as $r ) {
                $r = trim( $r );
                if ( in_array( $r, $valid_roles, true ) ) {
                    $role_filter[] = $r;
                }
            }
        }

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
        $skipped   = 0;
        $force_cleared = 0;

        foreach ( $posts as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post instanceof \WP_Post ) {
                continue;
            }

            // Get all attachment IDs with roles for this post.
            $attachments = \TMWSEO\Engine\Media\Image_Meta_Generator::get_attachments_with_roles( $post );
            if ( empty( $attachments ) ) {
                continue;
            }

            foreach ( $attachments as $attachment_id => $role ) {

                // Skip if role filter is set and this role is not in it.
                if ( ! empty( $role_filter ) && ! in_array( $role, $role_filter, true ) ) {
                    continue;
                }

                $already  = (bool) get_post_meta( $attachment_id, '_tmwseo_image_meta_generated', true );
                $version  = (int)  get_post_meta( $attachment_id, '_tmwseo_image_meta_version',   true );
                $s_role   = (string) get_post_meta( $attachment_id, '_tmwseo_image_role',         true );

                // Already at current version with correct role — skip unless forcing.
                $is_current = $already
                    && $version >= \TMWSEO\Engine\Media\Image_Meta_Generator::IMAGE_META_VERSION
                    && $s_role === $role;

                if ( $is_current && ! $force ) {
                    $skipped++;
                    continue;
                }

                // Safety: primary is excluded from force-clear unless explicitly listed.
                $primary_is_targeted = empty( $role_filter ) || in_array( 'primary', $role_filter, true );
                $should_force_clear  = $force && ( $role !== 'primary' || $primary_is_targeted );

                if ( $dry_run ) {
                    $action = $should_force_clear ? 'FORCE-CLEAR + regenerate' : 'generate';
                    \WP_CLI::log( sprintf(
                        '[DRY-RUN] %s  post #%d (%s)  attachment #%d  role: %-10s  current_version: %s',
                        $action,
                        $post_id,
                        $post->post_title,
                        $attachment_id,
                        $role,
                        $version > 0 ? "v{$version}" : 'unset'
                    ) );
                    $processed++;
                    continue;
                }

                // Clear flags for force-upgrade path.
                if ( $should_force_clear ) {
                    delete_post_meta( $attachment_id, '_tmwseo_image_meta_generated' );
                    delete_post_meta( $attachment_id, '_tmwseo_image_meta_version' );
                    delete_post_meta( $attachment_id, '_tmwseo_image_role' );
                    $force_cleared++;
                }

                \TMWSEO\Engine\Media\Image_Meta_Generator::generate_for_attachment( $attachment_id, $post, $role );
                $processed++;
            }
        }

        if ( $dry_run ) {
            \WP_CLI::success( "Dry run complete. Would process: {$processed} attachments." );
        } else {
            $msg = "Image meta complete. Processed: {$processed}. Skipped (already v2): {$skipped}.";
            if ( $force_cleared > 0 ) {
                $msg .= " Force-cleared flags: {$force_cleared}.";
            }
            \WP_CLI::success( $msg );
        }
    }
}

\WP_CLI::add_command( 'tmwseo', 'TMWSEO\Engine\CLI\TMWSEOCommand' );
