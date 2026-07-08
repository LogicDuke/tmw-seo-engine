<?php
/**
 * TMW SEO Engine — Model Page Cache Purge v1.0.1
 *
 * Purges all WordPress page cache and Cloudflare cache entries for the
 * 11 indexed model pages and the /models/ archive after running the
 * metadata repair.
 *
 * Run this AFTER: wp tmwseo repair-model-title-meta
 *
 * Usage:
 *   wp eval-file tools/tmw-model-cache-purge-v1.0.1.php
 *
 * What it does:
 *   1. Calls clean_post_cache() for each model post ID
 *   2. Calls wp_cache_delete() on the object cache key
 *   3. Calls Cloudflare cache purge via wp_remote_post() if CF credentials exist
 *   4. Outputs a curl verification command for each URL
 *
 * Cloudflare credentials required (optional):
 *   define('TMW_CF_ZONE_ID', 'your-zone-id');
 *   define('TMW_CF_API_TOKEN', 'your-api-token');
 *   (Add to wp-config.php — never commit to the repo)
 */

if ( ! defined( 'ABSPATH' ) ) {
    die( 'Run via WP-CLI: wp eval-file tools/tmw-model-cache-purge-v1.0.1.php' );
}
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    die( 'This script requires WP-CLI.' );
}

// ── Target model slugs ─────────────────────────────────────────────────────

$tmw_model_slugs = [
    'abby-murray',
    'aisha-dupont',
    'alice-schuster',
    'allysa-quinn',
    'anisyia',
    'arianna',
    'brook-hayes',
    'hana-ross',
    'julieta-montesco',
    'lexy-ness',
    'mia-collie',
];

$tmw_purge_urls  = [];
$tmw_purge_ids   = [];
$tmw_not_found   = [];

\WP_CLI::log( '' );
\WP_CLI::log( 'TMW Cache Purge v1.0.1 -- Model pages' );
\WP_CLI::log( str_repeat( '-', 60 ) );

// ── Resolve post IDs and URLs ──────────────────────────────────────────────

foreach ( $tmw_model_slugs as $slug ) {
    $post = get_page_by_path( $slug, OBJECT, 'model' );
    if ( ! $post instanceof \WP_Post ) {
        \WP_CLI::warning( "[TMW-PURGE] NOT FOUND: post_type=model slug={$slug}" );
        $tmw_not_found[] = $slug;
        continue;
    }
    $tmw_purge_ids[]  = (int) $post->ID;
    $tmw_purge_urls[] = get_permalink( $post->ID );
    \WP_CLI::log( "[TMW-PURGE] Resolved: post_id={$post->ID} slug={$slug}" );
}

// Also include /models/ archive
$models_page = get_page_by_path( 'models' );
if ( $models_page instanceof \WP_Post ) {
    $tmw_purge_ids[]  = (int) $models_page->ID;
    $tmw_purge_urls[] = get_permalink( $models_page->ID );
    \WP_CLI::log( "[TMW-PURGE] Resolved: models archive page id={$models_page->ID}" );
}

// ── WordPress object cache purge ───────────────────────────────────────────

\WP_CLI::log( '' );
\WP_CLI::log( '-- WordPress object cache purge --' );

foreach ( $tmw_purge_ids as $pid ) {
    clean_post_cache( $pid );
    wp_cache_delete( $pid, 'posts' );
    wp_cache_delete( $pid, 'post_meta' );
    \WP_CLI::log( "[TMW-PURGE] clean_post_cache: post_id={$pid}" );
}

// ── WP Super Cache / W3TC / LiteSpeed / WP Rocket compat ─────────────────

if ( function_exists( 'wp_cache_post_change' ) ) {
    foreach ( $tmw_purge_ids as $pid ) {
        wp_cache_post_change( $pid );
    }
    \WP_CLI::log( '[TMW-PURGE] wp_cache_post_change: called for all model posts' );
}

if ( function_exists( 'rocket_clean_post' ) ) {
    foreach ( $tmw_purge_ids as $pid ) {
        rocket_clean_post( $pid );
    }
    \WP_CLI::log( '[TMW-PURGE] rocket_clean_post: called for all model posts' );
}

if ( function_exists( 'w3tc_pgcache_flush_post' ) ) {
    foreach ( $tmw_purge_ids as $pid ) {
        w3tc_pgcache_flush_post( $pid );
    }
    \WP_CLI::log( '[TMW-PURGE] w3tc_pgcache_flush_post: called for all model posts' );
}

if ( function_exists( 'lscache_purge_post' ) ) {
    foreach ( $tmw_purge_ids as $pid ) {
        do_action( 'litespeed_purge_post', $pid );
    }
    \WP_CLI::log( '[TMW-PURGE] litespeed_purge_post: called for all model posts' );
}

// ── Cloudflare cache purge ─────────────────────────────────────────────────

\WP_CLI::log( '' );
\WP_CLI::log( '-- Cloudflare cache purge --' );

$cf_zone  = defined( 'TMW_CF_ZONE_ID' )    ? TMW_CF_ZONE_ID    : '';
$cf_token = defined( 'TMW_CF_API_TOKEN' )  ? TMW_CF_API_TOKEN  : '';

if ( $cf_zone === '' || $cf_token === '' ) {
    \WP_CLI::warning(
        '[TMW-PURGE] Cloudflare credentials not set. '
        . 'Define TMW_CF_ZONE_ID and TMW_CF_API_TOKEN in wp-config.php to enable automatic Cloudflare purge.'
    );
    \WP_CLI::log( '[TMW-PURGE] Manual Cloudflare purge: go to Cloudflare > Caching > Purge Cache > Custom Purge, and paste the URLs below.' );
} else {
    // Purge in batches of 30 (Cloudflare max per request)
    $tmw_url_chunks = array_chunk( $tmw_purge_urls, 30 );
    foreach ( $tmw_url_chunks as $chunk ) {
        $response = wp_remote_post(
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

        if ( is_wp_error( $response ) ) {
            \WP_CLI::warning( '[TMW-PURGE] Cloudflare purge request failed: ' . $response->get_error_message() );
        } else {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $ok   = ! empty( $body['success'] );
            \WP_CLI::log( '[TMW-PURGE] Cloudflare purge: ' . ( $ok ? 'SUCCESS' : 'FAILED' ) . ' (' . count( $chunk ) . ' URLs)' );
            if ( ! $ok && ! empty( $body['errors'] ) ) {
                foreach ( (array) $body['errors'] as $err ) {
                    \WP_CLI::warning( '  CF error: ' . wp_json_encode( $err ) );
                }
            }
        }
    }
}

// ── Curl verification commands ──────────────────────────────────────────────

\WP_CLI::log( '' );
\WP_CLI::log( '-- Curl verification commands (run from terminal to confirm live title/meta) --' );
foreach ( $tmw_purge_urls as $url ) {
    $clean_url = rtrim( $url, '/' ) . '/';
    \WP_CLI::log( "curl -s -L '{$clean_url}' | grep -E '<title>|<meta name=\"description\"'" );
}

// ── Summary ────────────────────────────────────────────────────────────────

\WP_CLI::log( '' );
\WP_CLI::log( str_repeat( '-', 60 ) );
\WP_CLI::success( sprintf(
    '[TMW-PURGE] Done. Purged=%d NotFound=%d',
    count( $tmw_purge_ids ),
    count( $tmw_not_found )
) );
\WP_CLI::log( '' );
\WP_CLI::log( 'Next steps:' );
\WP_CLI::log( '  1. Run the curl commands above to confirm live <title> and <meta description> are updated.' );
\WP_CLI::log( '  2. If titles are still stale, check AWEmpire cache settings in WP Admin.' );
\WP_CLI::log( '  3. Re-submit updated URLs in Google Search Console > URL Inspection > Request Indexing.' );
