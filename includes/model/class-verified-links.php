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
 *   "url"              : string  — esc_url_raw, required, https/http only.
 *                                  This is the operator-approved OUTBOUND TARGET URL.
 *                                  It may differ from source_url (see below).
 *                                  It is what the frontend shortcode links to UNLESS
 *                                  affiliate routing is active (see use_affiliate).
 *   "source_url"       : string  — optional. The original detected profile URL (audit
 *                                  trail). Stored when the operator chose a different
 *                                  outbound target. Never rendered; never used in schema.
 *   "outbound_type"    : string  — optional. Operator intent: "direct_profile" |
 *                                  "personal_site" | "website" | "social". Stored for
 *                                  future routing logic. No functional effect yet.
 *   "use_affiliate"    : bool    — default false. When true, the shortcode routes through
 *                                  the affiliate layer (get_routed_url()) instead of using
 *                                  url directly. Schema sameAs ALWAYS uses url regardless.
 *   "affiliate_network": string  — key into one of two admin-configured affiliate
 *                                  options, looked up in this order:
 *                                  1. tmwseo_affiliate_networks (network-level keys,
 *                                     e.g. 'crack_revenue' — configured in the
 *                                     Affiliate Networks section of admin)
 *                                  2. tmwseo_platform_affiliate_settings (platform
 *                                     slugs only — e.g. 'fansly', 'chaturbate')
 *                                  Use AffiliateLinkBuilder::get_configurable_network_keys()
 *                                  to get the current list of available keys.
 *                                  Required when use_affiliate=true; ignored otherwise.
 *   "type"             : string  — one of ALLOWED_TYPES, required.
 *   "label"            : string  — optional display override, max 80 chars.
 *   "is_active"        : bool    — default true; inactive entries excluded from output.
 *   "is_primary"       : bool    — at most one entry may be true.
 *   "added_at"         : string  — Y-m-d, set on creation, never overwritten.
 *   "promoted_from"    : string  — "manual" | "research" (audit trail only).
 * }
 *
 * URL semantics (three distinct concepts):
 *   source_url (audit) → url (outbound target) → get_routed_url() (affiliate or url)
 *   schema sameAs always uses url, never the routed affiliate URL.
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

    /**
     * Allowed type slugs for verified link entries.
     *
     * @since 5.1.0 Extended to include all cam platform / link-hub slugs
     *              used by VerifiedLinksFamilies. Order here is irrelevant
     *              for validation; per-block dropdown order comes from
     *              VerifiedLinksFamilies::types_in_family().
     */
    const ALLOWED_TYPES = [
        // Cam platforms
        'streamate',
        'chaturbate',
        'stripchat',
        'livejasmin',
        'camsoda',
        'bongacams',
        'cam4',
        'myfreecams',
        // Personal website
        'personal_site',
        // Fansites
        'onlyfans',
        'fansly',
        'fancentro',
        // Tube sites
        'pornhub',
        // Social media + link hubs
        'instagram',
        'tiktok',
        'x',
        'facebook',
        'youtube',
        'linktree',
        'beacons',
        'allmylinks',
        // Catch-all (Other / Legacy block)
        'other',
    ];

    const TYPE_LABELS = [
        'streamate'     => 'Streamate',
        'chaturbate'    => 'Chaturbate',
        'stripchat'     => 'Stripchat',
        'livejasmin'    => 'LiveJasmin',
        'camsoda'       => 'CamSoda',
        'bongacams'     => 'BongaCams',
        'cam4'          => 'Cam4',
        'myfreecams'    => 'MyFreeCams',
        'personal_site' => 'Personal Site',
        'onlyfans'      => 'OnlyFans',
        'fansly'        => 'Fansly',
        'fancentro'     => 'FanCentro',
        'pornhub'       => 'Pornhub',
        'instagram'     => 'Instagram',
        'tiktok'        => 'TikTok',
        'x'             => 'X (Twitter)',
        'facebook'      => 'Facebook',
        'youtube'       => 'YouTube',
        'linktree'      => 'Linktree',
        'beacons'       => 'Beacons',
        'allmylinks'    => 'AllMyLinks',
        'other'         => 'Other',
    ];

    // ── Bootstrap ─────────────────────────────────────────────────────────

    /**
     * Register all hooks for metabox render/save, Gutenberg fallback save,
     * shortcode output, and promote-from-research admin flows.
     */
    public static function init(): void {
        add_action( 'add_meta_boxes',                          [ __CLASS__, 'register_metabox' ] );
        add_action( 'save_post_model',                         [ __CLASS__, 'save_metabox' ], 20, 2 );
        add_action( 'enqueue_block_editor_assets',             [ __CLASS__, 'enqueue_editor_assets' ] );
        add_action( 'wp_ajax_tmwseo_save_verified_links',      [ __CLASS__, 'ajax_save_verified_links' ] );
        add_shortcode( 'tmw_verified_links',                   [ __CLASS__, 'shortcode_verified_links' ] );
        add_action( 'admin_post_tmwseo_promote_to_verified',   [ __CLASS__, 'handle_promote' ] );
        add_action( 'admin_notices',                           [ __CLASS__, 'render_promote_notice' ] );
    }

    // ── Metabox registration ──────────────────────────────────────────────

    /**
     * Register the Verified External Links metabox on model edit screens.
     */
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

    /**
     * Render the Verified External Links metabox as 5 grouped family blocks
     * (Cam Platforms / Personal Website / Fansites / Tube Sites / Social Media)
     * plus an Other / Legacy block that only appears when populated.
     *
     * @since 5.1.0 Replaced flat-table renderer with grouped <details> blocks.
     *              Each block has its own family-scoped Type dropdown and its
     *              own Add Link button. Each row has Move Up / Move Down
     *              controls that swap only with siblings inside the same block.
     *              Schema sameAs output and stored data shape are unchanged.
     */
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

        // Group existing links by family. Preserves stored order within each family.
        // Legacy rows whose `type` is not in the registry fall into 'unmapped'.
        $by_family = [];
        foreach ( VerifiedLinksFamilies::display_order() as $family_slug ) {
            $by_family[ $family_slug ] = [];
        }
        $row_index = 0;
        foreach ( $links as $entry ) {
            $type   = (string) ( $entry['type'] ?? '' );
            $family = VerifiedLinksFamilies::family_for( $type );
            $by_family[ $family ][] = [ 'idx' => $row_index, 'entry' => $entry ];
            $row_index++;
        }

        // ── Header / description ──────────────────────────────────────────
        echo '<p style="margin-top:0;color:#555;font-size:13px;">';
        echo esc_html__(
            'These links appear on the front end and in schema sameAs. '
            . 'They are never auto-imported from research.',
            'tmwseo'
        );
        echo '</p>';

        // Inline scoped CSS for the grouped UI (single style tag, prefixed).
        ?>
        <style>
            .tmwseo-vl-block { margin: 0 0 12px; border: 1px solid #dcdcde; border-radius: 4px; background: #fff; }
            .tmwseo-vl-block > summary {
                list-style: none; cursor: pointer; padding: 8px 12px;
                background: #f6f7f7; border-bottom: 1px solid #dcdcde;
                font-weight: 600; display: flex; align-items: center; gap: 8px;
                user-select: none;
            }
            .tmwseo-vl-block > summary::-webkit-details-marker { display: none; }
            .tmwseo-vl-block > summary:before {
                content: '▸'; display: inline-block; width: 12px; transition: transform 0.15s ease;
                color: #555;
            }
            .tmwseo-vl-block[open] > summary:before { transform: rotate(90deg); }
            .tmwseo-vl-block-accent { width: 6px; height: 16px; border-radius: 2px; display: inline-block; }
            .tmwseo-vl-count {
                background: #e9e9e9; color: #444; font-size: 11px; font-weight: 600;
                border-radius: 10px; padding: 1px 8px; margin-left: 4px;
            }
            .tmwseo-vl-block-body { padding: 8px 10px 12px; }
            .tmwseo-vl-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
            .tmwseo-vl-table th, .tmwseo-vl-table td { padding: 4px 6px; vertical-align: middle; }
            .tmwseo-vl-table thead th { background: #fafafa; font-size: 11px; color: #555; text-align: left; border-bottom: 1px solid #eee; }
            .tmwseo-vl-table thead th.tmwseo-vl-th-center { text-align: center; }
            .tmwseo-vl-row { border-bottom: 1px solid #f1f1f1; }
            .tmwseo-vl-aff-row { background: #fafafa; border-bottom: 1px solid #f1f1f1; }
            .tmwseo-vl-empty {
                padding: 10px 8px; color: #888; font-style: italic; font-size: 12px;
            }
            .tmwseo-vl-move-btn {
                background: #fff; border: 1px solid #c3c4c7; border-radius: 3px;
                width: 22px; height: 22px; line-height: 18px; font-size: 12px;
                cursor: pointer; padding: 0; color: #2c3338;
            }
            .tmwseo-vl-move-btn:hover:not(:disabled) { background: #f0f0f1; border-color: #8c8f94; }
            .tmwseo-vl-move-btn:disabled { opacity: 0.35; cursor: not-allowed; }
            .tmwseo-vl-move-cell { white-space: nowrap; width: 56px; }
            .tmwseo-vl-add-btn { margin-top: 6px; }
            .tmwseo-vl-block-add-help { margin-left: 10px; font-size: 11px; color: #777; }
        </style>
        <?php

        // ── Render the 5 visible blocks + optional Other/Legacy ───────────
        foreach ( VerifiedLinksFamilies::display_order() as $family_slug ) {
            $family_rows = $by_family[ $family_slug ];

            // Skip the unmapped block entirely when there are no legacy rows.
            if ( $family_slug === VerifiedLinksFamilies::FAMILY_UNMAPPED && empty( $family_rows ) ) {
                continue;
            }

            self::render_family_block( $family_slug, $family_rows );
        }

        // ── JS: per-block add + within-block reorder + global primary ────
        self::render_grouped_js( count( $links ) );
    }

    /**
     * Render one family block: collapsible <details> wrapper with its own
     * table body and Add Link button.
     *
     * @param string                              $family_slug
     * @param array<int,array{idx:int,entry:array<string,mixed>}> $rows
     */
    private static function render_family_block( string $family_slug, array $rows ): void {
        $label  = VerifiedLinksFamilies::family_label( $family_slug );
        $color  = VerifiedLinksFamilies::family_color( $family_slug );
        $count  = count( $rows );
        $is_unmapped = ( $family_slug === VerifiedLinksFamilies::FAMILY_UNMAPPED );

        $block_id      = 'tmwseo-vl-block-' . sanitize_key( $family_slug );
        $tbody_id      = 'tmwseo-vl-rows-' . sanitize_key( $family_slug );
        $add_btn_id    = 'tmwseo-vl-add-' . sanitize_key( $family_slug );
        $count_id      = 'tmwseo-vl-count-' . sanitize_key( $family_slug );

        echo '<details class="tmwseo-vl-block" id="' . esc_attr( $block_id ) . '"'
            . ' data-family="' . esc_attr( $family_slug ) . '" open>';
        echo '<summary>';
        echo '<span class="tmwseo-vl-block-accent" style="background:' . esc_attr( $color ) . ';"></span>';
        echo '<span>' . esc_html( $label ) . '</span>';
        echo '<span class="tmwseo-vl-count" id="' . esc_attr( $count_id ) . '">' . (int) $count . '</span>';
        echo '</summary>';

        echo '<div class="tmwseo-vl-block-body">';

        echo '<table class="tmwseo-vl-table">';
        echo '<colgroup>'
            . '<col style="width:56px;">'   // move
            . '<col style="width:130px;">'  // type
            . '<col>'                       // url
            . '<col style="width:130px;">'  // label
            . '<col style="width:56px;">'   // active
            . '<col style="width:60px;">'   // primary
            . '<col style="width:36px;">'   // remove
            . '</colgroup>';
        echo '<thead><tr>';
        echo '<th class="tmwseo-vl-th-center">' . esc_html__( 'Move',    'tmwseo' ) . '</th>';
        echo '<th>'                              . esc_html__( 'Type',    'tmwseo' ) . '</th>';
        echo '<th>'                              . esc_html__( 'URL',     'tmwseo' ) . '</th>';
        echo '<th>'                              . esc_html__( 'Label',   'tmwseo' ) . '</th>';
        echo '<th class="tmwseo-vl-th-center">' . esc_html__( 'Active',  'tmwseo' ) . '</th>';
        echo '<th class="tmwseo-vl-th-center">' . esc_html__( 'Primary', 'tmwseo' ) . '</th>';
        echo '<th></th>';
        echo '</tr></thead>';
        echo '<tbody id="' . esc_attr( $tbody_id ) . '" data-family="' . esc_attr( $family_slug ) . '">';

        if ( empty( $rows ) ) {
            echo '<tr class="tmwseo-vl-empty-row"><td colspan="7" class="tmwseo-vl-empty">'
                . esc_html__( 'No links in this block yet. Click "+ Add Link" below to add one.', 'tmwseo' )
                . '</td></tr>';
        } else {
            foreach ( $rows as $row ) {
                self::render_row( (int) $row['idx'], (array) $row['entry'], $family_slug );
            }
        }

        echo '</tbody>';
        echo '</table>';

        // Per-block Add Link button. Suppressed for the legacy/unmapped block —
        // operators relabel/migrate those, they don't intentionally add new ones.
        if ( ! $is_unmapped ) {
            echo '<p class="tmwseo-vl-add-btn">';
            echo '<button type="button" class="button tmwseo-vl-add-btn-trigger"'
                . ' id="' . esc_attr( $add_btn_id ) . '"'
                . ' data-family="' . esc_attr( $family_slug ) . '">'
                . esc_html__( '+ Add Link', 'tmwseo' )
                . '</button>';
            echo '<span class="tmwseo-vl-block-add-help">'
                . esc_html__( 'Saved with the post. Never auto-imported from research.', 'tmwseo' )
                . '</span>';
            echo '</p>';
        } else {
            echo '<p class="tmwseo-vl-block-add-help" style="margin:6px 0 0;">';
            echo esc_html__(
                'These rows have legacy or unrecognised types. Edit each row\'s type to move it into one of the blocks above.',
                'tmwseo'
            );
            echo '</p>';
        }

        echo '</div>'; // .tmwseo-vl-block-body
        echo '</details>';
    }

    /**
     * Emit the single inline JS block that drives all family blocks.
     * Kept inline (no external file) for v5.1 to avoid touching the assets
     * enqueue layer; the script handle name is reserved for a future split.
     *
     * @param int $existing_row_count Count of already-rendered rows (used to seed the global counter).
     */
    private static function render_grouped_js( int $existing_row_count ): void {
        // Build a JS-side type-options map keyed by family slug so newly-added
        // rows in any block get the right filtered Type dropdown.
        $type_options_by_family = [];
        foreach ( VerifiedLinksFamilies::block_order() as $family_slug ) {
            $opts = '';
            foreach ( VerifiedLinksFamilies::types_in_family( $family_slug ) as $val => $label_text ) {
                $opts .= '<option value="' . esc_attr( $val ) . '">' . esc_html( $label_text ) . '</option>';
            }
            $type_options_by_family[ $family_slug ] = $opts;
        }

        $default_type_by_family = [];
        foreach ( VerifiedLinksFamilies::block_order() as $family_slug ) {
            $default_type_by_family[ $family_slug ] = VerifiedLinksFamilies::default_type_for( $family_slug );
        }

        // Affiliate network options HTML (built once, shared by all new rows
        // across all blocks — same behaviour as the legacy renderer).
        $js_net_keys = class_exists( '\TMWSEO\Engine\Platform\AffiliateLinkBuilder' )
            ? \TMWSEO\Engine\Platform\AffiliateLinkBuilder::get_configurable_network_keys()
            : [];
        $js_net_opts = '<option value="">' . esc_html__( '— Select network —', 'tmwseo' ) . '</option>';
        foreach ( $js_net_keys as $nk => $nl ) {
            $js_net_opts .= '<option value="' . esc_attr( $nk ) . '">' . esc_html( $nl ) . '</option>';
        }

        $aff_url             = esc_url( admin_url( 'admin.php?page=tmwseo-affiliates' ) );
        $configure_aff_html  = esc_html__( 'No networks configured.', 'tmwseo' )
            . ' <a href="' . $aff_url . '" target="_blank" style="font-size:11px;">'
            . esc_html__( 'Configure in Affiliates →', 'tmwseo' ) . '</a>';

        ?>
        <script>
        (function () {
            // Global row index counter — must stay unique across all blocks because
            // every input name is tmwseo_vl[N][...]. PHP iterates POST in submission
            // order which equals DOM order, so per-family ordering is preserved.
            var counter = <?php echo (int) $existing_row_count; ?>;

            var typeOptionsByFamily   = <?php echo wp_json_encode( $type_options_by_family ); ?>;
            var defaultTypeByFamily   = <?php echo wp_json_encode( $default_type_by_family ); ?>;
            var networkSelectHtml     = <?php echo wp_json_encode( $js_net_opts ); ?>;
            var hasNetworks           = <?php echo empty( $js_net_keys ) ? 'false' : 'true'; ?>;
            var configureAffHtml      = <?php echo wp_json_encode( $configure_aff_html ); ?>;

            var labels = {
                placeholder:  <?php echo wp_json_encode( __( 'Optional label', 'tmwseo' ) ); ?>,
                empty:        <?php echo wp_json_encode( __( 'No links in this block yet. Click "+ Add Link" below to add one.', 'tmwseo' ) ); ?>,
                remove:       <?php echo wp_json_encode( __( 'Remove', 'tmwseo' ) ); ?>,
                moveUp:       <?php echo wp_json_encode( __( 'Move up',   'tmwseo' ) ); ?>,
                moveDown:     <?php echo wp_json_encode( __( 'Move down', 'tmwseo' ) ); ?>,
                affRoute:     <?php echo wp_json_encode( __( 'Route through affiliate', 'tmwseo' ) ); ?>,
                networkLabel: <?php echo wp_json_encode( __( 'Network:', 'tmwseo' ) ); ?>,
                schemaNote:   <?php echo wp_json_encode( __( '(schema sameAs always uses the outbound URL above)', 'tmwseo' ) ); ?>
            };

            function buildRow(n, family) {
                var typeOpts    = typeOptionsByFamily[family] || '';
                var defaultType = defaultTypeByFamily[family] || '';

                // Pre-select the default type for this family.
                var selectedTypeOpts = typeOpts.replace(
                    'value="' + defaultType + '"',
                    'value="' + defaultType + '" selected'
                );

                var tr = document.createElement('tr');
                tr.className = 'tmwseo-vl-row';
                tr.setAttribute('data-idx', n);
                tr.setAttribute('data-family', family);
                tr.innerHTML =
                    '<td class="tmwseo-vl-move-cell" style="text-align:center;">' +
                        '<button type="button" class="tmwseo-vl-move-btn tmwseo-vl-move-up" title="' + labels.moveUp + '">▲</button> ' +
                        '<button type="button" class="tmwseo-vl-move-btn tmwseo-vl-move-down" title="' + labels.moveDown + '">▼</button>' +
                    '</td>' +
                    '<td>' +
                        '<select name="tmwseo_vl[' + n + '][type]" class="tmwseo-vl-type" style="width:100%;">' + selectedTypeOpts + '</select>' +
                    '</td>' +
                    '<td>' +
                        '<input type="url" name="tmwseo_vl[' + n + '][url]" value="" class="large-text" placeholder="https://" />' +
                    '</td>' +
                    '<td>' +
                        '<input type="text" name="tmwseo_vl[' + n + '][label]" value="" style="width:100%;" placeholder="' + labels.placeholder + '" />' +
                    '</td>' +
                    '<td style="text-align:center;">' +
                        '<input type="checkbox" name="tmwseo_vl[' + n + '][is_active]" value="1" checked />' +
                    '</td>' +
                    '<td style="text-align:center;">' +
                        '<input type="checkbox" name="tmwseo_vl[' + n + '][is_primary]" value="1" class="tmwseo-vl-primary" />' +
                    '</td>' +
                    '<td style="text-align:center;">' +
                        '<button type="button" class="button-link tmwseo-vl-remove" title="' + labels.remove + '" style="color:#a00;font-size:18px;line-height:1;padding:0;">&times;</button>' +
                    '</td>';

                // Hidden audit-trail fields go in a single off-table holder so colspan
                // alignment in the affiliate sub-row stays correct (7 columns).
                var hiddenHolder = document.createElement('tr');
                hiddenHolder.style.display = 'none';
                hiddenHolder.className = 'tmwseo-vl-hidden-holder';
                hiddenHolder.setAttribute('data-parent-idx', n);
                hiddenHolder.innerHTML =
                    '<td colspan="7">' +
                        '<input type="hidden" name="tmwseo_vl[' + n + '][added_at]"      value="" />' +
                        '<input type="hidden" name="tmwseo_vl[' + n + '][promoted_from]" value="manual" />' +
                        '<input type="hidden" name="tmwseo_vl[' + n + '][source_url]"    value="" />' +
                        '<input type="hidden" name="tmwseo_vl[' + n + '][outbound_type]" value="" />' +
                    '</td>';

                var affTr = document.createElement('tr');
                affTr.className = 'tmwseo-vl-aff-row';
                affTr.setAttribute('data-parent-idx', n);
                var networkCtrl = hasNetworks
                    ? '<select name="tmwseo_vl[' + n + '][affiliate_network]" style="font-size:11px;margin-left:4px;">' + networkSelectHtml + '</select>'
                    : '<span style="font-size:11px;color:#888;margin-left:4px;">' + configureAffHtml + '</span>';
                affTr.innerHTML =
                    '<td colspan="7" style="padding:4px 8px;">' +
                        '<label style="font-size:11px;color:#555;cursor:pointer;">' +
                            '<input type="checkbox" name="tmwseo_vl[' + n + '][use_affiliate]" value="1" style="margin-right:4px;" />' +
                            labels.affRoute +
                        '</label>' +
                        '<span style="margin-left:12px;font-size:11px;color:#888;">' + labels.networkLabel + '</span>' +
                        networkCtrl +
                        '<span style="margin-left:8px;font-size:10px;color:#aaa;">' + labels.schemaNote + '</span>' +
                    '</td>';

                var frag = document.createDocumentFragment();
                frag.appendChild(tr);
                frag.appendChild(hiddenHolder);
                frag.appendChild(affTr);
                return frag;
            }

            // Recompute Move Up / Move Down enabled state for every row in a tbody,
            // and refresh the block's count badge.
            function refreshBlockState(tbody) {
                var rows = tbody.querySelectorAll('tr.tmwseo-vl-row');
                var family = tbody.getAttribute('data-family');

                rows.forEach(function (row, i) {
                    var up   = row.querySelector('.tmwseo-vl-move-up');
                    var down = row.querySelector('.tmwseo-vl-move-down');
                    if (up)   up.disabled   = ( i === 0 );
                    if (down) down.disabled = ( i === rows.length - 1 );
                });

                var countEl = document.getElementById('tmwseo-vl-count-' + family);
                if (countEl) countEl.textContent = String(rows.length);

                // Empty placeholder management.
                var emptyRow = tbody.querySelector('tr.tmwseo-vl-empty-row');
                if (rows.length === 0 && !emptyRow) {
                    var er = document.createElement('tr');
                    er.className = 'tmwseo-vl-empty-row';
                    er.innerHTML = '<td colspan="7" class="tmwseo-vl-empty">' + labels.empty + '</td>';
                    tbody.appendChild(er);
                } else if (rows.length > 0 && emptyRow) {
                    emptyRow.parentNode.removeChild(emptyRow);
                }
            }

            // Find the trio (main row, hidden holder, aff sub-row) for a given main row.
            function rowGroup(mainRow) {
                var idx = mainRow.getAttribute('data-idx');
                var siblings = mainRow.parentNode.children;
                var group = [mainRow];
                for (var i = 0; i < siblings.length; i++) {
                    var s = siblings[i];
                    if (s === mainRow) continue;
                    if (s.getAttribute && s.getAttribute('data-parent-idx') === idx) {
                        group.push(s);
                    }
                }
                return group;
            }

            // Swap a row group with its previous (or next) sibling row group.
            function moveRow(mainRow, direction) {
                var tbody = mainRow.parentNode;
                var allMainRows = Array.prototype.filter.call(
                    tbody.children,
                    function (n) { return n.classList && n.classList.contains('tmwseo-vl-row'); }
                );
                var pos = allMainRows.indexOf(mainRow);
                if (pos < 0) return;

                var targetPos = direction === 'up' ? pos - 1 : pos + 1;
                if (targetPos < 0 || targetPos >= allMainRows.length) return;

                var targetMain  = allMainRows[targetPos];
                var movingGroup = rowGroup(mainRow);
                var targetGroup = rowGroup(targetMain);

                // Detach moving group then reinsert before target group's first node
                // (for up) or after target group's last node (for down).
                movingGroup.forEach(function (n) { tbody.removeChild(n); });

                if (direction === 'up') {
                    var anchor = targetGroup[0];
                    movingGroup.forEach(function (n) { tbody.insertBefore(n, anchor); });
                } else {
                    var lastTarget = targetGroup[targetGroup.length - 1];
                    var afterAnchor = lastTarget.nextSibling;
                    movingGroup.forEach(function (n) {
                        if (afterAnchor) tbody.insertBefore(n, afterAnchor);
                        else tbody.appendChild(n);
                    });
                }

                refreshBlockState(tbody);
            }

            // ── Wire up Add Link buttons (one per family block) ─────────
            document.querySelectorAll('.tmwseo-vl-add-btn-trigger').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var family = btn.getAttribute('data-family');
                    var tbody = document.getElementById('tmwseo-vl-rows-' + family);
                    if (!tbody) return;

                    var emptyRow = tbody.querySelector('tr.tmwseo-vl-empty-row');
                    if (emptyRow) emptyRow.parentNode.removeChild(emptyRow);

                    tbody.appendChild(buildRow(counter, family));
                    counter++;
                    refreshBlockState(tbody);
                });
            });

            // ── Delegated handler for remove / move / primary ───────────
            document.querySelectorAll('tbody[id^="tmwseo-vl-rows-"]').forEach(function (tbody) {
                refreshBlockState(tbody);

                tbody.addEventListener('click', function (e) {
                    var tgt = e.target;
                    if (!tgt) return;

                    if (tgt.classList.contains('tmwseo-vl-remove')) {
                        var row = tgt.closest('tr.tmwseo-vl-row');
                        if (row) {
                            rowGroup(row).forEach(function (n) { tbody.removeChild(n); });
                            refreshBlockState(tbody);
                        }
                        return;
                    }

                    if (tgt.classList.contains('tmwseo-vl-move-up') || tgt.classList.contains('tmwseo-vl-move-down')) {
                        var row2 = tgt.closest('tr.tmwseo-vl-row');
                        if (row2) {
                            moveRow(row2, tgt.classList.contains('tmwseo-vl-move-up') ? 'up' : 'down');
                        }
                        return;
                    }
                });

                // Primary checkboxes: only one may be checked across the entire form.
                tbody.addEventListener('change', function (e) {
                    var tgt = e.target;
                    if (tgt && tgt.classList && tgt.classList.contains('tmwseo-vl-primary') && tgt.checked) {
                        document.querySelectorAll('.tmwseo-vl-primary').forEach(function (cb) {
                            if (cb !== tgt) cb.checked = false;
                        });
                    }
                });
            });
        }());
        </script>
        <?php
    }

    // ── Render a single existing row ──────────────────────────────────────

    /**
     * Render one existing row inside its family block.
     *
     * @since 5.1.0 Added Move Up / Move Down cell, $family parameter, and
     *              family-scoped Type dropdown. The row's $n is the global
     *              row index (input-name suffix); it stays stable across
     *              renders so reordering is purely a DOM-position change.
     *
     * @param int                  $n      Global row index used in input names.
     * @param array<string,mixed>  $entry  Stored entry.
     * @param string               $family Family slug for this block.
     */
    private static function render_row( int $n, array $entry, string $family = '' ): void {
        $url               = (string) ( $entry['url']               ?? '' );
        $type              = (string) ( $entry['type']              ?? '' );
        $label             = (string) ( $entry['label']             ?? '' );
        $is_active         = ! empty( $entry['is_active'] );
        $is_primary        = ! empty( $entry['is_primary'] );
        $added_at          = (string) ( $entry['added_at']          ?? '' );
        $prom_from         = (string) ( $entry['promoted_from']     ?? 'manual' );
        $source_url        = (string) ( $entry['source_url']        ?? '' );
        $outbound_type     = (string) ( $entry['outbound_type']     ?? '' );
        $use_affiliate     = ! empty( $entry['use_affiliate'] );
        $affiliate_network = (string) ( $entry['affiliate_network'] ?? '' );

        // Resolve family if caller didn't pass one (defensive).
        if ( $family === '' ) {
            $family = VerifiedLinksFamilies::family_for( $type );
        }

        // Determine the type options to render in this row's dropdown.
        // For unmapped rows we allow ALL known types so the operator can
        // relabel the row into any family. For family rows we filter strictly.
        if ( $family === VerifiedLinksFamilies::FAMILY_UNMAPPED ) {
            $type_options = VerifiedLinksFamilies::type_labels(); // full set
        } else {
            $type_options = VerifiedLinksFamilies::types_in_family( $family );
        }

        echo '<tr class="tmwseo-vl-row" data-idx="' . (int) $n . '" data-family="' . esc_attr( $family ) . '">';

        // Move
        echo '<td class="tmwseo-vl-move-cell" style="text-align:center;">';
        echo '<button type="button" class="tmwseo-vl-move-btn tmwseo-vl-move-up"'
            . ' title="' . esc_attr__( 'Move up', 'tmwseo' ) . '">▲</button> ';
        echo '<button type="button" class="tmwseo-vl-move-btn tmwseo-vl-move-down"'
            . ' title="' . esc_attr__( 'Move down', 'tmwseo' ) . '">▼</button>';
        echo '</td>';

        // Type
        echo '<td>';
        echo '<select name="tmwseo_vl[' . (int) $n . '][type]" class="tmwseo-vl-type" style="width:100%;">';
        // If the stored type isn't in the filtered list (e.g. a legacy slug
        // surfaced in unmapped mode), still show it as the selected option so
        // we never lose the operator's data on render.
        if ( $type !== '' && ! isset( $type_options[ $type ] ) ) {
            printf(
                '<option value="%s" selected>%s</option>',
                esc_attr( $type ),
                esc_html( self::type_label( $type ) . ' (' . __( 'legacy', 'tmwseo' ) . ')' )
            );
        }
        foreach ( $type_options as $val => $label_text ) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $val ),
                selected( $type, $val, false ),
                esc_html( $label_text )
            );
        }
        echo '</select></td>';

        // URL
        echo '<td>';
        echo '<input type="url" name="tmwseo_vl[' . (int) $n . '][url]"'
            . ' value="' . esc_attr( $url ) . '" class="large-text" placeholder="https://" />';
        if ( $source_url !== '' ) {
            echo '<div style="font-size:10px;color:#888;margin-top:2px;" title="' . esc_attr( $source_url ) . '">';
            echo esc_html__( 'Source:', 'tmwseo' ) . ' ';
            echo '<a href="' . esc_url( $source_url ) . '" target="_blank" rel="noopener" style="color:#888;">'
                . esc_html( strlen( $source_url ) > 50 ? substr( $source_url, 0, 50 ) . '…' : $source_url )
                . '</a>';
            echo '</div>';
        }
        echo '</td>';

        // Label
        echo '<td>';
        echo '<input type="text" name="tmwseo_vl[' . (int) $n . '][label]"'
            . ' value="' . esc_attr( $label ) . '" style="width:100%;"'
            . ' placeholder="' . esc_attr__( 'Optional label', 'tmwseo' ) . '" />';
        echo '</td>';

        // Active
        echo '<td style="text-align:center;">';
        echo '<input type="checkbox" name="tmwseo_vl[' . (int) $n . '][is_active]"'
            . ' value="1"' . checked( $is_active, true, false ) . ' />';
        echo '</td>';

        // Primary
        echo '<td style="text-align:center;">';
        echo '<input type="checkbox" name="tmwseo_vl[' . (int) $n . '][is_primary]"'
            . ' value="1" class="tmwseo-vl-primary"' . checked( $is_primary, true, false ) . ' />';
        echo '</td>';

        // Remove
        echo '<td style="text-align:center;">';
        echo '<button type="button" class="button-link tmwseo-vl-remove"'
            . ' title="' . esc_attr__( 'Remove', 'tmwseo' ) . '"'
            . ' style="color:#a00;font-size:18px;line-height:1;padding:0;">&times;</button>';
        echo '</td>';
        echo '</tr>';

        // Hidden audit-trail fields in a hidden sibling row so the table
        // continues to lay out correctly.
        echo '<tr class="tmwseo-vl-hidden-holder" data-parent-idx="' . (int) $n . '" style="display:none;">';
        echo '<td colspan="7">';
        echo '<input type="hidden" name="tmwseo_vl[' . (int) $n . '][added_at]"      value="' . esc_attr( $added_at ) . '" />';
        echo '<input type="hidden" name="tmwseo_vl[' . (int) $n . '][promoted_from]" value="' . esc_attr( $prom_from ) . '" />';
        echo '<input type="hidden" name="tmwseo_vl[' . (int) $n . '][source_url]"    value="' . esc_attr( $source_url ) . '" />';
        echo '<input type="hidden" name="tmwseo_vl[' . (int) $n . '][outbound_type]" value="' . esc_attr( $outbound_type ) . '" />';
        echo '</td></tr>';

        // Affiliate routing — sub-row directly below the main entry row.
        echo '<tr class="tmwseo-vl-aff-row" data-parent-idx="' . (int) $n . '">';
        echo '<td colspan="7" style="padding:4px 8px;">';
        echo '<label style="font-size:11px;color:#555;cursor:pointer;">';
        echo '<input type="checkbox"'
            . ' name="tmwseo_vl[' . (int) $n . '][use_affiliate]"'
            . ' value="1" '
            . ( $use_affiliate ? 'checked ' : '' )
            . 'style="margin-right:4px;" />';
        echo esc_html__( 'Route through affiliate', 'tmwseo' );
        echo '</label>';

        $network_keys = class_exists( '\TMWSEO\Engine\Platform\AffiliateLinkBuilder' )
            ? \TMWSEO\Engine\Platform\AffiliateLinkBuilder::get_configurable_network_keys()
            : [];

        echo '<span style="margin-left:12px;font-size:11px;color:#888;">'
            . esc_html__( 'Network:', 'tmwseo' ) . ' </span>';

        if ( ! empty( $network_keys ) ) {
            echo '<select name="tmwseo_vl[' . (int) $n . '][affiliate_network]"'
                . ' style="font-size:11px;margin-left:4px;">';
            echo '<option value="">' . esc_html__( '— Select network —', 'tmwseo' ) . '</option>';
            foreach ( $network_keys as $nk => $nl ) {
                printf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr( $nk ),
                    selected( $affiliate_network, $nk, false ),
                    esc_html( $nl )
                );
            }
            echo '</select>';
        } else {
            echo '<span style="font-size:11px;color:#888;margin-left:4px;">';
            if ( $affiliate_network !== '' ) {
                echo '<code>' . esc_html( $affiliate_network ) . '</code>';
                echo '<input type="hidden"'
                    . ' name="tmwseo_vl[' . (int) $n . '][affiliate_network]"'
                    . ' value="' . esc_attr( $affiliate_network ) . '" />';
            } else {
                echo esc_html__( 'No networks configured.', 'tmwseo' );
                echo ' <a href="' . esc_url( admin_url( 'admin.php?page=tmwseo-affiliates' ) ) . '"'
                    . ' style="font-size:11px;" target="_blank">'
                    . esc_html__( 'Configure in Affiliates →', 'tmwseo' )
                    . '</a>';
            }
            echo '</span>';
        }

        echo '<span style="margin-left:8px;font-size:10px;color:#aaa;">'
            . esc_html__( '(schema sameAs always uses the outbound URL above)', 'tmwseo' )
            . '</span>';
        echo '</td>';
        echo '</tr>';
    }

    // ── Metabox save ──────────────────────────────────────────────────────

    /**
     * Enqueue block-editor persistence JS for the Verified External Links metabox.
     *
     * The grouped UI is rendered in a classic metabox, but Gutenberg can save
     * without reliably posting the metabox payload in some environments. This
     * script snapshots the current DOM rows and persists them through admin-ajax
     * at the same time the editor saves the post.
     */
    public static function enqueue_editor_assets(): void {
        if ( ! function_exists( 'get_current_screen' ) ) { return; }
        $screen = get_current_screen();
        if ( ! $screen || ( $screen->base ?? '' ) !== 'post' ) { return; }
        if ( ( $screen->post_type ?? '' ) !== 'model' ) { return; }

        wp_enqueue_script(
            'tmwseo-verified-links-editor',
            TMWSEO_ENGINE_URL . 'assets/js/verified-links-editor.js',
            [ 'wp-data' ],
            TMWSEO_ENGINE_VERSION,
            true
        );

        wp_localize_script( 'tmwseo-verified-links-editor', 'TMWSEOVerifiedLinks', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'tmwseo_verified_links_ajax' ),
        ] );
    }

    /**
     * AJAX save bridge for Gutenberg.
     *
     * Persists grouped Verified External Links rows captured from the block-editor
     * DOM. Sanitization, dedup, ordering, primary enforcement, and MAX_LINKS are
     * delegated to the same persistence path used by save_metabox().
     */
    public static function ajax_save_verified_links(): void {
        check_ajax_referer( 'tmwseo_verified_links_ajax' );

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            Logs::warn( 'verified_links', '[TMW-VL] AJAX save failed: missing post_id', [] );
            wp_send_json_error( [ 'message' => 'Missing post_id' ], 400 );
        }

        if ( get_post_type( $post_id ) !== 'model' ) {
            Logs::warn( 'verified_links', '[TMW-VL] AJAX save failed: invalid post type', [
                'post_id'   => $post_id,
                'post_type' => (string) get_post_type( $post_id ),
            ] );
            wp_send_json_error( [ 'message' => 'Invalid post type' ], 400 );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            Logs::warn( 'verified_links', '[TMW-VL] AJAX save failed: forbidden', [
                'post_id' => $post_id,
            ] );
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        $rows_json = isset( $_POST['rows'] ) ? wp_unslash( (string) $_POST['rows'] ) : '[]';
        $raw_rows  = json_decode( $rows_json, true );
        if ( ! is_array( $raw_rows ) ) {
            $raw_rows = [];
        }

        $saved_links = self::persist_links_from_raw_rows( $post_id, $raw_rows, 'ajax' );

        wp_send_json_success( [
            'saved' => true,
            'count' => count( $saved_links ),
        ] );
    }

    /**
     * Save handler. Same trust contract as the legacy implementation:
     * nonce, capability, autosave guard, sanitize-and-validate per row,
     * normalised-URL dedup, single-primary enforcement, MAX_LINKS truncation.
     *
     * @since 5.1.0 Added family bucket-sort: rows are grouped by family in
     *              the fixed display order (cam → personal → fansite → tube
     *              → social → unmapped), preserving submission order within
     *              each family. The on-disk JSON shape is unchanged.
     */
    public static function save_metabox( int $post_id, \WP_Post $post ): void {
        if ( ! isset( $_POST['tmwseo_verified_links_nonce'] ) ) {
            Logs::info( 'verified_links', '[TMW-VL] save_metabox skipped: nonce missing (likely block-editor save without metabox payload)', [
                'post_id' => $post_id,
            ] );
            return;
        }
        if ( ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( (string) $_POST['tmwseo_verified_links_nonce'] ) ),
            self::NONCE_SAVE . $post_id
        ) ) {
            Logs::warn( 'verified_links', '[TMW-VL] save_metabox skipped: nonce verification failed', [
                'post_id' => $post_id,
            ] );
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            Logs::warn( 'verified_links', '[TMW-VL] save_metabox skipped: capability check failed', [
                'post_id' => $post_id,
            ] );
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw_rows = ( isset( $_POST['tmwseo_vl'] ) && is_array( $_POST['tmwseo_vl'] ) )
            ? wp_unslash( $_POST['tmwseo_vl'] )
            : [];

        if ( empty( $raw_rows ) ) {
            Logs::info( 'verified_links', '[TMW-VL] save_metabox received empty tmwseo_vl payload', [
                'post_id' => $post_id,
            ] );
        }

        self::persist_links_from_raw_rows( $post_id, $raw_rows, 'metabox' );
    }

    /**
     * Persist a raw links payload using the canonical Verified Links save rules.
     *
     * @param int                                 $post_id   Post ID.
     * @param array<int,array<string,mixed>>      $raw_rows  Raw rows from $_POST or AJAX JSON.
     * @param string                              $source    Save source label ('metabox'|'ajax').
     * @return array<int,array<string,mixed>>               Final links that were written.
     */
    private static function persist_links_from_raw_rows( int $post_id, array $raw_rows, string $source ): array {
        // Phase 1 — validate every row, retain submission order, drop invalid.
        $validated = [];
        foreach ( $raw_rows as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $entry = self::sanitize_and_validate_entry( (array) $row );
            if ( $entry === false ) { continue; }
            $validated[] = $entry;
        }

        // Phase 2 — bucket by family, preserving within-family submission order.
        $buckets = [];
        foreach ( VerifiedLinksFamilies::display_order() as $family_slug ) {
            $buckets[ $family_slug ] = [];
        }
        foreach ( $validated as $entry ) {
            $family = VerifiedLinksFamilies::family_for( (string) ( $entry['type'] ?? '' ) );
            $buckets[ $family ][] = $entry;
        }

        // Phase 3 — flatten in family display order, then apply dedup,
        // single-primary enforcement, and MAX_LINKS truncation.
        $links       = [];
        $seen_urls   = [];
        $has_primary = false;

        foreach ( VerifiedLinksFamilies::display_order() as $family_slug ) {
            foreach ( $buckets[ $family_slug ] as $entry ) {
                $norm = self::normalize_url_for_dedup( $entry['url'] );
                if ( isset( $seen_urls[ $norm ] ) ) {
                    Logs::info( 'verified_links', '[TMW-VL] Deduped duplicate URL on save', [
                        'post_id' => $post_id,
                        'url'     => $entry['url'],
                        'family'  => $family_slug,
                    ] );
                    continue;
                }
                $seen_urls[ $norm ] = true;

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
                    break 2;
                }
            }
        }

        update_post_meta( $post_id, self::META_KEY, wp_json_encode( $links ) );

        Logs::info( 'verified_links', '[TMW-VL] Saved verified external links', [
            'post_id'    => $post_id,
            'source'     => $source,
            'received'   => count( $raw_rows ),
            'validated'  => count( $validated ),
            'count'      => count( $links ),
            'per_family' => array_map( 'count', $buckets ),
        ] );

        return $links;
    }

    // ── handle_promote() — admin-post handler ─────────────────────────────

    /**
     * Handle explicit promote-from-research submissions.
     *
     * Validates nonce/capability checks, sanitizes selected URL/type rows,
     * and persists only operator-approved entries.
     */
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

        // Optional outbound target URLs — operator may provide a different URL
        // (e.g. personal site) to link to instead of the raw detected source.
        // When empty, falls back to the detected URL.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $outbound_urls = ( isset( $_POST['tmwseo_outbound_url'] ) && is_array( $_POST['tmwseo_outbound_url'] ) )
            ? array_map( 'esc_url_raw', array_map( 'wp_unslash', $_POST['tmwseo_outbound_url'] ) )
            : [];

        // Optional outbound target type hint (direct_profile|personal_site|website|social).
        // Stored as audit metadata; no functional routing effect in this phase.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $outbound_types = ( isset( $_POST['tmwseo_outbound_type'] ) && is_array( $_POST['tmwseo_outbound_type'] ) )
            ? array_map( 'sanitize_key', array_map( 'wp_unslash', $_POST['tmwseo_outbound_type'] ) )
            : [];

        $promoted = 0;
        $skipped  = 0;

        foreach ( $promote_urls as $idx => $source_url ) {
            $type = (string) ( $promote_types[ $idx ] ?? '' );

            if ( $type === '' || ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
                Logs::info( 'verified_links', '[TMW-VL] Skipped promote — no valid type', [
                    'post_id' => $post_id,
                    'url'     => $source_url,
                    'type'    => $type,
                ] );
                $skipped++;
                continue;
            }

            // Determine what URL to store as the outbound target.
            // If the operator provided a non-empty outbound URL, use it.
            // Otherwise fall back to the detected source URL.
            $outbound_url = trim( (string) ( $outbound_urls[ $idx ] ?? '' ) );
            if ( $outbound_url === '' ) {
                $outbound_url = $source_url;
            }
            $outbound_url = esc_url_raw( $outbound_url );

            $outbound_type = sanitize_key( (string) ( $outbound_types[ $idx ] ?? '' ) );
            $valid_outbound_types = [ 'direct_profile', 'personal_site', 'website', 'social' ];
            if ( ! in_array( $outbound_type, $valid_outbound_types, true ) ) {
                $outbound_type = 'direct_profile';
            }

            // Extra metadata stored alongside the entry for audit trail.
            // source_url only stored when it differs from outbound_url.
            $extra_meta = [];
            if ( $outbound_url !== $source_url && $source_url !== '' ) {
                $extra_meta['source_url']    = $source_url;
            }
            $extra_meta['outbound_type'] = $outbound_type;

            $added = self::add_link( $post_id, $outbound_url, $type, '', true, false, 'research', $extra_meta );
            if ( $added ) {
                $promoted++;
                Logs::info( 'verified_links', '[TMW-VL] Promoted link from research', [
                    'post_id'       => $post_id,
                    'source_url'    => $source_url,
                    'outbound_url'  => $outbound_url,
                    'outbound_type' => $outbound_type,
                    'type'          => $type,
                ] );
            } else {
                $skipped++;
                Logs::info( 'verified_links', '[TMW-VL] Promote skipped — invalid or duplicate', [
                    'post_id' => $post_id,
                    'url'     => $outbound_url,
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

    /**
     * Render an admin notice after promote flow redirects.
     */
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
        string $promoted_from = 'manual',
        array  $extra_meta    = []
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

        // Merge extra audit metadata (source_url, outbound_type).
        // These are stored as-is after light sanitisation — no validation
        // against ALLOWED_TYPES because they are informational only.
        if ( ! empty( $extra_meta ) ) {
            if ( isset( $extra_meta['source_url'] ) ) {
                $entry['source_url'] = esc_url_raw( (string) $extra_meta['source_url'] );
            }
            if ( isset( $extra_meta['outbound_type'] ) ) {
                $entry['outbound_type'] = sanitize_key( (string) $extra_meta['outbound_type'] );
            }
        }

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

    // ── get_routed_url() ──────────────────────────────────────────────────

    /**
     * Return the URL to render for a VL entry in frontend output (shortcode).
     *
     * Three-way routing decision:
     *   1. use_affiliate = false (or unset)
     *      → Return url (operator-approved outbound target). No affiliate involved.
     *
     *   2. use_affiliate = true, affiliate_network configured and template enabled
     *      → Build routed affiliate URL via AffiliateLinkBuilder::build_affiliate_url_for_target().
     *        Falls back to url on any failure so output is never broken.
     *
     *   3. use_affiliate = true but network misconfigured / template absent
     *      → Fall back to url silently (safe degradation).
     *
     * Schema sameAs NEVER uses this method — it always reads url directly.
     * That keeps identity URLs (sameAs) separate from monetized routing.
     *
     * @param  array<string,mixed> $link  One entry from get_links().
     * @return string                     URL safe for frontend output.
     */
    public static function get_routed_url( array $link ): string {
        $url = trim( (string) ( $link['url'] ?? '' ) );
        if ( $url === '' ) {
            return '';
        }

        $use_affiliate     = ! empty( $link['use_affiliate'] );
        $affiliate_network = sanitize_key( (string) ( $link['affiliate_network'] ?? '' ) );

        if ( ! $use_affiliate || $affiliate_network === '' ) {
            return $url;
        }

        if ( ! class_exists( '\TMWSEO\Engine\Platform\AffiliateLinkBuilder' ) ) {
            return $url;
        }

        $routed = \TMWSEO\Engine\Platform\AffiliateLinkBuilder::build_affiliate_url_for_target(
            $url,
            $affiliate_network
        );

        // build_affiliate_url_for_target returns $url on any failure, so we
        // always get a valid URL. Log when routing was attempted.
        if ( $routed !== $url ) {
            \TMWSEO\Engine\Logs::info( 'verified_links', '[TMW-VL] Affiliate routing applied', [
                'source_url'       => (string) ( $link['source_url'] ?? '' ),
                'outbound_url'     => $url,
                'routed_url'       => $routed,
                'affiliate_network'=> $affiliate_network,
            ] );
        }

        return $routed;
    }

    // ── get_schema_urls() ─────────────────────────────────────────────────

    /**
     * Return clean, validated URLs for active verified links.
     * Called by SchemaGenerator::person_schema() for sameAs.
     * Never reads research meta keys.
     *
     * Schema sameAs ALWAYS uses `url` (the operator-approved outbound target),
     * NEVER the affiliate-routed URL. sameAs identifies the model's identity
     * on a platform; affiliate routing is a commercial layer that must not
     * appear in structured-data identity claims.
     *
     * @return string[]
     */
    public static function get_schema_urls( int $post_id ): array {
        $links = self::get_links( $post_id );
        $urls  = [];
        foreach ( $links as $link ) {
            if ( empty( $link['is_active'] ) ) { continue; }
            // Intentionally reads 'url', not get_routed_url() — see doc above.
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
     * Frontend URL resolution:
     *   - Calls get_routed_url() per entry.
     *   - If affiliate routing is active for an entry, the rendered href is the
     *     affiliate URL, NOT the raw outbound target.
     *   - This is intentional: the shortcode is the monetized display layer.
     *   - Schema sameAs is NOT affected (it uses url directly).
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

            // Use routed URL (affiliate if configured; outbound target otherwise).
            $url = self::get_routed_url( $link );
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
        $pf_raw        = sanitize_text_field( (string) ( $raw['promoted_from'] ?? 'manual' ) );
        $promoted_from = in_array( $pf_raw, [ 'research', 'manual' ], true ) ? $pf_raw : 'manual';

        // source_url — optional audit trail; preserve across metabox save
        $source_url_raw = trim( (string) ( $raw['source_url'] ?? '' ) );
        $source_url     = $source_url_raw !== '' ? esc_url_raw( $source_url_raw ) : '';

        // outbound_type — informational, no validation against enum needed
        $valid_outbound_types = [ 'direct_profile', 'personal_site', 'website', 'social' ];
        $outbound_type_raw    = sanitize_key( (string) ( $raw['outbound_type'] ?? '' ) );
        $outbound_type        = in_array( $outbound_type_raw, $valid_outbound_types, true )
            ? $outbound_type_raw
            : '';

        // use_affiliate — bool; explicit '1' or true only (defensive)
        $raw_aff      = $raw['use_affiliate'] ?? '0';
        $use_affiliate = ( $raw_aff !== '' && $raw_aff !== '0' && $raw_aff !== false );

        // affiliate_network — sanitized key; empty string = no network configured
        $affiliate_network = sanitize_key( (string) ( $raw['affiliate_network'] ?? '' ) );

        $entry = [
            'url'               => $url,
            'type'              => $type,
            'label'             => $label,
            'is_active'         => $is_active,
            'is_primary'        => $is_primary,
            'added_at'          => $added_at,
            'promoted_from'     => $promoted_from,
        ];

        // Store optional audit/routing fields only when non-empty
        // to keep the JSON compact for entries that don't use them.
        if ( $source_url !== '' ) {
            $entry['source_url'] = $source_url;
        }
        if ( $outbound_type !== '' ) {
            $entry['outbound_type'] = $outbound_type;
        }
        if ( $use_affiliate ) {
            $entry['use_affiliate']     = true;
            $entry['affiliate_network'] = $affiliate_network;
        } elseif ( $affiliate_network !== '' ) {
            // Preserve network key even when routing disabled — operator may
            // re-enable without needing to re-enter the network key.
            $entry['affiliate_network'] = $affiliate_network;
        }

        return $entry;
    }

    // ── normalize_url_for_dedup() ─────────────────────────────────────────

    /**
     * Normalize a URL string for duplicate detection.
     */
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

    /**
     * Resolve a human-readable label for a verified-link type slug.
     */
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
            'fancentro.com'  => 'FanCentro',
            'streamate.com'  => 'Streamate',
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

    /**
     * Infer the most likely verified-link type for a URL.
     */
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
            'fancentro.com' => 'fancentro',
            'streamate.com' => 'streamate',
            'pornhub.com'   => 'pornhub',
        ];

        return $map[ $host ] ?? 'other';
    }
}
