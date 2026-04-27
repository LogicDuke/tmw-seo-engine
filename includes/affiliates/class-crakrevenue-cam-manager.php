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

    /** @var callable|null */
    private static $http_getter = null;

    /**
     * Set test HTTP getter override.
     *
     * @param callable|null $getter Getter callback.
     * @return void
     */
    public static function set_http_getter( $getter ): void {
        self::$http_getter = is_callable( $getter ) ? $getter : null;
    }

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
            'royal_cams_gay' => ['royal cams gay'],
            'exposed_webcams_live_free_fun' => ['exposed webcams', 'live free fun'],
            'sexymeet_tv' => ['sexymeet.tv', 'sexymeet tv'],
            'livejasmin' => ['live jasmin', 'livejasmin'],
            'cams_com' => ['cams.com', 'cams com'],
            'camsoda' => ['camsoda'],
            'visitx' => ['visit x', 'visitx'],
            'sinparty' => ['sinparty', 'sin party'],
            'myfreecams' => ['myfreecams', 'my free cams'],
            'stripchat' => ['stripchat'],
            'chaturbate' => ['chaturbate'],
            'bongacams' => ['bongacams', 'bonga cams'],
            'cam4' => ['cam4'],
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
        $diag = is_array( $input['last_sync_diagnostics'] ?? null ) ? $input['last_sync_diagnostics'] : [];
        $network_id = sanitize_key( strtolower( (string) ( $input['network_id'] ?? 'crakrevenue' ) ) );
        if ( $network_id === '' ) {
            $network_id = 'crakrevenue';
        }
        return [
            'api_key' => sanitize_text_field( (string) ( $input['api_key'] ?? '' ) ),
            'network_id' => $network_id,
            'affiliate_id' => sanitize_text_field( (string) ( $input['affiliate_id'] ?? '' ) ),
            'last_sync_at' => sanitize_text_field( (string) ( $input['last_sync_at'] ?? '' ) ),
            'last_sync_status' => sanitize_text_field( (string) ( $input['last_sync_status'] ?? '' ) ),
            'last_sync_message' => sanitize_text_field( (string) ( $input['last_sync_message'] ?? '' ) ),
            'last_sync_diagnostics' => $diag,
        ];
    }

    /**
     * Build CrakRevenue findAll URL.
     *
     * @param string $api_key API key.
     * @param string $network_id Network ID.
     * @param string $endpoint Base endpoint.
     * @return string
     */
    public static function build_offers_request_url( string $api_key, string $network_id = 'crakrevenue', string $endpoint = 'https://gateway.crakrevenue.com/affiliate' ): string {
        $pairs = [
            'Target=' . rawurlencode( 'Affiliate_Offer' ),
            'Method=' . rawurlencode( 'findAll' ),
            'Format=' . rawurlencode( 'json' ),
            'Service=' . rawurlencode( 'HasOffers' ),
            'Version=' . rawurlencode( '2' ),
            'NetworkId=' . rawurlencode( $network_id !== '' ? $network_id : 'crakrevenue' ),
            'api_key=' . rawurlencode( $api_key ),
        ];

        foreach ( self::offers_request_fields() as $field ) {
            $pairs[] = 'fields[]=' . rawurlencode( $field );
        }

        return $endpoint . '?' . implode( '&', $pairs );
    }

    /**
     * Redact API key from text.
     *
     * @param string $text Input text.
     * @return string
     */
    public static function redact_api_key_from_text( string $text ): string {
        $text = (string) preg_replace( '/api_key=[^&\s]*/i', 'api_key=[redacted]', $text );
        $text = (string) preg_replace( '/apikey=[^&\s]*/i', 'apikey=[redacted]', $text );
        $text = (string) preg_replace( '/key=[^&\s]*/i', 'key=[redacted]', $text );
        $text = (string) preg_replace( '/affiliate_id=[^&\s]*/i', 'affiliate_id=[redacted]', $text );
        $text = (string) preg_replace( '/AffiliateId=[^&\s]*/i', 'AffiliateId=[redacted]', $text );
        $text = (string) preg_replace( '/NetworkId=[^&\s]*/i', 'NetworkId=[redacted]', $text );
        return $text;
    }

    /**
     * Return requested CrakRevenue fields.
     *
     * @return string[]
     */
    public static function offers_request_fields(): array {
        return [
            'id','name','status','preview_url','require_approval','require_terms_and_conditions','show_custom_variables','allow_website_links','allow_direct_links','payout_type','default_payout','percent_payout','currency','description','is_expired','expiration_date','terms_and_conditions','use_target_rules','featured','has_goals_enabled','conversion_cap','monthly_conversion_cap','payout_cap','monthly_payout_cap',
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
        $network_id = sanitize_key( strtolower( (string) ( $settings['network_id'] ?? 'crakrevenue' ) ) );
        $affiliate_id = sanitize_text_field( (string) ( $settings['affiliate_id'] ?? '' ) );
        if ( $network_id === '' ) {
            $network_id = 'crakrevenue';
        }
        if ( $api_key === '' ) {
            return self::mark_sync( false, 'Missing API key.', [], [ 'endpoint_used' => '', 'malformed' => false ] );
        }

        $endpoints = [ 'https://gateway.crakrevenue.com/affiliate', 'http://gateway.crakrevenue.com/affiliate' ];
        $last_error = 'Unknown API error.';

        foreach ( $endpoints as $endpoint ) {
            $url = self::build_offers_request_url( $api_key, $network_id, $endpoint );
            $response = self::http_get( $url, [ 'timeout' => 20 ] );
            if ( is_wp_error( $response ) ) {
                $last_error = 'HTTP failure: ' . $response->get_error_message();
                continue;
            }

            $status = (int) wp_remote_retrieve_response_code( $response );
            $body = (string) wp_remote_retrieve_body( $response );
            $content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );

            if ( $status !== 200 ) {
                $last_error = 'HTTP status ' . $status . ' from API.';
                return self::mark_sync( false, $last_error, [], [
                    'endpoint_used' => $endpoint,
                    'http_status_code' => $status,
                    'response_content_type' => $content_type,
                    'response_preview' => self::sanitize_response_preview( $body ),
                    'request_url' => self::redact_api_key_from_text( $url ),
                ] );
            }

            if ( trim( $body ) === '' ) {
                return self::mark_sync( false, 'Empty response from API.', [], [
                    'endpoint_used' => $endpoint,
                    'http_status_code' => $status,
                    'response_content_type' => $content_type,
                ] );
            }

            $parsed = self::parse_offers_payload_with_diagnostics( $body );
            $offers = $parsed['offers'];
            $payload_shape = $parsed['payload_shape'];
            $parsed_diag = is_array( $parsed['diagnostics'] ?? null ) ? $parsed['diagnostics'] : [];
            if ( ! empty( $parsed['error_message'] ) ) {
                return self::mark_sync( false, (string) $parsed['error_message'], [], [
                    'endpoint_used' => $endpoint,
                    'http_status_code' => $status,
                    'response_content_type' => $content_type,
                    'payload_shape' => $payload_shape,
                    'response_preview' => self::sanitize_response_preview( $body ),
                ] + $parsed_diag );
            }
            if ( empty( $offers ) ) {
                return self::mark_sync( false, 'No offers found in response.', [], [
                    'endpoint_used' => $endpoint,
                    'http_status_code' => $status,
                    'response_content_type' => $content_type,
                    'payload_shape' => $payload_shape,
                    'malformed' => (bool) $parsed['malformed'],
                    'response_preview' => self::sanitize_response_preview( $body ),
                ] + $parsed_diag );
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
            $sync_meta = self::sync_offer_url_metadata( $normalized, $endpoint, $api_key, $network_id, $affiliate_id );
            $normalized = $sync_meta['offers'];

            update_option( self::OFFERS_CACHE_OPTION, [
                'imported_at' => gmdate( 'c' ),
                'offers' => $normalized,
            ] );

            $diag = [
                'endpoint_used' => $endpoint,
                'http_status_code' => $status,
                'response_content_type' => $content_type,
                'payload_shape' => $payload_shape,
                'raw_offer_count' => count( $offers ),
                'cam_offer_count' => count( $normalized ),
                'supported_platforms_detected' => count( array_unique( array_values( array_filter( array_map( static fn( $row ): string => sanitize_key( (string) ( $row['platform_slug'] ?? '' ) ), $normalized ) ) ) ) ),
                'response_preview' => self::sanitize_response_preview( $body ),
                'request_url' => self::redact_api_key_from_text( $url ),
                'offer_url_sync_ran' => $sync_meta['ran'],
                'offer_url_rows_matched' => $sync_meta['matched'],
                'offer_url_sync_method' => $sync_meta['method'] ?? 'Affiliate_OfferUrl/findAll',
                'offer_url_sync_http_status' => $sync_meta['http_status'] ?? 0,
                'offer_url_sync_raw_rows' => $sync_meta['raw_rows'] ?? 0,
                'offer_url_sync_matched' => $sync_meta['matched'] ?? 0,
                'offer_url_sync_error' => $sync_meta['error'] ?? '',
            ];
            if ( count( $offers ) > 0 && count( $normalized ) === 0 ) {
                $diag['first_offer_names'] = array_slice( array_values( array_filter( $raw_names ) ), 0, 10 );
            }

            return self::mark_sync( true, 'Offers synced.', $normalized, $diag );
        }

        return self::mark_sync( false, $last_error, [], [ 'endpoint_used' => '' ] );
    }

    /**
     * Parse offers payload with diagnostics.
     *
     * @param string $body Response body.
     * @return array{offers:array<int,array<string,mixed>>,payload_shape:string,malformed:bool,error_message:string,diagnostics:array<string,mixed>}
     */
    public static function parse_offers_payload_with_diagnostics( string $body ): array {
        $json = json_decode( $body, true );
        if ( is_array( $json ) ) {
            $json_shape = [
                'response.data' => $json['response']['data'] ?? null,
                'response.data.data' => $json['response']['data']['data'] ?? null,
                'response.data.Offer' => $json['response']['data']['Offer'] ?? null,
                'response.data.Affiliate_Offer' => $json['response']['data']['Affiliate_Offer'] ?? null,
                'data' => $json['data'] ?? null,
                'offers' => $json['offers'] ?? null,
                'offer' => $json['offer'] ?? null,
                'Affiliate_Offer' => $json['Affiliate_Offer'] ?? null,
                'response.offers' => $json['response']['offers'] ?? null,
                'response.offer' => $json['response']['offer'] ?? null,
                'response.Affiliate_Offer' => $json['response']['Affiliate_Offer'] ?? null,
            ];
            foreach ( $json_shape as $shape => $candidate ) {
                $rows = self::coerce_offer_rows( $candidate );
                if ( $rows !== [] ) {
                    return [
                        'offers' => $rows,
                        'payload_shape' => self::detect_payload_shape_label( $shape, $candidate ),
                        'malformed' => false,
                        'error_message' => '',
                        'diagnostics' => [],
                    ];
                }
            }
            if ( isset( $json[0] ) && is_array( $json[0] ) ) {
                return [ 'offers' => array_values( $json ), 'payload_shape' => 'top_level_array', 'malformed' => false, 'error_message' => '', 'diagnostics' => [] ];
            }

            $response_errors = self::collect_response_errors( $json );
            if ( $response_errors !== [] || (int) ( $json['response']['status'] ?? 0 ) < 0 ) {
                return [
                    'offers' => [],
                    'payload_shape' => 'response.error',
                    'malformed' => false,
                    'error_message' => 'API returned an error: ' . implode( '; ', $response_errors !== [] ? $response_errors : [ 'Unknown API error' ] ),
                    'diagnostics' => self::build_payload_diagnostics( $json ),
                ];
            }

            return [
                'offers' => [],
                'payload_shape' => self::detect_unknown_payload_shape( $json ),
                'malformed' => false,
                'error_message' => '',
                'diagnostics' => self::build_payload_diagnostics( $json ),
            ];
        }

        if ( str_starts_with( ltrim( $body ), '<' ) && function_exists( 'simplexml_load_string' ) ) {
            $xml = simplexml_load_string( $body );
            if ( $xml instanceof \SimpleXMLElement ) {
                $encoded = json_encode( $xml );
                $arr = is_string( $encoded ) ? json_decode( $encoded, true ) : [];
                $xml_shape = [
                    'response.data.offer' => $arr['response']['data']['offer'] ?? null,
                    'data.offer' => $arr['data']['offer'] ?? null,
                    'offers.offer' => $arr['offers']['offer'] ?? null,
                    'offer' => $arr['offer'] ?? null,
                    'Affiliate_Offer' => $arr['Affiliate_Offer'] ?? null,
                ];
                foreach ( $xml_shape as $shape => $candidate ) {
                    $rows = self::coerce_offer_rows( $candidate );
                    if ( $rows !== [] ) {
                        return [ 'offers' => $rows, 'payload_shape' => $shape, 'malformed' => false, 'error_message' => '', 'diagnostics' => [] ];
                    }
                }
            }
        }

        return [ 'offers' => [], 'payload_shape' => 'unknown', 'malformed' => true, 'error_message' => '', 'diagnostics' => [] ];
    }

    /**
     * Parse offers payload.
     *
     * @param string $body Response body.
     * @return array<int,array<string,mixed>>
     */
    public static function parse_offers_payload( string $body ): array {
        $parsed = self::parse_offers_payload_with_diagnostics( $body );
        return $parsed['offers'];
    }

    /**
     * Normalize row array from known wrappers.
     *
     * @param mixed $candidate Potential offers payload.
     * @return array<int,array<string,mixed>>
     */
    private static function coerce_offer_rows( $candidate ): array {
        if ( ! is_array( $candidate ) ) {
            return [];
        }

        if ( isset( $candidate['id'] ) || isset( $candidate['name'] ) ) {
            return [ $candidate ];
        }

        $rows = [];
        foreach ( [ 'Offer', 'Affiliate_Offer', 'AffiliateOffer' ] as $wrapper ) {
            if ( isset( $candidate[ $wrapper ] ) && is_array( $candidate[ $wrapper ] ) ) {
                $rows = array_merge( $rows, self::coerce_offer_rows( $candidate[ $wrapper ] ) );
            }
        }
        if ( $rows !== [] ) {
            return $rows;
        }

        foreach ( $candidate as $key => $value ) {
            if ( ! is_array( $value ) ) {
                continue;
            }
            $nested = self::coerce_offer_rows( $value );
            if ( $nested === [] ) {
                continue;
            }
            if ( is_string( $key ) && ctype_digit( $key ) ) {
                $offer_id = (int) $key;
                $nested = array_map(
                    static function ( array $row ) use ( $offer_id ): array {
                        if ( ! isset( $row['id'] ) || (int) $row['id'] <= 0 ) {
                            $row['id'] = $offer_id;
                        }
                        return $row;
                    },
                    $nested
                );
            }
            $rows = array_merge( $rows, $nested );
        }

        return $rows;
    }

    /**
     * Normalize one offer row.
     *
     * @param array<string,mixed> $offer Raw offer.
     * @return array<string,mixed>
     */
    public static function normalize_offer( array $offer ): array {
        $offer = self::flatten_offer_row( $offer );
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
            'raw_status' => sanitize_text_field( (string) self::first_alias_value( $offer, [ 'status', 'offer_status', 'approval_status', 'approvalStatus' ] ) ),
            'approval_status' => self::approval_status( $offer ),
            'require_approval' => self::first_alias_value( $offer, [ 'require_approval', 'requires_approval' ] ),
            'is_expired' => self::to_bool( $offer['is_expired'] ?? null ),
            'expiration_date' => sanitize_text_field( (string) ( $offer['expiration_date'] ?? '' ) ),
            'preview_url' => self::resolve_preview_url( $offer ),
            'allow_website_links' => self::to_bool( self::first_alias_value( $offer, [ 'allow_website_links' ] ) ),
            'allow_direct_links' => self::to_bool( self::first_alias_value( $offer, [ 'allow_direct_links' ] ) ),
            'offer_url_id' => (int) ( $offer['offer_url_id'] ?? 0 ),
            'landing_page_name' => sanitize_text_field( (string) ( $offer['landing_page_name'] ?? '' ) ),
            'show_custom_variables' => ! empty( $offer['show_custom_variables'] ),
            'payout_type' => sanitize_text_field( (string) ( $offer['payout_type'] ?? '' ) ),
            'default_payout' => (float) self::first_alias_value( $offer, [ 'default_payout', 'payout', 'revenue', 'payout_amount' ] ),
            'percent_payout' => (float) ( $offer['percent_payout'] ?? 0 ),
            'currency' => sanitize_text_field( (string) ( $offer['currency'] ?? '' ) ),
            'description' => sanitize_text_field( (string) ( $offer['description'] ?? '' ) ),
            'epc' => isset( $offer['epc'] ) && $offer['epc'] !== '' ? (float) $offer['epc'] : null,
            'epc_note' => isset( $offer['epc'] ) && $offer['epc'] !== '' ? '' : 'EPC from stats/manual only',
            'tracking_template' => self::resolve_tracking_template( $offer ),
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
        $offer = self::flatten_offer_row( $offer );
        $status = strtolower( trim( (string) self::first_alias_value( $offer, [ 'status', 'offer_status' ] ) ) );
        $approval = strtolower( trim( (string) self::first_alias_value( $offer, [ 'approval_status', 'approvalStatus' ] ) ) );
        $approved_flag = self::to_bool( self::first_alias_value( $offer, [ 'is_approved', 'isApproved', 'approved' ] ) );
        $requires_approval_raw = self::first_alias_value( $offer, [ 'require_approval', 'requires_approval' ] );
        $requires_approval = self::to_bool( $requires_approval_raw );
        $has_require_approval = $requires_approval_raw !== '' && $requires_approval_raw !== null;
        $status_is_active_or_empty = $status === '' || str_contains( $status, 'active' );

        if ( $approved_flag ) {
            return 'approved';
        }
        if ( str_contains( $approval, 'approv' ) || str_contains( $status, 'approv' ) ) {
            return 'approved';
        }
        if ( $has_require_approval && ! $requires_approval && $status_is_active_or_empty ) {
            return 'approved';
        }
        if ( $has_require_approval && $requires_approval ) {
            return 'needs_approval';
        }
        if ( str_contains( $status, 'pending' ) || str_contains( $status, 'required' ) || str_contains( $status, 'request' ) ) {
            return 'needs_approval';
        }
        if ( str_contains( $approval, 'pending' ) || str_contains( $approval, 'required' ) || str_contains( $approval, 'request' ) ) {
            return 'needs_approval';
        }
        return 'unknown';
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
     * Resolve tracking template from API payload.
     *
     * @param array<string,mixed> $offer Raw offer payload.
     * @return string
     */
    public static function resolve_tracking_template( array $offer ): string {
        foreach ( [ 'tracking_template', 'tracking_url', 'tracking_link', 'url_template', 'offer_url', 'trackingLink', 'trackingUrl', 'offerUrl', 'click_url', 'clickUrl', 'affiliate_url', 'affiliateUrl' ] as $key ) {
            $value = esc_url_raw( (string) ( $offer[ $key ] ?? '' ) );
            if ( $value !== '' ) {
                return $value;
            }
        }
        return '';
    }

    /**
     * Resolve preview URL aliases.
     *
     * @param array<string,mixed> $offer Raw offer payload.
     * @return string
     */
    public static function resolve_preview_url( array $offer ): string {
        foreach ( [ 'preview_url', 'previewUrl', 'preview', 'sample_url', 'sampleUrl' ] as $key ) {
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
                'raw_status' => (string) ( $pick['raw_status'] ?? $pick['status'] ?? '' ),
                'selected_offer_is_expired' => ! empty( $pick['is_expired'] ) ? 1 : 0,
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
        if ( isset( $offer['epc'] ) && $offer['epc'] !== null && $offer['epc'] !== '' ) {
            $score += (float) $offer['epc'] * 10;
        }
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
            'raw_status' => '',
            'selected_offer_is_expired' => 0,
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
        if ( isset( $link['is_active'] ) && empty( $link['is_active'] ) ) {
            return $url;
        }
        $activity = sanitize_key( (string) ( $link['activity_level'] ?? '' ) );
        if ( $activity !== '' && ! in_array( $activity, [ 'active', 'very_active' ], true ) ) {
            return $url;
        }
        if ( ! in_array( $type, self::supported_platform_slugs(), true ) ) {
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
        $diag = is_array( $settings['last_sync_diagnostics'] ?? null ) ? $settings['last_sync_diagnostics'] : [];
        $offers = self::get_cached_offers();
        $mappings = get_option( self::PLATFORM_MAPPINGS_OPTION, [] );
        $mappings = is_array( $mappings ) ? $mappings : [];
        $approved = 0;
        $needs_approval = 0;
        $unknown = 0;
        foreach ( $offers as $offer ) {
            if ( (string) ( $offer['approval_status'] ?? '' ) === 'approved' ) { $approved++; }
            if ( (string) ( $offer['approval_status'] ?? '' ) === 'needs_approval' ) { $needs_approval++; }
            if ( (string) ( $offer['approval_status'] ?? '' ) === 'unknown' ) { $unknown++; }
        }
        $filter = sanitize_key( (string) ( $_GET['tmwseo_cr_filter'] ?? 'all' ) );
        if ( ! in_array( $filter, [ 'all', 'approved', 'needs_approval', 'selected_defaults' ], true ) ) {
            $filter = 'all';
        }

        echo '<h2>CrakRevenue Cam Offers</h2>';
        echo '<p class="description">Sync cam offers via API, auto-map best offers, then enable by platform.</p>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'tmwseo_cr_sync' );
        echo '<input type="hidden" name="action" value="tmwseo_cr_save_and_sync" />';
        echo '<table class="form-table">';
        echo '<tr><th>API key</th><td><input type="password" name="api_key" class="regular-text" value="' . esc_attr( (string) ( $settings['api_key'] ?? '' ) ) . '" /></td></tr>';
        echo '<tr><th>Network ID</th><td><input type="text" name="network_id" class="regular-text" value="' . esc_attr( (string) ( $settings['network_id'] ?? 'crakrevenue' ) ) . '" /></td></tr>';
        echo '<tr><th>Affiliate ID</th><td><input type="text" name="affiliate_id" class="regular-text" value="' . esc_attr( (string) ( $settings['affiliate_id'] ?? '' ) ) . '" /></td></tr>';
        echo '<tr><th>Last sync time</th><td>' . esc_html( (string) ( $settings['last_sync_at'] ?? 'Never' ) ) . '</td></tr>';
        echo '<tr><th>Last sync status</th><td>' . esc_html( (string) ( $settings['last_sync_status'] ?? 'n/a' ) ) . '</td></tr>';
        echo '<tr><th>Last sync message</th><td>' . esc_html( (string) ( $settings['last_sync_message'] ?? 'n/a' ) ) . '</td></tr>';
        echo '<tr><th>Stats</th><td>Imported: ' . (int) count( $offers ) . ' | Approved: ' . (int) $approved . ' | Needs approval: ' . (int) $needs_approval . ' | Unknown: ' . (int) $unknown . '</td></tr>';
        echo '</table>';
        submit_button( 'Save + Sync Offers', 'primary', 'submit', false );
        echo ' ';
        submit_button( 'Test API Connection', 'secondary', 'test_connection', false );
        echo '</form>';

        echo '<h3>Diagnostics</h3>';
        echo '<ul>';
        echo '<li>Endpoint used: ' . esc_html( (string) ( $diag['endpoint_used'] ?? 'n/a' ) ) . '</li>';
        echo '<li>HTTP status code: ' . esc_html( (string) ( $diag['http_status_code'] ?? 'n/a' ) ) . '</li>';
        echo '<li>Response content type: ' . esc_html( (string) ( $diag['response_content_type'] ?? 'n/a' ) ) . '</li>';
        echo '<li>Payload shape detected: ' . esc_html( (string) ( $diag['payload_shape'] ?? 'unknown' ) ) . '</li>';
        echo '<li>Raw offer count: ' . esc_html( (string) ( $diag['raw_offer_count'] ?? 0 ) ) . '</li>';
        echo '<li>Cam offer count: ' . esc_html( (string) ( $diag['cam_offer_count'] ?? 0 ) ) . '</li>';
        echo '<li>Supported platforms detected: ' . esc_html( (string) ( $diag['supported_platforms_detected'] ?? 0 ) ) . '</li>';
        if ( ! empty( $diag['first_offer_names'] ) && is_array( $diag['first_offer_names'] ) ) {
            echo '<li>First raw offer names (up to 10): ' . esc_html( implode( ', ', $diag['first_offer_names'] ) ) . '</li>';
        }
        if ( ! empty( $diag['top_level_keys'] ) && is_array( $diag['top_level_keys'] ) ) {
            echo '<li>Top-level JSON keys: ' . esc_html( implode( ', ', $diag['top_level_keys'] ) ) . '</li>';
        }
        if ( ! empty( $diag['response_keys'] ) && is_array( $diag['response_keys'] ) ) {
            echo '<li>Response keys: ' . esc_html( implode( ', ', $diag['response_keys'] ) ) . '</li>';
        }
        if ( isset( $diag['response_status'] ) && $diag['response_status'] !== null && $diag['response_status'] !== '' ) {
            echo '<li>Response status: ' . esc_html( (string) $diag['response_status'] ) . '</li>';
        }
        if ( isset( $diag['response_http_status'] ) && $diag['response_http_status'] !== null && $diag['response_http_status'] !== '' ) {
            echo '<li>Response httpStatus: ' . esc_html( (string) $diag['response_http_status'] ) . '</li>';
        }
        if ( ! empty( $diag['response_errors'] ) && is_array( $diag['response_errors'] ) ) {
            echo '<li>Response errors: ' . esc_html( implode( '; ', $diag['response_errors'] ) ) . '</li>';
        }
        if ( ! empty( $diag['request_summary'] ) && is_array( $diag['request_summary'] ) ) {
            $req_parts = [];
            foreach ( [ 'Method', 'Target', 'NetworkId', 'AffiliateId', 'api_key' ] as $key ) {
                if ( isset( $diag['request_summary'][ $key ] ) ) {
                    $req_parts[] = $key . '=' . (string) $diag['request_summary'][ $key ];
                }
            }
            echo '<li>Request summary: ' . esc_html( implode( ', ', $req_parts ) ) . '</li>';
        }
        echo '<li>Offer URL sync ran: ' . ( ! empty( $diag['offer_url_sync_ran'] ) ? 'yes' : 'no' ) . '</li>';
        echo '<li>Offer URL sync method: ' . esc_html( (string) ( $diag['offer_url_sync_method'] ?? 'n/a' ) ) . '</li>';
        echo '<li>Offer URL sync HTTP status: ' . esc_html( (string) ( $diag['offer_url_sync_http_status'] ?? 'n/a' ) ) . '</li>';
        echo '<li>Offer URL sync raw rows: ' . esc_html( (string) ( $diag['offer_url_sync_raw_rows'] ?? 0 ) ) . '</li>';
        echo '<li>Offer URL sync matched rows: ' . esc_html( (string) ( $diag['offer_url_sync_matched'] ?? 0 ) ) . '</li>';
        if ( ! empty( $diag['offer_url_sync_error'] ) ) {
            echo '<li>Offer URL sync error: ' . esc_html( (string) $diag['offer_url_sync_error'] ) . '</li>';
        }
        if ( current_user_can( 'manage_options' ) && ! empty( $diag['response_preview'] ) ) {
            echo '<li>Response preview: <code>' . esc_html( (string) $diag['response_preview'] ) . '</code></li>';
        }
        echo '</ul>';
        echo '<p class="description">EPC is not returned by offer sync; use stats sync/manual value.</p>';
        echo '<p class="description">Preview URL is preview-only and is never used as a tracking template.</p>';

        echo '<p style="margin-top:8px;">';
        echo '<strong>Offer filter:</strong> ';
        foreach ( [ 'all' => 'Show all detected cam offers', 'approved' => 'Show only approved/runnable', 'needs_approval' => 'Show only needs approval', 'selected_defaults' => 'Show only selected platform defaults' ] as $value => $label ) {
            $url = add_query_arg( [ 'page' => 'tmwseo-affiliates', 'tmwseo_cr_filter' => $value ], admin_url( 'admin.php' ) );
            $active = $filter === $value ? ' style="font-weight:700;"' : '';
            echo '<a href="' . esc_url( $url ) . '"' . $active . '>' . esc_html( $label ) . '</a> ';
        }
        echo '</p>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px;display:flex;gap:8px;">';
        wp_nonce_field( 'tmwseo_cr_quick_actions' );
        echo '<input type="hidden" name="action" value="tmwseo_cr_quick_action" />';
        echo '<button class="button" name="quick_action" value="auto_map">Auto-map best approved offers</button>';
        echo '<button class="button" name="quick_action" value="enable_defaults">Enable all approved cam platform defaults</button>';
        echo '<button class="button" name="quick_action" value="disable_all">Disable all CrakRevenue cam routing</button>';
        echo '</form>';

        echo '<h3 style="margin-top:16px;">Platform summary</h3>';
        echo '<table class="widefat striped"><thead><tr><th>Platform</th><th>Best approved offer</th><th>Selected offer</th><th>Offer ID</th><th>Raw status</th><th>Require approval (raw)</th><th>Normalized approval</th><th>Payout type</th><th>Payout</th><th>Currency</th><th>Preview URL</th><th>Allow website links</th><th>Allow direct links</th><th>EPC</th><th>Tracking template status</th><th>Offer URL sync</th><th>Routing status</th><th>Template safety</th></tr></thead><tbody>';
        foreach ( self::supported_platform_slugs() as $slug ) {
            $rows = array_values( array_filter( $offers, static fn( $row ) => (string) ( $row['platform_slug'] ?? '' ) === $slug ) );
            usort( $rows, [ __CLASS__, 'compare_offer_rank' ] );
            $best = $rows[0] ?? [];
            $map = is_array( $mappings[ $slug ] ?? null ) ? $mappings[ $slug ] : self::default_mapping_row( $slug );
            if ( $filter === 'approved' && (string) ( $map['approval_status'] ?? '' ) !== 'approved' ) { continue; }
            if ( $filter === 'needs_approval' && (string) ( $map['approval_status'] ?? '' ) !== 'needs_approval' ) { continue; }
            if ( $filter === 'selected_defaults' && (int) ( $map['selected_offer_id'] ?? 0 ) <= 0 ) { continue; }
            $template_check = self::validate_template( (string) ( $map['template_url'] ?? '' ) );
            $tracking_status = (string) ( $map['template_url'] ?? '' ) === '' ? 'Tracking template missing — manual template required' : 'Template set';
            echo '<tr>';
            echo '<td><strong>' . esc_html( $slug ) . '</strong></td>';
            echo '<td>' . esc_html( (string) ( $best['offer_name'] ?? '—' ) ) . '</td>';
            echo '<td>' . esc_html( (string) ( $map['selected_offer_name'] ?? '—' ) ) . '</td>';
            echo '<td>' . esc_html( (string) ( $map['selected_offer_id'] ?? 0 ) ) . '</td>';
            echo '<td>' . esc_html( (string) ( $best['raw_status'] ?? $best['status'] ?? 'unknown' ) ) . '</td>';
            echo '<td>' . esc_html( (string) ( $best['require_approval'] ?? '' ) ) . '</td>';
            echo '<td>' . esc_html( (string) ( $map['approval_status'] ?? 'unknown' ) ) . '</td>';
            echo '<td>' . esc_html( (string) ( $best['payout_type'] ?? '' ) ) . '</td>';
            echo '<td>' . esc_html( self::format_payout_display( $best ) ) . '</td>';
            echo '<td>' . esc_html( (string) ( $best['currency'] ?? '' ) ) . '</td>';
            $preview = esc_url( (string) ( $map['selected_preview_url'] ?? '' ) );
            echo '<td>' . ( $preview !== '' ? '<a href="' . $preview . '" target="_blank" rel="noopener">Preview URL</a>' : 'N/A' ) . '</td>';
            echo '<td>' . ( ! empty( $best['allow_website_links'] ) ? 'yes' : 'no' ) . '</td>';
            echo '<td>' . ( ! empty( $best['allow_direct_links'] ) ? 'yes' : 'no' ) . '</td>';
            $epc_display = isset( $best['epc'] ) && $best['epc'] !== null ? (string) $best['epc'] : '';
            if ( $epc_display === '' ) {
                $epc_display = (string) ( $best['epc_note'] ?? 'EPC from stats/manual only' );
            }
            echo '<td>' . esc_html( $epc_display ) . '</td>';
            echo '<td>' . esc_html( $tracking_status ) . '</td>';
            echo '<td>' . esc_html( (string) ( $diag['offer_url_sync_method'] ?? 'n/a' ) ) . ' / ' . esc_html( (string) ( $diag['offer_url_sync_http_status'] ?? 'n/a' ) ) . '</td>';
            echo '<td>' . ( ! empty( $map['enabled'] ) ? 'Enabled' : 'Disabled' ) . '</td>';
            echo '<td>' . ( $template_check['safe'] ? 'Safe to route' : 'Not safe to route' ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
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
        $settings['network_id'] = sanitize_key( strtolower( (string) ( $_POST['network_id'] ?? 'crakrevenue' ) ) );
        $settings['affiliate_id'] = sanitize_text_field( (string) ( $_POST['affiliate_id'] ?? '' ) );
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
     * @param array<string,mixed> $diag Diagnostics.
     * @return array{ok:bool,message:string,offers:int,cam_offers:int}
     */
    private static function mark_sync( bool $ok, string $message, array $offers, array $diag ): array {
        $settings = get_option( self::API_SETTINGS_OPTION, [] );
        $settings = is_array( $settings ) ? $settings : [];
        $settings['last_sync_at'] = gmdate( 'c' );
        $settings['last_sync_status'] = $ok ? 'success' : 'error';
        $settings['last_sync_message'] = $message;
        $settings['last_sync_diagnostics'] = self::redact_sensitive_array( $diag );
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
        if ( (int) ( $map['selected_offer_id'] ?? 0 ) <= 0 || ! empty( $map['selected_offer_is_expired'] ) ) {
            return false;
        }
        $template_check = self::validate_template( (string) ( $map['template_url'] ?? '' ) );
        return $template_check['safe'];
    }

    /**
     * Sanitize body preview for diagnostics.
     *
     * @param string $body API body.
     * @return string
     */
    private static function sanitize_response_preview( string $body ): string {
        $decoded = json_decode( $body, true );
        if ( is_array( $decoded ) ) {
            $redacted = self::redact_sensitive_array( $decoded );
            $json = wp_json_encode( $redacted );
            if ( is_string( $json ) ) {
                return substr( $json, 0, 300 );
            }
        }

        $flat = preg_replace( '/\s+/', ' ', strip_tags( $body ) );
        $flat = is_string( $flat ) ? $flat : '';
        return substr( self::redact_api_key_from_text( $flat ), 0, 300 );
    }


    /**
     * Redact sensitive values recursively from diagnostics/request structures.
     *
     * @param mixed $value Any scalar/array value.
     * @return mixed
     */
    private static function redact_sensitive_array( $value ) {
        if ( ! is_array( $value ) ) {
            return is_string( $value ) ? self::redact_api_key_from_text( $value ) : $value;
        }

        $result = [];
        foreach ( $value as $key => $item ) {
            $key_lc = strtolower( (string) $key );
            if ( in_array( $key_lc, [ 'api_key', 'apikey', 'key', 'affiliate_id', 'affiliateid', 'networkid', 'network_id' ], true ) ) {
                $result[ $key ] = '[redacted]';
                continue;
            }
            $result[ $key ] = self::redact_sensitive_array( $item );
        }
        return $result;
    }

    /**
     * Build payload diagnostics for unknown/error responses.
     *
     * @param array<string,mixed> $json Decoded JSON.
     * @return array<string,mixed>
     */
    private static function build_payload_diagnostics( array $json ): array {
        $diag = [
            'top_level_keys' => array_keys( $json ),
            'response_keys' => is_array( $json['response'] ?? null ) ? array_keys( $json['response'] ) : [],
            'response_status' => $json['response']['status'] ?? null,
            'response_http_status' => $json['response']['httpStatus'] ?? null,
            'response_errors' => self::collect_response_errors( $json ),
        ];
        if ( is_array( $json['request'] ?? null ) ) {
            $diag['request_summary'] = self::redact_sensitive_array( [
                'Method' => $json['request']['Method'] ?? '',
                'Target' => $json['request']['Target'] ?? '',
                'NetworkId' => $json['request']['NetworkId'] ?? '',
                'AffiliateId' => $json['request']['AffiliateId'] ?? '',
                'affiliate_id' => $json['request']['affiliate_id'] ?? '',
                'api_key' => $json['request']['api_key'] ?? '',
            ] );
        }
        return self::redact_sensitive_array( $diag );
    }

    /**
     * Collect response errors from JSON payload.
     *
     * @param array<string,mixed> $json Decoded JSON.
     * @return array<int,string>
     */
    private static function collect_response_errors( array $json ): array {
        $errors = $json['response']['errors'] ?? [];
        $collected = [];
        if ( is_array( $errors ) ) {
            foreach ( $errors as $error ) {
                if ( is_scalar( $error ) ) {
                    $collected[] = sanitize_text_field( (string) $error );
                } elseif ( is_array( $error ) ) {
                    $flatten = implode( ' ', array_map( static fn( $v ): string => is_scalar( $v ) ? (string) $v : '', $error ) );
                    if ( trim( $flatten ) !== '' ) {
                        $collected[] = sanitize_text_field( $flatten );
                    }
                }
            }
        }
        return array_values( array_filter( self::redact_sensitive_array( $collected ) ) );
    }

    /**
     * Describe unknown JSON shape with useful specificity.
     *
     * @param array<string,mixed> $json Decoded JSON.
     * @return string
     */
    private static function detect_unknown_payload_shape( array $json ): string {
        if ( isset( $json['response']['data']['data'] ) && is_array( $json['response']['data']['data'] ) ) {
            return isset( $json['response']['data']['data'][0] ) ? 'response.data.data.array' : 'response.data.data.map';
        }
        if ( isset( $json['response']['data'] ) && is_array( $json['response']['data'] ) ) {
            return isset( $json['response']['data'][0] ) ? 'response.data.array' : 'response.data.map';
        }
        if ( isset( $json['response'] ) && is_array( $json['response'] ) ) {
            return 'response.' . implode( '+', array_keys( $json['response'] ) );
        }
        return 'unknown';
    }

    /**
     * Improve payload shape labels for response.data variants.
     *
     * @param string $shape Base shape key.
     * @param mixed $candidate Candidate payload.
     * @return string
     */
    private static function detect_payload_shape_label( string $shape, $candidate ): string {
        if ( ! is_array( $candidate ) ) {
            return $shape;
        }
        if ( $shape === 'response.data' ) {
            return isset( $candidate[0] ) ? 'response.data.array' : 'response.data.map';
        }
        if ( $shape === 'response.data.data' ) {
            return isset( $candidate[0] ) ? 'response.data.data.array' : 'response.data.data.map';
        }
        return $shape;
    }

    /**
     * Try to fetch secondary offer URL metadata.
     *
     * @param array<int,array<string,mixed>> $offers Offer rows.
     * @param string $endpoint Endpoint.
     * @param string $api_key API key.
     * @param string $network_id Network ID.
     * @param string $affiliate_id Affiliate ID.
     * @return array{offers:array<int,array<string,mixed>>,ran:bool,matched:int}
     */
    private static function sync_offer_url_metadata( array $offers, string $endpoint, string $api_key, string $network_id, string $affiliate_id ): array {
        if ( $offers === [] ) {
            return [ 'offers' => $offers, 'ran' => false, 'matched' => 0, 'method' => 'Affiliate_OfferUrl/findAll', 'http_status' => 0, 'raw_rows' => 0, 'error' => '' ];
        }
        $pairs = [
            'Target=' . rawurlencode( 'Affiliate_OfferUrl' ),
            'Method=' . rawurlencode( 'findAll' ),
            'Format=' . rawurlencode( 'json' ),
            'Service=' . rawurlencode( 'HasOffers' ),
            'Version=' . rawurlencode( '2' ),
            'NetworkId=' . rawurlencode( $network_id ),
            'api_key=' . rawurlencode( $api_key ),
        ];
        if ( $affiliate_id !== '' ) {
            $pairs[] = 'AffiliateId=' . rawurlencode( $affiliate_id );
        }
        foreach ( [ 'id', 'offer_id', 'name', 'preview_url', 'offer_url', 'tracking_url', 'tracking_link', 'status', 'approval_status', 'allow_direct_links', 'allow_website_links' ] as $field ) {
            $pairs[] = 'fields[]=' . rawurlencode( $field );
        }
        $response = self::http_get( $endpoint . '?' . implode( '&', $pairs ), [ 'timeout' => 20 ] );
        if ( is_wp_error( $response ) ) {
            return [ 'offers' => $offers, 'ran' => true, 'matched' => 0, 'method' => 'Affiliate_OfferUrl/findAll', 'http_status' => 0, 'raw_rows' => 0, 'error' => $response->get_error_message() ];
        }
        $http_status = (int) wp_remote_retrieve_response_code( $response );
        if ( $http_status !== 200 ) {
            return [ 'offers' => $offers, 'ran' => true, 'matched' => 0, 'method' => 'Affiliate_OfferUrl/findAll', 'http_status' => $http_status, 'raw_rows' => 0, 'error' => 'HTTP status ' . $http_status ];
        }
        $parsed = self::parse_offers_payload( (string) wp_remote_retrieve_body( $response ) );
        if ( $parsed === [] ) {
            return [ 'offers' => $offers, 'ran' => true, 'matched' => 0, 'method' => 'Affiliate_OfferUrl/findAll', 'http_status' => $http_status, 'raw_rows' => 0, 'error' => 'No parsable rows returned by offer URL endpoint.' ];
        }
        $by_offer_id = [];
        foreach ( $parsed as $raw ) {
            $flat = self::flatten_offer_row( is_array( $raw ) ? $raw : [] );
            $id = (int) self::first_alias_value( $flat, [ 'offer_id', 'id' ] );
            if ( $id > 0 ) {
                $by_offer_id[ $id ] = $flat;
            }
        }
        $matched = 0;
        foreach ( $offers as $idx => $offer ) {
            $id = (int) ( $offer['offer_id'] ?? 0 );
            if ( $id <= 0 || ! isset( $by_offer_id[ $id ] ) ) {
                continue;
            }
            $matched++;
            $meta = $by_offer_id[ $id ];
            $offers[ $idx ]['offer_url_id'] = (int) self::first_alias_value( $meta, [ 'offer_url_id', 'id' ] );
            $offers[ $idx ]['landing_page_name'] = sanitize_text_field( (string) self::first_alias_value( $meta, [ 'landing_page_name', 'name' ] ) );
            $offers[ $idx ]['allow_direct_links'] = self::to_bool( self::first_alias_value( $meta, [ 'allow_direct_links' ] ) );
            $offers[ $idx ]['allow_website_links'] = self::to_bool( self::first_alias_value( $meta, [ 'allow_website_links' ] ) );
            $offers[ $idx ]['preview_url'] = $offers[ $idx ]['preview_url'] ?: self::resolve_preview_url( $meta );
            $offers[ $idx ]['tracking_template'] = $offers[ $idx ]['tracking_template'] ?: self::resolve_tracking_template( $meta );
            $offers[ $idx ]['approval_status'] = self::approval_status( array_merge( $offer, $meta ) );
            $offers[ $idx ]['status'] = sanitize_text_field( (string) self::first_alias_value( $meta, [ 'status', 'offer_status' ] ) ) ?: (string) ( $offer['status'] ?? '' );
        }
        return [ 'offers' => $offers, 'ran' => true, 'matched' => $matched, 'method' => 'Affiliate_OfferUrl/findAll', 'http_status' => $http_status, 'raw_rows' => count( $parsed ), 'error' => '' ];
    }

    /**
     * Flatten wrapper keys commonly returned by HasOffers.
     *
     * @param array<string,mixed> $offer Offer data.
     * @return array<string,mixed>
     */
    private static function flatten_offer_row( array $offer ): array {
        $flat = $offer;
        foreach ( [ 'Offer', 'Affiliate_Offer', 'AffiliateOffer', 'OfferUrl', 'Affiliate_OfferUrl' ] as $wrapper ) {
            if ( isset( $offer[ $wrapper ] ) && is_array( $offer[ $wrapper ] ) ) {
                $flat = array_merge( $flat, $offer[ $wrapper ] );
            }
        }
        return $flat;
    }

    /**
     * Read first defined alias from offer.
     *
     * @param array<string,mixed> $offer Offer data.
     * @param string[] $aliases Candidate aliases.
     * @return mixed
     */
    private static function first_alias_value( array $offer, array $aliases ) {
        foreach ( $aliases as $alias ) {
            if ( array_key_exists( $alias, $offer ) && $offer[ $alias ] !== '' && $offer[ $alias ] !== null ) {
                return $offer[ $alias ];
            }
        }
        return '';
    }

    /**
     * Convert mixed values to boolean.
     *
     * @param mixed $value Input.
     * @return bool
     */
    private static function to_bool( $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( $value === null ) {
            return false;
        }
        if ( is_numeric( $value ) ) {
            return (int) $value === 1;
        }
        $normalized = strtolower( trim( (string) $value ) );
        if ( in_array( $normalized, [ '1', 'true', 'enabled', 'yes', 'on', 'approved' ], true ) ) {
            return true;
        }
        if ( in_array( $normalized, [ '0', 'false', 'disabled', 'no', 'off', '' ], true ) ) {
            return false;
        }
        return false;
    }

    /**
     * Render payout display prioritizing percent payout then default payout.
     *
     * @param array<string,mixed> $offer Offer row.
     * @return string
     */
    private static function format_payout_display( array $offer ): string {
        $percent = (float) ( $offer['percent_payout'] ?? 0 );
        $default = (float) ( $offer['default_payout'] ?? 0 );
        if ( $percent > 0 ) {
            return rtrim( rtrim( (string) $percent, '0' ), '.' ) . '%';
        }
        if ( $default > 0 ) {
            $currency = trim( (string) ( $offer['currency'] ?? '' ) );
            $amount = rtrim( rtrim( (string) $default, '0' ), '.' );
            return $currency !== '' ? $currency . ' ' . $amount : $amount;
        }
        return '';
    }

    /**
     * Execute HTTP GET request.
     *
     * @param string $url Request URL.
     * @param array<string,mixed> $args Request args.
     * @return mixed
     */
    private static function http_get( string $url, array $args ) {
        if ( self::$http_getter !== null ) {
            return call_user_func( self::$http_getter, $url, $args );
        }
        return wp_remote_get( $url, $args );
    }
}
