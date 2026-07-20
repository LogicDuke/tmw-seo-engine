<?php
/**
 * Model Keyword Pool Template Expander
 *
 * Loads the global template config, enforces approval and safety gates, and
 * expands {model} placeholders for the requested pool target.
 *
 * Returns accepted keywords and structured warning items separately.
 *
 * @package TMWSEO\Engine\Keywords
 * @since   5.9.3
 */

declare( strict_types=1 );

namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ModelKeywordPoolTemplateExpander {

    private const PLACEHOLDER = '{model}';

    /** @var string[] */
    private const VALID_POOLS = [
        'model_rankmath_pool', 'model_body_pool', 'model_h2_pool',
        'model_h3_faq_pool',   'model_meta_pool', 'model_tag_keyword_pool',
        'category_keyword_pool', 'video_keyword_pool',
    ];

    /** Pools included in preview_for_model(). Future category/video excluded. @var string[] */
    private const MODEL_POOLS = [
        'model_rankmath_pool', 'model_body_pool', 'model_h2_pool',
        'model_h3_faq_pool',   'model_meta_pool', 'model_tag_keyword_pool',
    ];

    /** Maps pool → template safety-flag key. @var array<string,string> */
    private const POOL_SAFETY_FLAGS = [
        'model_rankmath_pool'    => 'rankmath_safe',
        'model_h2_pool'          => 'h2_safe',
        'model_h3_faq_pool'      => 'h3_safe',
        'model_body_pool'        => 'body_safe',
        'model_meta_pool'        => 'meta_safe',
        'model_tag_keyword_pool' => 'tag_pool_safe',
        'category_keyword_pool'  => 'category_pool_safe',
        'video_keyword_pool'     => 'video_pool_safe',
    ];

    /** Pools that expand {model} with lowercase name. @var string[] */
    private const LOWERCASE_POOLS = [
        'model_rankmath_pool', 'model_body_pool', 'model_tag_keyword_pool',
        'category_keyword_pool', 'video_keyword_pool',
    ];

    /**
     * Bucket processing order for expand_for_pool_bucketed().
     * @var string[]
     */
    private const BUCKET_ORDER = [
        'platform_entity',
        'live_cam',
        'profile_discovery',
        'chat_conversion',
    ];

    /**
     * Blocked for model-page pools only. NOT globally blacklisted.
     * Video pools may use some of these in a later PR.
     * @var string[]
     */
    private const BLOCKED_FRAGMENTS_MODEL = [
        'porn', 'sex', 'xxx', 'nude', 'underage',
        'teen', 'teens', 'schoolgirl', 'school girl', 'virgin', 'young',
    ];

    /** @var array<int,array<string,mixed>>|null */
    private static ?array $cache = null;

    /** Prevents duplicate load-summary log lines within one request. */
    private static bool $load_logged = false;

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Load and cache the global template config.
     * Emits [TMW-KW-TEMPLATE] loaded once per request.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function load_templates(): array {
        if ( self::$cache !== null ) {
            return self::$cache;
        }

        $file = dirname( __DIR__, 2 ) . '/data/global-model-keyword-pool-templates.php';

        if ( ! file_exists( $file ) ) {
            self::$cache = [];
            self::emit_load_summary( 0, 0, 0 );
            return [];
        }

        $loaded       = include $file;
        self::$cache  = is_array( $loaded ) ? array_values( $loaded ) : [];

        $a = $p = $r = 0;
        foreach ( self::$cache as $e ) {
            if ( ! is_array( $e ) ) { continue; }
            switch ( $e['approval_status'] ?? 'pending' ) {
                case 'approved': $a++; break;
                case 'pending':  $p++; break;
                case 'rejected': $r++; break;
            }
        }

        self::emit_load_summary( $a, $p, $r );
        return self::$cache;
    }

    /**
     * Expand all qualifying templates for the requested pool and model.
     *
     * @param  string   $model_name
     * @param  string   $pool_target           One of VALID_POOLS.
     * @param  int      $post_id               Model post ID; 0 = preview context.
     * @param  string[] $active_platform_slugs Active sanitised platform slugs.
     * @return array{accepted:string[],warnings:list<array<string,string>>}
     */
    public static function expand_for_pool(
        string $model_name,
        string $pool_target,
        int    $post_id = 0,
        array  $active_platform_slugs = []
    ): array {
        $model_name = trim( $model_name );
        if ( $model_name === '' || ! in_array( $pool_target, self::VALID_POOLS, true ) ) {
            return [ 'accepted' => [], 'warnings' => [] ];
        }

        $accepted = [];
        $warnings = [];

        foreach ( self::load_templates() as $entry ) {
            if ( ! is_array( $entry ) ) { continue; }
            $result = self::process_template( $entry, $model_name, $pool_target, $post_id, $active_platform_slugs );
            if ( $result['accepted'] !== null ) {
                $accepted[] = $result['accepted'];
            }
            foreach ( $result['warnings'] as $w ) {
                $warnings[] = $w;
            }
        }

        $accepted = self::deterministic_sort( self::dedupe( $accepted ), $post_id );
        self::log_expanded( $post_id, $pool_target, count( $accepted ), count( $warnings ) );
        return [ 'accepted' => $accepted, 'warnings' => $warnings ];
    }

    /**
     * Expand all qualifying templates for the requested pool and include template metadata.
     *
     * Read-only helper for admin review screens. Does not alter expand_for_pool() output shape.
     *
     * @param  string   $model_name
     * @param  string   $pool_target           One of VALID_POOLS.
     * @param  int      $post_id               Model post ID; 0 = preview context.
     * @param  string[] $active_platform_slugs Active sanitised platform slugs.
     * @return array{accepted:list<array<string,string>>,warnings:list<array<string,string>>}
     */
    public static function expand_for_pool_with_metadata(
        string $model_name,
        string $pool_target,
        int    $post_id = 0,
        array  $active_platform_slugs = []
    ): array {
        $model_name = trim( $model_name );
        if ( $model_name === '' || ! in_array( $pool_target, self::VALID_POOLS, true ) ) {
            return [ 'accepted' => [], 'warnings' => [] ];
        }

        $accepted = [];
        $warnings = [];
        foreach ( self::load_templates() as $entry ) {
            if ( ! is_array( $entry ) ) { continue; }
            $result = self::process_template( $entry, $model_name, $pool_target, $post_id, $active_platform_slugs );
            if ( $result['accepted'] !== null ) {
                $keyword = (string) $result['accepted'];
                $accepted[] = [
                    'keyword'     => $keyword,
                    'template_id' => (string) ( $entry['id'] ?? '' ),
                    'template'    => (string) ( $entry['template'] ?? '' ),
                    'pool_target' => $pool_target,
                    'bucket'      => (string) ( $entry['bucket'] ?? '' ),
                ];
            }
            foreach ( $result['warnings'] as $w ) {
                $warnings[] = $w;
            }
        }

        $accepted = self::dedupe_metadata( $accepted );
        $accepted = self::deterministic_sort_metadata( $accepted, $post_id );
        self::log_expanded( $post_id, $pool_target, count( $accepted ), count( $warnings ) );
        return [ 'accepted' => $accepted, 'warnings' => $warnings ];
    }

    /**
     * Bucketed deterministic selector for model Rank Math extra keywords.
     *
     * Picks up to $count keywords from model_rankmath_pool eligible templates,
     * one per bucket in priority order [platform_entity, live_cam, profile_discovery,
     * chat_conversion], then fills remaining slots from any bucket on a second pass.
     *
     * Within each bucket, templates are sorted first by bucket_priority (1 = preferred
     * compound, 2 = fallback single-word suffix), then by deterministic hash so
     * different post IDs rotate through template variants.
     *
     * Same post_id + same config = same selected keywords.
     * Never returns literal {model}. Obeys platform_gate and all existing safety gates.
     *
     * @param  string   $model_name
     * @param  int      $post_id               Model post ID (used as rotation seed).
     * @param  string[] $active_platform_slugs Active sanitised platform slugs.
     * @param  int      $count                 Target number of extra keywords (default 4).
     * @return array{accepted:string[],warnings:list<array<string,string>>,metadata:list<array<string,string>>}
     */
    public static function expand_for_pool_bucketed(
        string $model_name,
        int    $post_id,
        array  $active_platform_slugs = [],
        int    $count = 4
    ): array {
        $model_name = trim( $model_name );
        if ( $model_name === '' || $count <= 0 ) {
            return [ 'accepted' => [], 'warnings' => [], 'metadata' => [] ];
        }

        $all_templates = self::load_templates();
        $all_warnings  = [];

        // Build per-bucket candidate lists filtered to model_rankmath_pool eligible.
        // Only approved templates with rankmath_safe=true and model_rankmath_pool in pool_targets.
        $buckets = [];
        foreach ( $all_templates as $entry ) {
            if ( ! is_array( $entry ) ) { continue; }
            if ( ( $entry['approval_status'] ?? '' ) !== 'approved' ) { continue; }
            if ( ! ( (bool) ( $entry['rankmath_safe'] ?? false ) ) ) { continue; }
            $pool_targets = (array) ( $entry['pool_targets'] ?? [] );
            if ( ! in_array( 'model_rankmath_pool', $pool_targets, true ) ) { continue; }
            $bucket = (string) ( $entry['bucket'] ?? 'body_only' );
            if ( ! in_array( $bucket, self::BUCKET_ORDER, true ) ) { continue; }

            $priority = (int) ( $entry['bucket_priority'] ?? 2 );
            $tid      = (string) ( $entry['id'] ?? '' );
            // Deterministic hash: encodes post_id × bucket × template_id for stable rotation.
            $sort_key = sprintf( '%u', crc32( (string) $post_id . '|' . $bucket . '|' . $tid ) );

            $buckets[ $bucket ][] = [
                'entry'    => $entry,
                'priority' => $priority,
                'sort_key' => $sort_key,
            ];
        }

        // Sort each bucket: priority ASC (1 before 2), then sort_key ASC (deterministic).
        foreach ( $buckets as $bucket => &$candidates ) {
            usort( $candidates, static function ( array $a, array $b ): int {
                if ( $a['priority'] !== $b['priority'] ) {
                    return $a['priority'] < $b['priority'] ? -1 : 1;
                }
                return strcmp( $a['sort_key'], $b['sort_key'] );
            } );
        }
        unset( $candidates );

        $accepted         = [];
        $metadata         = [];
        $used_template_ids = [];

        // First pass: one keyword per bucket in BUCKET_ORDER priority.
        foreach ( self::BUCKET_ORDER as $bucket ) {
            if ( count( $accepted ) >= $count ) { break; }
            if ( empty( $buckets[ $bucket ] ) ) { continue; }

            foreach ( $buckets[ $bucket ] as $candidate ) {
                $entry = $candidate['entry'];
                $tid   = (string) ( $entry['id'] ?? '' );
                if ( isset( $used_template_ids[ $tid ] ) ) { continue; }

                $result = self::process_template(
                    $entry, $model_name, 'model_rankmath_pool', $post_id, $active_platform_slugs
                );
                foreach ( $result['warnings'] as $w ) { $all_warnings[] = $w; }

                if ( $result['accepted'] !== null ) {
                    $keyword = (string) $result['accepted'];
                    $accepted[]                   = $keyword;
                    $used_template_ids[ $tid ]    = true;
                    $metadata[]                   = [
                        'keyword'     => $keyword,
                        'template_id' => $tid,
                        'template'    => (string) ( $entry['template'] ?? '' ),
                        'bucket'      => $bucket,
                    ];
                    if ( self::dbg() ) {
                        $msg = '[TMW-KW-TEMPLATE] bucketed post_id=' . $post_id
                             . ' bucket=' . $bucket
                             . ' selected="' . $keyword . '"'
                             . ' template_id=' . $tid;
                        error_log( $msg );
                        self::ilog( $msg, [
                            'post_id'     => $post_id,
                            'bucket'      => $bucket,
                            'selected'    => $keyword,
                            'template_id' => $tid,
                        ] );
                    }
                    break; // one per bucket in first pass
                }
            }
        }

        // Second pass: fill remaining slots from any bucket without the one-per-bucket limit.
        if ( count( $accepted ) < $count ) {
            foreach ( self::BUCKET_ORDER as $bucket ) {
                if ( count( $accepted ) >= $count ) { break; }
                if ( empty( $buckets[ $bucket ] ) ) { continue; }

                foreach ( $buckets[ $bucket ] as $candidate ) {
                    if ( count( $accepted ) >= $count ) { break; }
                    $entry = $candidate['entry'];
                    $tid   = (string) ( $entry['id'] ?? '' );
                    if ( isset( $used_template_ids[ $tid ] ) ) { continue; }

                    $result = self::process_template(
                        $entry, $model_name, 'model_rankmath_pool', $post_id, $active_platform_slugs
                    );
                    foreach ( $result['warnings'] as $w ) { $all_warnings[] = $w; }

                    if ( $result['accepted'] !== null ) {
                        $keyword = (string) $result['accepted'];
                        $accepted[]                = $keyword;
                        $used_template_ids[ $tid ] = true;
                        $metadata[]                = [
                            'keyword'     => $keyword,
                            'template_id' => $tid,
                            'template'    => (string) ( $entry['template'] ?? '' ),
                            'bucket'      => $bucket,
                        ];
                        if ( self::dbg() ) {
                            $msg = '[TMW-KW-TEMPLATE] bucketed post_id=' . $post_id
                                 . ' bucket=' . $bucket . ' (fill)'
                                 . ' selected="' . $keyword . '"'
                                 . ' template_id=' . $tid;
                            error_log( $msg );
                            self::ilog( $msg, [
                                'post_id'     => $post_id,
                                'bucket'      => $bucket . '_fill',
                                'selected'    => $keyword,
                                'template_id' => $tid,
                            ] );
                        }
                    }
                }
            }
        }

        if ( self::dbg() ) {
            $msg = '[TMW-KW-TEMPLATE] bucketed_summary post_id=' . $post_id
                 . ' accepted=' . count( $accepted )
                 . ' warnings=' . count( $all_warnings );
            error_log( $msg );
            self::ilog( $msg, [
                'post_id'  => $post_id,
                'accepted' => count( $accepted ),
                'warnings' => count( $all_warnings ),
            ] );
        }

        return [
            'accepted' => array_slice( $accepted, 0, $count ),
            'warnings' => $all_warnings,
            'metadata' => array_slice( $metadata, 0, $count ),
        ];
    }

    /**
     * Preview all model-scoped pools for a given model.
     *
     * @param  string   $model_name
     * @param  int      $post_id
     * @param  string[] $active_platform_slugs
     * @return array<string,array{accepted:string[],warnings:list<array<string,string>>}>
     */
    public static function preview_for_model(
        string $model_name,
        int    $post_id = 0,
        array  $active_platform_slugs = []
    ): array {
        $out = [];
        foreach ( self::MODEL_POOLS as $pool ) {
            $out[ $pool ] = self::expand_for_pool( $model_name, $pool, $post_id, $active_platform_slugs );
        }
        return $out;
    }

    /**
     * Return all warnings across every model pool. Each item includes '_pool' key.
     * Intended for future admin review tooling.
     *
     * @param  string   $model_name
     * @param  int      $post_id
     * @param  string[] $active_platform_slugs
     * @return list<array<string,string>>
     */
    public static function get_warnings_for_model(
        string $model_name,
        int    $post_id = 0,
        array  $active_platform_slugs = []
    ): array {
        $out = [];
        foreach ( self::preview_for_model( $model_name, $post_id, $active_platform_slugs ) as $pool => $data ) {
            foreach ( (array) ( $data['warnings'] ?? [] ) as $w ) {
                $w['_pool'] = $pool;
                $out[]      = $w;
            }
        }
        return $out;
    }

    /**
     * Volume gate: remove expanded keywords confirmed volume = 0 in tmw_keyword_candidates.
     * Keywords not found in DB pass through (unverified = allowed). Fails open if
     * table or volume column is absent.
     *
     * NOT called automatically from expand_for_pool() in this PR.
     *
     * @param  string[] $keywords
     * @return string[]
     */
    public static function volume_gate_filter( array $keywords ): array {
        if ( empty( $keywords ) ) { return []; }

        global $wpdb;
        if ( ! is_object( $wpdb )
            || ! method_exists( $wpdb, 'get_var' )
            || ! method_exists( $wpdb, 'prepare' )
            || ! method_exists( $wpdb, 'get_results' )
            || ! method_exists( $wpdb, 'get_col' )
            || ! method_exists( $wpdb, 'esc_like' )
        ) {
            return $keywords;
        }

        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        if ( ! is_string( $found ) || strtolower( $found ) !== strtolower( $table ) ) {
            return $keywords;
        }

        $cols = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $table, 0 );
        if ( ! is_array( $cols ) || ! in_array( 'volume', array_map( 'strtolower', $cols ), true ) ) {
            return $keywords;
        }

        $lc = array_values( array_map( 'strtolower', $keywords ) );
        $ph = implode( ', ', array_fill( 0, count( $lc ), '%s' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT `keyword`, `volume` FROM ' . $table . ' WHERE LOWER(`keyword`) IN (' . $ph . ')',
                ...$lc
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) || empty( $rows ) ) { return $keywords; }

        $zero = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $kw  = strtolower( trim( (string) ( $row['keyword'] ?? '' ) ) );
            $vol = $row['volume'];
            if ( $kw !== '' && $vol !== null && (int) $vol === 0 ) {
                $zero[ $kw ] = true;
            }
        }

        if ( empty( $zero ) ) { return $keywords; }
        return array_values( array_filter( $keywords, static fn( string $k ): bool => ! isset( $zero[ strtolower( $k ) ] ) ) );
    }

    /** Reset static cache and load flag. For unit tests only. */
    public static function reset_cache(): void {
        self::$cache       = null;
        self::$load_logged = false;
    }

    // ── Private: core processing ───────────────────────────────────────────────

    /**
     * Process one template entry against the requested pool and model.
     *
     * @param  array<string,mixed> $entry
     * @param  string[]            $active_slugs
     * @return array{accepted:string|null,warnings:list<array<string,string>>}
     */
    private static function process_template(
        array  $entry,
        string $model_name,
        string $pool_target,
        int    $post_id,
        array  $active_slugs
    ): array {
        $none = [ 'accepted' => null, 'warnings' => [] ];
        $tid  = (string) ( $entry['id']       ?? '' );
        $tmpl = (string) ( $entry['template'] ?? '' );
        if ( $tmpl === '' ) { return $none; }

        $status = (string) ( $entry['approval_status'] ?? 'pending' );

        // Gate 0: pool_targets membership — silent skip, not an error.
        if ( ! in_array( $pool_target, (array) ( $entry['pool_targets'] ?? [] ), true ) ) {
            return $none;
        }

        // Gate 1: approval status
        if ( $status === 'rejected' ) {
            self::log_warning( $post_id, 'rejected_template', $tid );
            return [ 'accepted' => null, 'warnings' => [
                self::warn( 'rejected_template', $tid, $tmpl, '', $pool_target, 'Template is rejected.' ),
            ] ];
        }
        if ( $status !== 'approved' ) {
            $preview = self::expand_template( $tmpl, $model_name, $pool_target );
            self::log_warning( $post_id, 'pending_approval', $tid );
            return [ 'accepted' => null, 'warnings' => [
                self::warn( 'pending_approval', $tid, $tmpl, $preview, $pool_target,
                    (string) ( $entry['warning_if_used_without_approval'] ?? 'Template pending operator approval.' ) ),
            ] ];
        }

        // Gate 2: model_page_eligible
        if ( self::is_model_pool( $pool_target ) && ! ( (bool) ( $entry['model_page_eligible'] ?? true ) ) ) {
            self::log_warning( $post_id, 'not_model_eligible', $tid );
            return [ 'accepted' => null, 'warnings' => [
                self::warn( 'not_model_eligible', $tid, $tmpl, '', $pool_target, 'Not eligible for model pages.' ),
            ] ];
        }

        // Gate 4: safety flag
        $flag = self::POOL_SAFETY_FLAGS[ $pool_target ] ?? null;
        if ( $flag !== null && ! ( (bool) ( $entry[ $flag ] ?? false ) ) ) {
            self::log_warning( $post_id, 'safety_flag_blocked', $tid );
            return [ 'accepted' => null, 'warnings' => [
                self::warn( 'safety_flag_blocked', $tid, $tmpl, '', $pool_target, 'Safety flag ' . $flag . ' is false.' ),
            ] ];
        }

        // Gate 5: platform gate
        $gate = ( isset( $entry['platform_gate'] ) && $entry['platform_gate'] !== null )
            ? sanitize_key( (string) $entry['platform_gate'] )
            : null;
        if ( $gate !== null && $gate !== '' && ! self::platform_active( $gate, $active_slugs ) ) {
            self::log_skipped_platform( $post_id, $gate );
            return [ 'accepted' => null, 'warnings' => [
                self::warn( 'skipped_platform_gate', $tid, $tmpl, '', $pool_target, 'Platform ' . $gate . ' not active.' ),
            ] ];
        }

        // Expand
        $expanded = self::expand_template( $tmpl, $model_name, $pool_target );
        if ( $expanded === '' ) { return $none; }

        // Gate 6: literal placeholder leak guard — always log
        if ( str_contains( $expanded, '{' ) ) {
            self::log_skipped_literal( $post_id );
            return [ 'accepted' => null, 'warnings' => [
                self::warn( 'skipped_literal_placeholder', $tid, $tmpl, $expanded, $pool_target, 'Brace token survived expansion.' ),
            ] ];
        }

        // Gate 7: blocked unsafe fragment (model pages only)
        $blocked = self::is_model_pool( $pool_target ) ? self::blocked_fragment( $expanded ) : null;
        if ( $blocked !== null ) {
            self::log_blocked_fragment( $post_id, $blocked );
            return [ 'accepted' => null, 'warnings' => [
                self::warn( 'blocked_unsafe_fragment', $tid, $tmpl, $expanded, $pool_target, 'Blocked fragment: ' . $blocked ),
            ] ];
        }

        // Accepted — optional ancillary warning for db_gate volume policy
        $extras = [];
        if ( ( $entry['volume_policy'] ?? 'always' ) === 'db_gate' ) {
            $extras[] = self::warn( 'volume_unverified', $tid, $tmpl, $expanded, $pool_target,
                'volume_policy=db_gate: call volume_gate_filter() before Rank Math use.' );
        }

        return [ 'accepted' => $expanded, 'warnings' => $extras ];
    }

    private static function is_model_pool( string $pool_target ): bool {
        return str_starts_with( $pool_target, 'model_' );
    }

    private static function expand_template( string $tpl, string $name, string $pool ): string {
        $n = in_array( $pool, self::LOWERCASE_POOLS, true )
            ? ( function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name ) )
            : $name;
        return str_replace( self::PLACEHOLDER, $n, $tpl );
    }

    private static function blocked_fragment( string $expanded ): ?string {
        $lower = strtolower( $expanded );
        foreach ( self::BLOCKED_FRAGMENTS_MODEL as $f ) {
            if ( preg_match( '/(?:^|\s)' . preg_quote( $f, '/' ) . '(?:\s|$)/u', $lower ) === 1 ) {
                return $f;
            }
        }
        return null;
    }

    /** Handles livejasmin ↔ jasmin alias pair. */
    private static function platform_active( string $gate, array $slugs ): bool {
        $active = array_map( 'sanitize_key', $slugs );
        if ( in_array( $gate, $active, true ) ) { return true; }
        if ( $gate === 'livejasmin' && in_array( 'jasmin',     $active, true ) ) { return true; }
        if ( $gate === 'jasmin'     && in_array( 'livejasmin', $active, true ) ) { return true; }
        return false;
    }

    /** @return array<string,string> */
    private static function warn( string $code, string $tid, string $tmpl, string $expanded, string $pool, string $msg ): array {
        return [
            'code'        => $code,
            'template_id' => $tid,
            'template'    => $tmpl,
            'expanded'    => $expanded,
            'pool_target' => $pool,
            'message'     => $msg,
        ];
    }

    /** @param string[] $items @return string[] */
    private static function deterministic_sort( array $items, int $post_id ): array {
        if ( count( $items ) <= 1 ) { return $items; }
        $seed = abs( $post_id ) % 997;
        usort( $items, static function ( string $a, string $b ) use ( $seed ): int {
            $ha = sprintf( '%u', crc32( (string) $seed . '|' . $a ) );
            $hb = sprintf( '%u', crc32( (string) $seed . '|' . $b ) );
            return $ha === $hb ? strcmp( $a, $b ) : ( $ha < $hb ? -1 : 1 );
        } );
        return $items;
    }

    /** @param string[] $items @return string[] */
    private static function dedupe( array $items ): array {
        $seen = [];
        $out  = [];
        foreach ( $items as $item ) {
            $k = strtolower( trim( $item ) );
            if ( $k === '' || isset( $seen[ $k ] ) ) { continue; }
            $seen[ $k ] = true;
            $out[]      = $item;
        }
        return $out;
    }

    /** @param list<array<string,string>> $items @return list<array<string,string>> */
    private static function deterministic_sort_metadata( array $items, int $post_id ): array {
        if ( count( $items ) <= 1 ) { return $items; }
        $seed = abs( $post_id ) % 997;
        usort( $items, static function ( array $a, array $b ) use ( $seed ): int {
            $ak = (string) ( $a['keyword'] ?? '' );
            $bk = (string) ( $b['keyword'] ?? '' );
            $ha = sprintf( '%u', crc32( (string) $seed . '|' . $ak ) );
            $hb = sprintf( '%u', crc32( (string) $seed . '|' . $bk ) );
            return $ha === $hb ? strcmp( $ak, $bk ) : ( $ha < $hb ? -1 : 1 );
        } );
        return $items;
    }

    /** @param list<array<string,string>> $items @return list<array<string,string>> */
    private static function dedupe_metadata( array $items ): array {
        $seen = [];
        $out  = [];
        foreach ( $items as $item ) {
            $keyword = (string) ( $item['keyword'] ?? '' );
            $key = strtolower( trim( $keyword ) );
            if ( $key === '' || isset( $seen[ $key ] ) ) { continue; }
            $seen[ $key ] = true;
            $out[] = $item;
        }
        return $out;
    }

    // ── Logging ────────────────────────────────────────────────────────────────

    private static function emit_load_summary( int $a, int $p, int $r ): void {
        if ( self::$load_logged ) { return; }
        self::$load_logged = true;
        if ( ! self::dbg() ) { return; }
        $msg = '[TMW-KW-TEMPLATE] loaded approved=' . $a . ' pending=' . $p . ' rejected=' . $r;
        error_log( $msg );
        self::ilog( $msg, [ 'approved' => $a, 'pending' => $p, 'rejected' => $r ] );
    }

    private static function log_expanded( int $pid, string $pool, int $acc, int $warn ): void {
        if ( ! self::dbg() ) { return; }
        $msg = '[TMW-KW-TEMPLATE] expanded post_id=' . $pid . ' pool=' . $pool . ' accepted=' . $acc . ' warnings=' . $warn;
        error_log( $msg );
        self::ilog( $msg, [ 'post_id' => $pid, 'pool' => $pool, 'accepted' => $acc, 'warnings' => $warn ] );
    }

    private static function log_warning( int $pid, string $code, string $tid ): void {
        if ( ! self::dbg() ) { return; }
        $msg = '[TMW-KW-TEMPLATE] warning post_id=' . $pid . ' code=' . $code . ' template_id=' . $tid;
        error_log( $msg );
        self::ilog( $msg, [ 'post_id' => $pid, 'code' => $code, 'template_id' => $tid ] );
    }

    /** Always fires — literal placeholder survival is a safety event. */
    private static function log_skipped_literal( int $pid ): void {
        $msg = '[TMW-KW-TEMPLATE] skipped_literal_placeholder post_id=' . $pid;
        error_log( $msg );
        if ( class_exists( Logs::class ) && method_exists( Logs::class, 'warn' ) ) {
            Logs::warn( 'keywords', $msg, [ 'post_id' => $pid ] );
        }
    }

    private static function log_skipped_platform( int $pid, string $platform ): void {
        if ( ! self::dbg() ) { return; }
        $msg = '[TMW-KW-TEMPLATE] skipped_platform_gate post_id=' . $pid . ' platform=' . $platform;
        error_log( $msg );
        self::ilog( $msg, [ 'post_id' => $pid, 'platform' => $platform ] );
    }

    private static function log_blocked_fragment( int $pid, string $frag ): void {
        if ( ! self::dbg() ) { return; }
        $msg = '[TMW-KW-TEMPLATE] blocked_unsafe_fragment post_id=' . $pid . ' fragment=' . $frag;
        error_log( $msg );
        self::ilog( $msg, [ 'post_id' => $pid, 'fragment' => $frag ] );
    }

    /** @param array<string,mixed> $ctx */
    private static function ilog( string $msg, array $ctx = [] ): void {
        if ( class_exists( Logs::class ) && method_exists( Logs::class, 'info' ) ) {
            Logs::info( 'keywords', $msg, $ctx );
        }
    }

    private static function dbg(): bool {
        return ( defined( 'TMW_DEBUG' )    && TMW_DEBUG )
            || ( defined( 'TMWSEO_DEBUG' ) && TMWSEO_DEBUG )
            || ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG );
    }
}