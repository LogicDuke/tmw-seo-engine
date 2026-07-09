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

            $posts = get_posts( [
                'post_type'      => $cpt,
                'posts_per_page' => 1,
                'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
                'meta_query'     => [
                    [ 'key' => '_tmw_linked_term_id', 'value' => $term->term_id ],
                    [ 'key' => '_tmw_linked_taxonomy', 'value' => 'category' ],
                ],
            ] );
            $post = ! empty( $posts ) ? $posts[0] : null;

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
            $has_internal_link = (bool) preg_match( '~href="[^"]*(/models/|/videos/|/category/)~i', $rendered );
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
                'robots'        => $robots,
                'ready'         => $is_ready ? 'YES' : 'no',
            ];
        }

        $fields = [ 'term', 'post_id', 'post', 'title', 'meta_desc', 'no_internal', 'single_block', 'internal_link', 'title_unique', 'desc_unique', 'robots', 'ready' ];
        \WP_CLI\Utils\format_items( $format, $rows, $fields );
        \WP_CLI::log( sprintf( '[TMW-CAT-READY] checked=%d ready=%d (read-only, no data mutated)', count( $rows ), $ready ) );
    }

}

\WP_CLI::add_command( 'tmwseo', 'TMWSEO\Engine\CLI\TMWSEOCommand' );
