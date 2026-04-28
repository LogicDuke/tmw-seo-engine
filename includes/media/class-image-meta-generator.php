<?php
/**
 * Image_Meta_Generator — auto-fills ALT, title, caption, and description
 * for featured images on model and video posts.
 *
 * Rules:
 * - Only runs once per attachment per version (tracked via
 *   _tmwseo_image_meta_generated, _tmwseo_image_meta_version,
 *   and _tmwseo_image_role).
 * - Never overwrites fields that contain user-customised text.
 *   If the existing value matches the known v1 auto-generated pattern it
 *   will be upgraded to v2 role-aware text automatically on the next save.
 * - Generates SFW, brand-safe text only.
 * - Role-aware since v2: primary, banner, front, back, secondary.
 *
 * Meta flags written per attachment:
 *   _tmwseo_image_meta_generated  (int 1)  — ever processed
 *   _tmwseo_image_meta_version    (int)     — generator version; currently 2
 *   _tmwseo_image_role            (string)  — primary|banner|front|back|secondary
 *
 * @package TMWSEO\Engine\Media
 */
namespace TMWSEO\Engine\Media;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Image_Meta_Generator {

    /** Schema version for generated metadata. Bump when templates change. */
    const IMAGE_META_VERSION = 2;

    // ── Public entry points ────────────────────────────────────────────────

    /**
     * Called when a featured image is set or changed.
     * Always treats the attachment as the primary/profile image.
     *
     * @param int      $attachment_id
     * @param \WP_Post $parent_post
     */
    public static function generate_for_featured_image( int $attachment_id, \WP_Post $parent_post ): void {
        self::generate_for_attachment( $attachment_id, $parent_post, 'primary' );
    }

    /**
     * Generate metadata for all relevant images connected to a post,
     * assigning each one its correct role (primary / banner / front / back).
     *
     * @param \WP_Post $parent_post
     */
    public static function generate_for_post_images( \WP_Post $parent_post ): void {
        $attachments = self::get_post_image_attachments_with_roles( $parent_post );
        if ( empty( $attachments ) ) {
            return;
        }
        foreach ( $attachments as $attachment_id => $role ) {
            self::generate_for_attachment( $attachment_id, $parent_post, $role );
        }
    }

    /**
     * Core generation entry-point.  Public so the CLI can call it with an
     * explicit role after clearing version/generated flags for a force pass.
     *
     * @param int      $attachment_id
     * @param \WP_Post $parent_post
     * @param string   $role  primary|banner|front|back|secondary  (default: primary)
     */
    public static function generate_for_attachment( int $attachment_id, \WP_Post $parent_post, string $role = 'primary' ): void {
        $role = self::sanitise_role( $role );

        // Only real image attachments.
        $attachment = get_post( $attachment_id );
        if (
            ! $attachment
            || 'attachment' !== $attachment->post_type
            || 0 !== strpos( (string) get_post_mime_type( $attachment_id ), 'image/' )
        ) {
            return;
        }

        $already_generated = (bool)   get_post_meta( $attachment_id, '_tmwseo_image_meta_generated', true );
        $meta_version      = (int)    get_post_meta( $attachment_id, '_tmwseo_image_meta_version',   true );
        $stored_role       = (string) get_post_meta( $attachment_id, '_tmwseo_image_role',           true );

        // Current field values.
        $current_alt     = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
        $current_title   = (string) $attachment->post_title;
        $current_caption = (string) $attachment->post_excerpt;
        $current_content = (string) $attachment->post_content;

        // ── Guard logic ────────────────────────────────────────────────────
        // Already at the current version with the same role — nothing to do.
        if ( $already_generated && $meta_version >= self::IMAGE_META_VERSION && $stored_role === $role ) {
            return;
        }

        $has_content = $current_alt !== '' || $current_title !== '' || $current_caption !== '' || $current_content !== '';

        if ( $already_generated && $has_content ) {
            if ( self::is_v1_generated_text( $current_alt, $current_title ) ) {
                // v1 auto-text detected — fall through to upgrade to v2 role-aware text.
            } else {
                // User-customised text detected — preserve it.
                // Stamp version/role so we stop inspecting it on every save.
                if ( $meta_version < self::IMAGE_META_VERSION ) {
                    update_post_meta( $attachment_id, '_tmwseo_image_meta_version', self::IMAGE_META_VERSION );
                    update_post_meta( $attachment_id, '_tmwseo_image_role', $role );
                }
                return;
            }
        }

        // ── Generate role-aware text ───────────────────────────────────────
        $meta   = self::build_meta_text( $attachment, $parent_post, $role );
        $update = [ 'ID' => $attachment_id ];

        // Title: write when empty or matches v1 pattern.
        if ( ( $current_title === '' || self::matches_v1_model_title( $current_title ) ) && ! empty( $meta['title'] ) ) {
            $update['post_title'] = $meta['title'];
        }
        // Caption: write when empty or matches v1 pattern.
        if ( ( $current_caption === '' || self::matches_v1_caption( $current_caption ) ) && ! empty( $meta['caption'] ) ) {
            $update['post_excerpt'] = $meta['caption'];
        }
        // Description: write when empty or matches v1 pattern.
        if ( ( $current_content === '' || self::matches_v1_description( $current_content ) ) && ! empty( $meta['description'] ) ) {
            $update['post_content'] = $meta['description'];
        }

        if ( count( $update ) > 1 ) {
            // Prevent save_post hook re-entry.
            remove_action( 'save_post', [ ImageMetaHooks::class, 'on_save_post_with_thumbnail' ], 20 );
            wp_update_post( $update );
            add_action( 'save_post', [ ImageMetaHooks::class, 'on_save_post_with_thumbnail' ], 20, 3 );
        }

        // Alt: write when empty or matches v1 pattern.
        if ( ( $current_alt === '' || self::matches_v1_alt( $current_alt ) ) && ! empty( $meta['alt'] ) ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $meta['alt'] );
        }

        // Stamp all three version-tracking flags.
        update_post_meta( $attachment_id, '_tmwseo_image_meta_generated', 1 );
        update_post_meta( $attachment_id, '_tmwseo_image_meta_version',   self::IMAGE_META_VERSION );
        update_post_meta( $attachment_id, '_tmwseo_image_role',           $role );
    }

    /**
     * Returns a map of attachment_id => role for every image attached to the post.
     * Public so the CLI can iterate attachments and display / force-clear flags.
     *
     * @param  \WP_Post $post
     * @return array<int,string>  e.g. [ 42 => 'primary', 55 => 'front', 56 => 'back' ]
     */
    public static function get_attachments_with_roles( \WP_Post $post ): array {
        $collected = self::collect_attachments_with_meta( $post );
        $out = [];
        foreach ( $collected as $id => $entry ) {
            $out[ $id ] = $entry['role'];
        }
        return $out;
    }

    /**
     * Returns a richer map for debug / CLI inspection.
     *
     * @param  \WP_Post $post
     * @return array<int, array{role:string, source_key:string}>
     */
    public static function debug_attachments_for_post( \WP_Post $post ): array {
        return self::collect_attachments_with_meta( $post );
    }

    // ── Internal ID + role collection ──────────────────────────────────────

    /**
     * Core collector.  Returns every image attachment for the post with its
     * assigned role AND the source meta key that provided the ID.
     *
     * First-encountered role wins when the same attachment ID appears in
     * multiple meta keys (e.g. same image used as thumbnail AND front).
     *
     * @return array<int, array{role:string, source_key:string}>
     */
    private static function collect_attachments_with_meta( \WP_Post $post ): array {
        $post_id = (int) $post->ID;
        /** @var array<int, array{role:string, source_key:string}> $result */
        $result = [];

        $add = static function ( int $id, string $role, string $source_key ) use ( &$result ): void {
            if ( $id > 0 && ! isset( $result[ $id ] ) ) {
                $result[ $id ] = [ 'role' => $role, 'source_key' => $source_key ];
            }
        };

        // ── Featured / thumbnail → primary ─────────────────────────────────
        $thumbnail_id = (int) get_post_thumbnail_id( $post_id );
        if ( $thumbnail_id > 0 ) {
            $add( $thumbnail_id, 'primary', '_thumbnail_id' );
        }

        if ( $post->post_type === 'model' ) {

            // ── Banner ─────────────────────────────────────────────────────
            // Keys listed without suffix (plain) AND with _id suffix so we
            // cover both storage conventions.
            $banner_keys = [
                'banner_image_id',
                '_banner_image_id',
                'banner_image',
                '_banner_image',
                'vertical_banner_image_id',
                '_vertical_banner_image_id',
                'vertical_banner_image',
                '_vertical_banner_image',
                'banner_focus_image_id',
                '_banner_focus_image_id',
                'model_banner_image_id',
                '_model_banner_image_id',
                'model_banner_image',
                '_model_banner_image',
            ];
            foreach ( $banner_keys as $meta_key ) {
                foreach ( self::extract_attachment_ids( get_post_meta( $post_id, $meta_key, true ) ) as $id ) {
                    $add( $id, 'banner', $meta_key );
                }
            }

            // ── Flipbox front ──────────────────────────────────────────────
            // Covers:  *_front_image_id  *_front_image  flipbox_front*
            //          tmw_front*  front_image  _front_image  etc.
            $front_keys = [
                // _id suffix variants (original)
                'front_image_id',
                '_front_image_id',
                'model_front_image_id',
                '_model_front_image_id',
                'model_flipbox_front_image_id',
                '_model_flipbox_front_image_id',
                // plain (no _id suffix) — real panel keys
                'front_image',
                '_front_image',
                'flipbox_front_image',
                '_flipbox_front_image',
                'model_front_image',
                '_model_front_image',
                'tmw_front_image',
                '_tmw_front_image',
                'flipbox_front',
                '_flipbox_front',
                'front_img',
                '_front_img',
            ];
            foreach ( $front_keys as $meta_key ) {
                foreach ( self::extract_attachment_ids( get_post_meta( $post_id, $meta_key, true ) ) as $id ) {
                    $add( $id, 'front', $meta_key );
                }
            }

            // ── Flipbox back ───────────────────────────────────────────────
            $back_keys = [
                // _id suffix variants (original)
                'back_image_id',
                '_back_image_id',
                'model_back_image_id',
                '_model_back_image_id',
                'model_flipbox_back_image_id',
                '_model_flipbox_back_image_id',
                // plain (no _id suffix) — real panel keys
                'back_image',
                '_back_image',
                'flipbox_back_image',
                '_flipbox_back_image',
                'model_back_image',
                '_model_back_image',
                'tmw_back_image',
                '_tmw_back_image',
                'flipbox_back',
                '_flipbox_back',
                'back_img',
                '_back_img',
            ];
            foreach ( $back_keys as $meta_key ) {
                foreach ( self::extract_attachment_ids( get_post_meta( $post_id, $meta_key, true ) ) as $id ) {
                    $add( $id, 'back', $meta_key );
                }
            }

            // ── Wildcard scan — any remaining image-related meta keys ──────
            //
            // BUG FIX (v2.1): previous code assigned 'secondary' to EVERY
            // unknown image-related key, including keys that clearly name
            // 'front' or 'back' in their segments.  We now split the key on
            // common delimiters and derive the role from its word parts:
            //
            //   key contains segment 'front'  → role front
            //   key contains segment 'back'   → role back
            //   key contains segment 'banner' → role banner
            //   otherwise                     → role secondary
            //
            // This guarantees that ANY key saved by the Flipbox panel
            // (regardless of prefix/suffix naming) receives the correct role.
            $known_keys = array_merge( $banner_keys, $front_keys, $back_keys );
            $all_meta   = get_post_meta( $post_id );
            if ( is_array( $all_meta ) ) {
                foreach ( $all_meta as $meta_key => $values ) {
                    if ( in_array( $meta_key, $known_keys, true ) ) {
                        continue;
                    }
                    $key = strtolower( (string) $meta_key );
                    if ( ! preg_match( '/(image|banner|front|back|photo)/', $key ) ) {
                        continue;
                    }
                    if ( ! is_array( $values ) ) {
                        continue;
                    }

                    // Derive role from key word-segments (split on _, -, space).
                    $wildcard_role = self::role_from_key_segments( $key );

                    foreach ( $values as $value ) {
                        foreach ( self::extract_attachment_ids( $value ) as $id ) {
                            $add( $id, $wildcard_role, $meta_key );
                        }
                    }
                }
            }
        }

        // Filter to verified image attachments only.
        $filtered = [];
        foreach ( $result as $id => $entry ) {
            if ( $id > 0 && wp_attachment_is_image( $id ) ) {
                $filtered[ $id ] = $entry;
            }
        }

        return $filtered;
    }

    /**
     * Backward-compat shim used internally.
     * Returns a plain int[] of IDs preserving role order.
     *
     * @return int[]
     */
    private static function get_post_image_attachments_with_roles( \WP_Post $post ): array {
        return self::get_attachments_with_roles( $post );
    }

    /**
     * Derives an image role from the word-segments of a meta key.
     *
     * The key is split on underscores, hyphens, and spaces; if any segment
     * matches a role indicator word, that role is returned.  This ensures
     * that any custom Flipbox panel key (e.g. "flipbox_front_image",
     * "tmw_back_img", "model_banner_photo") gets the correct role even when
     * the key name was not anticipated in the hardcoded lists.
     *
     * Priority: front > back > banner > secondary
     *
     * @param  string $lower_key  Meta key already lowercased by caller.
     * @return string  primary|banner|front|back|secondary
     */
    private static function role_from_key_segments( string $lower_key ): string {
        $segments = preg_split( '/[_\-\s]+/', $lower_key, -1, PREG_SPLIT_NO_EMPTY );
        if ( in_array( 'front', $segments, true ) ) {
            return 'front';
        }
        if ( in_array( 'back', $segments, true ) ) {
            return 'back';
        }
        if ( in_array( 'banner', $segments, true ) ) {
            return 'banner';
        }
        return 'secondary';
    }

    // ── Text generation ────────────────────────────────────────────────────

    /**
     * Builds SFW, SEO-friendly alt/title/caption/description for an image,
     * differentiated by role for model posts.
     *
     * Model role templates
     * ─────────────────────────────────────────────────────────────────────
     *   primary  →  "{Name} — verified live webcam model profile photo"
     *   banner   →  "{Name} — live webcam model banner image"
     *   front    →  "{Name} {kw1} profile preview image"
     *   back     →  "{Name} {kw2} profile preview image"
     *   secondary→  "{Name} — live webcam model image"
     *
     * @param  \WP_Post $attachment
     * @param  \WP_Post $parent_post
     * @param  string   $role
     * @return array{alt:string,title:string,caption:string,description:string}
     */
    private static function build_meta_text( \WP_Post $attachment, \WP_Post $parent_post, string $role ): array {
        $site_name  = get_bloginfo( 'name' );
        $post_title = trim( strip_tags( $parent_post->post_title ) );
        $file_title = preg_replace( '/\.[^.]+$/', '', (string) $attachment->post_name );
        $base       = $post_title ?: ucwords( str_replace( [ '-', '_' ], ' ', $file_title ) );

        $is_video = self::is_video_post( $parent_post );
        $is_model = ( $parent_post->post_type === 'model' );

        if ( $is_model ) {

            switch ( $role ) {

                // ── Banner ─────────────────────────────────────────────────
                case 'banner':
                    $alt         = sprintf( '%s — live webcam model banner image', $base );
                    $title       = sprintf( '%s | Live Cam Model | %s', $base, $site_name );
                    $caption     = sprintf( 'Banner image for %s, live webcam model on %s', $base, $site_name );
                    $description = sprintf(
                        'Profile banner for %s, a live cam model on %s. Browse model profile, shows, and streaming schedule.',
                        $base,
                        $site_name
                    );
                    break;

                // ── Flipbox front ──────────────────────────────────────────
                case 'front':
                    $kws      = self::resolve_secondary_keywords( (int) $parent_post->ID );
                    $kw_front = $kws[0] ?? 'live cam';
                    $alt      = sprintf( '%s %s profile preview image', $base, $kw_front );
                    $title    = sprintf( '%s | Profile Preview | %s', $base, $site_name );
                    $caption  = sprintf( 'Profile preview card for %s, live webcam model on %s', $base, $site_name );
                    $description = sprintf(
                        'Preview card for %s\'s profile on %s. Shows live cam availability and model details.',
                        $base,
                        $site_name
                    );
                    break;

                // ── Flipbox back ───────────────────────────────────────────
                case 'back':
                    $kws          = self::resolve_secondary_keywords( (int) $parent_post->ID );
                    $kw_front_ref = $kws[0] ?? '';
                    $kw_back      = $kws[1] ?? $kws[0] ?? 'webcam chat';
                    // Safety: if back keyword would duplicate front, use a static fallback.
                    if ( $kw_back === '' || strcasecmp( $kw_back, $kw_front_ref ) === 0 ) {
                        $kw_back = 'webcam chat';
                    }
                    $alt     = sprintf( '%s %s profile preview image', $base, $kw_back );
                    $title   = sprintf( '%s | Webcam Model Info | %s', $base, $site_name );
                    $caption = sprintf( 'Profile detail card for %s, live cam model on %s', $base, $site_name );
                    $description = sprintf(
                        'Info card for %s\'s profile on %s. Includes platform links and streaming schedule.',
                        $base,
                        $site_name
                    );
                    break;

                // ── Wildcard / secondary ───────────────────────────────────
                case 'secondary':
                    $alt         = sprintf( '%s — live webcam model image', $base );
                    $title       = sprintf( '%s | %s', $base, $site_name );
                    $caption     = sprintf( 'Image from %s\'s profile on %s', $base, $site_name );
                    $description = sprintf( 'Profile image for %s, a live cam model on %s.', $base, $site_name );
                    break;

                // ── Primary / header / profile ─────────────────────────────
                case 'primary':
                default:
                    $alt         = sprintf( '%s — verified live webcam model profile photo', $base );
                    $title       = sprintf( '%s | Live Cam Model | %s', $base, $site_name );
                    $caption     = sprintf( 'Profile photo of %s, live webcam model on %s', $base, $site_name );
                    $description = sprintf(
                        'Featured profile image for %s, a live cam model available on %s. Browse model profile, shows, and streaming schedule.',
                        $base,
                        $site_name
                    );
                    break;
            }

        } elseif ( $is_video ) {
            $alt         = sprintf( '%s — webcam show screenshot', $base );
            $title       = sprintf( '%s | Webcam Show | %s', $base, $site_name );
            $caption     = sprintf( 'Screenshot from %s\'s webcam show on %s', $base, $site_name );
            $description = sprintf( 'Preview image for "%s", a live cam show on %s.', $base, $site_name );

        } else {
            // Generic fallback.
            $alt         = sprintf( '%s — %s', $base, $site_name );
            $title       = sprintf( '%s | %s', $base, $site_name );
            $caption     = $base;
            $description = sprintf( 'Image related to %s on %s.', $base, $site_name );
        }

        return [
            'alt'         => sanitize_text_field( $alt ),
            'title'       => sanitize_text_field( $title ),
            'caption'     => sanitize_text_field( $caption ),
            'description' => sanitize_text_field( $description ),
        ];
    }

    // ── Secondary keyword resolution ───────────────────────────────────────

    /**
     * Builds a prioritised, deduped list of short SEO-safe keyword phrases
     * for use in Flipbox front / back alt text.
     *
     * Priority order:
     *   1. Primary platform label from tmw_keyword_pack['platforms'][0].
     *   2. tmw_keyword_pack['additional'][0] and [1].
     *   3. rank_math_focus_keyword.
     *   4. First post tag name.
     *   5. Static fallbacks: 'live cam', 'webcam chat', 'profile preview', 'cam show'.
     *
     * @param  int      $post_id
     * @return string[]
     */
    private static function resolve_secondary_keywords( int $post_id ): array {
        $candidates = [];

        $pack = get_post_meta( $post_id, 'tmw_keyword_pack', true );
        if ( is_array( $pack ) ) {
            if ( ! empty( $pack['platforms'] ) && is_array( $pack['platforms'] ) ) {
                $slug  = sanitize_key( (string) ( $pack['platforms'][0] ?? '' ) );
                $label = self::platform_label( $slug );
                if ( $label !== '' ) {
                    $candidates[] = $label;
                }
            }
            if ( ! empty( $pack['additional'] ) && is_array( $pack['additional'] ) ) {
                foreach ( array_slice( $pack['additional'], 0, 2 ) as $kw ) {
                    $kw = sanitize_text_field( (string) $kw );
                    if ( $kw !== '' ) {
                        $candidates[] = $kw;
                    }
                }
            }
        }

        $focus_kw = sanitize_text_field( (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true ) );
        if ( $focus_kw !== '' ) {
            $candidates[] = $focus_kw;
        }

        if ( function_exists( 'get_the_tags' ) ) {
            $tags = get_the_tags( $post_id );
            if ( is_array( $tags ) && ! empty( $tags ) ) {
                $tag_name = sanitize_text_field( (string) ( $tags[0]->name ?? '' ) );
                if ( $tag_name !== '' ) {
                    $candidates[] = $tag_name;
                }
            }
        }

        $candidates[] = 'live cam';
        $candidates[] = 'webcam chat';
        $candidates[] = 'profile preview';
        $candidates[] = 'cam show';

        $seen = [];
        $out  = [];
        foreach ( $candidates as $c ) {
            $key = strtolower( $c );
            if ( ! isset( $seen[ $key ] ) ) {
                $seen[ $key ] = true;
                $out[]        = $c;
            }
        }

        return $out;
    }

    /**
     * Maps a platform slug to a human-readable label for alt text.
     */
    private static function platform_label( string $slug ): string {
        static $map = [
            'livejasmin'  => 'LiveJasmin',
            'stripchat'   => 'Stripchat',
            'myfreecams'  => 'MyFreeCams',
            'camsoda'     => 'CamSoda',
            'cam4'        => 'CAM4',
            'chaturbate'  => 'Chaturbate',
            'bonga'       => 'Bonga',
            'bongacams'   => 'BongaCams',
            'streamate'   => 'Streamate',
            'flirt4free'  => 'Flirt4Free',
            'imlive'      => 'ImLive',
        ];

        if ( isset( $map[ $slug ] ) ) {
            return $map[ $slug ];
        }

        $slug = trim( str_replace( [ '-', '_' ], ' ', $slug ) );
        return $slug !== '' ? ucwords( $slug ) : '';
    }

    // ── v1 pattern detection ───────────────────────────────────────────────

    private static function is_v1_generated_text( string $alt, string $title ): bool {
        return self::matches_v1_alt( $alt ) || self::matches_v1_model_title( $title );
    }

    private static function matches_v1_alt( string $alt ): bool {
        return str_ends_with( $alt, '— verified live webcam model profile photo' );
    }

    private static function matches_v1_model_title( string $title ): bool {
        return str_contains( $title, '| Live Cam Model |' );
    }

    private static function matches_v1_caption( string $caption ): bool {
        return str_starts_with( $caption, 'Profile photo of ' )
            && str_contains( $caption, ', live webcam model on ' );
    }

    /**
     * Detects the v1 model description pattern.
     * In v1 every model image — regardless of role — received:
     *   "Featured profile image for {Name}, a live cam model available on {Site}…"
     * This allows Flipbox images that still carry the wrong description to be
     * upgraded to role-specific v2 text on the next post save.
     */
    private static function matches_v1_description( string $desc ): bool {
        return str_starts_with( $desc, 'Featured profile image for ' )
            && str_contains( $desc, 'a live cam model available on ' );
    }

    // ── Misc helpers ───────────────────────────────────────────────────────

    private static function is_video_post( \WP_Post $post ): bool {
        $video_types = [ 'video', 'tmw_video', 'livejasmin_video' ];
        return in_array( $post->post_type, $video_types, true );
    }

    private static function sanitise_role( string $role ): string {
        static $valid = [ 'primary', 'banner', 'front', 'back', 'secondary' ];
        return in_array( $role, $valid, true ) ? $role : 'primary';
    }

    /**
     * Recursively extracts integer attachment IDs from a meta value.
     *
     * Handles:
     *   - plain integer or numeric string
     *   - array keyed with 'ID' or 'id'  (ACF / custom metabox array format)
     *   - generic array (recurses into values, skips URL/path strings)
     *   - serialised PHP value
     *   - JSON array / object string
     *
     * @param  mixed  $value
     * @return int[]
     */
    private static function extract_attachment_ids( $value ): array {
        if ( is_numeric( $value ) ) {
            return [ (int) $value ];
        }

        if ( is_array( $value ) ) {
            // Explicit 'ID' or 'id' key — ACF image field / array-format custom metabox.
            foreach ( [ 'ID', 'id' ] as $id_key ) {
                if ( isset( $value[ $id_key ] ) && is_numeric( $value[ $id_key ] ) ) {
                    return [ (int) $value[ $id_key ] ];
                }
            }
            // Generic array — recurse into values, skip URL/path strings to avoid
            // false digit extraction from paths like /wp-content/uploads/2024/01/photo.jpg
            $out = [];
            foreach ( $value as $item ) {
                if ( is_string( $item ) && ! is_numeric( $item ) && str_contains( $item, '/' ) ) {
                    continue;
                }
                $out = array_merge( $out, self::extract_attachment_ids( $item ) );
            }
            return $out;
        }

        if ( ! is_string( $value ) ) {
            return [];
        }

        $raw = trim( $value );
        if ( $raw === '' ) {
            return [];
        }

        if ( is_serialized( $raw ) ) {
            $decoded = maybe_unserialize( $raw );
            if ( $decoded !== $raw ) {
                return self::extract_attachment_ids( $decoded );
            }
        }

        if ( strpos( $raw, '[' ) === 0 || strpos( $raw, '{' ) === 0 ) {
            $json = json_decode( $raw, true );
            if ( is_array( $json ) ) {
                return self::extract_attachment_ids( $json );
            }
        }

        // Only accept plain numeric strings — do NOT regex-extract digits from
        // URLs or other structured strings to avoid false ID matches.
        if ( ctype_digit( ltrim( $raw, ' ' ) ) ) {
            return [ (int) $raw ];
        }

        return [];
    }
}
