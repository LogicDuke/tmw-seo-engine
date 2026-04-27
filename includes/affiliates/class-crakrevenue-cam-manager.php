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
    public const DEBUG_PREVIEW_LIMIT = 300;

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
            'last_sync_notice' => sanitize_key( (string) ( $input['last_sync_notice'] ?? '' ) ),
            'last_sync_http_status' => (int) ( $input['last_sync_http_status'] ?? 0 ),
            'last_sync_content_type' => sanitize_text_field( (string) ( $input['last_sync_content_type'] ?? '' ) ),
            'last_sync_endpoint' => esc_url_raw( (string) ( $input['last_sync_endpoint'] ?? '' ) ),
            'last_sync_body_preview' => sanitize_textarea_field( (string) ( $input['last_sync_body_preview'] ?? '' ) ),
            'last_sync_raw_offer_count' => (int) ( $input['last_sync_raw_offer_count'] ?? 0 ),
            'last_sync_cam_offer_count' => (int) ( $input['last_sync_cam_offer_count'] ?? 0 ),
            'last_sync_payload_shape' => sanitize_text_field( (string) ( $input['last_sync_payload_shape'] ?? '' ) ),
            'last_sync_offer_name_samples' => array_values( array_map( 'sanitize_text_field', is_array( $input['last_sync_offer_name_samples'] ?? null ) ? $input['last_sync_offer_name_samples'] : [] ) ),
        ];
    }

    /**
     * Build CrakRevenue findAll URL.
     *
     * @param string $api_key API key.
     * @return string
     */
    public static function build_offers_request_url( string $api_key, string $endpoint = 'https://gateway.crakrevenue.com/affiliate' ): string {
        $pairs = [
            'Target=' . rawurlencode( 'Affiliate_Offer' ),
            'Method=' . rawurlencode( 'findAll' ),
            'api_key=' . rawurlencode( $api_key ),
        ];

        foreach ( self::offers_request_fields() as $field ) {
            $pairs[] = 'fields[]=' . rawurlencode( $field );
        }

        return rtrim( $endpoint, '?' ) . '?' . implode( '&', $pairs );
    }

    /**
     * Return requested CrakRevenue fields.
     *
     * @return string[]
     */
    public static function offers_request_fields(): array {
        return [
            'id','name','status','preview_url','require_approval','require_terms_and_conditions','show_custom_variables','allow_website_links','allow_direct_links','payout_type','default_payout','percent_payout','currency','description','is_expired','expiration_date','terms_and_conditions','use_target_rules','featured','has_goals_enabled','conversion_cap','monthly_conversion_cap','payout_cap','monthly_payout_cap','epc',
        ];
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
            return self::mark_sync( false, 'Missing API key.', [], [ 'notice' => 'error' ] );
        }

        $response_body = '';
        $response_code = 0;
        $response_type = '';
        $endpoint_used = '';
        $http_error = '';
        foreach ( self::endpoint_candidates() as $endpoint ) {
            $endpoint_used = $endpoint;
            $response = wp_remote_get( self::build_offers_request_url( $api_key, $endpoint ), [ 'timeout' => 20 ] );
            if ( is_wp_error( $response ) ) {
                $http_error = $response->get_error_message();
                continue;
            }
            $response_code = (int) wp_remote_retrieve_response_code( $response );
            $response_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
            $response_body = (string) wp_remote_retrieve_body( $response );
            if ( $response_code === 200 && trim( $response_body ) !== '' ) {
                break;
            }
            if ( strpos( $endpoint, 'https://' ) !== 0 ) {
                break;
            }
        }

        $diagnostics = [
            'endpoint_used' => $endpoint_used,
            'http_status' => $response_code,
            'content_type' => $response_type,
            'body_preview' => self::sanitize_body_preview( $response_body, $api_key ),
            'raw_offer_count' => 0,
            'cam_offer_count' => 0,
            'offer_name_samples' => [],
        ];

        if ( $http_error !== '' && $response_code === 0 ) {
            return self::mark_sync( false, 'HTTP failure: ' . $http_error, [], $diagnostics + [ 'notice' => 'error' ] );
        }
        if ( $response_code !== 200 ) {
            return self::mark_sync( false, 'Non-200 HTTP status code: ' . $response_code, [], $diagnostics + [ 'notice' => 'error' ] );
        }
        if ( trim( $response_body ) === '' ) {
            return self::mark_sync( false, 'Empty response from API.', [], $diagnostics + [ 'notice' => 'error' ] );
        }

        $parsed = self::parse_offers_payload_with_meta( $response_body );
        $offers = $parsed['offers'];
        $diagnostics['raw_offer_count'] = count( $offers );
        if ( ! empty( $parsed['shape'] ) ) {
            $diagnostics['payload_shape'] = (string) $parsed['shape'];
        }
        if ( empty( $offers ) ) {
            $msg = ! empty( $parsed['malformed'] ) ? 'Malformed response.' : 'API returned no offers.';
            return self::mark_sync( false, $msg, [], $diagnostics + [ 'notice' => 'error' ] );
        }

        $normalized = [];
        $raw_names = [];
        foreach ( $offers as $offer ) {
            $raw_names[] = sanitize_text_field( (string) ( $offer['name'] ?? '' ) );
            $row = self::normalize_offer( $offer );
            if ( $row !== [] ) {
                $normalized[] = $row;
            }
        }
        $diagnostics['cam_offer_count'] = count( $normalized );
        $diagnostics['offer_name_samples'] = array_values( array_slice( array_filter( $raw_names ), 0, 10 ) );

        if ( $normalized === [] ) {
            return self::mark_sync(
                false,
                sprintf( 'API returned %d offers, but no cam offers matched the platform detector.', (int) count( $offers ) ),
                [],
                $diagnostics + [ 'notice' => 'warning' ]
            );
        }

        update_option( self::OFFERS_CACHE_OPTION, [
            'imported_at' => gmdate( 'c' ),
            'offers' => $normalized,
        ] );

        return self::mark_sync( true, 'Offers imported successfully.', $normalized, $diagnostics + [ 'notice' => 'success' ] );
    }

    /**
     * Parse offers payload.
     *
     * @param string $body Response body.
     * @return array<int,array<string,mixed>>
     */
    public static function parse_offers_payload( string $body ): array {
        $result = self::parse_offers_payload_with_meta( $body );
        return $result['offers'];
    }

    /**
     * Parse offers payload and diagnostics metadata.
     *
     * @param string $body Response body.
     * @return array{offers:array<int,array<string,mixed>>,shape:string,malformed:bool}
     */
    public static function parse_offers_payload_with_meta( string $body ): array {
        $json = json_decode( $body, true );
        if ( is_array( $json ) ) {
            if ( isset( $json['response']['data'] ) && is_array( $json['response']['data'] ) ) {
                return [ 'offers' => array_values( $json['response']['data'] ), 'shape' => 'response.data', 'malformed' => false ];
            }
            if ( isset( $json['data'] ) && is_array( $json['data'] ) ) {
                return [ 'offers' => array_values( $json['data'] ), 'shape' => 'data', 'malformed' => false ];
            }
            if ( isset( $json[0] ) ) {
                return [ 'offers' => $json, 'shape' => 'top-level-array', 'malformed' => false ];
            }
            if ( isset( $json['offers'] ) && is_array( $json['offers'] ) ) {
                return [ 'offers' => array_values( $json['offers'] ), 'shape' => 'offers', 'malformed' => false ];
            }
            if ( isset( $json['Affiliate_Offer'] ) && is_array( $json['Affiliate_Offer'] ) ) {
                return [ 'offers' => array_values( $json['Affiliate_Offer'] ), 'shape' => 'Affiliate_Offer', 'malformed' => false ];
            }
            if ( isset( $json['response'] ) && is_array( $json['response'] ) ) {
                $nested = self::flatten_array_offers( $json['response'] );
                if ( $nested !== [] ) {
                    return [ 'offers' => $nested, 'shape' => 'response', 'malformed' => false ];
                }
            }
        }

        if ( str_contains( ltrim( $body ), '<' ) && function_exists( 'simplexml_load_string' ) ) {
            $xml = simplexml_load_string( $body );
            if ( $xml instanceof \SimpleXMLElement ) {
                $encoded = json_encode( $xml );
                $arr = is_string( $encoded ) ? json_decode( $encoded, true ) : [];
                if ( is_array( $arr ) && isset( $arr['data']['offer'] ) ) {
                    $offers = $arr['data']['offer'];
                    return [ 'offers' => isset( $offers[0] ) ? $offers : [ $offers ], 'shape' => 'xml.data.offer', 'malformed' => false ];
                }
                if ( is_array( $arr ) && isset( $arr['response'] ) ) {
                    $nested = self::flatten_array_offers( is_array( $arr['response'] ) ? $arr['response'] : [] );
                    if ( $nested !== [] ) {
                        return [ 'offers' => $nested, 'shape' => 'xml.response', 'malformed' => false ];
                    }
                }
            }
        }

        return [ 'offers' => [], 'shape' => 'unknown', 'malformed' => true ];
    }

    /**
     * Flatten likely nested offer structures.
     *
     * @param array<string,mixed> $payload Payload.
     * @return array<int,array<string,mixed>>
     */
    public static function flatten_array_offers( array $payload ): array {
        foreach ( [ 'offers', 'offer', 'Affiliate_Offer', 'data' ] as $key ) {
            $candidate = $payload[ $key ] ?? null;
            if ( is_array( $candidate ) && isset( $candidate[0] ) && is_array( $candidate[0] ) ) {
                return $candidate;
            }
            if ( is_array( $candidate ) && isset( $candidate['id'] ) ) {
                return [ $candidate ];
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
            'tracking_template' => self::resolve_tracking_template( $offer ),
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
     * Resolve best-guess tracking template from API payload.
     *
     * @param array<string,mixed> $offer Raw offer payload.
     * @return string
     */
    public static function resolve_tracking_template( array $offer ): string {
        foreach ( [ 'tracking_template', 'tracking_url', 'tracking_link', 'url_template', 'offer_url' ] as $key ) {
            $value = esc_url_raw( (string) ( $offer[ $key ] ?? '' ) );
            if ( $value !== '' ) {
                return $value;
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
                'template_url' => (string) ( $pick['tracking_template'] ?? '' ),
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
        if ( trim( $template ) === '' ) {
            return [
                'safe' => false,
                'warnings' => [ 'Tracking template missing — click to add template.' ],
            ];
        }
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
        self::render_last_sync_notice( $settings );

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tmwseo_cr_sync' );
        echo '<input type="hidden" name="action" value="tmwseo_cr_save_and_sync" />';
        echo '<table class="form-table">';
        echo '<tr><th>API key</th><td><input type="password" name="api_key" class="regular-text" value="' . esc_attr( (string) ( $settings['api_key'] ?? '' ) ) . '" /></td></tr>';
        echo '<tr><th>Last sync status</th><td>' . esc_html( (string) ( $settings['last_sync_status'] ?? 'n/a' ) ) . '</td></tr>';
        echo '<tr><th>Last sync message</th><td>' . esc_html( (string) ( $settings['last_sync_message'] ?? 'No sync yet.' ) ) . '</td></tr>';
        echo '<tr><th>Last sync at</th><td>' . esc_html( (string) ( $settings['last_sync_at'] ?? 'Never' ) ) . '</td></tr>';
        echo '<tr><th>Stats</th><td>Imported: ' . (int) count( $offers ) . ' | Approved: ' . (int) $approved . ' | Needs approval: ' . (int) $needs_approval . '</td></tr>';
        if ( self::can_show_diagnostics() ) {
            echo '<tr><th>Raw API diagnostics</th><td><pre style="white-space:pre-wrap;">' . esc_html( self::diagnostics_summary( $settings ) ) . '</pre></td></tr>';
        }
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
            if ( $template_check['safe'] ) {
                echo '<td>Safe to route</td>';
            } else {
                $hint = $map['selected_preview_url'] !== '' ? 'Preview URL available. ' : '';
                $hint .= 'Tracking template missing.';
                echo '<td>Not safe to route. ' . esc_html( $hint ) . '</td>';
            }
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
                if ( self::mapping_is_eligible_for_frontend( is_array( $map ) ? $map : [] ) ) {
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
    private static function mark_sync( bool $ok, string $message, array $offers, array $diagnostics = [] ): array {
        $settings = get_option( self::API_SETTINGS_OPTION, [] );
        $settings = is_array( $settings ) ? $settings : [];
        $settings['last_sync_at'] = gmdate( 'c' );
        $settings['last_sync_status'] = $ok ? 'success' : 'error';
        $settings['last_sync_message'] = $message;
        $settings['last_sync_notice'] = sanitize_key( (string) ( $diagnostics['notice'] ?? ( $ok ? 'success' : 'error' ) ) );
        $settings['last_sync_http_status'] = (int) ( $diagnostics['http_status'] ?? 0 );
        $settings['last_sync_content_type'] = sanitize_text_field( (string) ( $diagnostics['content_type'] ?? '' ) );
        $settings['last_sync_endpoint'] = esc_url_raw( (string) ( $diagnostics['endpoint_used'] ?? '' ) );
        $settings['last_sync_body_preview'] = sanitize_textarea_field( (string) ( $diagnostics['body_preview'] ?? '' ) );
        $settings['last_sync_raw_offer_count'] = (int) ( $diagnostics['raw_offer_count'] ?? 0 );
        $settings['last_sync_cam_offer_count'] = (int) ( $diagnostics['cam_offer_count'] ?? count( $offers ) );
        $settings['last_sync_payload_shape'] = sanitize_text_field( (string) ( $diagnostics['payload_shape'] ?? '' ) );
        $settings['last_sync_offer_name_samples'] = array_values( array_map( 'sanitize_text_field', is_array( $diagnostics['offer_name_samples'] ?? null ) ? $diagnostics['offer_name_samples'] : [] ) );
        update_option( self::API_SETTINGS_OPTION, self::sanitize_api_settings( $settings ) );
        return [
            'ok' => $ok,
            'message' => $message,
            'offers' => count( $offers ),
            'cam_offers' => count( $offers ),
        ];
    }

    /**
     * Check if one mapping is eligible for frontend enable action.
     *
     * @param array<string,mixed> $map Mapping row.
     * @return bool
     */
    private static function mapping_is_eligible_for_frontend( array $map ): bool {
        $approved = (string) ( $map['approval_status'] ?? '' ) === 'approved' || ! empty( $map['manually_approved'] );
        if ( ! $approved ) {
            return false;
        }
        $template_check = self::validate_template( (string) ( $map['template_url'] ?? '' ) );
        return $template_check['safe'];
    }

    /**
     * Return endpoint candidates (HTTPS first, HTTP fallback).
     *
     * @return string[]
     */
    private static function endpoint_candidates(): array {
        return [
            'https://gateway.crakrevenue.com/affiliate',
            'http://gateway.crakrevenue.com/affiliate',
        ];
    }

    /**
     * Build safe body preview for diagnostics.
     *
     * @param string $body Raw body.
     * @param string $api_key API key to redact.
     * @return string
     */
    private static function sanitize_body_preview( string $body, string $api_key ): string {
        $snippet = substr( $body, 0, self::DEBUG_PREVIEW_LIMIT );
        $snippet = preg_replace( '/(api[_-]?key=)[^&\\s\"]+/i', '$1[redacted]', (string) $snippet ) ?? '';
        if ( $api_key !== '' ) {
            $snippet = str_replace( $api_key, '[redacted]', $snippet );
        }
        return sanitize_textarea_field( $snippet );
    }

    /**
     * Check if diagnostics can be shown in admin.
     *
     * @return bool
     */
    private static function can_show_diagnostics(): bool {
        $debug_enabled = defined( 'WP_DEBUG' ) && WP_DEBUG;
        return $debug_enabled || current_user_can( 'manage_options' );
    }

    /**
     * Render sync result notice.
     *
     * @param array<string,mixed> $settings API settings.
     * @return void
     */
    private static function render_last_sync_notice( array $settings ): void {
        $msg = trim( (string) ( $settings['last_sync_message'] ?? '' ) );
        if ( $msg === '' ) {
            return;
        }
        $kind = sanitize_key( (string) ( $settings['last_sync_notice'] ?? 'success' ) );
        $class = $kind === 'success' ? 'notice notice-success' : ( $kind === 'warning' ? 'notice notice-warning' : 'notice notice-error' );
        echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $msg ) . '</p></div>';
    }

    /**
     * Build diagnostics summary text.
     *
     * @param array<string,mixed> $settings API settings.
     * @return string
     */
    private static function diagnostics_summary( array $settings ): string {
        $lines = [
            'Endpoint: ' . (string) ( $settings['last_sync_endpoint'] ?? '' ),
            'HTTP status: ' . (string) ( $settings['last_sync_http_status'] ?? 0 ),
            'Content type: ' . (string) ( $settings['last_sync_content_type'] ?? '' ),
            'Payload shape: ' . (string) ( $settings['last_sync_payload_shape'] ?? '' ),
            'Raw offer count: ' . (string) ( $settings['last_sync_raw_offer_count'] ?? 0 ),
            'Cam offer count: ' . (string) ( $settings['last_sync_cam_offer_count'] ?? 0 ),
            'Body preview: ' . (string) ( $settings['last_sync_body_preview'] ?? '' ),
        ];
        $samples = is_array( $settings['last_sync_offer_name_samples'] ?? null ) ? $settings['last_sync_offer_name_samples'] : [];
        if ( $samples !== [] ) {
            $lines[] = 'Offer samples: ' . implode( ' | ', array_slice( $samples, 0, 10 ) );
        }
        return implode( PHP_EOL, $lines );
    }
}
