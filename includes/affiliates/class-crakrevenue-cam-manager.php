<?php
namespace TMWSEO\Engine\Affiliates;

use TMWSEO\Engine\Platform\AffiliateLinkBuilder;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * CrakRevenue API sync + cam routing registry manager.
 */
class CrakRevenueCamManager {
    public const API_SETTINGS_OPTION = 'tmwseo_crakrevenue_api_settings';
    public const OFFERS_CACHE_OPTION = 'tmwseo_crakrevenue_offers_cache';
    public const PLATFORM_MAPPINGS_OPTION = 'tmwseo_crakrevenue_platform_mappings';

    /**
     * Return supported platform slugs.
     *
     * @return string[]
     */
    public static function supported_platform_slugs(): array {
        return [
            'jerkmate','myfreecams','sinparty','xtease','olecams','cam_smartlink','bongacams','cam4','camera_prive','camirada','cams_com','camsoda','chaturbate','delhi_sex_chat','flirt4free','imlive','livejasmin','oranum','revealme','royal_cams','royal_cams_gay','sakuralive','sexymeet_tv','stripchat','xcams','xlovecam','xlovegay','visitx','nananue_live','filf','beianrufsex','big7','dxlive','exposed_webcams_live_free_fun','nananue_cam','slutroulette','sweepsex','total_webcam',
        ];
    }

    /**
     * Return platform alias map.
     *
     * @return array<string,string[]>
     */
    public static function platform_alias_map(): array {
        return [
            'camsoda' => ['camsoda'],
            'livejasmin' => ['live jasmin', 'livejasmin'],
            'bongacams' => ['bongacams', 'bonga cams'],
            'cam4' => ['cam4'],
            'cams_com' => ['cams.com', 'cams com'],
            'royal_cams_gay' => ['royal cams gay'],
            'exposed_webcams_live_free_fun' => ['exposed webcams', 'live free fun'],
            'sexymeet_tv' => ['sexymeet.tv', 'sexymeet tv'],
            'visitx' => ['visit x', 'visitx'],
            'sinparty' => ['sinparty', 'sin party'],
            'myfreecams' => ['myfreecams', 'my free cams'],
            'stripchat' => ['stripchat'],
            'chaturbate' => ['chaturbate'],
            'jerkmate' => ['jerkmate'],
            'xtease' => ['xtease'],
            'olecams' => ['olecams'],
            'cam_smartlink' => ['cam smartlink', 'smartlink'],
            'camera_prive' => ['camera prive', 'cameraprive'],
            'camirada' => ['camirada'],
            'delhi_sex_chat' => ['delhi sex chat', 'dscgirls'],
            'flirt4free' => ['flirt4free'],
            'imlive' => ['imlive'],
            'oranum' => ['oranum'],
            'revealme' => ['revealme'],
            'royal_cams' => ['royal cams'],
            'sakuralive' => ['sakuralive'],
            'xcams' => ['xcams'],
            'xlovecam' => ['xlovecam'],
            'xlovegay' => ['xlovegay'],
            'nananue_live' => ['nananue live'],
            'filf' => ['filf'],
            'beianrufsex' => ['beianrufsex'],
            'big7' => ['big7'],
            'dxlive' => ['dxlive'],
            'nananue_cam' => ['nananue cam'],
            'slutroulette' => ['slutroulette'],
            'sweepsex' => ['sweepsex'],
            'total_webcam' => ['total webcam'],
        ];
    }

    /**
     * Sanitize API settings option.
     *
     * @param mixed $input Raw value.
     * @return array<string,mixed>
     */
    public static function sanitize_api_settings( $input ): array {
        $input = is_array( $input ) ? $input : [];
        return [
            'api_key' => sanitize_text_field( (string) ( $input['api_key'] ?? '' ) ),
            'last_sync_at' => sanitize_text_field( (string) ( $input['last_sync_at'] ?? '' ) ),
            'last_sync_status' => sanitize_text_field( (string) ( $input['last_sync_status'] ?? '' ) ),
            'last_sync_message' => sanitize_text_field( (string) ( $input['last_sync_message'] ?? '' ) ),
        ];
    }

    /**
     * Build CrakRevenue findAll URL.
     *
     * @param string $api_key API key.
     * @return string
     */
    public static function build_offers_request_url( string $api_key ): string {
        $fields = implode( ',', [
            'id','name','status','preview_url','require_approval','require_terms_and_conditions','show_custom_variables','allow_website_links','allow_direct_links','payout_type','default_payout','percent_payout','currency','description','is_expired','expiration_date','terms_and_conditions','use_target_rules','featured','has_goals_enabled','conversion_cap','monthly_conversion_cap','payout_cap','monthly_payout_cap','epc',
        ] );

        return add_query_arg( [
            'Target' => 'Affiliate_Offer',
            'Method' => 'findAll',
            'api_key' => $api_key,
            'fields' => $fields,
        ], 'http://gateway.crakrevenue.com/affiliate' );
    }

    /**
     * Sync offers from CrakRevenue API.
     *
     * @return array{ok:bool,message:string,offers:int,cam_offers:int}
     */
    public static function sync_offers(): array {
        $settings = get_option( self::API_SETTINGS_OPTION, [] );
        $settings = is_array( $settings ) ? $settings : [];
        $api_key = sanitize_text_field( (string) ( $settings['api_key'] ?? '' ) );
        if ( $api_key === '' ) {
            return self::mark_sync( false, 'Missing API key.', [] );
        }

        $response = wp_remote_get( self::build_offers_request_url( $api_key ), [ 'timeout' => 20 ] );
        if ( is_wp_error( $response ) ) {
            return self::mark_sync( false, 'HTTP failure: ' . $response->get_error_message(), [] );
        }

        $body = (string) wp_remote_retrieve_body( $response );
        if ( trim( $body ) === '' ) {
            return self::mark_sync( false, 'Empty response from API.', [] );
        }

        $offers = self::parse_offers_payload( $body );
        if ( empty( $offers ) ) {
            return self::mark_sync( false, 'No offers found in response.', [] );
        }

        $normalized = [];
        foreach ( $offers as $offer ) {
            $row = self::normalize_offer( $offer );
            if ( $row !== [] ) {
                $normalized[] = $row;
            }
        }

        update_option( self::OFFERS_CACHE_OPTION, [
            'imported_at' => gmdate( 'c' ),
            'offers' => $normalized,
        ] );

        return self::mark_sync( true, 'Offers synced.', $normalized );
    }

    /**
     * Parse offers payload.
     *
     * @param string $body Response body.
     * @return array<int,array<string,mixed>>
     */
    public static function parse_offers_payload( string $body ): array {
        $json = json_decode( $body, true );
        if ( is_array( $json ) ) {
            if ( isset( $json['response']['data'] ) && is_array( $json['response']['data'] ) ) {
                return array_values( $json['response']['data'] );
            }
            if ( isset( $json['data'] ) && is_array( $json['data'] ) ) {
                return array_values( $json['data'] );
            }
            if ( isset( $json[0] ) ) {
                return $json;
            }
        }

        if ( str_contains( ltrim( $body ), '<' ) && function_exists( 'simplexml_load_string' ) ) {
            $xml = simplexml_load_string( $body );
            if ( $xml instanceof \SimpleXMLElement ) {
                $encoded = json_encode( $xml );
                $arr = is_string( $encoded ) ? json_decode( $encoded, true ) : [];
                if ( is_array( $arr ) && isset( $arr['data']['offer'] ) ) {
                    $offers = $arr['data']['offer'];
                    return isset( $offers[0] ) ? $offers : [ $offers ];
                }
            }
        }

        return [];
    }

    /**
     * Normalize one offer row.
     *
     * @param array<string,mixed> $offer Raw offer.
     * @return array<string,mixed>
     */
    public static function normalize_offer( array $offer ): array {
        $offer_id = (int) ( $offer['id'] ?? 0 );
        $name = sanitize_text_field( (string) ( $offer['name'] ?? '' ) );
        if ( $offer_id <= 0 || $name === '' ) {
            return [];
        }

        $platform_slug = self::detect_platform_slug( $name );
        if ( $platform_slug === '' ) {
            return [];
        }

        return [
            'offer_id' => $offer_id,
            'offer_name' => $name,
            'platform_slug' => $platform_slug,
            'platform_label' => ucwords( str_replace( '_', ' ', $platform_slug ) ),
            'status' => sanitize_text_field( (string) ( $offer['status'] ?? '' ) ),
            'require_approval' => ! empty( $offer['require_approval'] ),
            'is_expired' => ! empty( $offer['is_expired'] ),
            'expiration_date' => sanitize_text_field( (string) ( $offer['expiration_date'] ?? '' ) ),
            'preview_url' => esc_url_raw( (string) ( $offer['preview_url'] ?? '' ) ),
            'allow_website_links' => ! empty( $offer['allow_website_links'] ),
            'allow_direct_links' => ! empty( $offer['allow_direct_links'] ),
            'show_custom_variables' => ! empty( $offer['show_custom_variables'] ),
            'payout_type' => sanitize_text_field( (string) ( $offer['payout_type'] ?? '' ) ),
            'default_payout' => (float) ( $offer['default_payout'] ?? 0 ),
            'percent_payout' => (float) ( $offer['percent_payout'] ?? 0 ),
            'currency' => sanitize_text_field( (string) ( $offer['currency'] ?? '' ) ),
            'description' => sanitize_text_field( (string) ( $offer['description'] ?? '' ) ),
            'epc' => isset( $offer['epc'] ) ? (float) $offer['epc'] : 0.0,
            'approval_status' => self::approval_status( $offer ),
            'imported_at' => gmdate( 'c' ),
            'raw' => $offer,
        ];
    }

    /**
     * Resolve approval status.
     *
     * @param array<string,mixed> $offer Offer row.
     * @return string
     */
    public static function approval_status( array $offer ): string {
        $status = strtolower( trim( (string) ( $offer['status'] ?? '' ) ) );
        if ( str_contains( $status, 'approv' ) ) {
            return 'approved';
        }
        return ! empty( $offer['require_approval'] ) ? 'needs_approval' : ( $status !== '' ? $status : 'unknown' );
    }

    /**
     * Detect platform slug from offer name.
     *
     * @param string $offer_name Offer name.
     * @return string
     */
    public static function detect_platform_slug( string $offer_name ): string {
        $needle = strtolower( preg_replace( '/\s+/', ' ', str_replace( [ '-', '/', '.' ], ' ', $offer_name ) ) ?? '' );
        foreach ( self::platform_alias_map() as $slug => $aliases ) {
            foreach ( $aliases as $alias ) {
                if ( str_contains( $needle, strtolower( $alias ) ) ) {
                    return $slug;
                }
            }
        }
        return '';
    }

    /**
     * Return cached offers.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_cached_offers(): array {
        $cache = get_option( self::OFFERS_CACHE_OPTION, [] );
        $offers = is_array( $cache ) ? ( $cache['offers'] ?? [] ) : [];
        return is_array( $offers ) ? $offers : [];
    }

    /**
     * Auto map best approved offers.
     *
     * @param bool $reset_manual Reset manual selections.
     * @return void
     */
    public static function auto_map_best_offers( bool $reset_manual = false ): void {
        $offers = self::get_cached_offers();
        $current = get_option( self::PLATFORM_MAPPINGS_OPTION, [] );
        $current = is_array( $current ) ? $current : [];
        $grouped = [];
        foreach ( $offers as $offer ) {
            $slug = sanitize_key( (string) ( $offer['platform_slug'] ?? '' ) );
            if ( $slug === '' ) { continue; }
            $grouped[ $slug ][] = $offer;
        }

        foreach ( $grouped as $slug => $rows ) {
            if ( ! $reset_manual && ! empty( $current[ $slug ]['selected_offer_id'] ) ) {
                continue;
            }
            usort( $rows, [ __CLASS__, 'compare_offer_rank' ] );
            $pick = $rows[0] ?? [];
            if ( empty( $pick ) ) { continue; }
            $current[ $slug ] = array_merge( self::default_mapping_row( $slug ), [
                'selected_offer_id' => (int) ( $pick['offer_id'] ?? 0 ),
                'selected_offer_name' => (string) ( $pick['offer_name'] ?? '' ),
                'selected_preview_url' => (string) ( $pick['preview_url'] ?? '' ),
                'approval_status' => (string) ( $pick['approval_status'] ?? 'unknown' ),
                'enabled' => 0,
                'template_url' => (string) ( $pick['preview_url'] ?? '' ),
                'last_updated' => gmdate( 'c' ),
            ] );
        }

        update_option( self::PLATFORM_MAPPINGS_OPTION, $current );
    }

    /**
     * Compare offers by priority.
     *
     * @param array<string,mixed> $a Offer A.
     * @param array<string,mixed> $b Offer B.
     * @return int
     */
    public static function compare_offer_rank( array $a, array $b ): int {
        $score_a = self::offer_score( $a );
        $score_b = self::offer_score( $b );
        return $score_b <=> $score_a;
    }

    /**
     * Compute offer score.
     *
     * @param array<string,mixed> $offer Offer row.
     * @return float
     */
    public static function offer_score( array $offer ): float {
        if ( ! empty( $offer['is_expired'] ) ) {
            return -10000;
        }
        $score = 0.0;
        $status = (string) ( $offer['approval_status'] ?? '' );
        if ( $status === 'approved' ) { $score += 1000; }
        $name = strtolower( (string) ( $offer['offer_name'] ?? '' ) );
        if ( str_contains( $name, 'revshare lifetime' ) ) { $score += 500; }
        elseif ( str_contains( $name, 'revshare' ) ) { $score += 400; }
        elseif ( str_contains( $name, 'pps' ) ) { $score += 300; }
        elseif ( str_contains( $name, 'multi-cpa' ) || str_contains( $name, 'multi cpa' ) ) { $score += 200; }
        elseif ( str_contains( $name, 'doi' ) || str_contains( $name, 'soi' ) ) { $score += 100; }
        if ( (string) ( $offer['platform_slug'] ?? '' ) === 'cam_smartlink' ) { $score -= 250; }
        $score += (float) ( $offer['epc'] ?? 0 ) * 10;
        $score += (float) ( $offer['default_payout'] ?? 0 );
        return $score;
    }

    /**
     * Return default mapping row.
     *
     * @param string $slug Platform slug.
     * @return array<string,mixed>
     */
    public static function default_mapping_row( string $slug ): array {
        return [
            'platform_slug' => $slug,
            'enabled' => 0,
            'selected_offer_id' => 0,
            'selected_offer_name' => '',
            'selected_preview_url' => '',
            'approval_status' => 'unknown',
            'manually_approved' => 0,
            'template_url' => '',
            'campaign' => '',
            'source' => '',
            'subaffid' => '',
            'last_updated' => gmdate( 'c' ),
        ];
    }

    /**
     * Validate template safety.
     *
     * @param string $template Template URL.
     * @return array{safe:bool,warnings:string[]}
     */
    public static function validate_template( string $template ): array {
        $warnings = [];
        parse_str( (string) wp_parse_url( $template, PHP_URL_QUERY ), $query );
        foreach ( [ 'performerName', 'model', 'username', 'name', 'user', 'screenName', 'profile' ] as $key ) {
            if ( ! isset( $query[ $key ] ) ) { continue; }
            $value = (string) $query[ $key ];
            if ( $value !== '' && ! str_contains( $value, '{username}' ) && ! str_contains( $value, '{encoded_profile_url}' ) ) {
                $warnings[] = 'Hardcoded model name detected for ' . $key . '. Use {username} instead.';
            }
        }
        return [ 'safe' => empty( $warnings ), 'warnings' => $warnings ];
    }

    /**
     * Build routed URL for verified link using global CrakRevenue mapping.
     *
     * @param array<string,mixed> $link Verified link.
     * @return string
     */
    public static function maybe_route_verified_link( array $link ): string {
        $url = esc_url_raw( (string) ( $link['url'] ?? '' ) );
        $type = sanitize_key( (string) ( $link['type'] ?? '' ) );
        if ( $url === '' || $type === '' ) {
            return $url;
        }

        $mappings = get_option( self::PLATFORM_MAPPINGS_OPTION, [] );
        $mappings = is_array( $mappings ) ? $mappings : [];
        $map = is_array( $mappings[ $type ] ?? null ) ? $mappings[ $type ] : [];
        if ( empty( $map['enabled'] ) || empty( $map['selected_offer_id'] ) ) {
            return $url;
        }
        $approved = ( (string) ( $map['approval_status'] ?? '' ) === 'approved' ) || ! empty( $map['manually_approved'] );
        if ( ! $approved ) {
            return $url;
        }
        $template = (string) ( $map['template_url'] ?? '' );
        if ( $template === '' ) {
            return $url;
        }
        $validation = self::validate_template( $template );
        if ( ! $validation['safe'] ) {
            return $url;
        }

        $username = self::extract_username_from_profile_url( $url, $type );
        if ( $username === '' && str_contains( $template, '{username}' ) ) {
            return $url;
        }

        $built = AffiliateLinkBuilder::build_from_template_for_tests(
            $template,
            $type,
            $username,
            $url,
            [
                'campaign' => (string) ( $map['campaign'] ?? '' ),
                'source' => (string) ( $map['source'] ?? '' ),
                'subaffid' => (string) ( $map['subaffid'] ?? '' ),
            ]
        );

        return wp_http_validate_url( $built ) ? $built : $url;
    }

    /**
     * Extract username by platform URL pattern.
     *
     * @param string $url Profile URL.
     * @param string $platform Platform slug.
     * @return string
     */
    public static function extract_username_from_profile_url( string $url, string $platform ): string {
        $path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
        if ( $path === '' ) {
            return '';
        }
        $parts = explode( '/', $path );
        $candidate = (string) end( $parts );
        if ( $platform === 'chaturbate' && str_ends_with( $url, '/' ) ) {
            $candidate = (string) ( $parts[ count( $parts ) - 1 ] ?? '' );
        }
        return sanitize_key( preg_replace( '/[^A-Za-z0-9._-]/', '', $candidate ) ?? '' );
    }

    /**
     * Render CrakRevenue admin section.
     *
     * @return void
     */
    public static function render_admin_section(): void {
        $settings = get_option( self::API_SETTINGS_OPTION, [] );
        $settings = is_array( $settings ) ? $settings : [];
        $offers = self::get_cached_offers();
        $mappings = get_option( self::PLATFORM_MAPPINGS_OPTION, [] );
        $mappings = is_array( $mappings ) ? $mappings : [];
        $approved = 0;
        $needs_approval = 0;
        foreach ( $offers as $offer ) {
            if ( (string) ( $offer['approval_status'] ?? '' ) === 'approved' ) { $approved++; }
            if ( (string) ( $offer['approval_status'] ?? '' ) === 'needs_approval' ) { $needs_approval++; }
        }

        echo '<h2>CrakRevenue Cam Offers</h2>';
        echo '<p class="description">Sync cam offers via API, auto-map best offers, then enable by platform.</p>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tmwseo_cr_sync' );
        echo '<input type="hidden" name="action" value="tmwseo_cr_save_and_sync" />';
        echo '<table class="form-table">';
        echo '<tr><th>API key</th><td><input type="password" name="api_key" class="regular-text" value="' . esc_attr( (string) ( $settings['api_key'] ?? '' ) ) . '" /></td></tr>';
        echo '<tr><th>Last sync</th><td>' . esc_html( (string) ( $settings['last_sync_at'] ?? 'Never' ) ) . ' — ' . esc_html( (string) ( $settings['last_sync_status'] ?? 'n/a' ) ) . '</td></tr>';
        echo '<tr><th>Stats</th><td>Imported: ' . (int) count( $offers ) . ' | Approved: ' . (int) $approved . ' | Needs approval: ' . (int) $needs_approval . '</td></tr>';
        echo '</table>';
        submit_button( 'Save API key + Sync CrakRevenue Offers', 'primary', 'submit', false );
        echo '</form>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px;display:flex;gap:8px;">';
        wp_nonce_field( 'tmwseo_cr_quick_actions' );
        echo '<input type="hidden" name="action" value="tmwseo_cr_quick_action" />';
        echo '<button class="button" name="quick_action" value="auto_map">Auto-map best approved offers</button>';
        echo '<button class="button" name="quick_action" value="enable_defaults">Enable all approved cam platform defaults</button>';
        echo '<button class="button" name="quick_action" value="disable_all">Disable all CrakRevenue cam routing</button>';
        echo '</form>';

        echo '<h3 style="margin-top:16px;">Platform summary</h3>';
        echo '<table class="widefat striped"><thead><tr><th>Platform</th><th>Best approved offer</th><th>Selected offer</th><th>Approval</th><th>Payout</th><th>EPC</th><th>Routing</th><th>Template safety</th></tr></thead><tbody>';
        foreach ( self::supported_platform_slugs() as $slug ) {
            $rows = array_values( array_filter( $offers, static fn( $row ) => (string) ( $row['platform_slug'] ?? '' ) === $slug ) );
            usort( $rows, [ __CLASS__, 'compare_offer_rank' ] );
            $best = $rows[0] ?? [];
            $map = is_array( $mappings[ $slug ] ?? null ) ? $mappings[ $slug ] : self::default_mapping_row( $slug );
            $template_check = self::validate_template( (string) ( $map['template_url'] ?? '' ) );
            echo '<tr>';
            echo '<td><strong>' . esc_html( $slug ) . '</strong></td>';
            echo '<td>' . esc_html( (string) ( $best['offer_name'] ?? '—' ) ) . '</td>';
            echo '<td>' . esc_html( (string) ( $map['selected_offer_name'] ?? '—' ) ) . '</td>';
            echo '<td>' . esc_html( (string) ( $map['approval_status'] ?? 'unknown' ) ) . '</td>';
            echo '<td>' . esc_html( (string) ( $best['default_payout'] ?? '' ) ) . ' ' . esc_html( (string) ( $best['currency'] ?? '' ) ) . '</td>';
            echo '<td>' . esc_html( (string) ( $best['epc'] ?? '' ) ) . '</td>';
            echo '<td>' . ( ! empty( $map['enabled'] ) ? 'Enabled' : 'Disabled' ) . '</td>';
            echo '<td>' . ( $template_check['safe'] ? 'Safe to route' : 'Not safe to route' ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<h3 style="margin-top:16px;">Platform offer details</h3>';
        foreach ( self::supported_platform_slugs() as $slug ) {
            $rows = array_values( array_filter( $offers, static fn( $row ) => (string) ( $row['platform_slug'] ?? '' ) === $slug ) );
            if ( empty( $rows ) ) {
                continue;
            }
            usort( $rows, static function( array $a, array $b ): int {
                $a_status = (string) ( $a['approval_status'] ?? '' );
                $b_status = (string) ( $b['approval_status'] ?? '' );
                if ( $a_status === $b_status ) {
                    return 0;
                }
                return $a_status === 'approved' ? -1 : 1;
            } );
            echo '<details style="margin-bottom:8px;"><summary><strong>' . esc_html( $slug ) . '</strong> (' . count( $rows ) . ' offers)</summary>';
            echo '<table class="widefat striped" style="margin-top:8px;"><thead><tr><th>Offer</th><th>Status</th><th>Payout</th><th>EPC</th><th>Preview</th><th>Direct Links</th><th>Website Links</th></tr></thead><tbody>';
            foreach ( $rows as $row ) {
                $status = (string) ( $row['approval_status'] ?? 'unknown' );
                if ( ! empty( $row['is_expired'] ) ) {
                    $status .= ' (expired)';
                }
                echo '<tr>';
                echo '<td>' . esc_html( (string) ( $row['offer_name'] ?? '' ) ) . '</td>';
                echo '<td>' . esc_html( $status ) . '</td>';
                echo '<td>' . esc_html( (string) ( $row['default_payout'] ?? '' ) ) . ' ' . esc_html( (string) ( $row['currency'] ?? '' ) ) . '</td>';
                echo '<td>' . esc_html( (string) ( $row['epc'] ?? '' ) ) . '</td>';
                $preview_url = esc_url( (string) ( $row['preview_url'] ?? '' ) );
                echo '<td>' . ( $preview_url !== '' ? '<a href="' . $preview_url . '" target="_blank" rel="noopener">Preview URL</a>' : 'N/A' ) . '</td>';
                echo '<td>' . ( ! empty( $row['allow_direct_links'] ) ? 'Yes' : 'No' ) . '</td>';
                echo '<td>' . ( ! empty( $row['allow_website_links'] ) ? 'Yes' : 'No' ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</details>';
        }
    }

    /**
     * Handle save+sync request.
     *
     * @return void
     */
    public static function handle_save_and_sync(): void {
        check_admin_referer( 'tmwseo_cr_sync' );
        $settings = get_option( self::API_SETTINGS_OPTION, [] );
        $settings = is_array( $settings ) ? $settings : [];
        $settings['api_key'] = sanitize_text_field( (string) ( $_POST['api_key'] ?? '' ) );
        update_option( self::API_SETTINGS_OPTION, self::sanitize_api_settings( $settings ) );
        self::sync_offers();
        wp_safe_redirect( admin_url( 'admin.php?page=tmwseo-affiliates' ) );
        exit;
    }

    /**
     * Handle quick actions.
     *
     * @return void
     */
    public static function handle_quick_action(): void {
        check_admin_referer( 'tmwseo_cr_quick_actions' );
        $action = sanitize_key( (string) ( $_POST['quick_action'] ?? '' ) );
        $mappings = get_option( self::PLATFORM_MAPPINGS_OPTION, [] );
        $mappings = is_array( $mappings ) ? $mappings : [];
        if ( $action === 'auto_map' ) {
            self::auto_map_best_offers( false );
        } elseif ( $action === 'enable_defaults' ) {
            foreach ( $mappings as $slug => $map ) {
                if ( (string) ( $map['approval_status'] ?? '' ) === 'approved' ) {
                    $mappings[ $slug ]['enabled'] = 1;
                }
            }
            update_option( self::PLATFORM_MAPPINGS_OPTION, $mappings );
        } elseif ( $action === 'disable_all' ) {
            foreach ( $mappings as $slug => $map ) {
                $mappings[ $slug ]['enabled'] = 0;
            }
            update_option( self::PLATFORM_MAPPINGS_OPTION, $mappings );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=tmwseo-affiliates' ) );
        exit;
    }

    /**
     * Persist sync metadata.
     *
     * @param bool $ok Success flag.
     * @param string $message Status message.
     * @param array<int,array<string,mixed>> $offers Offer rows.
     * @return array{ok:bool,message:string,offers:int,cam_offers:int}
     */
    private static function mark_sync( bool $ok, string $message, array $offers ): array {
        $settings = get_option( self::API_SETTINGS_OPTION, [] );
        $settings = is_array( $settings ) ? $settings : [];
        $settings['last_sync_at'] = gmdate( 'c' );
        $settings['last_sync_status'] = $ok ? 'success' : 'error';
        $settings['last_sync_message'] = $message;
        update_option( self::API_SETTINGS_OPTION, self::sanitize_api_settings( $settings ) );
        return [
            'ok' => $ok,
            'message' => $message,
            'offers' => count( $offers ),
            'cam_offers' => count( $offers ),
        ];
    }
}
