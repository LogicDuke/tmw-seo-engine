<?php
/**
 * TMW_Category_Affiliate_CTA — optional, isolated affiliate URL + CTA for
 * WordPress category archive pages.
 *
 * Deliberately independent of the model/video platform+username affiliate
 * routing system (TMWSEO\Engine\Platform\AffiliateLinkBuilder). This feature
 * stores one raw, operator-entered destination URL per category term and
 * renders a single neutral CTA link on the live category archive — no
 * platform templates, no click tracking, no auto-generated URLs.
 *
 * @package TMWSEO\Engine\Categories
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class TMW_Category_Affiliate_CTA {

    /** Term meta key storing the raw affiliate destination URL. */
    public const META_KEY = 'tmw_category_affiliate_url';

    /** Nonce action/field names for the category edit screen save. */
    private const NONCE_ACTION = 'tmw_category_affiliate_url_save';
    private const NONCE_FIELD  = 'tmw_category_affiliate_url_nonce';

    /** CSS class used both for rendering and for duplicate-output detection. */
    private const CTA_MARKER_CLASS = 'tmw-category-page-affiliate-cta';

    public static function init(): void {
        add_action( 'category_add_form_fields', [ __CLASS__, 'render_add_field' ] );
        add_action( 'category_edit_form_fields', [ __CLASS__, 'render_edit_field' ] );

        add_action( 'created_category', [ __CLASS__, 'save_term_meta' ] );
        add_action( 'edited_category', [ __CLASS__, 'save_term_meta' ] );

        // NOTE (PR-720): this class intentionally does NOT hook
        // `get_the_archive_description` to append the CTA. That filter
        // fires before the theme's Read-more wrapper is built, so the
        // appended CTA rendered outside/above the expandable generated
        // text instead of inside it, at the very end. CTA placement is
        // now the child theme's responsibility: it calls
        // tmwseo_get_category_affiliate_cta_html() and appends the
        // returned HTML to the end of its own generated content string,
        // inside the same wrapper as the FAQ/closing paragraph. This
        // class only owns the term meta field, URL sanitization, and the
        // CTA HTML helpers below.
    }

    // ── Admin: category edit screen field ───────────────────────────────────

    public static function render_add_field( $taxonomy ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
        wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
        ?>
        <div class="form-field term-tmw-affiliate-url-wrap">
            <label for="tmw_category_affiliate_url_field"><?php esc_html_e( 'Affiliate URL (optional)', 'tmwseo' ); ?></label>
            <input type="url" name="tmw_category_affiliate_url" id="tmw_category_affiliate_url_field" value="" class="regular-text" />
            <p class="description"><?php esc_html_e( 'Optional destination URL used for this category page CTA. Leave empty to disable.', 'tmwseo' ); ?></p>
        </div>
        <?php
    }

    public static function render_edit_field( $term ): void {
        $value = get_term_meta( $term->term_id, self::META_KEY, true );
        $value = is_string( $value ) ? $value : '';

        wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
        ?>
        <tr class="form-field term-tmw-affiliate-url-wrap">
            <th scope="row"><label for="tmw_category_affiliate_url_field"><?php esc_html_e( 'Affiliate URL (optional)', 'tmwseo' ); ?></label></th>
            <td>
                <input type="url" name="tmw_category_affiliate_url" id="tmw_category_affiliate_url_field" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
                <p class="description"><?php esc_html_e( 'Optional destination URL used for this category page CTA. Leave empty to disable.', 'tmwseo' ); ?></p>
            </td>
        </tr>
        <?php
    }

    // ── Admin: save handling ─────────────────────────────────────────────────

    public static function save_term_meta( $term_id ): void {
        if ( ! is_admin() ) {
            return;
        }

        if ( ! isset( $_POST[ self::NONCE_FIELD ] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
        ) {
            return;
        }

        if ( ! current_user_can( 'manage_categories' ) ) {
            return;
        }

        if ( ! isset( $_POST['tmw_category_affiliate_url'] ) ) {
            return;
        }

        $raw = trim( wp_unslash( (string) $_POST['tmw_category_affiliate_url'] ) );

        if ( $raw === '' ) {
            $existing = get_term_meta( $term_id, self::META_KEY, true );
            if ( $existing !== '' && $existing !== false ) {
                delete_term_meta( $term_id, self::META_KEY );

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[TMW-CAT-AFF] deleted affiliate URL term_id=' . $term_id );
                }
            }
            return;
        }

        $sanitized = self::sanitize_absolute_affiliate_url( $raw );

        if ( $sanitized === '' ) {
            // Invalid/disallowed scheme: never silently overwrite an
            // existing good value with garbage from a bad resubmission.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[TMW-CAT-AFF] skipped invalid affiliate URL term_id=' . $term_id );
            }
            return;
        }

        update_term_meta( $term_id, self::META_KEY, $sanitized );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[TMW-CAT-AFF] saved affiliate URL term_id=' . $term_id );
        }
    }

    // ── Public data helper ───────────────────────────────────────────────────

    public static function get_affiliate_url( \WP_Term $term ): string {
        $url = (string) get_term_meta( $term->term_id, self::META_KEY, true );
        $url = trim( $url );
        if ( $url === '' ) {
            return '';
        }

        return self::sanitize_absolute_affiliate_url( $url );
    }

    /**
     * Sanitizes an affiliate URL and requires an absolute HTTP(S) URL.
     *
     * @param string $url Raw URL value.
     * @return string Sanitized absolute HTTP(S) URL, or empty string when invalid.
     */
    private static function sanitize_absolute_affiliate_url( string $url ): string {
        $sanitized = esc_url_raw( $url, [ 'http', 'https' ] );

        if ( $sanitized === '' ) {
            return '';
        }

        $parts = wp_parse_url( $sanitized );

        if ( ! is_array( $parts ) ) {
            return '';
        }

        $scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
        $host   = isset( $parts['host'] ) ? trim( (string) $parts['host'] ) : '';

        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || $host === '' ) {
            return '';
        }

        return $sanitized;
    }

    // ── Frontend: CTA rendering ──────────────────────────────────────────────

    /**
     * Public: returns the CSS marker class used on the CTA wrapper, so
     * callers (e.g. the child theme) can run their own dedupe check
     * against the actual generated HTML string without hardcoding or
     * duplicating the class name.
     */
    public static function get_cta_marker_class(): string {
        return self::CTA_MARKER_CLASS;
    }

    /**
     * Public: returns the CTA HTML for a category term, or an empty
     * string when no affiliate URL is set. Intended to be called by the
     * child theme and appended to the end of its own generated
     * category-text/FAQ output, inside the same Read-more wrapper —
     * this class deliberately does not render or place the CTA itself.
     */
    public static function get_cta_html( \WP_Term $term ): string {
        return self::build_cta_html( $term );
    }

    private static function build_cta_html( \WP_Term $term ): string {
        $url = self::get_affiliate_url( $term );
        if ( $url === '' ) {
            return '';
        }

        $html = sprintf(
            '<div class="%s"><a href="%s" target="_blank" rel="sponsored nofollow noopener">%s</a></div>',
            esc_attr( self::CTA_MARKER_CLASS ),
            esc_url( $url ),
            esc_html__( 'Visit live category related models', 'tmwseo' )
        );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[TMW-CAT-AFF] rendered CTA term_id=' . $term->term_id );
        }

        return $html;
    }
}

if ( ! function_exists( 'tmwseo_get_category_affiliate_url' ) ) {
    /**
     * Stable public helper: returns the sanitized affiliate URL for a
     * category term, or an empty string when none is set.
     */
    function tmwseo_get_category_affiliate_url( \WP_Term $term ): string {
        return TMW_Category_Affiliate_CTA::get_affiliate_url( $term );
    }
}

if ( ! function_exists( 'tmwseo_get_category_affiliate_cta_html' ) ) {
    /**
     * Stable public helper: returns the CTA HTML markup for a category
     * term, or an empty string when no affiliate URL is set for that
     * term. Callers (e.g. the active child theme's category generated-
     * content output) are responsible for placement — append the
     * returned string to the end of the generated text block, inside
     * the same Read-more wrapper, after the FAQ/closing paragraph. This
     * plugin does not render or position the CTA itself.
     */
    function tmwseo_get_category_affiliate_cta_html( \WP_Term $term ): string {
        return TMW_Category_Affiliate_CTA::get_cta_html( $term );
    }
}
