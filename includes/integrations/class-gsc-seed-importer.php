<?php
namespace TMWSEO\Engine\Integrations;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\Keywords\KeywordValidator;
use TMWSEO\Engine\Keywords\SeedRegistry;
use TMWSEO\Engine\Keywords\ExpansionCandidateRepository;
use TMWSEO\Engine\Services\Settings;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * GSC Seed Importer — imports real search queries from Google Search Console.
 *
 * As of 4.3.0 GSC queries are treated as real-signal phrases and go to the
 * preview layer with status=fast_track. They do NOT write directly to tmwseo_seeds.
 *
 * Fast-track means they appear at the top of the review queue with lighter
 * scrutiny expected, but an operator still confirms them before they reach
 * the working keyword layer.
 *
 * Rationale: GSC queries are real traffic, not synthetic combinatorics, so
 * they deserve a lighter review burden — but not automatic trust, since brand
 * misspellings, unrelated queries, and NSFW variations still occur.
 */
class GSCSeedImporter {
    const HOOK            = 'tmwseo_gsc_seed_import_weekly';
    const LIMIT           = 100;
    const MIN_IMPRESSIONS = 10;

    /** @var string[] */
    private static array $hard_reject_fragments = [
        'download',
        'reddit',
        'pornhub',
        'xvideos',
        'xnxx',
    ];

    public static function init(): void {
        add_filter( 'cron_schedules', [ __CLASS__, 'add_schedules' ] );
        add_action( self::HOOK, [ __CLASS__, 'run' ] );
    }

    public static function add_schedules( array $schedules ): array {
        if ( ! isset( $schedules['tmwseo_weekly'] ) ) {
            $schedules['tmwseo_weekly'] = [
                'interval' => 7 * DAY_IN_SECONDS,
                'display'  => 'Weekly (TMW SEO Engine)',
            ];
        }
        return $schedules;
    }

    public static function schedule(): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 1800, 'tmwseo_weekly', self::HOOK );
        }
    }

    public static function unschedule(): void {
        wp_clear_scheduled_hook( self::HOOK );
    }

    public static function run(): array {
        $site_url = trim( (string) Settings::get( 'gsc_site_url', '' ) );
        if ( ! GSCApi::is_connected() || $site_url === '' ) {
            Logs::warn( 'gsc', '[TMW-SEO-AUTO] GSC seed import skipped (not connected or site URL missing)' );
            return [ 'imported' => 0, 'duplicates_skipped' => 0, 'checked' => 0 ];
        }

        $end_date   = gmdate( 'Y-m-d' );
        $start_date = gmdate( 'Y-m-d', strtotime( '-90 days' ) );

        $res = GSCApi::search_analytics( $site_url, $start_date, $end_date, [ 'query' ], 5000 );
        if ( empty( $res['ok'] ) ) {
            Logs::warn( 'gsc', '[TMW-SEO-AUTO] GSC seed import failed', [ 'error' => (string) ( $res['error'] ?? 'unknown' ) ] );
            return [ 'imported' => 0, 'duplicates_skipped' => 0, 'checked' => 0 ];
        }

        $imported   = 0;
        $duplicates = 0;
        $checked    = 0;
        $seen       = [];
        $candidates = [];

        foreach ( (array) ( $res['rows'] ?? [] ) as $row ) {
            if ( $imported >= self::LIMIT ) {
                break;
            }

            $impressions = (int) ( $row['impressions'] ?? 0 );
            if ( $impressions < self::MIN_IMPRESSIONS ) {
                continue;
            }

            $query = trim( (string) ( $row['keys'][0] ?? '' ) );
            if ( $query === '' ) {
                continue;
            }

            $checked++;

            $normalized = SeedRegistry::normalize_seed( $query );
            if ( $normalized === '' || isset( $seen[ $normalized ] ) ) {
                continue;
            }
            $seen[ $normalized ] = true;

            if ( self::is_hard_reject( $normalized ) ) {
                continue;
            }

            $reason = null;
            if ( ! KeywordValidator::is_relevant( $normalized, $reason ) ) {
                continue;
            }

            // Dedup against existing trusted seeds
            if ( SeedRegistry::seed_exists( $normalized ) ) {
                $duplicates++;
                continue;
            }

            $candidates[] = [
                'phrase'      => $normalized,
                'impressions' => $impressions,
                'clicks'      => (int) ( $row['clicks'] ?? 0 ),
                'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
            ];
            $imported++;
        }

        // Batch-insert to preview layer with status=fast_track.
        $inserted_count = 0;
        if ( ! empty( $candidates ) ) {
            $batch_id = ExpansionCandidateRepository::make_batch_id( 'gsc' );
            foreach ( $candidates as $c ) {
                $ok = SeedRegistry::register_candidate_phrase(
                    $c['phrase'],
                    'gsc',
                    'gsc_search_analytics',
                    'system',
                    0,
                    $batch_id,
                    [
                        'impressions' => $c['impressions'],
                        'clicks'      => $c['clicks'],
                        'position'    => $c['position'],
                        'window_days' => 90,
                        'site_url'    => $site_url,
                    ]
                );
                if ( $ok ) {
                    $inserted_count++;
                } else {
                    $duplicates++;
                }
            }
        }

        Logs::info( 'gsc', '[TMW-SEO-AUTO] GSC phrases → preview layer (fast_track)', [
            'gsc_candidates_queued' => $inserted_count,
            'duplicates_skipped'    => $duplicates,
            'checked'               => $checked,
            'limit'                 => self::LIMIT,
            'window_days'           => 90,
            'min_impressions'       => self::MIN_IMPRESSIONS,
        ] );

        /**
         * Fires after seed import completes so downstream keyword discovery layers
         * can optionally run expansion automation.
         */
        do_action( 'tmwseo_seed_import_completed', [
            'source' => 'gsc',
            'imported' => $inserted_count,
            'checked' => $checked,
        ] );

        return [
            'imported'           => $inserted_count,
            'duplicates_skipped' => $duplicates,
            'checked'            => $checked,
        ];
    }

    private static function is_hard_reject( string $query ): bool {
        foreach ( self::$hard_reject_fragments as $fragment ) {
            if ( strpos( $query, $fragment ) !== false ) {
                return true;
            }
        }
        return false;
    }
}
