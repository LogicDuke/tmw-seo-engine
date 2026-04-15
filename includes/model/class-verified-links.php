<?php
/**
 * TMW SEO Engine — Verified External Links
 *
 * Provides a safe, manually-gated verified external links subsystem for model pages.
 *
 * Trust contract
 * ──────────────
 * • This class NEVER reads _tmwseo_research_social_urls, _tmwseo_research_proposed,
 *   or _tmwseo_research_source_urls.
 * • Every entry in _tmwseo_verified_external_links was put there by an explicit
 *   operator action: either saving the metabox or using the promote-from-research
 *   flow with an explicit per-URL checkbox selection.
 * • No URL is ever auto-promoted when "Apply Proposed Data" is clicked.
 *
 * Storage
 * ───────
 * Meta key : _tmwseo_verified_external_links
 * Format   : JSON-encoded array of entry objects (see DATA_SHAPE below)
 *
 * DATA_SHAPE per entry:
 * {
 *   "url"          : string  — esc_url_raw, required, https/http only
 *   "type"         : string  — one of ALLOWED_TYPES, required
 *   "label"        : string  — optional display override, max 80 chars
 *   "is_active"    : bool    — default true; inactive entries excluded from output
 *   "is_primary"   : bool    — at most one entry may be true
 *   "added_at"     : string  — Y-m-d, set on creation, never overwritten
 *   "promoted_from": string  — "manual" | "research"  (audit trail only)
 * }
 *
 * @package TMWSEO\Engine\Model
 * @since   4.7.0
 */
namespace TMWSEO\Engine\Model;

use TMWSEO\Engine\Logs;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class VerifiedLinks {

    // ── Constants ─────────────────────────────────────────────────────────

    const META_KEY      = '_tmwseo_verified_external_links';
    const MAX_LINKS     = 30;
    const NONCE_SAVE    = 'tmwseo_verified_links_save_';
    const NONCE_PROMOTE = 'tmwseo_promote_research_';

    const ALLOWED_TYPES = [
        'instagram',
        'tiktok',
        'x',
        'facebook',
        'youtube',
        'linktree',
        'personal_site',
        'onlyfans',
        'fansly',
        'pornhub',
        'other',
    ];

    const TYPE_LABELS = [
        'instagram'     => 'Instagram',
        'tiktok'        => 'TikTok',
        'x'             => 'X (Twitter)',
        'facebook'      => 'Facebook',
        'youtube'       => 'YouTube',
        'linktree'      => 'Linktree',
        'personal_site' => 'Personal Site',
        'onlyfans'      => 'OnlyFans',
        'fansly'        => 'Fansly',
        'pornhub'       => 'Pornhub',
        'other'         => 'Other',
    ];

    // ── Bootstrap ─────────────────────────────────────────────────────────

    public static function init(): void {
        add_action( 'add_meta_boxes',                          [ __CLASS__, 'register_metabox' ] );
        add_action( 'save_post_model',                         [ __CLASS__, 'save_metabox' ], 20, 2 );
        add_shortcode( 'tmw_verified_links',                   [ __CLASS__, 'shortcode_verified_links' ] );
        add_action( 'admin_post_tmwseo_promote_to_verified',   [ __CLASS__, 'handle_promote' ] );
        add_action( 'admin_notices',                           [ __CLASS__, 'render_promote_notice' ] );
    }

    // ── Metabox registration ──────────────────────────────────────────────

    public static function register_metabox(): void {
        add_meta_box(
            'tmwseo_verified_external_links',
            __( 'Verified External Links', 'tmwseo' ),
            [ __CLASS__, 'render_metabox' ],
            'model',
            'normal',
            'high'
        );
    }

    // ── Metabox render ────────────────────────────────────────────────────

    public static function render_metabox( \WP_Post $post ): void {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            echo '<p>' . esc_html__( 'You do not have permission to view this.', 'tmwseo' ) . '</p>';
            return;
        }

        wp_nonce_field(
            self::NONCE_SAVE . $post->ID,
            'tmwseo_verified_links_nonce'
        );

        $links = self::get_links( $post->ID );

        echo '<p style="margin-top:0;color:#555;font-size:13px;">';
        echo esc_html__(
            'These links appear on the front end and in schema sameAs. '
            . 'They are never auto-imported from research.',
            'tmwseo'
        );
        echo '</p>';

        // ── Table ─────────────────────────────────────────────────────────
        echo '<table class="widefat" id="tmwseo-vl-table" '
            . 'style="border-collapse:collapse;margin-bottom:10px;table-layout:fixed;">';
        echo '<colgroup>'
            . '<col style="width:130px;">'
            . '<col>'
            . '<col style="width:130px;">'
            . '<col style="width:56px;">'
            . '<col style="width:60px;">'
            . '<col style="width:36px;">'
            . '</colgroup>';
        echo '<thead><tr style="background:#f6f7f7;">';
        echo '<th style="padding:6px 8px;">'                                    . esc_html__( 'Type',    'tmwseo' ) . '</th>';
        echo '<th style="padding:6px 8px;">'                                    . esc_html__( 'URL',     'tmwseo' ) . '</th>';
        echo '<th style="padding:6px 8px;">'                                    . esc_html__( 'Label',   'tmwseo' ) . '</th>';
        echo '<th style="padding:6px 8px;text-align:center;">'                  . esc_html__( 'Active',  'tmwseo' ) . '</th>';
        echo '<th style="padding:6px 8px;text-align:center;">'                  . esc_html__( 'Primary', 'tmwseo' ) . '</th>';
        echo '<th style="padding:6px 8px;"></th>';
        echo '</tr></thead>';
        echo '<tbody id="tmwseo-vl-rows">';

        if ( empty( $links ) ) {
            echo '<tr id="tmwseo-vl-empty-row">'
                . '<td colspan="6" style="padding:10px 8px;color:#888;font-style:italic;">'
                . esc_html__( 'No verified links yet. Click "+ Add Link" below to add one.', 'tmwseo' )
                . '</td></tr>';
        }

        foreach ( $links as $n => $entry ) {
            self::render_row( $n, $entry );
        }

        echo '</tbody></table>';

        // ── Add Link button ───────────────────────────────────────────────
        echo '<p style="margin-bottom:14px;">';
        echo '<button type="button" class="button" id="tmwseo-vl-add-btn">'
            . esc_html__( '+ Add Link', 'tmwseo' )
            . '</button>';
        echo '<span style="margin-left:12px;color:#666;font-size:12px;">'
            . esc_html__( 'Saved with the post. Never auto-imported from research.', 'tmwseo' )
            . '</span>';
        echo '</p>';

        // ── Inline JS (no external file needed for v1) ────────────────────
        $type_options_html = '<option value="">' . esc_html__( '— Select type —', 'tmwseo' ) . '</option>';
        foreach ( self::TYPE_LABELS as $val => $label_text ) {
            $type_options_html .= '<option value="' . esc_attr( $val ) . '">' . esc_html( $label_text ) . '</option>';
        }
        ?>
        <script>
        (function () {
            var counter        = <?php echo (int) count( $links ); ?>;
            var typeOptions    = <?php echo wp_json_encode( $type_options_html ); ?>;
            var placeholderTxt = <?php echo wp_json_encode( __( 'Optional label', 'tmwseo' ) ); ?>;
            var emptyTxt       = <?php echo wp_json_encode( __( 'No verified links yet. Click "+ Add Link" below to add one.', 'tmwseo' ) ); ?>;
            var removeTxt      = <?php echo wp_json_encode( __( 'Remove', 'tmwseo' ) ); ?>;

            function buildRow(n) {
                var tr = document.createElement('tr');
                tr.className = 'tmwseo-vl-row';
                tr.setAttribute('data-idx', n);
                tr.innerHTML =
                    '<td style="padding:4px 6px;">' +
                        '<select name="tmwseo_vl[' + n + '][type]" style="width:100%;">' + typeOptions + '</select>' +
                    '</td>' +
                    '<td style="padding:4px 6px;">' +
                        '<input type="url" name="tmwseo_vl[' + n + '][url]" value="" ' +
                        '       class="large-text" placeholder="https://" />' +
                    '</td>' +
                    '<td style="padding:4px 6px;">' +
                        '<input type="text" name="tmwseo_vl[' + n + '][label]" value="" ' +
                        '       style="width:100%;" placeholder="' + placeholderTxt + '" />' +
                    '</td>' +
                    '<td style="padding:4px 6px;text-align:center;">' +
                        '<input type="checkbox" name="tmwseo_vl[' + n + '][is_active]" value="1" checked />' +
                    '</td>' +
                    '<td style="padding:4px 6px;text-align:center;">' +
                        '<input type="checkbox" name="tmwseo_vl[' + n + '][is_primary]" value="1" ' +
                        '       class="tmwseo-vl-primary" />' +
                    '</td>' +
                    '<td style="padding:4px 6px;text-align:center;">' +
                        '<button type="button" class="button-link tmwseo-vl-remove" ' +
                        '        title="' + removeTxt + '" ' +
                        '        style="color:#a00;font-size:18px;line-height:1;padding:0;">&times;</button>' +
                    '</td>' +
                    // Hidden audit-trail fields
                    '<td style="display:none;">' +
                        '<input type="hidden" name="tmwseo_vl[' + n + '][added_at]"      value="" />' +
                        '<input type="hidden" name="tmwseo_vl[' + n + '][promoted_from]" value="manual" />' +
                    '</td>';
                return tr;
            }

            // Add Link
            document.getElementById('tmwseo-vl-add-btn').addEventListener('click', function () {
                var emptyRow = document.getElementById('tmwseo-vl-empty-row');
                if (emptyRow) emptyRow.parentNode.removeChild(emptyRow);
                document.getElementById('tmwseo-vl-rows').appendChild(buildRow(counter));
                counter++;
            });

            // Remove row + Primary enforcement (delegated)
            document.getElementById('tmwseo-vl-rows').addEventListener('click', function (e) {
                var tgt = e.target;

                if (tgt && tgt.classList.contains('tmwseo-vl-remove')) {
                    var row = tgt.closest('tr.tmwseo-vl-row');
                    if (row) {
                        row.parentNode.removeChild(row);
                        var tbody = document.getElementById('tmwseo-vl-rows');
                        if (!tbody.querySelector('tr.tmwseo-vl-row')) {
                            var empty = document.createElement('tr');
                            empty.id = 'tmwseo-vl-empty-row';
                            empty.innerHTML = '<td colspan="6" style="padding:10px 8px;color:#888;font-style:italic;">' +
                                emptyTxt + '</td>';
                            tbody.appendChild(empty);
                        }
                    }
                }

                // Primary: only one may be checked
                if (tgt && tgt.classList.contains('tmwseo-vl-primary') && tgt.checked) {
                    document.querySelectorAll('.tmwseo-vl-primary').forEach(function (cb) {
                        if (cb !== tgt) cb.checked = false;
                    });
                }
            });
        }());
        </script>
        <?php
    }

    // ── Render a single existing row ──────────────────────────────────────

    private static function render_row( int $n, array $entry ): void {
        $url         = (string) ( $entry['url']          ?? '' );
        $type        = (string) ( $entry['type']         ?? '' );
        $label       = (string) ( $entry['label']        ?? '' );
        $is_active   = ! empty( $entry['is_active'] );
        $is_primary  = ! empty( $entry['is_primary'] );
        $added_at    = (string) ( $entry['added_at']      ?? '' );
        $prom_from   = (string) ( $entry['promoted_from'] ?? 'manual' );

        echo '<tr class="tmwseo-vl-row" data-idx="' . (int) $n . '">';

        // Type
        echo '<td style="padding:4px 6px;">';
        echo '<select name="tmwseo_vl[' . (int) $n . '][type]" style="width:100%;">';
        echo '<option value="">' . esc_html__( '— Select type —', 'tmwseo' ) . '</option>';
        foreach ( self::TYPE_LABELS as $val => $label_text ) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $val ),
                selected( $type, $val, false ),
                esc_html( $label_text )
            );
        }
        echo '</select></td>';

        // URL
        echo '<td style="padding:4px 6px;">';
        echo '<input type="url" name="tmwseo_vl[' . (int) $n . '][url]" '
            . 'value="' . esc_attr( $url ) . '" class="large-text" placeholder="https://" />';
        echo '</td>';

        // Label
        echo '<td style="padding:4px 6px;">';
        echo '<input type="text" name="tmwseo_vl[' . (int) $n . '][label]" '
            . 'value="' . esc_attr( $label ) . '" style="width:100%;" '
            . 'placeholder="' . esc_attr__( 'Optional label', 'tmwseo' ) . '" />';
        echo '</td>';

        // Active
        echo '<td style="padding:4px 6px;text-align:center;">';
        echo '<input type="checkbox" name="tmwseo_vl[' . (int) $n . '][is_active]" '
            . 'value="1"' . checked( $is_active, true, false ) . ' />';
        echo '</td>';

        // Primary
        echo '<td style="padding:4px 6px;text-align:center;">';
        echo '<input type="checkbox" name="tmwseo_vl[' . (int) $n . '][is_primary]" '
            . 'value="1" class="tmwseo-vl-primary"' . checked( $is_primary, true, false ) . ' />';
        echo '</td>';

        // Remove
        echo '<td style="padding:4px 6px;text-align:center;">';
        echo '<button type="button" class="button-link tmwseo-vl-remove" '
            . 'title="' . esc_attr__( 'Remove', 'tmwseo' ) . '" '
            . 'style="color:#a00;font-size:18px;line-height:1;padding:0;">&times;</button>';
        echo '</td>';

        // Hidden audit-trail fields (no display cell needed — keep them in a hidden td)
        echo '<td style="display:none;">';
        echo '<input type="hidden" name="tmwseo_vl[' . (int) $n . '][added_at]"      value="' . esc_attr( $added_at ) . '" />';
        echo '<input type="hidden" name="tmwseo_vl[' . (int) $n . '][promoted_from]" value="' . esc_attr( $prom_from ) . '" />';
        echo '</td>';

        echo '</tr>';
    }

    // ── Metabox save ──────────────────────────────────────────────────────

    public static function save_metabox( int $post_id, \WP_Post $post ): void {
        if ( ! isset( $_POST['tmwseo_verified_links_nonce'] ) ) { return; }
        if ( ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( (string) $_POST['tmwseo_verified_links_nonce'] ) ),
            self::NONCE_SAVE . $post_id
        ) ) { return; }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw_rows = ( isset( $_POST['tmwseo_vl'] ) && is_array( $_POST['tmwseo_vl'] ) )
            ? wp_unslash( $_POST['tmwseo_vl'] )
            : [];

        $links       = [];
        $seen_urls   = [];
        $has_primary = false;

        foreach ( $raw_rows as $row ) {
            if ( ! is_array( $row ) ) { continue; }

            $entry = self::sanitize_and_validate_entry( (array) $row );
            if ( $entry === false ) { continue; }

            // Dedup by normalised URL
            $norm = self::normalize_url_for_dedup( $entry['url'] );
            if ( isset( $seen_urls[ $norm ] ) ) {
                Logs::info( 'verified_links', '[TMW-VL] Deduped duplicate URL on save', [
                    'post_id' => $post_id,
                    'url'     => $entry['url'],
                ] );
                continue;
            }
            $seen_urls[ $norm ] = true;

            // Enforce single primary
            if ( $entry['is_primary'] ) {
                if ( $has_primary ) {
                    $entry['is_primary'] = false;
                } else {
                    $has_primary = true;
                }
            }

            $links[] = $entry;

            if ( count( $links ) >= self::MAX_LINKS ) {
                Logs::warn( 'verified_links', '[TMW-VL] MAX_LINKS reached — truncating on save', [
                    'post_id'   => $post_id,
                    'max_links' => self::MAX_LINKS,
                ] );
                break;
            }
        }

        update_post_meta( $post_id, self::META_KEY, wp_json_encode( $links ) );

        Logs::info( 'verified_links', '[TMW-VL] Saved verified external links', [
            'post_id' => $post_id,
            'count'   => count( $links ),
        ] );
    }

    // ── handle_promote() — admin-post handler ─────────────────────────────

    public static function handle_promote(): void {
        $post_id = (int) ( $_POST['post_id'] ?? 0 );
        if ( $post_id <= 0 ) {
            wp_die( esc_html__( 'Invalid post.', 'tmwseo' ) );
        }

        if (
            ! isset( $_POST['tmwseo_promote_nonce'] ) ||
            ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( (string) $_POST['tmwseo_promote_nonce'] ) ),
                self::NONCE_PROMOTE . $post_id
            )
        ) {
            wp_die( esc_html__( 'Security check failed.', 'tmwseo' ) );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'tmwseo' ) );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $promote_urls = ( isset( $_POST['tmwseo_promote_url'] ) && is_array( $_POST['tmwseo_promote_url'] ) )
            ? array_map( 'esc_url_raw', array_map( 'wp_unslash', $_POST['tmwseo_promote_url'] ) )
            : [];

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $promote_types = ( isset( $_POST['tmwseo_promote_type'] ) && is_array( $_POST['tmwseo_promote_type'] ) )
            ? array_map( 'sanitize_key', array_map( 'wp_unslash', $_POST['tmwseo_promote_type'] ) )
            : [];

        $promoted = 0;
        $skipped  = 0;

        foreach ( $promote_urls as $idx => $url ) {
            $type = (string) ( $promote_types[ $idx ] ?? '' );

            if ( $type === '' || ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
                Logs::info( 'verified_links', '[TMW-VL] Skipped promote — no valid type', [
                    'post_id' => $post_id,
                    'url'     => $url,
                    'type'    => $type,
                ] );
                $skipped++;
                continue;
            }

            $added = self::add_link( $post_id, $url, $type, '', true, false, 'research' );
            if ( $added ) {
                $promoted++;
                Logs::info( 'verified_links', '[TMW-VL] Promoted link from research', [
                    'post_id' => $post_id,
                    'url'     => $url,
                    'type'    => $type,
                ] );
            } else {
                $skipped++;
                Logs::info( 'verified_links', '[TMW-VL] Promote skipped — invalid or duplicate', [
                    'post_id' => $post_id,
                    'url'     => $url,
                ] );
            }
        }

        $redirect = (string) get_edit_post_link( $post_id, 'url' );
        $redirect  = add_query_arg( 'tmwseo_vl_promoted', $promoted, $redirect );
        $redirect .= '#tmwseo_verified_external_links';

        wp_safe_redirect( $redirect );
        exit;
    }

    // ── Admin notice after promote ────────────────────────────────────────

    public static function render_promote_notice(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->base !== 'post' || $screen->post_type !== 'model' ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['tmwseo_vl_promoted'] ) ) { return; }

        $n = (int) $_GET['tmwseo_vl_promoted'];

        if ( $n > 0 ) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html( sprintf(
                /* translators: %d number of links promoted */
                _n(
                    '%d link promoted to Verified External Links.',
                    '%d links promoted to Verified External Links.',
                    $n,
                    'tmwseo'
                ),
                $n
            ) );
            echo '</p></div>';
        } else {
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo esc_html__( 'No links were promoted — make sure you checked at least one URL and selected a type for it.', 'tmwseo' );
            echo '</p></div>';
        }
    }

    // ── add_link() — internal CRUD ────────────────────────────────────────

    /**
     * Add a single verified link to a model post.
     * Used by the promote handler and by tests. Returns false if the URL
     * is invalid, already present (deduped), or the limit is reached.
     */
    public static function add_link(
        int    $post_id,
        string $url,
        string $type,
        string $label         = '',
        bool   $is_active     = true,
        bool   $is_primary    = false,
        string $promoted_from = 'manual'
    ): bool {
        $entry = self::sanitize_and_validate_entry( [
            'url'          => $url,
            'type'         => $type,
            'label'        => $label,
            'is_active'    => $is_active    ? '1' : '0',
            'is_primary'   => $is_primary   ? '1' : '0',
            'promoted_from'=> $promoted_from,
            'added_at'     => '',
        ] );
        if ( $entry === false ) { return false; }

        $links = self::get_links( $post_id );

        if ( count( $links ) >= self::MAX_LINKS ) {
            Logs::warn( 'verified_links', '[TMW-VL] MAX_LINKS reached — cannot add via add_link()', [
                'post_id' => $post_id,
            ] );
            return false;
        }

        // Dedup
        $norm = self::normalize_url_for_dedup( $entry['url'] );
        foreach ( $links as $existing ) {
            if ( self::normalize_url_for_dedup( (string) ( $existing['url'] ?? '' ) ) === $norm ) {
                return false; // silent dedup
            }
        }

        // Only one primary
        if ( $entry['is_primary'] ) {
            foreach ( $links as &$l ) {
                $l['is_primary'] = false;
            }
            unset( $l );
        }

        $links[] = $entry;
        update_post_meta( $post_id, self::META_KEY, wp_json_encode( $links ) );

        return true;
    }

    // ── get_links() ───────────────────────────────────────────────────────

    /**
     * Return all verified link entries for a model post (active and inactive).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_links( int $post_id ): array {
        $raw = (string) get_post_meta( $post_id, self::META_KEY, true );
        if ( $raw === '' ) { return []; }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    // ── get_schema_urls() ─────────────────────────────────────────────────

    /**
     * Return clean, validated URLs for active verified links.
     * Called by SchemaGenerator::person_schema() for sameAs.
     * Never reads research meta keys.
     *
     * @return string[]
     */
    public static function get_schema_urls( int $post_id ): array {
        $links = self::get_links( $post_id );
        $urls  = [];
        foreach ( $links as $link ) {
            if ( empty( $link['is_active'] ) ) { continue; }
            $url = trim( (string) ( $link['url'] ?? '' ) );
            if ( $url !== '' && filter_var( $url, FILTER_VALIDATE_URL ) ) {
                $urls[] = $url;
            }
        }
        return array_values( array_unique( $urls ) );
    }

    // ── Shortcode ─────────────────────────────────────────────────────────

    /**
     * [tmw_verified_links model_id="" active_only="1" show_label="1"]
     *
     * Renders only verified external links. Does NOT render platform profile
     * links — use [tmw_model_links] for those. The two shortcodes are independent.
     *
     * @param array|string $atts
     */
    public static function shortcode_verified_links( $atts ): string {
        $atts = shortcode_atts( [
            'model_id'    => 0,
            'active_only' => '1',
            'show_label'  => '1',
        ], $atts, 'tmw_verified_links' );

        $model_id    = (int) ( $atts['model_id'] ?: get_the_ID() );
        if ( $model_id <= 0 ) { return ''; }

        $active_only = ( (string) $atts['active_only'] !== '0' );
        $show_label  = ( (string) $atts['show_label']  !== '0' );

        $links = self::get_links( $model_id );
        if ( empty( $links ) ) { return ''; }

        $items = '';
        foreach ( $links as $link ) {
            if ( $active_only && empty( $link['is_active'] ) ) { continue; }

            $url = trim( (string) ( $link['url'] ?? '' ) );
            if ( $url === '' || ! filter_var( $url, FILTER_VALIDATE_URL ) ) { continue; }

            $custom_label = trim( (string) ( $link['label'] ?? '' ) );
            $type         = (string) ( $link['type'] ?? 'other' );

            $display = ( $show_label && $custom_label !== '' )
                ? $custom_label
                : self::type_label( $type );

            $items .= '<li>'
                . '<a href="' . esc_url( $url ) . '" rel="nofollow noopener" target="_blank">'
                . esc_html( $display )
                . '</a></li>';
        }

        if ( $items === '' ) { return ''; }

        return '<div class="tmw-verified-links"><ul>' . $items . '</ul></div>';
    }

    // ── render_promote_block() — called from ModelHelper ──────────────────

    /**
     * Render the promote-from-research block inside the yellow proposed-data panel.
     * Called by ModelHelper::render_metabox() when proposed social_urls exist.
     *
     * Safety contract:
     * - No URL is promoted without an explicit checkbox selection.
     * - No URL is promoted without a chosen type (invalid/blank type → skipped).
     * - This form has NO connection to the "Apply Proposed Data" button.
     * - The proposed blob is NOT deleted by this action.
     *
     * @param int      $post_id
     * @param string[] $social_urls  Raw URL strings from the merged research blob.
     */
    public static function render_promote_block( int $post_id, array $social_urls ): void {
        $social_urls = array_values(
            array_filter( array_map( 'trim', $social_urls ) )
        );
        if ( empty( $social_urls ) ) { return; }

        $promote_url = admin_url( 'admin-post.php' );
        $nonce       = wp_create_nonce( self::NONCE_PROMOTE . $post_id );

        echo '<div style="margin-top:12px;border-top:1px dashed #d4a900;padding-top:10px;">';
        echo '<strong style="display:block;margin-bottom:4px;">';
        echo esc_html__( 'Candidate Social / External Links (from research)', 'tmwseo' );
        echo '</strong>';
        echo '<p style="margin:0 0 8px;color:#666;font-size:12px;">';
        echo esc_html__(
            'Select links and assign a type to promote them to Verified External Links. '
            . 'No link is promoted automatically. This form is independent of "Apply Proposed Data".',
            'tmwseo'
        );
        echo '</p>';

        echo '<form method="post" action="' . esc_url( $promote_url ) . '">';
        echo '<input type="hidden" name="action"                value="tmwseo_promote_to_verified" />';
        echo '<input type="hidden" name="post_id"               value="' . (int) $post_id . '" />';
        echo '<input type="hidden" name="tmwseo_promote_nonce"  value="' . esc_attr( $nonce ) . '" />';

        // Sort type labels A→Z for the dropdown — display only, no functional change.
        $sorted_type_labels = self::TYPE_LABELS;
        asort( $sorted_type_labels );

        echo '<table style="width:100%;border-collapse:collapse;margin-bottom:8px;font-size:13px;">';
        echo '<tr style="background:#fef3cd;font-size:11px;color:#555;">'
            . '<th style="padding:3px 0;width:22px;"></th>'
            . '<th style="padding:3px 4px;text-align:left;">' . esc_html__( 'Platform', 'tmwseo' ) . '</th>'
            . '<th style="padding:3px 4px;text-align:left;">' . esc_html__( 'URL', 'tmwseo' ) . '</th>'
            . '<th style="padding:3px 4px;width:150px;text-align:left;">' . esc_html__( 'Type', 'tmwseo' ) . '</th>'
            . '</tr>';

        foreach ( $social_urls as $idx => $url ) {
            $display        = strlen( $url ) > 55 ? substr( $url, 0, 55 ) . '…' : $url;
            $guessed        = self::guess_type_from_url( $url );
            $platform_label = self::platform_label_from_url( $url );

            echo '<tr style="border-bottom:1px solid #f0e0a0;">';

            // Checkbox
            echo '<td style="padding:5px 6px 5px 0;width:22px;vertical-align:middle;">';
            echo '<input type="checkbox" '
                . 'name="tmwseo_promote_url[]" '
                . 'value="' . esc_attr( $url ) . '" '
                . 'id="tmwseo-promote-' . (int) $idx . '" />';
            echo '</td>';

            // Platform label
            echo '<td style="padding:5px 4px;vertical-align:middle;white-space:nowrap;font-size:11px;color:#444;">';
            echo esc_html( $platform_label );
            echo '</td>';

            // URL link
            echo '<td style="padding:5px 8px 5px 4px;vertical-align:middle;">';
            echo '<label for="tmwseo-promote-' . (int) $idx . '" '
                . 'style="font-family:monospace;font-size:11px;" '
                . 'title="' . esc_attr( $url ) . '">';
            echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" '
                . 'style="color:#1a5276;">'
                . esc_html( $display )
                . '</a>';
            echo '</label>';
            echo '</td>';

            // Type dropdown — pre-selected by URL heuristic, A→Z sorted
            echo '<td style="padding:5px 0;width:150px;vertical-align:middle;">';
            echo '<select name="tmwseo_promote_type[' . (int) $idx . ']" style="width:100%;">';
            echo '<option value="">' . esc_html__( '— Type —', 'tmwseo' ) . '</option>';
            foreach ( $sorted_type_labels as $val => $label_text ) {
                printf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr( $val ),
                    selected( $guessed, $val, false ),
                    esc_html( $label_text )
                );
            }
            echo '</select>';
            echo '</td>';

            echo '</tr>';
        }

        echo '</table>';

        echo '<input type="submit" class="button" '
            . 'value="' . esc_attr__( 'Promote Selected to Verified Links', 'tmwseo' ) . '" />';
        echo '</form>';
        echo '</div>';
    }

    // ── sanitize_and_validate_entry() ─────────────────────────────────────

    /**
     * Validate and sanitize one raw entry array.
     * Returns false if the entry is invalid (bad URL, bad type).
     *
     * @param  array<string,mixed> $raw
     * @return array<string,mixed>|false
     */
    private static function sanitize_and_validate_entry( array $raw ): array|false {
        // URL — required, https or http only
        $url_raw = trim( (string) ( $raw['url'] ?? '' ) );
        if ( $url_raw === '' ) { return false; }
        $url = esc_url_raw( $url_raw );
        if ( $url === '' || ! filter_var( $url, FILTER_VALIDATE_URL ) ) { return false; }
        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        if ( ! in_array( $scheme, [ 'https', 'http' ], true ) ) { return false; }

        // Type — required, must be in ALLOWED_TYPES
        $type = sanitize_key( (string) ( $raw['type'] ?? '' ) );
        if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) { return false; }

        // Label — optional, max 80 chars
        $label = substr( sanitize_text_field( (string) ( $raw['label'] ?? '' ) ), 0, 80 );

        // Flags
        $raw_active  = $raw['is_active']  ?? '1';
        $raw_primary = $raw['is_primary'] ?? '0';
        $is_active   = ( $raw_active  !== '' && $raw_active  !== '0' && $raw_active  !== false );
        $is_primary  = ( $raw_primary !== '' && $raw_primary !== '0' && $raw_primary !== false );

        // added_at — preserve valid Y-m-d; otherwise set today
        $added_at_raw = trim( (string) ( $raw['added_at'] ?? '' ) );
        $added_at     = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $added_at_raw )
            ? $added_at_raw
            : date( 'Y-m-d' );

        // promoted_from — audit trail
        $pf_raw       = sanitize_text_field( (string) ( $raw['promoted_from'] ?? 'manual' ) );
        $promoted_from = in_array( $pf_raw, [ 'research', 'manual' ], true ) ? $pf_raw : 'manual';

        return [
            'url'          => $url,
            'type'         => $type,
            'label'        => $label,
            'is_active'    => $is_active,
            'is_primary'   => $is_primary,
            'added_at'     => $added_at,
            'promoted_from'=> $promoted_from,
        ];
    }

    // ── normalize_url_for_dedup() ─────────────────────────────────────────

    private static function normalize_url_for_dedup( string $url ): string {
        $parts = wp_parse_url( trim( $url ) );
        if ( ! is_array( $parts ) ) {
            return strtolower( rtrim( $url, '/' ) );
        }
        $scheme = strtolower( $parts['scheme'] ?? 'https' );
        $host   = strtolower( $parts['host']   ?? '' );
        $path   = rtrim( $parts['path'] ?? '', '/' );
        $query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
        return $scheme . '://' . $host . $path . $query;
    }

    // ── type_label() ──────────────────────────────────────────────────────

    private static function type_label( string $type ): string {
        return self::TYPE_LABELS[ $type ] ?? ucfirst( str_replace( '_', ' ', $type ) );
    }

    // ── platform_label_from_url() — human-readable name for promote table ────

    /**
     * Return a short human-readable platform name for the promote-block table.
     * Best-effort heuristic for operator context only — not a trust decision.
     */
    private static function platform_label_from_url( string $url ): string {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $host = (string) preg_replace( '/^www\./', '', $host );

        $map = [
            'instagram.com'  => 'Instagram',
            'tiktok.com'     => 'TikTok',
            'twitter.com'    => 'X (Twitter)',
            'x.com'          => 'X (Twitter)',
            'facebook.com'   => 'Facebook',
            'fb.com'         => 'Facebook',
            'youtube.com'    => 'YouTube',
            'youtu.be'       => 'YouTube',
            'linktr.ee'      => 'Linktree',
            'linktree.com'   => 'Linktree',
            'onlyfans.com'   => 'OnlyFans',
            'fansly.com'     => 'Fansly',
            'chaturbate.com' => 'Chaturbate',
            'stripchat.com'  => 'Stripchat',
            'livejasmin.com' => 'LiveJasmin',
            'camsoda.com'    => 'CamSoda',
            'bongacams.com'  => 'BongaCams',
            'cam4.com'       => 'Cam4',
            'myfreecams.com' => 'MyFreeCams',
            'allmylinks.com' => 'AllMyLinks',
            'beacons.ai'     => 'Beacons',
            'solo.to'        => 'solo.to',
            'pornhub.com'    => 'Pornhub',
        ];

        if ( isset( $map[ $host ] ) ) {
            return $map[ $host ];
        }

        // Generic fallback: strip TLD and capitalise the root domain.
        $root = explode( '.', $host )[0] ?? $host;
        return ucfirst( (string) $root );
    }

    // ── guess_type_from_url() — UI convenience only ───────────────────────

    private static function guess_type_from_url( string $url ): string {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $host = (string) preg_replace( '/^www\./', '', $host );

        $map = [
            'instagram.com' => 'instagram',
            'tiktok.com'    => 'tiktok',
            'twitter.com'   => 'x',
            'x.com'         => 'x',
            'facebook.com'  => 'facebook',
            'fb.com'        => 'facebook',
            'youtube.com'   => 'youtube',
            'youtu.be'      => 'youtube',
            'linktr.ee'     => 'linktree',
            'linktree.com'  => 'linktree',
            'onlyfans.com'  => 'onlyfans',
            'fansly.com'    => 'fansly',
            'pornhub.com'   => 'pornhub',
        ];

        return $map[ $host ] ?? 'other';
    }
}
