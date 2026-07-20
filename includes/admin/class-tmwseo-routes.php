<?php
/**
 * TMW SEO Engine — Admin Route Map (v1.0)
 *
 * Centralises every admin URL used across the plugin.
 * All navigation links in admin pages should call these helpers
 * instead of scattering raw add_query_arg() strings everywhere.
 *
 * Usage:
 *   TMWSEORoutes::seed_registry( 'trusted_seeds' )
 *   TMWSEORoutes::csv_manager( 'packs', [ 'tmw_csv_status' => 'db_only' ] )
 *   TMWSEORoutes::keywords( 'pipeline' )
 *
 * @package TMWSEO\Engine\Admin
 * @since   4.6.3
 */

namespace TMWSEO\Engine\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TMWSEORoutes {

    // ── Page slugs (must match class-admin.php add_submenu_page calls) ─────
    const SLUG_SEED_REGISTRY   = 'tmwseo-seed-registry';
    const SLUG_CSV_MANAGER     = 'tmwseo-csv-manager';
    const SLUG_KEYWORDS        = 'tmwseo-keywords';
    const SLUG_OPPORTUNITIES   = 'tmwseo-opportunities';
    const SLUG_REPORTS         = 'tmwseo-reports';
    const SLUG_CONNECTIONS     = 'tmwseo-connections';
    const SLUG_TOPIC_AUTHORITY = 'tmwseo-topic-authority';
    const SLUG_LINK_GRAPH      = 'tmwseo-link-graph';
    const SLUG_GENERATED       = 'tmwseo-generated';
    const SLUG_AUTOPILOT       = 'tmwseo-autopilot';
    const SLUG_CLUSTER         = 'tmwseo-clusters';
    const SLUG_INTERNAL_LINKS  = 'tmwseo-internal-links';
    const SLUG_DASHBOARD       = 'tmwseo-engine';

    // ── Generic helper ─────────────────────────────────────────────────────

    /**
     * Build an admin.php URL for any of our page slugs.
     *
     * @param string $slug  Page slug constant value.
     * @param array  $extra Additional query args (e.g. ['tab' => 'pipeline']).
     * @return string Escaped URL.
     */
    public static function page( string $slug, array $extra = [] ): string {
        return esc_url( add_query_arg(
            array_merge( [ 'page' => $slug ], $extra ),
            admin_url( 'admin.php' )
        ) );
    }

    // ── Seed Registry ──────────────────────────────────────────────────────

    /**
     * @param string $tab      'registry'|'trusted_seeds'|'candidates'|'preview'|'history'|'builders'|'reset'
     * @param array  $filters  Extra filter args (ts_source, ts_slabel, etc.)
     */
    public static function seed_registry( string $tab = 'registry', array $filters = [] ): string {
        return self::page( self::SLUG_SEED_REGISTRY, array_merge( [ 'tab' => $tab ], $filters ) );
    }

    /** Trusted Seeds tab, optionally pre-filtered by source. */
    public static function trusted_seeds( string $source = '' ): string {
        $args = [ 'tab' => 'trusted_seeds' ];
        if ( $source !== '' ) {
            $args['ts_source'] = $source;
        }
        return self::page( self::SLUG_SEED_REGISTRY, $args );
    }

    /**
     * Trusted Seeds tab pre-filtered to show ALL imported seeds
     * (approved_import + csv_import combined).
     *
     * Uses the __imported__ preset which expands to an IN() clause in
     * build_seeds_where(), so the row count exactly matches the
     * "Imported Seeds" summary bar card value.
     */
    public static function imported_seeds(): string {
        return self::page( self::SLUG_SEED_REGISTRY, [
            'tab'       => 'trusted_seeds',
            'ts_source' => '__imported__',
        ] );
    }

    /**
     * Candidates tab, optionally pre-filtered by status.
     *
     * Note: for the "Candidates Pending" summary card use
     * preview_queue('') instead — that view shows pending+fast_track
     * combined, which is exactly what summary_counts()['candidates_pending']
     * counts.
     */
    public static function candidates( string $status = '' ): string {
        $args = [ 'tab' => 'candidates' ];
        if ( $status !== '' ) {
            $args['cand_status'] = $status;
        }
        return self::page( self::SLUG_SEED_REGISTRY, $args );
    }

    /** Expansion Preview tab, optionally pre-filtered by status. */
    public static function preview_queue( string $status = '' ): string {
        $args = [ 'tab' => 'preview' ];
        if ( $status !== '' ) {
            $args['status'] = $status;
        }
        return self::page( self::SLUG_SEED_REGISTRY, $args );
    }

    // ── CSV Manager ────────────────────────────────────────────────────────

    /**
     * @param string $tab     'packs'|'linked_seeds'
     * @param array  $filters Extra filter args (tmw_csv_status, batch_id, source, etc.)
     */
    public static function csv_manager( string $tab = 'packs', array $filters = [] ): string {
        return self::page( self::SLUG_CSV_MANAGER, array_merge( [ 'tmw_csv_tab' => $tab ], $filters ) );
    }

    /** CSV Manager packs tab, optionally pre-filtered by status. */
    public static function csv_packs( string $status_filter = '' ): string {
        $args = [];
        if ( $status_filter !== '' ) {
            $args['tmw_csv_status'] = $status_filter;
        }
        return self::csv_manager( 'packs', $args );
    }

    /** CSV Manager linked seeds tab with optional pack context. */
    public static function csv_linked_seeds( string $batch_id = '', string $source_label = '', string $source = '' ): string {
        $args = [];
        if ( $batch_id !== '' )     { $args['batch_id']     = rawurlencode( $batch_id ); }
        if ( $source_label !== '' ) { $args['source_label'] = rawurlencode( $source_label ); }
        if ( $source !== '' )       { $args['source']       = rawurlencode( $source ); }
        return self::csv_manager( 'linked_seeds', $args );
    }

    // ── Keywords page ──────────────────────────────────────────────────────

    /**
     * @param string $tab 'pipeline'|'clusters'|'opportunities'|'graph'
     */
    public static function keywords( string $tab = 'pipeline' ): string {
        return self::page( self::SLUG_KEYWORDS, [ 'tab' => $tab ] );
    }

    // ── Opportunities ──────────────────────────────────────────────────────

    public static function opportunities(): string {
        return self::page( self::SLUG_OPPORTUNITIES );
    }

    // ── Reports ────────────────────────────────────────────────────────────

    /**
     * @param string $tab 'content'|'orphans'|'ai'|'pagespeed'|''
     */
    public static function reports( string $tab = '' ): string {
        $args = $tab !== '' ? [ 'tab' => $tab ] : [];
        return self::page( self::SLUG_REPORTS, $args );
    }

    // ── Connections ────────────────────────────────────────────────────────

    public static function connections(): string {
        return self::page( self::SLUG_CONNECTIONS );
    }

    // ── Topic Authority ────────────────────────────────────────────────────

    public static function topic_authority(): string {
        return self::page( self::SLUG_TOPIC_AUTHORITY );
    }

    // ── Link Graph ─────────────────────────────────────────────────────────

    public static function link_graph(): string {
        return self::page( self::SLUG_LINK_GRAPH );
    }

    // ── Internal Links ─────────────────────────────────────────────────────

    public static function internal_links(): string {
        return self::page( self::SLUG_INTERNAL_LINKS );
    }

    // ── Generated / Drafts ────────────────────────────────────────────────

    public static function generated_pages(): string {
        return self::page( self::SLUG_GENERATED );
    }

    // ── Autopilot ─────────────────────────────────────────────────────────

    public static function autopilot(): string {
        return self::page( self::SLUG_AUTOPILOT );
    }

    // ── Dashboard (root) ──────────────────────────────────────────────────

    public static function dashboard(): string {
        return self::page( self::SLUG_DASHBOARD );
    }

    // ── Utility: WordPress edit-post URL with graceful fallback ───────────

    /**
     * Returns get_edit_post_link() for the given post ID, or null if the
     * post does not exist or the current user cannot edit it.
     *
     * @param int $post_id
     * @return string|null
     */
    public static function edit_post( int $post_id ): ?string {
        if ( $post_id <= 0 ) {
            return null;
        }
        $post = get_post( $post_id );
        if ( ! $post ) {
            return null;
        }
        $url = get_edit_post_link( $post_id );
        return ( $url && $url !== '' ) ? $url : null;
    }

    /**
     * Returns get_permalink() for the given post ID, or null if the post does
     * not exist or does not have a public permalink.
     *
     * @param int $post_id
     * @return string|null
     */
    public static function view_post( int $post_id ): ?string {
        if ( $post_id <= 0 ) {
            return null;
        }
        $url = get_permalink( $post_id );
        return ( $url && $url !== false ) ? $url : null;
    }
}
