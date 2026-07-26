<?php
/**
 * WP-CLI commands for TMW SEO Engine.
 *
 * Usage examples:
 *   wp tmwseo rollback --post_id=123
 *   wp tmwseo keyword-library status
 *   wp tmwseo image-meta --post_type=model --limit=100
 *   wp tmwseo image-meta --post_id=4457 --roles=front,back --force
 *   wp tmwseo image-meta --post_id=4457 --roles=front,back --force --dry-run
 *   wp tmwseo image-inspect --post_id=4457
 *   wp tmwseo global-pool-repair --dry-run
 *   wp tmwseo link-model-keywords --model_name="Anisyia" --dry-run
 *   wp tmwseo link-model-keywords --model_name="Anisyia"
 *   wp tmwseo link-model-keywords --dry-run --limit=500
 *
 * @package TMWSEO\Engine\CLI
 */
namespace TMWSEO\Engine\CLI;

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

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
                $total += count( \TMWSEO\Engine\Keywords\CuratedKeywordLibrary::load( $cat, $type ) );
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
     * Backfill or force-upgrade image ALT / title / caption / description.
     *
     * Processes ALL image roles (primary, banner, front, back, secondary).
     *
     * ## OPTIONS
     *
     * [--post_id=<id>]
     * : Target a single post by ID.  When provided, --post_type and --limit
     *   are ignored.
     *
     * [--post_type=<type>]
     * : Post type to target. Default: model
     *
     * [--limit=<n>]
     * : Max posts to process per run. Default: 100
     *
     * [--dry-run]
     * : Print what would happen without writing any data.
     *   Shows post ID, attachment ID, filename, source meta key, detected role.
     *
     * [--force]
     * : Clear _tmwseo_image_meta_generated / _version / _role flags for the
     *   targeted roles before regenerating.  Required to upgrade v1-generated
     *   metadata on existing front/back images.
     *   Primary is excluded from force-clear unless --roles=primary is given.
     *
     * [--roles=<roles>]
     * : Comma-separated roles to target: primary,banner,front,back,secondary
     *   Default: all roles.
     *
     * ## EXAMPLES
     *
     *     # Inspect which images will be processed (safe, no writes)
     *     wp tmwseo image-meta --post_id=4457 --dry-run
     *
     *     # Force-regenerate only front/back for post 4457
     *     wp tmwseo image-meta --post_id=4457 --roles=front,back --force
     *
     *     # Dry-run force-upgrade for front/back across all model posts
     *     wp tmwseo image-meta --roles=front,back --force --dry-run
     *
     *     # Run for real, 200 posts at a time
     *     wp tmwseo image-meta --roles=front,back --force --limit=200
     *
     * @subcommand image-meta
     */
    public function image_meta( $args, $assoc ) {
        $single_post_id = (int) ( $assoc['post_id'] ?? 0 );
        $post_type      = sanitize_key( $assoc['post_type'] ?? 'model' );
        $limit          = max( 1, (int) ( $assoc['limit'] ?? 100 ) );
        $dry_run        = ! empty( $assoc['dry-run'] );
        $force          = ! empty( $assoc['force'] );

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

        if ( $single_post_id > 0 ) {
            $posts = [ $single_post_id ];
        } else {
            $posts = get_posts( [
                'post_type'      => $post_type,
                'posts_per_page' => $limit,
                'post_status'    => 'publish',
                'fields'         => 'ids',
            ] );
        }

        if ( empty( $posts ) ) {
            \WP_CLI::warning( "No posts found." );
            return;
        }

        $processed     = 0;
        $skipped       = 0;
        $force_cleared = 0;

        foreach ( $posts as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post instanceof \WP_Post ) {
                continue;
            }

            $debug_entries = \TMWSEO\Engine\Media\Image_Meta_Generator::debug_attachments_for_post( $post );
            if ( empty( $debug_entries ) ) {
                if ( $dry_run ) {
                    \WP_CLI::log( "[DRY-RUN] post #{$post_id} ({$post->post_title}) — no image attachments found" );
                }
                continue;
            }

            foreach ( $debug_entries as $attachment_id => $entry ) {
                $role       = $entry['role'];
                $source_key = $entry['source_key'];

                if ( ! empty( $role_filter ) && ! in_array( $role, $role_filter, true ) ) {
                    continue;
                }

                $already  = (bool)   get_post_meta( $attachment_id, '_tmwseo_image_meta_generated', true );
                $version  = (int)    get_post_meta( $attachment_id, '_tmwseo_image_meta_version',   true );
                $s_role   = (string) get_post_meta( $attachment_id, '_tmwseo_image_role',           true );

                $is_current = $already
                    && $version >= \TMWSEO\Engine\Media\Image_Meta_Generator::IMAGE_META_VERSION
                    && $s_role === $role;

                if ( $is_current && ! $force ) {
                    $skipped++;
                    continue;
                }

                $primary_is_targeted = empty( $role_filter ) || in_array( 'primary', $role_filter, true );
                $should_force_clear  = $force && ( $role !== 'primary' || $primary_is_targeted );

                // Resolve filename from attachment.
                $filename = self::attachment_filename( $attachment_id );

                if ( $dry_run ) {
                    $action = $should_force_clear ? 'FORCE+regen' : 'generate';
                    \WP_CLI::log( sprintf(
                        '[DRY-RUN] %-12s  post:%-6d  att:%-6d  role:%-10s  key:%-35s  file:%s  stored_v:%s',
                        $action,
                        $post_id,
                        $attachment_id,
                        $role,
                        $source_key,
                        $filename,
                        $version > 0 ? "v{$version}" : 'unset'
                    ) );
                    $processed++;
                    continue;
                }

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
                $msg .= " Force-cleared: {$force_cleared}.";
            }
            \WP_CLI::success( $msg );
        }
    }

    // ── Image inspect ──────────────────────────────────────────────────────

    /**
     * Inspect all image attachments for a post and show their detected roles.
     *
     * This is a read-only diagnostic — it writes nothing.
     * Use before running image-meta --force to confirm the role mapping is correct.
     *
     * ## OPTIONS
     *
     * --post_id=<id>
     * : The post ID to inspect.
     *
     * ## EXAMPLES
     *
     *     wp tmwseo image-inspect --post_id=4457
     *
     * @subcommand image-inspect
     */
    public function image_inspect( $args, $assoc ) {
        $post_id = (int) ( $assoc['post_id'] ?? 0 );
        if ( $post_id <= 0 ) {
            \WP_CLI::error( 'Please provide --post_id=<id>' );
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            \WP_CLI::error( "Post #{$post_id} not found." );
        }

        \WP_CLI::log( "Post #{$post_id} — {$post->post_title} (type: {$post->post_type})" );
        \WP_CLI::log( str_repeat( '-', 100 ) );

        $entries = \TMWSEO\Engine\Media\Image_Meta_Generator::debug_attachments_for_post( $post );

        if ( empty( $entries ) ) {
            \WP_CLI::warning( 'No image attachments found for this post.' );
            return;
        }

        $rows = [];
        foreach ( $entries as $attachment_id => $entry ) {
            $role       = $entry['role'];
            $source_key = $entry['source_key'];
            $filename   = self::attachment_filename( $attachment_id );
            $version    = (int) get_post_meta( $attachment_id, '_tmwseo_image_meta_version', true );
            $stored_role = (string) get_post_meta( $attachment_id, '_tmwseo_image_role', true );
            $current_alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

            $rows[] = [
                'att_id'      => $attachment_id,
                'filename'    => $filename,
                'source_key'  => $source_key,
                'role'        => $role,
                'stored_v'    => $version > 0 ? "v{$version}" : 'unset',
                'stored_role' => $stored_role ?: '(none)',
                'current_alt' => mb_strimwidth( $current_alt, 0, 60, '…' ),
            ];
        }

        \WP_CLI\Utils\format_items( 'table', $rows,
            [ 'att_id', 'filename', 'source_key', 'role', 'stored_v', 'stored_role', 'current_alt' ]
        );
    }

    // ── Global Model Pool repair ────────────────────────────────────────────

    /**
     * Repair Global Model Pool keyword candidates missing explicit DB-column markers.
     *
     * Scans tmw_keyword_candidates for rows whose sources JSON contains
     * model_keyword_usage_scope="global_model_pool" or global_model_pool=true,
     * then writes target_type='global', target_name='Global Model Pool',
     * target_slug='global-model-pool' for rows that lack those markers.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Identify rows and log without writing changes.
     *
     * ## EXAMPLES
     *
     *   wp tmwseo global-pool-repair
     *   wp tmwseo global-pool-repair --dry-run
     *
     * @subcommand global-pool-repair
     */
    public function global_pool_repair( $args, $assoc ) {
        $dry_run = !empty( $assoc['dry-run'] );

        if ( $dry_run ) {
            \WP_CLI::log( '[TMW] Dry-run mode — no database writes will be performed.' );
        }

        require_once dirname( __DIR__ ) . '/keywords/class-global-model-pool-repair-service.php';
        $service = new \TMWSEO\Engine\Keywords\GlobalModelPoolRepairService();
        $stats   = $service->scan_and_repair( $dry_run );

        $label = $dry_run ? '[DRY-RUN] Would update' : 'Updated';
        \WP_CLI::log( '[TMW-KW-GLOBAL-REPAIR] scanned=' . $stats['scanned']
            . ' updated=' . $stats['updated']
            . ' skipped=' . $stats['skipped']
            . ' errors=' . $stats['errors'] );

        if ( $stats['errors'] > 0 ) {
            \WP_CLI::warning( $stats['errors'] . ' row(s) failed to update. Check debug.log for details.' );
        }
        if ( $dry_run ) {
            \WP_CLI::success( 'Dry-run complete. ' . $stats['updated'] . ' row(s) would be repaired.' );
        } else {
            \WP_CLI::success( $label . ' ' . $stats['updated'] . ' row(s).' );
        }
    }

    // ── Personal model keyword linker ──────────────────────────────────────

    /**
     * Link approved personal model keyword rows from entity_id=0
     * to the correct WordPress model post ID.
     *
     * Scans tmw_keyword_candidates for approved rows where:
     *   - intent_type = model
     *   - entity_type = model
     *   - entity_id   = 0
     *   - sources.personal_model_keyword_csv = true
     *   - sources.model_keyword_owner is non-empty
     *
     * Skips Global Model Pool rows, rows without CSV provenance,
     * and ambiguous / not-found owner matches.
     *
     * Only entity_id is written — status, keyword text, target fields,
     * and Rank Math fields are never touched.
     *
     * ## OPTIONS
     *
     * [--model_name=<name>]
     * : Restrict the scan to rows whose sources.model_keyword_owner
     *   matches this name (after normalisation).  Use for safe per-model
     *   testing before running the full scan.
     *
     * [--limit=<n>]
     * : Maximum number of eligible rows to process. Default: 500. Max: 5000.
     *
     * [--dry-run]
     * : Identify rows and log what would happen; write nothing.
     *
     * [--force]
     * : Reserved for future use.  Currently, all eligible entity_id=0 rows
     *   are processed regardless of this flag.
     *
     * ## EXAMPLES
     *
     *     # Safe dry-run for Anisyia only — confirms which rows will be linked.
     *     wp tmwseo link-model-keywords --model_name="Anisyia" --dry-run
     *
     *     # Link Anisyia rows for real.
     *     wp tmwseo link-model-keywords --model_name="Anisyia"
     *
     *     # Full scan, dry-run, up to 500 rows.
     *     wp tmwseo link-model-keywords --dry-run --limit=500
     *
     *     # Full scan, real run, up to 500 rows.
     *     wp tmwseo link-model-keywords --limit=500
     *
     * @subcommand link-model-keywords
     */
    public function link_model_keywords( $args, $assoc ) {
        $dry_run    = ! empty( $assoc['dry-run'] );
        $force      = ! empty( $assoc['force'] );
        $limit      = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 500;
        $model_name = isset( $assoc['model_name'] ) ? trim( (string) $assoc['model_name'] ) : '';

        if ( $dry_run ) {
            \WP_CLI::log( '[TMW-KW-MODEL-LINK] Dry-run mode — no database writes will be performed.' );
        }

        if ( '' !== $model_name ) {
            \WP_CLI::log( '[TMW-KW-MODEL-LINK] Restricting scan to owner: "' . $model_name . '"' );
        }

        require_once dirname( __DIR__ ) . '/keywords/class-model-keyword-link-service.php';
        $service = new \TMWSEO\Engine\Keywords\ModelKeywordLinkService();
        $stats   = $service->scan_and_link( $dry_run, $limit, $model_name, $force );

        // Per-row output.
        $action_rows = array_filter( $stats['rows'], static function ( $row ) {
            return is_array( $row )
                && ! in_array( (string) ( $row['action'] ?? '' ), [ 'skipped' ], true );
        } );
        $skip_rows = array_filter( $stats['rows'], static function ( $row ) {
            return is_array( $row ) && 'skipped' === (string) ( $row['action'] ?? '' );
        } );

        foreach ( $action_rows as $row ) {
            $action = (string) ( $row['action'] ?? '' );
            $msg    = sprintf(
                'id=%-6d  keyword="%-30s"  owner="%-20s"  post_id=%-6d  action=%s',
                (int) ( $row['id'] ?? 0 ),
                (string) ( $row['keyword'] ?? '' ),
                (string) ( $row['owner'] ?? '' ),
                (int) ( $row['resolved_post_id'] ?? 0 ),
                $action
            );
            \WP_CLI::log( $msg );
        }

        foreach ( $skip_rows as $row ) {
            \WP_CLI::log( sprintf(
                'id=%-6d  keyword="%-30s"  owner="%-20s"  skipped reason=%s',
                (int) ( $row['id'] ?? 0 ),
                (string) ( $row['keyword'] ?? '' ),
                (string) ( $row['owner'] ?? '' ),
                (string) ( $row['reason'] ?? '' )
            ) );
        }

        // Summary log line — mirrors the service's internal debug_log format.
        \WP_CLI::log(
            '[TMW-KW-MODEL-LINK] scanned=' . $stats['scanned']
            . ' linked='   . $stats['linked']
            . ' skipped='  . $stats['skipped']
            . ' errors='   . $stats['errors']
            . ' dry_run='  . ( $dry_run ? 'yes' : 'no' )
        );

        if ( $stats['errors'] > 0 ) {
            \WP_CLI::warning( $stats['errors'] . ' row(s) failed to update. Check debug.log for [TMW-KW-MODEL-LINK] entries.' );
        }

        if ( $dry_run ) {
            \WP_CLI::success(
                'Dry-run complete. '
                . $stats['linked'] . ' row(s) would be linked; '
                . $stats['skipped'] . ' skipped.'
            );
        } else {
            \WP_CLI::success(
                'Linked ' . $stats['linked'] . ' row(s). '
                . $stats['skipped'] . ' skipped. '
                . $stats['errors'] . ' error(s).'
            );
        }
    }

    // ── Shared helpers ─────────────────────────────────────────────────────

    /**
     * Returns the base filename of an attachment without directory prefix.
     */
    private static function attachment_filename( int $attachment_id ): string {
        $path = get_attached_file( $attachment_id );
        if ( $path ) {
            return basename( $path );
        }
        // Fallback: attachment slug
        $att = get_post( $attachment_id );
        return $att ? ( $att->post_name ?: "(att #{$attachment_id})" ) : "(att #{$attachment_id})";
    }

    // ── Sparse model meta description repair ─────────────────────────────────

    /**
     * Replace old placeholder meta descriptions on model pages.
     *
     * Usage:
     *   wp tmwseo repair-sparse-model-descriptions --dry-run
     *   wp tmwseo repair-sparse-model-descriptions
     *   wp tmwseo repair-sparse-model-descriptions --limit=50
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Preview changes without writing to the database.
     *
     * [--limit=<n>]
     * : Max number of posts to process per run. Default: 200.
     *
     * ## EXAMPLES
     *
     *   wp tmwseo repair-sparse-model-descriptions --dry-run
     *   wp tmwseo repair-sparse-model-descriptions
     *
     * @subcommand repair-sparse-model-descriptions
     */
    public function repair_sparse_model_descriptions( $args, $assoc ): void {
        $dry_run = ! empty( $assoc['dry-run'] );
        $limit   = max( 1, (int) ( $assoc['limit'] ?? 200 ) );

        // The old placeholder substring — any rank_math_description containing this
        // string was machine-generated by the sparse fallback path and is safe to replace.
        $old_needle = 'Detailed editorial sections are held until more performer data is confirmed.';

        $posts = get_posts( [
            'post_type'      => 'model',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_query'     => [
                [
                    'key'     => 'rank_math_description',
                    'value'   => $old_needle,
                    'compare' => 'LIKE',
                ],
            ],
        ] );

        if ( empty( $posts ) ) {
            \WP_CLI::success( '[TMW-SPARSE-META-REPAIR] No model posts with placeholder descriptions found.' );
            return;
        }

        $updated = 0;
        $skipped = 0;

        foreach ( $posts as $post_id ) {
            $current_desc = (string) get_post_meta( (int) $post_id, 'rank_math_description', true );

            // Safety: only touch descriptions that contain the exact placeholder substring.
            if ( strpos( $current_desc, $old_needle ) === false ) {
                $skipped++;
                continue;
            }

            $post = get_post( (int) $post_id );
            if ( ! $post instanceof \WP_Post ) {
                $skipped++;
                continue;
            }

            $model_name = trim( (string) $post->post_title );

            // Resolve primary platform from TMW meta.
            $platform_label = '';
            if ( class_exists( \TMWSEO\Engine\Content\TemplateContent::class ) ) {
                // Canonical key written by ContentEngine (v5.8+).
                // Fall back to legacy key written by PlatformProfiles if canonical is empty.
                $primary_slug = sanitize_key( (string) get_post_meta( (int) $post_id, '_tmwseo_primary_platform', true ) );
                if ( $primary_slug === '' ) {
                    $primary_slug = sanitize_key( (string) get_post_meta( (int) $post_id, '_tmwseo_platform_primary', true ) );
                }
                $platform_map = [
                    'livejasmin'  => 'LiveJasmin',
                    'chaturbate'  => 'Chaturbate',
                    'stripchat'   => 'Stripchat',
                    'myfreecams'  => 'MyFreeCams',
                    'camsoda'     => 'CamSoda',
                    'bonga'       => 'BongaCams',
                    'cam4'        => 'Cam4',
                    'imlive'      => 'ImLive',
                    'streamate'   => 'Streamate',
                    'flirt4free'  => 'Flirt4Free',
                    'jerkmate'    => 'Jerkmate',
                    'camscom'     => 'Cams.com',
                    'fansly'      => 'Fansly',
                    'fancentro'   => 'FanCentro',
                ];
                if ( $primary_slug !== '' && isset( $platform_map[ $primary_slug ] ) ) {
                    $platform_label = $platform_map[ $primary_slug ];
                } else {
                    // Fallback: first non-empty username meta.
                    foreach ( array_keys( $platform_map ) as $slug ) {
                        $username = trim( (string) get_post_meta( (int) $post_id, '_tmwseo_platform_username_' . $slug, true ) );
                        if ( $username !== '' ) {
                            $platform_label = $platform_map[ $slug ];
                            break;
                        }
                    }
                }
            }

            $new_desc = \TMWSEO\Engine\Content\TemplateContent::build_sparse_model_meta_description(
                $model_name,
                $platform_label
            );

            if ( $dry_run ) {
                \WP_CLI::log( sprintf(
                    '[TMW-SPARSE-META-REPAIR] [DRY-RUN] post_id=%d name=%s platform=%s new_desc="%s"',
                    $post_id,
                    $model_name,
                    $platform_label ?: '(none)',
                    $new_desc
                ) );
            } else {
                update_post_meta( (int) $post_id, 'rank_math_description', $new_desc );
                \WP_CLI::log( sprintf(
                    '[TMW-SPARSE-META-REPAIR] post_id=%d updated. platform=%s',
                    $post_id,
                    $platform_label ?: '(none)'
                ) );
            }

            $updated++;
        }

        $verb = $dry_run ? 'Would update' : 'Updated';
        \WP_CLI::success( sprintf(
            '[TMW-SPARSE-META-REPAIR] %s %d post(s). Skipped %d.',
            $verb,
            $updated,
            $skipped
        ) );
    }

    // ── Repair model SEO titles and meta descriptions (v1.0.3) ───────────

    /**
     * Standardise rank_math_title, rank_math_description, rank_math_facebook_title,
     * and rank_math_twitter_title for the 11 Phase-1 indexed model pages and the
     * /models/ archive page.
     *
     * Writes:
     *   rank_math_title          "[Name] LiveJasmin Profile — Live Cam Guide 2026"
     *   rank_math_description    Unique 120-155 char description per model
     *   rank_math_facebook_title Same as rank_math_title (eliminates OG divergence)
     *   rank_math_twitter_title  Same as rank_math_title (eliminates Twitter divergence)
     *
     * Uses Rollback::snapshot() before any write so the standard
     * `wp tmwseo rollback --post_id=<id>` command can undo changes.
     *
     * Usage:
     *   wp tmwseo repair-model-title-meta --dry-run
     *   wp tmwseo repair-model-title-meta
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Preview changes without writing to the database.
     *
     * ## EXAMPLES
     *
     *   wp tmwseo repair-model-title-meta --dry-run
     *   wp tmwseo repair-model-title-meta
     *
     * @subcommand repair-model-title-meta
     */
    public function repair_model_title_meta( $args, $assoc ): void {
        $dry_run = ! empty( $assoc['dry-run'] );
        $label   = $dry_run ? '[DRY-RUN]' : '[APPLY]';

        // ── Target meta per model slug ────────────────────────────────────
        // Title format: canonical TemplateContent::build_default_model_seo_title() policy
        // All titles are generated by the same policy used by model generation and bulk title repair.
        $model_meta = [
            'abby-murray'      => [
                'title' => '',
                'desc'  => "Abby Murray’s LiveJasmin guide gives viewers a quick webcam profile, room-finding context, and practical notes before starting a chat.",
            ],
            'aisha-dupont'     => [
                'title' => '',
                'desc'  => "Aisha Dupont on LiveJasmin is covered with a focused cam profile, room access context, and simple guidance for first-time visitors.",
            ],
            'alice-schuster'   => [
                'title' => '',
                'desc'  => "Alice Schuster’s LiveJasmin page helps visitors understand her webcam style, profile details, and how to find her cam room.",
            ],
            'allysa-quinn'     => [
                'title' => '',
                'desc'  => "Allysa Quinn’s LiveJasmin profile highlights her webcam presence, room access notes, and useful context before joining her chat.",
            ],
            'anisyia'          => [
                'title' => '',
                'desc'  => "See Anisyia on LiveJasmin with a focused cam profile, show-status guidance, simple visitor notes, and context before entering her room.",
            ],
            'arianna'          => [
                'title' => '',
                'desc'  => "Arianna’s LiveJasmin guide gives a clear webcam profile, room context, and viewer-friendly notes for checking her latest cam presence.",
            ],
            'brook-hayes'      => [
                'title' => '',
                'desc'  => "Brook Hayes on LiveJasmin is summarized with webcam profile details, cam room context, and practical tips for new viewers.",
            ],
            'hana-ross'        => [
                'title' => '',
                'desc'  => "Hana Ross’s LiveJasmin profile gives visitors a quick look at her webcam style, room context, and what to know before joining.",
            ],
            'julieta-montesco' => [
                'title' => '',
                'desc'  => "Julieta Montesco’s LiveJasmin guide covers her cam profile, room-finding context, and helpful notes for visitors exploring her page.",
            ],
            'lexy-ness'        => [
                'title' => '',
                'desc'  => "Lexy Ness brings a polished LiveJasmin cam presence. This guide helps viewers find her room, understand her style, and start with confidence.",
            ],
            'mia-collie'       => [
                'title' => '',
                'desc'  => "Mia Collie’s LiveJasmin page gives viewers a quick overview of her webcam style, profile details, and the best way to find her live room.",
            ],
        ];

        $models_page_desc = 'Browse all webcam model profiles on Top-Models.Webcam. Find LiveJasmin models by name, compare profiles, and discover live cam options.';

        $updated   = 0;
        $skipped   = 0;
        $not_found = 0;

        \WP_CLI::log( '' );
        \WP_CLI::log( "TMW SEO repair-model-title-meta  {$label}" );
        \WP_CLI::log( str_repeat( '-', 60 ) );

        // ── Model posts ───────────────────────────────────────────────────
        foreach ( $model_meta as $slug => $meta ) {
            $post = get_page_by_path( $slug, OBJECT, 'model' );

            if ( ! $post instanceof \WP_Post ) {
                \WP_CLI::warning( "[TMW-V103] [NOT FOUND] post_type=model slug={$slug}" );
                $not_found++;
                continue;
            }

            $post_id   = (int) $post->ID;
            $platform_label = \TMWSEO\Engine\Content\TemplateContent::resolve_primary_platform_label_for_title( $post_id );
            $new_title = \TMWSEO\Engine\Content\TemplateContent::build_default_model_seo_title( (string) $post->post_title, $platform_label, $post_id );
            $new_desc  = (string) $meta['desc'];

            $current_title = trim( (string) get_post_meta( $post_id, 'rank_math_title', true ) );
            $current_desc  = trim( (string) get_post_meta( $post_id, 'rank_math_description', true ) );
            $title_changed = ( $current_title !== $new_title );
            $desc_changed  = ( $current_desc  !== $new_desc );

            if ( ! $title_changed && ! $desc_changed ) {
                \WP_CLI::log( "[TMW-V103] [SKIP] post_id={$post_id} slug={$slug} already matches." );
                $skipped++;
                continue;
            }

            \WP_CLI::log( "[TMW-V103] [UPDATE] post_id={$post_id} slug={$slug}" );
            if ( $title_changed ) {
                \WP_CLI::log( "  title:  \"{$current_title}\"" );
                \WP_CLI::log( "       -> \"{$new_title}\"" );
            }
            if ( $desc_changed ) {
                \WP_CLI::log( "  desc:   \"{$current_desc}\"" );
                \WP_CLI::log( "       -> \"{$new_desc}\"" );
            }

            if ( ! $dry_run ) {
                // Snapshot via existing Rollback class before any write.
                // Enables `wp tmwseo rollback --post_id=<id>` for undo.
                if ( class_exists( '\TMWSEO\Engine\Model\Rollback' ) ) {
                    \TMWSEO\Engine\Model\Rollback::snapshot( $post_id );
                }

                update_post_meta( $post_id, 'rank_math_title',       $new_title );
                update_post_meta( $post_id, 'rank_math_description',  $new_desc );
                // Align OG and Twitter title fields. When rank_math_facebook_title /
                // rank_math_twitter_title hold stale values from prior generation runs,
                // Rank Math uses them over rank_math_title — causing the OG divergence
                // documented in audit v1.0.3. Writing all three to the same value fixes it.
                update_post_meta( $post_id, 'rank_math_facebook_title', $new_title );
                update_post_meta( $post_id, 'rank_math_twitter_title',  $new_title );
                // Repair stamp so future tooling can identify v1.0.3 writes.
                update_post_meta( $post_id, '_tmwseo_title_meta_repair_v103', '1' );
            }

            $updated++;
        }

        // ── /models/ archive page ─────────────────────────────────────────
        // The archive description is bridged from the 'models' page post via
        // tmw_models_archive_rankmath_description_bridge() in tmw-seo-model-bridge.php.
        // Updating rank_math_description on the 'models' page is sufficient.
        \WP_CLI::log( '' );
        \WP_CLI::log( '-- /models/ archive page --' );

        $models_page = get_page_by_path( 'models' );
        if ( ! $models_page instanceof \WP_Post ) {
            \WP_CLI::warning( '[TMW-V103] [NOT FOUND] page slug=models' );
        } else {
            $mpid          = (int) $models_page->ID;
            $current_mdesc = trim( (string) get_post_meta( $mpid, 'rank_math_description', true ) );

            if ( $current_mdesc === $models_page_desc ) {
                \WP_CLI::log( "[TMW-V103] [SKIP] models page id={$mpid} description already matches." );
                $skipped++;
            } else {
                \WP_CLI::log( "[TMW-V103] [UPDATE] models page id={$mpid}" );
                \WP_CLI::log( "  desc:   \"{$current_mdesc}\"" );
                \WP_CLI::log( "       -> \"{$models_page_desc}\"" );

                if ( ! $dry_run ) {
                    if ( class_exists( '\TMWSEO\Engine\Model\Rollback' ) ) {
                        \TMWSEO\Engine\Model\Rollback::snapshot( $mpid );
                    }
                    update_post_meta( $mpid, 'rank_math_description', $models_page_desc );
                    update_post_meta( $mpid, '_tmwseo_title_meta_repair_v103', '1' );
                }
                $updated++;
            }
        }

        // ── Summary ───────────────────────────────────────────────────────
        \WP_CLI::log( '' );
        \WP_CLI::log( str_repeat( '-', 60 ) );
        $verb = $dry_run ? 'Would update' : 'Updated';
        \WP_CLI::success( sprintf(
            '[TMW-V103] %s %d post(s). Skipped=%d NotFound=%d',
            $verb,
            $updated,
            $skipped,
            $not_found
        ) );
        if ( $dry_run ) {
            \WP_CLI::log( '  -> Re-run without --dry-run to commit.' );
        }
    }


    // ── Purge model page caches after metadata repair ─────────────────────

    /**
     * Purge WordPress object cache and Cloudflare cache for all 11 model pages
     * and the /models/ archive after running repair-model-title-meta.
     *
     * Usage:
     *   wp tmwseo purge-model-cache
     *   wp tmwseo purge-model-cache --cloudflare
     *
     * ## OPTIONS
     *
     * [--cloudflare]
     * : Also purge Cloudflare cache. Requires TMW_CF_ZONE_ID and TMW_CF_API_TOKEN
     *   defined in wp-config.php.
     *
     * ## EXAMPLES
     *
     *   wp tmwseo purge-model-cache
     *   wp tmwseo purge-model-cache --cloudflare
     *
     * @subcommand purge-model-cache
     */
    public function purge_model_cache( $args, $assoc ): void {
        $do_cf = ! empty( $assoc['cloudflare'] );

        $model_slugs = [
            'abby-murray', 'aisha-dupont', 'alice-schuster', 'allysa-quinn',
            'anisyia', 'arianna', 'brook-hayes', 'hana-ross',
            'julieta-montesco', 'lexy-ness', 'mia-collie',
        ];

        $purge_ids  = [];
        $purge_urls = [];
        $not_found  = 0;

        \WP_CLI::log( '' );
        \WP_CLI::log( 'TMW purge-model-cache' );
        \WP_CLI::log( str_repeat( '-', 60 ) );

        foreach ( $model_slugs as $slug ) {
            $post = get_page_by_path( $slug, OBJECT, 'model' );
            if ( ! $post instanceof \WP_Post ) {
                \WP_CLI::warning( "[TMW-PURGE] NOT FOUND: slug={$slug}" );
                $not_found++;
                continue;
            }
            $purge_ids[]  = (int) $post->ID;
            $purge_urls[] = (string) get_permalink( $post->ID );
        }

        $models_page = get_page_by_path( 'models' );
        if ( $models_page instanceof \WP_Post ) {
            $purge_ids[]  = (int) $models_page->ID;
            $purge_urls[] = (string) get_permalink( $models_page->ID );
        }

        // WordPress object cache
        foreach ( $purge_ids as $pid ) {
            clean_post_cache( $pid );
            wp_cache_delete( $pid, 'posts' );
            wp_cache_delete( $pid, 'post_meta' );
            \WP_CLI::log( "[TMW-PURGE] clean_post_cache post_id={$pid}" );
        }

        // Plugin-specific page cache hooks
        foreach ( $purge_ids as $pid ) {
            if ( function_exists( 'wp_cache_post_change' ) )   { wp_cache_post_change( $pid ); }
            if ( function_exists( 'rocket_clean_post' ) )      { rocket_clean_post( $pid ); }
            if ( function_exists( 'w3tc_pgcache_flush_post' ) ){ w3tc_pgcache_flush_post( $pid ); }
            do_action( 'litespeed_purge_post', $pid );
        }

        // Cloudflare
        if ( $do_cf ) {
            $cf_zone  = defined( 'TMW_CF_ZONE_ID' )   ? TMW_CF_ZONE_ID   : '';
            $cf_token = defined( 'TMW_CF_API_TOKEN' )  ? TMW_CF_API_TOKEN  : '';

            if ( $cf_zone === '' || $cf_token === '' ) {
                \WP_CLI::warning( '[TMW-PURGE] TMW_CF_ZONE_ID or TMW_CF_API_TOKEN not defined in wp-config.php.' );
            } else {
                foreach ( array_chunk( $purge_urls, 30 ) as $chunk ) {
                    $resp = wp_remote_post(
                        "https://api.cloudflare.com/client/v4/zones/{$cf_zone}/purge_cache",
                        [
                            'method'  => 'POST',
                            'headers' => [
                                'Authorization' => "Bearer {$cf_token}",
                                'Content-Type'  => 'application/json',
                            ],
                            'body'    => wp_json_encode( [ 'files' => $chunk ] ),
                            'timeout' => 15,
                        ]
                    );

                    if ( is_wp_error( $resp ) ) {
                        \WP_CLI::warning( '[TMW-PURGE] Cloudflare error: ' . $resp->get_error_message() );
                    } else {
                        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
                        $ok   = ! empty( $body['success'] );
                        \WP_CLI::log( '[TMW-PURGE] Cloudflare: ' . ( $ok ? 'SUCCESS' : 'FAILED' ) . ' (' . count( $chunk ) . ' URLs)' );
                        if ( ! $ok && ! empty( $body['errors'] ) ) {
                            foreach ( (array) $body['errors'] as $err ) {
                                \WP_CLI::warning( '  CF error: ' . wp_json_encode( $err ) );
                            }
                        }
                    }
                }
            }
        }

        // Curl verification output
        \WP_CLI::log( '' );
        \WP_CLI::log( '-- Verify live title/meta with curl --' );
        foreach ( $purge_urls as $url ) {
            \WP_CLI::log( "curl -s -L '" . rtrim( $url, '/' ) . "/' | grep -E '<title>|<meta name=\"description\"'" );
        }

        \WP_CLI::log( '' );
        \WP_CLI::success( sprintf(
            '[TMW-PURGE] Done. Purged=%d NotFound=%d CF=%s',
            count( $purge_ids ),
            $not_found,
            $do_cf ? 'yes' : 'skipped'
        ) );
    }


    // ── Category index readiness (read-only) ────────────────────────────────

    /**
     * Read-only index-readiness report for category archive pages.
     *
     * Checks each category term's linked tmw_category_page CPT post against
     * the pre-index checklist. Never mutates data. Debug tag: [TMW-CAT-READY].
     *
     * ## OPTIONS
     *
     * [--term=<slug>]
     * : Only check the category with this slug.
     *
     * [--format=<format>]
     * : Output format: table or json. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp tmwseo category-readiness
     *     wp tmwseo category-readiness --term=big-boob-cam
     *     wp tmwseo category-readiness --format=json
     *
     * @subcommand category-readiness
     */
    public function category_readiness( $args, $assoc ) {
        $format = isset( $assoc['format'] ) && in_array( $assoc['format'], [ 'table', 'json' ], true )
            ? $assoc['format']
            : 'table';
        $only_slug = isset( $assoc['term'] ) ? sanitize_title( (string) $assoc['term'] ) : '';

        $cpt = defined( 'TMW_CATEGORY_PAGE_CPT' ) ? TMW_CATEGORY_PAGE_CPT : 'tmw_category_page';

        $forbidden_terms = [
            'draft',
            'pipeline',
            'bridge',
            'manual review',
            'generator',
            'taxonomy structure',
            'tmw_category_page',
        ];

        $term_query = [
            'taxonomy'   => 'category',
            'hide_empty' => false,
        ];
        if ( $only_slug !== '' ) {
            $term_query['slug'] = $only_slug;
        }

        $terms = get_terms( $term_query );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            \WP_CLI::error( '[TMW-CAT-READY] No category terms found' . ( $only_slug !== '' ? " for slug '{$only_slug}'." : '.' ) );
        }

        $all_pages = get_posts( [
            'post_type'      => $cpt,
            'posts_per_page' => -1,
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'fields'         => 'ids',
        ] );
        $title_counts = [];
        $desc_counts  = [];
        foreach ( $all_pages as $pid ) {
            $t = strtolower( trim( (string) get_post_meta( (int) $pid, 'rank_math_title', true ) ) );
            $d = strtolower( trim( (string) get_post_meta( (int) $pid, 'rank_math_description', true ) ) );
            if ( $t !== '' ) { $title_counts[ $t ] = ( $title_counts[ $t ] ?? 0 ) + 1; }
            if ( $d !== '' ) { $desc_counts[ $d ]  = ( $desc_counts[ $d ] ?? 0 ) + 1; }
        }

        $rows  = [];
        $ready = 0;

        foreach ( $terms as $term ) {
            if ( ! $term instanceof \WP_Term ) {
                continue;
            }

            $post = $this->find_category_page_for_term( $term, $cpt );

            $post_exists = $post instanceof \WP_Post && $post->post_status === 'publish';
            $title = $post ? trim( (string) get_post_meta( $post->ID, 'rank_math_title', true ) ) : '';
            $desc  = $post ? trim( (string) get_post_meta( $post->ID, 'rank_math_description', true ) ) : '';
            $robots = $post ? get_post_meta( $post->ID, 'rank_math_robots', true ) : '';
            if ( is_array( $robots ) ) {
                $robots = implode( ',', array_map( 'strval', $robots ) );
            }
            $robots = (string) $robots;
            if ( $robots === '' ) {
                $robots = 'noindex,follow (fallback)';
            }
            $robots_ready = stripos( $robots, 'noindex' ) === false;

            $rendered = '';
            if ( $post instanceof \WP_Post && trim( (string) $post->post_content ) !== '' ) {
                $rendered = (string) apply_filters( 'the_content', $post->post_content );
            }
            $scan_haystack = strtolower( $rendered . ' ' . $title . ' ' . $desc );
            $found_forbidden = [];
            foreach ( $forbidden_terms as $needle ) {
                if ( strpos( $scan_haystack, $needle ) !== false ) {
                    $found_forbidden[] = $needle;
                }
            }

            $single_block = $rendered !== '' && substr_count( $rendered, 'tmw-category-page-content' ) <= 1;
            $has_internal_link = (bool) preg_match( '~href="[^"]*(/models/|/videos/|/category/|/categories/)~i', $rendered );
            $title_unique = $title !== '' && ( $title_counts[ strtolower( $title ) ] ?? 0 ) <= 1;
            $desc_unique  = $desc !== ''  && ( $desc_counts[ strtolower( $desc ) ] ?? 0 ) <= 1;

            $checks = [
                'post'          => $post_exists,
                'title'         => $title !== '',
                'meta_desc'     => $desc !== '',
                'no_internal'   => empty( $found_forbidden ),
                'single_block'  => $single_block,
                'internal_link' => $has_internal_link,
                'title_unique'  => $title_unique,
                'desc_unique'   => $desc_unique,
                'robots'        => $robots_ready,
            ];
            $is_ready = ! in_array( false, $checks, true );
            if ( $is_ready ) { $ready++; }

            $rows[] = [
                'term'          => $term->slug,
                'post_id'       => $post instanceof \WP_Post ? $post->ID : 0,
                'post'          => $checks['post'] ? 'PASS' : 'FAIL',
                'title'         => $checks['title'] ? 'PASS' : 'FAIL',
                'meta_desc'     => $checks['meta_desc'] ? 'PASS' : 'FAIL',
                'no_internal'   => $checks['no_internal'] ? 'PASS' : 'FAIL:' . implode( '|', $found_forbidden ),
                'single_block'  => $checks['single_block'] ? 'PASS' : 'FAIL',
                'internal_link' => $checks['internal_link'] ? 'PASS' : 'FAIL',
                'title_unique'  => $checks['title_unique'] ? 'PASS' : 'FAIL',
                'desc_unique'   => $checks['desc_unique'] ? 'PASS' : 'FAIL',
                'robots'        => $checks['robots'] ? 'PASS:' . $robots : 'FAIL:' . $robots,
                'ready'         => $is_ready ? 'YES' : 'no',
            ];
        }

        $fields = [ 'term', 'post_id', 'post', 'title', 'meta_desc', 'no_internal', 'single_block', 'internal_link', 'title_unique', 'desc_unique', 'robots', 'ready' ];
        \WP_CLI\Utils\format_items( $format, $rows, $fields );
        if ( $format === 'table' ) {
            \WP_CLI::log( sprintf( '[TMW-CAT-READY] checked=%d ready=%d (read-only, no data mutated)', count( $rows ), $ready ) );
        }
    }

    /**
     * Resolve the category-page post for a term using current and legacy mappings.
     *
     * Mirrors the mapping paths used by category content generation: the new
     * linked-term metadata, legacy term-id metadata, slug matching, and title
     * matching. This keeps the audit read-only while supporting existing pages.
     */
    private function find_category_page_for_term( \WP_Term $term, string $cpt ): ?\WP_Post {
        $statuses = [ 'publish', 'draft', 'pending', 'private' ];

        $linked_posts = get_posts( [
            'post_type'      => $cpt,
            'posts_per_page' => 1,
            'post_status'    => $statuses,
            'meta_query'     => [
                [ 'key' => '_tmw_linked_term_id', 'value' => $term->term_id ],
                [ 'key' => '_tmw_linked_taxonomy', 'value' => 'category' ],
            ],
        ] );
        if ( ! empty( $linked_posts ) && $linked_posts[0] instanceof \WP_Post ) {
            return $linked_posts[0];
        }

        foreach ( [ '_tmwseo_term_id', '_tmwseo_category_term_id', '_tmwseo_target_term_id', 'target_term_id' ] as $meta_key ) {
            $legacy_posts = get_posts( [
                'post_type'      => $cpt,
                'posts_per_page' => 1,
                'post_status'    => $statuses,
                'meta_key'       => $meta_key,
                'meta_value'     => (string) $term->term_id,
            ] );
            if ( ! empty( $legacy_posts ) && $legacy_posts[0] instanceof \WP_Post ) {
                return $legacy_posts[0];
            }
        }

        $slug_posts = get_posts( [
            'post_type'      => $cpt,
            'posts_per_page' => 1,
            'post_status'    => $statuses,
            'name'           => $term->slug,
        ] );
        if ( ! empty( $slug_posts ) && $slug_posts[0] instanceof \WP_Post ) {
            return $slug_posts[0];
        }

        $title_posts = get_posts( [
            'post_type'              => $cpt,
            'posts_per_page'         => 1,
            'post_status'            => $statuses,
            'title'                  => $term->name,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ] );
        if ( ! empty( $title_posts ) && $title_posts[0] instanceof \WP_Post ) {
            return $title_posts[0];
        }

        return null;
    }

    // ── Keyword ownership report (PR-A, read-only) ─────────────────────────

    /**
     * Read-only global keyword ownership report.
     *
     * For every keyword candidate across all pools, targets, and page types,
     * reports identity, ownership, import-row lineage, shared candidate_ids,
     * Rank Math presence, content presence, staleness, duplicates, cross-pool
     * collisions, and the parallel ownership registries. Performs SELECT/SHOW
     * queries only — never mutates data. Debug tag: [TMW-KW-OWNERSHIP-REPORT].
     *
     * Summary totals always reflect the FULL dataset; filters affect only the
     * rows printed above the summary.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format: table, csv, or json. Default: table. Table shows a
     * curated column subset; csv/json carry all fields.
     *
     * [--keyword=<phrase>]
     * : Only the candidate whose normalized form equals this phrase.
     *
     * [--candidate-id=<id>]
     * : Only this candidate row.
     *
     * [--target-id=<id>]
     * : Only keywords referencing this target/page ID anywhere.
     *
     * [--pool=<pool>]
     * : Only keywords touching this pool: category, model, or video.
     *
     * [--conflicts-only]
     * : Only rows with a different-target block, cross-pool collision, or
     * candidate_id shared across targets.
     *
     * [--shared-candidate-ids-only]
     * : Only rows whose candidate_id is referenced by multiple batches or targets.
     *
     * [--approved-unused-only]
     * : Only approved keywords absent from Rank Math and content everywhere.
     *
     * [--rankmath-unsupported-only]
     * : Only keywords active in Rank Math on a page but absent from that
     * page's content.
     *
     * [--duplicates-only]
     * : Only duplicate import rows (same/cross batch) or near-duplicate clusters.
     *
     * [--output=<path>]
     * : Stream csv/json rows to this file (directory must exist; .php refused).
     * The summary still prints to STDOUT.
     *
     * ## EXAMPLES
     *
     *     wp tmwseo keyword-ownership-report
     *     wp tmwseo keyword-ownership-report --format=json --output=/tmp/kw-ownership.json
     *     wp tmwseo keyword-ownership-report --keyword="example phrase"
     *     wp tmwseo keyword-ownership-report --pool=category --conflicts-only
     *     wp tmwseo keyword-ownership-report --shared-candidate-ids-only --format=csv --output=/tmp/shared.csv
     *     wp tmwseo keyword-ownership-report --approved-unused-only
     *     wp tmwseo keyword-ownership-report --rankmath-unsupported-only
     *     wp tmwseo keyword-ownership-report --duplicates-only --target-id=456
     *     wp tmwseo keyword-ownership-report --candidate-id=123 --format=json
     *
     * @subcommand keyword-ownership-report
     */
    public function keyword_ownership_report( $args, $assoc ) {
        if ( ! class_exists( '\TMWSEO\Engine\Keywords\KeywordOwnershipReportService' ) ) {
            require_once dirname( __DIR__ ) . '/keywords/class-keyword-ownership-report-service.php';
        }

        $format = isset( $assoc['format'] ) && in_array( $assoc['format'], [ 'table', 'csv', 'json' ], true )
            ? (string) $assoc['format']
            : 'table';

        $filters = [
            'keyword'                   => (string) ( $assoc['keyword'] ?? '' ),
            'candidate_id'              => (int) ( $assoc['candidate-id'] ?? 0 ),
            'target_id'                 => (int) ( $assoc['target-id'] ?? 0 ),
            'pool'                      => (string) ( $assoc['pool'] ?? '' ),
            'conflicts_only'            => isset( $assoc['conflicts-only'] ),
            'shared_candidate_ids_only' => isset( $assoc['shared-candidate-ids-only'] ),
            'approved_unused_only'      => isset( $assoc['approved-unused-only'] ),
            'rankmath_unsupported_only' => isset( $assoc['rankmath-unsupported-only'] ),
            'duplicates_only'           => isset( $assoc['duplicates-only'] ),
        ];
        if ( '' !== $filters['pool'] && ! in_array( $filters['pool'], [ 'category', 'model', 'video' ], true ) ) {
            \WP_CLI::error( '[TMW-KW-OWNERSHIP-REPORT] --pool must be one of: category, model, video.' );
        }

        $handle = null;
        $output = (string) ( $assoc['output'] ?? '' );
        if ( '' !== $output ) {
            if ( 'table' === $format ) {
                \WP_CLI::error( '[TMW-KW-OWNERSHIP-REPORT] --output requires --format=csv or --format=json.' );
            }
            if ( 'php' === strtolower( (string) pathinfo( $output, PATHINFO_EXTENSION ) ) ) {
                \WP_CLI::error( '[TMW-KW-OWNERSHIP-REPORT] --output refuses .php paths.' );
            }
            $dir = dirname( $output );
            if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
                \WP_CLI::error( '[TMW-KW-OWNERSHIP-REPORT] --output directory does not exist or is not writable: ' . $dir );
            }
            $handle = fopen( $output, 'w' );
            if ( false === $handle ) {
                \WP_CLI::error( '[TMW-KW-OWNERSHIP-REPORT] Unable to open --output file for writing: ' . $output );
            }
        } elseif ( 'table' !== $format ) {
            $handle = fopen( 'php://output', 'w' );
        }

        $service       = new \TMWSEO\Engine\Keywords\KeywordOwnershipReportService();
        $table_columns = [ 'candidate_id', 'keyword', 'status', 'intent_type', 'owner_target', 'targets', 'flags', 'resolution_state' ];
        $csv_header    = null;
        $json_first    = true;
        $printed       = 0;

        if ( 'json' === $format ) { fwrite( $handle, '[' ); }
        if ( 'table' === $format ) {
            \WP_CLI::log( implode( "\t", $table_columns ) );
        }

        foreach ( $service->run( $filters ) as $row ) {
            $printed++;
            if ( 'json' === $format ) {
                fwrite( $handle, ( $json_first ? '' : ',' ) . "\n" . wp_json_encode( $row ) );
                $json_first = false;
                continue;
            }
            if ( 'csv' === $format ) {
                $flat = $this->flatten_ownership_row( $row );
                if ( null === $csv_header ) {
                    $csv_header = array_keys( $flat );
                    fputcsv( $handle, $csv_header );
                }
                fputcsv( $handle, array_values( $flat ) );
                continue;
            }
            \WP_CLI::log( implode( "\t", [
                (string) $row['candidate_id'],
                (string) $row['keyword'],
                (string) $row['status'],
                (string) $row['intent_type'],
                trim( (string) $row['target_type'] . ':' . (string) $row['target_id'] . ' ' . (string) $row['target_name'] ),
                (string) count( (array) $row['distinct_targets'] ),
                $this->ownership_row_flags( $row ),
                (string) $row['resolution_state'],
            ] ) );
        }

        if ( 'json' === $format ) { fwrite( $handle, "\n]\n" ); }
        if ( null !== $handle ) { fclose( $handle ); }

        $summary = $service->summary();
        \WP_CLI::log( '[TMW-KW-OWNERSHIP-REPORT] SUMMARY (full dataset; filters affect rows above only)' );
        foreach ( [
            'total_candidate_identities',
            'approved_candidates',
            'candidates_referenced_by_multiple_targets',
            'shared_candidate_ids_across_batches',
            'approved_but_unused',
            'rankmath_active_content_missing',
            'blocked_due_to_different_target',
            'cross_pool_conflicts',
            'duplicate_import_rows_same_batch',
            'duplicate_import_rows_cross_batch',
            'near_duplicate_clusters',
            'stale_owners',
            'optional_tables_missing',
            'assignments_table_present',
            'assignment_count',
            'candidates_with_assignments',
            'candidates_with_multiple_assignments',
            'primary_owner_violations',
            'orphan_assignments',
            'duplicate_assignment_identities',
        ] as $key ) {
            \WP_CLI::log( '  ' . $key . ': ' . (string) ( $summary[ $key ] ?? 0 ) );
        }
        if ( '' !== $output ) {
            \WP_CLI::log( '[TMW-KW-OWNERSHIP-REPORT] Rows written to ' . $output . ': ' . $printed );
        }
        \WP_CLI::success( '[TMW-KW-OWNERSHIP-REPORT] Report complete. Rows emitted: ' . $printed );
    }

    /**
     * Flatten one report row for CSV output (nested arrays serialized compactly).
     *
     * @param array<string,mixed> $row
     * @return array<string,string>
     */
    private function flatten_ownership_row( array $row ): array {
        $flat = [];
        foreach ( $row as $key => $value ) {
            if ( 'import_rows' === $key ) {
                $cells = [];
                foreach ( (array) $value as $import_row ) {
                    $cells[] = sprintf(
                        'batch#%d[%s->%s]:%s/%s/%s(%s)',
                        (int) ( $import_row['batch_id'] ?? 0 ),
                        (string) ( $import_row['pool'] ?? '' ),
                        (string) ( $import_row['batch_target_name'] ?? '' ),
                        (string) ( $import_row['row_status'] ?? '' ),
                        (string) ( $import_row['result_action'] ?? '' ),
                        (string) ( $import_row['result_reason'] ?? '' ),
                        (string) ( $import_row['match'] ?? '' )
                    );
                }
                $flat[ $key ] = implode( '; ', $cells );
                continue;
            }
            if ( is_array( $value ) ) {
                $flat[ $key ] = (string) wp_json_encode( $value );
                continue;
            }
            $flat[ $key ] = is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value;
        }
        return $flat;
    }

    /** @param array<string,mixed> $row */
    private function ownership_row_flags( array $row ): string {
        $flags = [];
        foreach ( [
            'candidate_id_shared_across_batches' => 'shared_batches',
            'candidate_id_shared_across_targets' => 'shared_targets',
            'active_but_unsupported'             => 'rm_unsupported',
            'approved_but_unused'                => 'approved_unused',
            'stale_owner'                        => 'stale',
            'blocked_different_target_history'   => 'blocked_target',
            'cross_pool_collision'               => 'cross_pool',
            'duplicate_rows_same_batch'          => 'dup_batch',
            'duplicate_rows_cross_batch'         => 'dup_cross',
        ] as $key => $label ) {
            if ( ! empty( $row[ $key ] ) ) { $flags[] = $label; }
        }
        if ( '' !== (string) ( $row['near_duplicate_cluster_id'] ?? '' ) ) { $flags[] = 'near_dup'; }
        return [] === $flags ? '-' : implode( ',', $flags );
    }

    // ── Keyword assignment migration (PR-D, dry-run default) ──────────────

    /**
     * Analyze and (explicitly) execute the keyword-assignment migration.
     *
     * DEFAULT MODE IS DRY-RUN AND READ-ONLY. Writes to the assignments table
     * happen only with --mode=execute; rollback deletion only with
     * --mode=rollback-execute. Candidate rows, Rank Math metadata, content,
     * and postmeta are never mutated in any mode. Tag: [TMW-KW-ASSIGN-MIGRATE].
     *
     * ## OPTIONS
     *
     * [--mode=<mode>]
     * : dry-run (default), execute, rollback-dry-run, or rollback-execute.
     *
     * [--source-type=<types>]
     * : Rollback modes only — comma-separated subset of the migration source
     * types (migration_candidate, migration_import, migration_postmeta,
     * migration_combined). Restricts rollback to rows created from those
     * sources. Invalid or empty values fail; the option is rejected in
     * dry-run and execute modes.
     *
     * [--output=<path>]
     * : Write the full JSON report to this file (directory must exist; .php refused).
     *
     * [--keyword=<phrase>]
     * : Restrict analysis to one normalized keyword (local testing).
     *
     * [--candidate-id=<id>]
     * : Restrict analysis to one candidate row (local testing).
     *
     * [--target-id=<id>]
     * : Restrict analysis to keywords referencing this target ID (local testing).
     *
     * [--pool=<pool>]
     * : Restrict analysis to one pool: category, model, or video.
     *
     * [--limit=<n>]
     * : Stop after n analyzed keywords (local testing).
     *
     * ## EXAMPLES
     *
     *     wp tmwseo keyword-assignment-migration
     *     wp tmwseo keyword-assignment-migration --output=/tmp/kwmig-dry-run.json
     *     wp tmwseo keyword-assignment-migration --mode=execute --output=/tmp/kwmig-execute.json
     *     wp tmwseo keyword-assignment-migration --mode=rollback-dry-run
     *     wp tmwseo keyword-assignment-migration --mode=rollback-dry-run --source-type=migration_import
     *     wp tmwseo keyword-assignment-migration --mode=rollback-execute --source-type=migration_candidate,migration_import
     *     wp tmwseo keyword-assignment-migration --mode=rollback-execute
     *     wp tmwseo keyword-assignment-migration --candidate-id=123 --limit=1
     *
     * @subcommand keyword-assignment-migration
     */
    public function keyword_assignment_migration( $args, $assoc ) {
        if ( ! class_exists( '\TMWSEO\Engine\Keywords\KeywordAssignmentMigrationService' ) ) {
            require_once dirname( __DIR__ ) . '/keywords/class-keyword-assignment-repository.php';
            require_once dirname( __DIR__ ) . '/keywords/class-keyword-assignment-migration-analyzer.php';
            require_once dirname( __DIR__ ) . '/keywords/class-keyword-assignment-migration-service.php';
        }

        $mode = isset( $assoc['mode'] ) ? (string) $assoc['mode'] : 'dry-run';
        if ( ! in_array( $mode, [ 'dry-run', 'execute', 'rollback-dry-run', 'rollback-execute' ], true ) ) {
            \WP_CLI::error( '[TMW-KW-ASSIGN-MIGRATE] --mode must be dry-run, execute, rollback-dry-run, or rollback-execute.' );
        }

        $source_types = [];
        if ( isset( $assoc['source-type'] ) ) {
            if ( ! in_array( $mode, [ 'rollback-dry-run', 'rollback-execute' ], true ) ) {
                \WP_CLI::error( '[TMW-KW-ASSIGN-MIGRATE] --source-type applies only to rollback-dry-run and rollback-execute.' );
            }
            $allowed = \TMWSEO\Engine\Keywords\KeywordAssignmentMigrationAnalyzer::MIGRATION_SOURCE_TYPES;
            $requested = array_values( array_filter( array_map( 'trim', explode( ',', (string) $assoc['source-type'] ) ), 'strlen' ) );
            if ( [] === $requested ) {
                \WP_CLI::error( '[TMW-KW-ASSIGN-MIGRATE] --source-type is empty. Allowed: ' . implode( ', ', $allowed ) . '.' );
            }
            $invalid = array_values( array_diff( $requested, $allowed ) );
            if ( [] !== $invalid ) {
                \WP_CLI::error( '[TMW-KW-ASSIGN-MIGRATE] Invalid --source-type value(s): ' . implode( ', ', $invalid ) . '. Allowed: ' . implode( ', ', $allowed ) . '.' );
            }
            $source_types = array_values( array_unique( $requested ) );
        }
        $output = (string) ( $assoc['output'] ?? '' );
        if ( '' !== $output ) {
            if ( 'php' === strtolower( (string) pathinfo( $output, PATHINFO_EXTENSION ) ) ) {
                \WP_CLI::error( '[TMW-KW-ASSIGN-MIGRATE] --output refuses .php paths.' );
            }
            $dir = dirname( $output );
            if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
                \WP_CLI::error( '[TMW-KW-ASSIGN-MIGRATE] --output directory does not exist or is not writable: ' . $dir );
            }
        }
        $filters = [];
        foreach ( [ 'keyword' => 'keyword', 'candidate-id' => 'candidate_id', 'target-id' => 'target_id', 'pool' => 'pool', 'limit' => 'limit' ] as $option => $filter_key ) {
            if ( isset( $assoc[ $option ] ) && '' !== (string) $assoc[ $option ] ) {
                $filters[ $filter_key ] = in_array( $filter_key, [ 'candidate_id', 'target_id', 'limit' ], true ) ? (int) $assoc[ $option ] : (string) $assoc[ $option ];
            }
        }
        if ( isset( $filters['pool'] ) && ! in_array( $filters['pool'], [ 'category', 'model', 'video' ], true ) ) {
            \WP_CLI::error( '[TMW-KW-ASSIGN-MIGRATE] --pool must be one of: category, model, video.' );
        }

        $service = new \TMWSEO\Engine\Keywords\KeywordAssignmentMigrationService();
        if ( 'dry-run' === $mode ) {
            $report = $service->analyze( $filters );
        } elseif ( 'execute' === $mode ) {
            $report = $service->execute( $filters );
        } else {
            $report = $service->rollback( 'rollback-execute' === $mode, $source_types );
        }

        $json = $service->serialize_report( $report );
        if ( '' !== $service->serialization_error() ) {
            \WP_CLI::error( '[TMW-KW-ASSIGN-MIGRATE] Report serialization failed: ' . $service->serialization_error() );
        }
        if ( '' !== $output ) {
            if ( false === file_put_contents( $output, $json ) ) {
                \WP_CLI::error( '[TMW-KW-ASSIGN-MIGRATE] Unable to write report to ' . $output );
            }
            \WP_CLI::log( '[TMW-KW-ASSIGN-MIGRATE] Report written to ' . $output );
        }

        \WP_CLI::log( '[TMW-KW-ASSIGN-MIGRATE] mode=' . (string) $report['mode'] );
        if ( isset( $report['classification_counts'] ) ) {
            \WP_CLI::log( '  keywords_analyzed: ' . (string) ( $report['normalized_keyword_count'] ?? 0 ) );
            \WP_CLI::log( '  target_relationships: ' . (string) ( $report['target_relationship_count'] ?? 0 ) );
            foreach ( (array) $report['classification_counts'] as $classification => $count ) {
                \WP_CLI::log( '  ' . $classification . ': ' . (string) $count );
            }
            foreach ( (array) ( $report['planned'] ?? [] ) as $action => $count ) {
                \WP_CLI::log( '  planned_' . $action . ': ' . (string) $count );
            }
        }
        if ( isset( $report['execution'] ) && is_array( $report['execution'] ) ) {
            foreach ( $report['execution'] as $outcome => $count ) {
                \WP_CLI::log( '  executed_' . $outcome . ': ' . (string) $count );
            }
        }
        if ( isset( $report['would_delete'] ) ) {
            \WP_CLI::log( '  rollback_rows: ' . (string) count( (array) $report['would_delete'] ) . ' deleted: ' . (string) ( $report['deleted'] ?? 0 ) . ' preserved_manual: ' . (string) ( $report['preserved_manual'] ?? 0 ) );
        }
        $errors = (array) ( $report['execution_errors'] ?? ( $report['errors'] ?? [] ) );
        if ( [] !== $errors ) {
            \WP_CLI::error( '[TMW-KW-ASSIGN-MIGRATE] Completed with ' . count( $errors ) . ' error(s); see report.', false );
            exit( 1 );
        }
        \WP_CLI::success( '[TMW-KW-ASSIGN-MIGRATE] ' . (string) $report['mode'] . ' complete.' );
    }

    // ── Keyword assignment review workflow (PR-E, explicit actions only) ──

    /**
     * Reviewed, auditable rollout workflow for the keyword-assignment
     * migration. EVERY action is explicit — there is no default action, no
     * mutation without an explicit subaction, and execution is dry-run
     * unless --mode=execute is passed. Tag: [TMW-KW-ASSIGN-REVIEW].
     *
     * Candidate rows, Rank Math metadata, page content, and postmeta are
     * never touched. Assignment writes happen only via
     * `execute-approved --mode=execute` on explicitly approved records.
     *
     * ## OPTIONS
     *
     * <action>
     * : One of: sync, list, approve, reject, defer, reset-to-pending,
     * export, execute-approved, status.
     *
     * [--id=<ids>]
     * : Comma-separated explicit review record ID(s) for approve, reject,
     * defer, reset-to-pending, and execute-approved.
     *
     * [--review-state=<state>]
     * : Filter: pending, approved, rejected, or deferred.
     *
     * [--execution-state=<state>]
     * : Filter: not_executed, executed, skipped, failed, or stale.
     *
     * [--classification=<classification>]
     * : Filter by analyzer classification.
     *
     * [--candidate-id=<id>]
     * : Filter by keyword candidate ID.
     *
     * [--keyword=<phrase>]
     * : Filter by normalized keyword.
     *
     * [--pool=<pool>]
     * : Filter by pool.
     *
     * [--page-type=<type>]
     * : Filter by page type.
     *
     * [--target-type=<type>]
     * : Filter by target type.
     *
     * [--target-id=<id>]
     * : Filter by target ID.
     *
     * [--target-key=<key>]
     * : Filter by target key.
     *
     * [--role=<role>]
     * : Filter by planned role.
     *
     * [--planned-status=<status>]
     * : Filter by planned assignment status.
     *
     * [--source-type=<type>]
     * : Filter by source type.
     *
     * [--source-batch-id=<id>]
     * : Filter by source import batch ID.
     *
     * [--candidate-status=<status>]
     * : Filter by candidate status snapshot.
     *
     * [--rankmath=<0|1>]
     * : Filter by active-in-Rank-Math flag.
     *
     * [--content=<0|1>]
     * : Filter by present-in-content flag.
     *
     * [--limit=<n>]
     * : list/export row limit; sync analysis limit (disables stale-missing detection).
     *
     * [--offset=<n>]
     * : list/export row offset.
     *
     * [--include-report-only]
     * : sync only — also record non-writable classifications as report-only
     * records (never approvable, never executable).
     *
     * [--confirm]
     * : Required for any filtered bulk review mutation.
     *
     * [--all-matching]
     * : Required in addition to --confirm for unbounded filtered mutation.
     *
     * [--note=<text>]
     * : Review note stored with the mutation and audit row.
     *
     * [--reviewer=<identity>]
     * : Reviewer identity for the audit trail (defaults to the WP-CLI user).
     *
     * [--mode=<mode>]
     * : execute-approved only — dry-run (default) or execute.
     *
     * [--output=<path>]
     * : export only — .json or .csv output path; every other extension refused.
     *
     * ## EXAMPLES
     *
     *     wp tmwseo keyword-assignment-review sync
     *     wp tmwseo keyword-assignment-review sync --classification=clear_primary_owner
     *     wp tmwseo keyword-assignment-review list --review-state=pending --limit=50
     *     wp tmwseo keyword-assignment-review approve --id=12
     *     wp tmwseo keyword-assignment-review approve --classification=clear_primary_owner --confirm --all-matching
     *     wp tmwseo keyword-assignment-review reject --id=13 --note="wrong target"
     *     wp tmwseo keyword-assignment-review export --output=/tmp/review.csv
     *     wp tmwseo keyword-assignment-review execute-approved
     *     wp tmwseo keyword-assignment-review execute-approved --mode=execute
     *     wp tmwseo keyword-assignment-review status
     *
     * @subcommand keyword-assignment-review
     */
    public function keyword_assignment_review( $args, $assoc ) {
        $this->require_review_classes();

        $action = (string) ( $args[0] ?? '' );
        $allowed_actions = [ 'sync', 'list', 'approve', 'reject', 'defer', 'reset-to-pending', 'export', 'execute-approved', 'status' ];
        if ( ! in_array( $action, $allowed_actions, true ) ) {
            \WP_CLI::error( '[TMW-KW-ASSIGN-REVIEW] Explicit action required. One of: ' . implode( ', ', $allowed_actions ) . '.' );
        }

        $repository = new \TMWSEO\Engine\Keywords\KeywordAssignmentReviewRepository();
        if ( ! $repository->tables_exist() && ! \TMWSEO\Engine\Schema::ensure_keyword_assignment_review_schema() ) {
            \WP_CLI::error( '[TMW-KW-ASSIGN-REVIEW] Review tables are missing and could not be created.' );
        }
        $actor = (string) ( $assoc['reviewer'] ?? '' );
        if ( '' === $actor ) {
            $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
            $actor = $user_id > 0 ? 'user:' . $user_id : 'cli';
        }
        $note = (string) ( $assoc['note'] ?? '' );
        $source = 'wp tmwseo keyword-assignment-review ' . $action;

        switch ( $action ) {
            case 'sync':
                $this->review_action_sync( $assoc, $actor, $source );
                return;
            case 'list':
                $this->review_action_list( $repository, $assoc );
                return;
            case 'approve':
            case 'reject':
            case 'defer':
            case 'reset-to-pending':
                $this->review_action_mutate( $repository, $action, $assoc, $actor, $note, $source );
                return;
            case 'export':
                $this->review_action_export( $repository, $assoc );
                return;
            case 'execute-approved':
                $this->review_action_execute( $assoc, $actor, $source );
                return;
            case 'status':
                $this->review_action_status( $repository );
                return;
        }
    }

    private function require_review_classes(): void {
        if ( ! class_exists( '\TMWSEO\Engine\Keywords\KeywordAssignmentReviewExecutionService' ) ) {
            require_once dirname( __DIR__ ) . '/keywords/class-keyword-assignment-repository.php';
            require_once dirname( __DIR__ ) . '/keywords/class-keyword-assignment-migration-analyzer.php';
            require_once dirname( __DIR__ ) . '/keywords/class-keyword-assignment-migration-service.php';
            require_once dirname( __DIR__ ) . '/keywords/class-keyword-assignment-review-repository.php';
            require_once dirname( __DIR__ ) . '/keywords/class-keyword-assignment-review-sync-service.php';
            require_once dirname( __DIR__ ) . '/keywords/class-keyword-assignment-review-execution-service.php';
            require_once dirname( __DIR__ ) . '/keywords/class-keyword-assignment-review-export-service.php';
        }
    }

    /**
     * Generic review filters shared by list, export, and bulk mutation.
     *
     * @param array<string,mixed> $assoc
     * @return array<string,mixed> repository column filters
     */
    private function review_filters_from_assoc( array $assoc ): array {
        $filters = [];
        $map = [
            'review-state'     => [ 'review_state', 'string' ],
            'execution-state'  => [ 'execution_state', 'string' ],
            'classification'   => [ 'classification', 'string' ],
            'candidate-id'     => [ 'keyword_candidate_id', 'int' ],
            'keyword'          => [ 'normalized_keyword', 'string' ],
            'pool'             => [ 'pool', 'string' ],
            'page-type'        => [ 'page_type', 'string' ],
            'target-type'      => [ 'target_type', 'string' ],
            'target-id'        => [ 'target_id', 'int' ],
            'target-key'       => [ 'target_key', 'string' ],
            'role'             => [ 'planned_role', 'string' ],
            'planned-status'   => [ 'planned_status', 'string' ],
            'source-type'      => [ 'source_type', 'string' ],
            'source-batch-id'  => [ 'source_batch_id', 'int' ],
            'candidate-status' => [ 'candidate_status', 'string' ],
            'rankmath'         => [ 'active_in_rank_math', 'int' ],
            'content'          => [ 'present_in_content', 'int' ],
        ];
        foreach ( $map as $option => [ $column, $type ] ) {
            if ( isset( $assoc[ $option ] ) && '' !== (string) $assoc[ $option ] ) {
                $filters[ $column ] = 'int' === $type ? (int) $assoc[ $option ] : (string) $assoc[ $option ];
            }
        }
        if ( isset( $filters['review_state'] ) && ! in_array( $filters['review_state'], \TMWSEO\Engine\Keywords\KeywordAssignmentReviewRepository::REVIEW_STATES, true ) ) {
            \WP_CLI::error( '[TMW-KW-ASSIGN-REVIEW] --review-state must be one of: ' . implode( ', ', \TMWSEO\Engine\Keywords\KeywordAssignmentReviewRepository::REVIEW_STATES ) . '.' );
        }
        if ( isset( $filters['execution_state'] ) && ! in_array( $filters['execution_state'], \TMWSEO\Engine\Keywords\KeywordAssignmentReviewRepository::EXECUTION_STATES, true ) ) {
            \WP_CLI::error( '[TMW-KW-ASSIGN-REVIEW] --execution-state must be one of: ' . implode( ', ', \TMWSEO\Engine\Keywords\KeywordAssignmentReviewRepository::EXECUTION_STATES ) . '.' );
        }
        return $filters;
    }

    /** @param array<string,mixed> $assoc @return array<int,int> */
    private function review_ids_from_assoc( array $assoc ): array {
        if ( ! isset( $assoc['id'] ) ) { return []; }
        $ids = array_values( array_filter( array_map( 'intval', array_map( 'trim', explode( ',', (string) $assoc['id'] ) ) ), fn ( $id ) => $id > 0 ) );
        if ( [] === $ids ) {
            \WP_CLI::error( '[TMW-KW-ASSIGN-REVIEW] --id contains no valid review record IDs.' );
        }
        return $ids;
    }

    /** @param array<string,mixed> $assoc */
    private function review_action_sync( array $assoc, string $actor, string $source ): void {
        $filters = [];
        foreach ( [ 'keyword' => 'keyword', 'candidate-id' => 'candidate_id', 'target-id' => 'target_id', 'pool' => 'pool', 'limit' => 'limit', 'classification' => 'classification', 'candidate-status' => 'candidate_status', 'source-type' => 'source_type' ] as $option => $key ) {
            if ( isset( $assoc[ $option ] ) && '' !== (string) $assoc[ $option ] ) {
                $filters[ $key ] = in_array( $key, [ 'candidate_id', 'target_id', 'limit' ], true ) ? (int) $assoc[ $option ] : (string) $assoc[ $option ];
            }
        }
        foreach ( [ 'rankmath' => 'active_in_rank_math', 'content' => 'present_in_content' ] as $option => $key ) {
            if ( isset( $assoc[ $option ] ) && '' !== (string) $assoc[ $option ] ) {
                $filters[ $key ] = (int) $assoc[ $option ];
            }
        }
        $service = new \TMWSEO\Engine\Keywords\KeywordAssignmentReviewSyncService();
        $report = $service->sync( $filters, isset( $assoc['include-report-only'] ), $actor, $source );
        \WP_CLI::log( '[TMW-KW-ASSIGN-REVIEW] sync fresh_planned_records=' . (string) $report['fresh_planned_records'] . ' missing_check_ran=' . ( $report['missing_check_ran'] ? 'yes' : 'no' ) );
        foreach ( (array) $report['counts'] as $bucket => $count ) {
            \WP_CLI::log( '  ' . $bucket . ': ' . (string) $count );
        }
        if ( [] !== (array) $report['failures'] ) {
            foreach ( (array) $report['failures'] as $failure ) {
                \WP_CLI::log( '  FAILED candidate=' . (string) $failure['candidate_id'] . ' target=' . (string) $failure['target_key'] . ' error=' . (string) $failure['error'] );
            }
            \WP_CLI::error( '[TMW-KW-ASSIGN-REVIEW] sync completed with failures.' );
        }
        \WP_CLI::success( '[TMW-KW-ASSIGN-REVIEW] sync complete.' );
    }

    private function review_action_list( \TMWSEO\Engine\Keywords\KeywordAssignmentReviewRepository $repository, array $assoc ): void {
        $filters = $this->review_filters_from_assoc( $assoc );
        $limit = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 100;
        $offset = isset( $assoc['offset'] ) ? max( 0, (int) $assoc['offset'] ) : 0;
        $total = $repository->count_reviews( $filters );
        $rows = $repository->list_reviews( $filters, $limit, $offset );
        \WP_CLI::log( '[TMW-KW-ASSIGN-REVIEW] matched=' . $total . ' showing=' . count( $rows ) . ' offset=' . $offset );
        foreach ( $rows as $row ) {
            \WP_CLI::log( sprintf(
                '  #%d cand=%d "%s" %s %s/%s target=%s role=%s status=%s review=%s exec=%s%s',
                (int) $row['id'],
                (int) $row['keyword_candidate_id'],
                (string) $row['normalized_keyword'],
                (string) $row['classification'],
                (string) $row['pool'],
                (string) $row['page_type'],
                (string) $row['target_key'],
                (string) $row['planned_role'],
                (string) $row['planned_status'],
                (string) $row['review_state'],
                (string) $row['execution_state'],
                ! empty( $row['report_only'] ) ? ' [report-only]' : ''
            ) );
        }
    }

    private function review_action_mutate( \TMWSEO\Engine\Keywords\KeywordAssignmentReviewRepository $repository, string $action, array $assoc, string $actor, string $note, string $source ): void {
        $state_map = [ 'approve' => 'approved', 'reject' => 'rejected', 'defer' => 'deferred', 'reset-to-pending' => 'pending' ];
        $new_state = $state_map[ $action ];
        $ids = $this->review_ids_from_assoc( $assoc );

        if ( [] === $ids ) {
            // Filtered bulk mutation: explicit confirmation is mandatory, and
            // an unbounded selection additionally requires --all-matching.
            $filters = $this->review_filters_from_assoc( $assoc );
            if ( [] === $filters ) {
                \WP_CLI::error( '[TMW-KW-ASSIGN-REVIEW] ' . $action . ' requires --id=<ids> or at least one filter.' );
            }
            $matched = $repository->count_reviews( $filters );
            \WP_CLI::log( '[TMW-KW-ASSIGN-REVIEW] ' . $action . ' matches ' . $matched . ' review record(s).' );
            if ( ! isset( $assoc['confirm'] ) ) {
                \WP_CLI::error( '[TMW-KW-ASSIGN-REVIEW] Filtered bulk ' . $action . ' requires --confirm. No records were mutated.' );
            }
            $limit = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 0;
            if ( 0 === $limit && ! isset( $assoc['all-matching'] ) ) {
                \WP_CLI::error( '[TMW-KW-ASSIGN-REVIEW] Unbounded filtered ' . $action . ' refused. Add --limit=<n> or the explicit --all-matching safety flag. No records were mutated.' );
            }
            $rows = $repository->list_reviews( $filters, $limit, isset( $assoc['offset'] ) ? max( 0, (int) $assoc['offset'] ) : 0 );
            $ids = array_map( fn ( $row ) => (int) $row['id'], $rows );
            if ( [] === $ids ) {
                \WP_CLI::error( '[TMW-KW-ASSIGN-REVIEW] No review records match the given filters.' );
            }
        }

        $ok = 0; $unchanged = 0; $failed = 0;
        foreach ( $ids as $id ) {
            $result = $repository->transition_review_state( $id, $new_state, $actor, $note, $source );
            if ( ! empty( $result['ok'] ) ) {
                'unchanged' === (string) ( $result['outcome'] ?? '' ) ? $unchanged++ : $ok++;
                continue;
            }
            $failed++;
            \WP_CLI::log( '  #' . $id . ' REFUSED: ' . (string) ( $result['error'] ?? 'unknown_error' ) );
        }
        \WP_CLI::log( '[TMW-KW-ASSIGN-REVIEW] ' . $action . ' applied=' . $ok . ' unchanged=' . $unchanged . ' refused=' . $failed );
        if ( $failed > 0 ) {
            \WP_CLI::error( '[TMW-KW-ASSIGN-REVIEW] ' . $action . ' completed with refusals; see above.' );
        }
        \WP_CLI::success( '[TMW-KW-ASSIGN-REVIEW] ' . $action . ' complete.' );
    }

    private function review_action_export( \TMWSEO\Engine\Keywords\KeywordAssignmentReviewRepository $repository, array $assoc ): void {
        $output = (string) ( $assoc['output'] ?? '' );
        if ( '' === $output ) {
            \WP_CLI::error( '[TMW-KW-ASSIGN-REVIEW] export requires --output=<path> ending in .json or .csv.' );
        }
        $export = new \TMWSEO\Engine\Keywords\KeywordAssignmentReviewExportService();
        if ( ! $export->is_safe_output_path( $output ) ) {
            \WP_CLI::error( '[TMW-KW-ASSIGN-REVIEW] --output must end in .json or .csv; other extensions (including .php) are refused.' );
        }
        $dir = dirname( $output );
        if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
            \WP_CLI::error( '[TMW-KW-ASSIGN-REVIEW] --output directory does not exist or is not writable: ' . $dir );
        }
        $filters = $this->review_filters_from_assoc( $assoc );
        $limit = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 0;
        $offset = isset( $assoc['offset'] ) ? max( 0, (int) $assoc['offset'] ) : 0;
        $rows = $repository->list_reviews( $filters, $limit, $offset );
        $body = 'csv' === $export->format_for_path( $output ) ? $export->to_csv( $rows ) : $export->to_json( $rows );
        if ( false === file_put_contents( $output, $body ) ) {
            \WP_CLI::error( '[TMW-KW-ASSIGN-REVIEW] Unable to write export to ' . $output );
        }
        \WP_CLI::success( '[TMW-KW-ASSIGN-REVIEW] Exported ' . count( $rows ) . ' record(s) to ' . $output );
    }

    private function review_action_execute( array $assoc, string $actor, string $source ): void {
        $mode = (string) ( $assoc['mode'] ?? 'dry-run' );
        if ( ! in_array( $mode, [ 'dry-run', 'execute' ], true ) ) {
            \WP_CLI::error( '[TMW-KW-ASSIGN-REVIEW] --mode must be dry-run or execute.' );
        }
        $filters = [];
        foreach ( [ 'candidate-id' => 'candidate_id', 'target-id' => 'target_id', 'pool' => 'pool', 'keyword' => 'keyword', 'classification' => 'classification' ] as $option => $key ) {
            if ( isset( $assoc[ $option ] ) && '' !== (string) $assoc[ $option ] ) {
                $filters[ $key ] = in_array( $key, [ 'candidate_id', 'target_id' ], true ) ? (int) $assoc[ $option ] : (string) $assoc[ $option ];
            }
        }
        if ( isset( $assoc['id'] ) ) {
            $filters['review_ids'] = $this->review_ids_from_assoc( $assoc );
        }
        $service = new \TMWSEO\Engine\Keywords\KeywordAssignmentReviewExecutionService();
        $report = $service->execute_approved( $filters, 'execute' === $mode, $actor, $source );
        \WP_CLI::log( '[TMW-KW-ASSIGN-REVIEW] mode=' . (string) $report['mode'] . ' matched=' . (string) $report['selection']['matched'] );
        foreach ( (array) $report['counts'] as $bucket => $count ) {
            \WP_CLI::log( '  ' . $bucket . ': ' . (string) $count );
        }
        foreach ( (array) $report['results'] as $result ) {
            \WP_CLI::log( sprintf(
                '  #%d cand=%d "%s" %s target=%s -> %s%s',
                (int) $result['review_id'],
                (int) $result['candidate_id'],
                (string) $result['normalized_keyword'],
                (string) $result['classification'],
                (string) $result['target_key'],
                (string) $result['outcome'],
                isset( $result['error'] ) ? ' error=' . (string) $result['error'] : ( isset( $result['reason'] ) ? ' reason=' . (string) $result['reason'] : '' )
            ) );
        }
        if ( (int) $report['counts']['failed'] > 0 ) {
            \WP_CLI::error( '[TMW-KW-ASSIGN-REVIEW] ' . (string) $report['mode'] . ' completed with ' . (string) $report['counts']['failed'] . ' failure(s).' );
        }
        \WP_CLI::success( '[TMW-KW-ASSIGN-REVIEW] ' . (string) $report['mode'] . ' complete.' );
    }

    private function review_action_status( \TMWSEO\Engine\Keywords\KeywordAssignmentReviewRepository $repository ): void {
        $total = $repository->count_reviews( [] );
        \WP_CLI::log( '[TMW-KW-ASSIGN-REVIEW] total review records: ' . $total );
        foreach ( \TMWSEO\Engine\Keywords\KeywordAssignmentReviewRepository::REVIEW_STATES as $review_state ) {
            \WP_CLI::log( '  review_state ' . $review_state . ': ' . $repository->count_reviews( [ 'review_state' => $review_state ] ) );
        }
        foreach ( \TMWSEO\Engine\Keywords\KeywordAssignmentReviewRepository::EXECUTION_STATES as $execution_state ) {
            \WP_CLI::log( '  execution_state ' . $execution_state . ': ' . $repository->count_reviews( [ 'execution_state' => $execution_state ] ) );
        }
        foreach ( [
            \TMWSEO\Engine\Keywords\KeywordAssignmentMigrationAnalyzer::C_CLEAR_PRIMARY,
            \TMWSEO\Engine\Keywords\KeywordAssignmentMigrationAnalyzer::C_SECONDARY,
            \TMWSEO\Engine\Keywords\KeywordAssignmentMigrationAnalyzer::C_UNUSED_OWNER,
            \TMWSEO\Engine\Keywords\KeywordAssignmentMigrationAnalyzer::C_MANUAL_REVIEW,
            \TMWSEO\Engine\Keywords\KeywordAssignmentMigrationAnalyzer::C_STALE_OWNER,
            \TMWSEO\Engine\Keywords\KeywordAssignmentMigrationAnalyzer::C_CONFLICT,
            \TMWSEO\Engine\Keywords\KeywordAssignmentMigrationAnalyzer::C_UNRESOLVED,
            \TMWSEO\Engine\Keywords\KeywordAssignmentMigrationAnalyzer::C_REJECTED_SKIP,
        ] as $classification ) {
            $count = $repository->count_reviews( [ 'classification' => $classification ] );
            if ( $count > 0 ) {
                \WP_CLI::log( '  classification ' . $classification . ': ' . $count );
            }
        }
        \WP_CLI::log( '  report_only records: ' . $repository->count_reviews( [ 'report_only' => 1 ] ) );
        \WP_CLI::success( '[TMW-KW-ASSIGN-REVIEW] status complete.' );
    }

}

\WP_CLI::add_command( 'tmwseo', 'TMWSEO\Engine\CLI\TMWSEOCommand' );
