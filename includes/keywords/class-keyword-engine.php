<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;
use TMWSEO\Engine\DiscoveryGovernor;
use TMWSEO\Engine\KeywordIntelligence\KeywordDatabase;
use TMWSEO\Engine\KeywordIntelligence\KeywordClassifier;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Keywords\ExpansionCandidateRepository;

if (!defined('ABSPATH')) { exit; }

class KeywordEngine {

    public static function init(): void {
        // Register admin notice for queue-full condition.
        if ( is_admin() ) {
            add_action( 'admin_notices', [ __CLASS__, 'maybe_show_queue_full_notice' ] );
        }
    }

    /**
     * Show a persistent admin notice when the keyword review queue is full.
     * This makes the "engine runs but nothing progresses" problem visible to operators.
     */
    public static function maybe_show_queue_full_notice(): void {
        $since = (int) get_option( 'tmwseo_kw_queue_full_since', 0 );
        if ( $since <= 0 ) {
            return;
        }

        // Only show on TMW SEO Engine admin pages.
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && strpos( (string) $screen->id, 'tmwseo' ) === false ) {
            return;
        }

        $minutes_full = (int) round( ( time() - $since ) / 60 );
        $queue_url    = esc_url( admin_url( 'admin.php?page=tmwseo-keyword-command-center' ) );
        $cap          = self::get_review_queue_cap();

        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>' . esc_html__( '⚠ Keyword Review Queue Full', 'tmwseo' ) . '</strong> — ';
        echo esc_html( sprintf(
            /* translators: 1: minutes queue has been full, 2: cap number */
            __( 'The keyword discovery pipeline has been running but results are being discarded because the review queue is at capacity (%2$d items) for the past %1$d min.', 'tmwseo' ),
            $minutes_full,
            $cap
        ) );
        echo ' <a href="' . $queue_url . '">' . esc_html__( 'Review &amp; approve keywords →', 'tmwseo' ) . '</a></p>';
        echo '</div>';
    }

    /**
     * Main keyword cycle job — called by the job queue worker.
     *
     * PHASES:
     *   Phase 1 — Seed collection + discovery expansion (DataForSEO / Google KP / Trends)
     *   Phase 2 — (reserved: was secondary expansion, now inline)
     *   Phase 3 — KD refresh for candidates missing difficulty scores
     *   Phase 3b — Google Keyword Planner volume enrichment pass
     *   Phase 4 — Promote scored keywords into the human review queue
     *   Phase 5 — Incremental clustering + page suggestion projection
     *
     * Each phase is clearly labelled inline. Phases 3–5 are delegated to
     * self::phase_kd_refresh(), self::phase_gkp_enrichment(), and
     * self::phase_review_promotion() + self::run_cluster_projection_steps()
     * to keep this method navigable.
     */
    public static function run_cycle_job(array $job): void {
        global $wpdb;

        Logs::info('keywords', 'Keyword cycle started', ['job_id' => $job['id'] ?? null]);

        if (!DiscoveryGovernor::is_discovery_allowed()) {
            self::record_stop_reason('discovery_governor_blocked', ['job_id' => $job['id'] ?? null]);
            return;
        }

        self::update_cycle_metrics([
            'last_stop_reason' => '',
            'last_stop_reason_at' => 0,
        ]);

        $payload = $job['payload'] ?? [];
        if (!is_array($payload)) $payload = [];
        $mode = (string)($payload['mode'] ?? 'full');


        // DataForSEO is no longer a hard prerequisite for the whole cycle.
        // Availability is handled per-provider inside the aggregator:
        //   - DataForSEOKeywordIdeaProvider::is_available() gates on is_configured() + budget.
        //   - Google KP and Google Trends run independently when DataForSEO is absent.
        // KD refresh calls DataForSEO::bulk_keyword_difficulty() which returns ok=>false
        // gracefully when credentials are missing or budget is exceeded.
        // DataForSEO status is informational only — the cycle continues via
        // other providers.  Do NOT call record_stop_reason() here: it would
        // pollute last_stop_reason with a non-terminal condition that later
        // branches may never overwrite (the cause of the stale-no_seeds bug).
        if ( ! DataForSEO::is_configured() ) {
            Logs::info( 'keywords', '[TMW-KW] DataForSEO not configured — discovery will use Google KP / Google Trends only; KD refresh skipped.' );
        } elseif ( DataForSEO::is_over_budget() ) {
            $budget = DataForSEO::get_monthly_budget_stats();
            Logs::warn( 'keywords', '[TMW-KW] DataForSEO monthly budget exceeded — discovery continues via other providers; KD refresh skipped.', [
                'budget_usd'    => $budget['budget_usd'] ?? 0,
                'spent_usd'     => $budget['spent_usd'] ?? 0,
                'remaining_usd' => $budget['remaining_usd'] ?? 0,
            ] );
        }

        $job_started = microtime(true);

        $lock_key        = 'tmw_keyword_cycle_lock'; // stored directly in wp_options (not transients)
        $lock_ttl        = 10 * MINUTE_IN_SECONDS;
        $lock_acquired   = false;

        // ── Atomic lock acquisition ────────────────────────────────────────
        // Strategy: INSERT IGNORE for first acquisition, then CAS-UPDATE for
        // stale-lock takeover. A single DB round-trip per path prevents the
        // TOCTOU race of get-then-set that transients suffer from.
        global $wpdb;
        $now = time();

        $existing_lock = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $lock_key
        ) );

        if ( $existing_lock === null ) {
            // No lock row — attempt INSERT IGNORE (atomic).
            $inserted = (int) $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $lock_key, (string) $now
            ) );
            $lock_acquired = ( $inserted > 0 );
        } elseif ( ( $now - (int) $existing_lock ) > $lock_ttl ) {
            // Stale lock — CAS-UPDATE: only succeeds if the value we read is
            // still the current value (another process hasn't already taken it).
            $updated = (int) $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                (string) $now, $lock_key, (string) $existing_lock
            ) );
            if ( $updated > 0 ) {
                Logs::warn( 'keywords', '[TMW-KW] Stale keyword cycle lock taken over (CAS)', [
                    'stale_age_seconds' => $now - (int) $existing_lock,
                ] );
                $lock_acquired = true;
            }
        }

        if ( ! $lock_acquired ) {
            $lock_age = $existing_lock !== null ? ( $now - (int) $existing_lock ) : 0;
            Logs::warn( 'keywords', '[TMW-KW] Keyword cycle skipped — lock held by another process', [
                'lock_age_seconds' => $lock_age,
                'lock_ttl_seconds' => $lock_ttl,
            ] );
            self::record_stop_reason( 'active_lock', [
                'lock_age_seconds' => $lock_age,
                'lock_key'         => $lock_key,
            ] );
            return;
        }

        $breaker = get_option('tmw_keyword_engine_breaker', []);

        if (!empty($breaker['last_triggered'])) {

            $cooldown_seconds = 15 * MINUTE_IN_SECONDS;
            $cooldown_until = (int)$breaker['last_triggered'] + $cooldown_seconds;

            if (time() < $cooldown_until) {
                Logs::warn('keywords', 'Breaker cooldown active, skipping execution', [
                    'cooldown_until' => $cooldown_until,
                ]);
                delete_transient($lock_key);
                self::record_stop_reason('breaker_cooldown', ['cooldown_until' => $cooldown_until]);
                return;
            }
        }

        $min_volume = (int) Settings::get('keyword_min_volume', 30);
        $max_kd     = (float) Settings::get('keyword_max_kd', 60);
        $new_limit  = (int) Settings::get('keyword_new_limit', 300);
        $kd_limit   = (int) Settings::get('keyword_kd_batch_limit', 300);
        $pages_per_day = (int) Settings::get('keyword_pages_per_day', 3);

        $raw_table = $wpdb->prefix . 'tmw_keyword_raw';
        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';
        $cluster_keyword_map_table = $wpdb->prefix . 'tmw_keyword_cluster_map';

        $cluster_map_table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $cluster_keyword_map_table)) === $cluster_keyword_map_table;

        QueryExpansionGraph::create_table();

        // 1) Collect seeds (adaptive, mix of base + your top categories).
        //    Mode 'import_only' skips discovery and only runs KD + clustering + pages.
        $inserted = 0;
        $discovered_total = 0;
        $accepted_total = 0;
        $cycle_seeds = [];
        $seed_report = [
            'base_seeds' => 0,
            'static_seeds' => 0,
            'model_seeds' => 0,
            'tag_seeds' => 0,
            'video_seeds' => 0,
            'category_seeds' => 0,
            'competitor_seeds' => 0,
            'total_seeds' => 0,
        ];

        try {
            if ($mode !== 'import_only') {
                        $orchestrated = (array) ($job['orchestrated_discovery'] ?? []);
                        $orchestrated_seeds = array_values(array_filter(array_map('strval', (array) ($orchestrated['seeds'] ?? []))));
                        $orchestrated_entities = (array) ($orchestrated['entities'] ?? []);

                        if (!empty($orchestrated_seeds)) {
                            $seeds = $orchestrated_seeds;
                            $cycle_seeds = $orchestrated_seeds;
                            $entities = $orchestrated_entities;
                            $seed_report['orchestrated_seeds'] = count($orchestrated_seeds);
                            $seed_report['total_seeds'] = count($orchestrated_seeds);
                            $seed_report['total_entities'] = count($entities);
                        } else {
                            $seed_bundle = self::collect_seeds((int) Settings::get('keyword_seeds_per_run', 300));
                            $seeds = $seed_bundle['seeds'];
                            $cycle_seeds = $seeds;
                            $entities = (array) ($seed_bundle['entities'] ?? []);
                            $seed_report = $seed_bundle['counts'];
                        }
                        $max_seeds_per_run = (int) Settings::get('keyword_seed_batch_limit', 300);
                        $max_seeds_per_run = min(300, $max_seeds_per_run);
                        $adaptive = get_option('tmw_engine_adaptive_state', []);
                        $metrics = get_option('tmw_keyword_engine_metrics', []);

                        $now = time();
                        $recent_failures = $metrics['failures'] ?? 0;

                        $adaptive_active = false;

                        if (!empty($adaptive['reduced_until']) && $now < (int)$adaptive['reduced_until']) {
                            $adaptive_active = true;
                        }
                        elseif ($recent_failures > 2) {
                            // Activate reduced mode for 20 minutes
                            $adaptive = [
                                'mode' => 'reduced',
                                'reduced_until' => $now + (20 * MINUTE_IN_SECONDS),
                            ];
                            update_option('tmw_engine_adaptive_state', $adaptive);
                            $adaptive_active = true;
                        }
                        elseif (!empty($adaptive['reduced_until']) && $now >= (int)$adaptive['reduced_until']) {
                            // Recovery
                            delete_option('tmw_engine_adaptive_state');
                        }

                        if ($adaptive_active) {
                            $max_seeds_per_run = max(1, floor($max_seeds_per_run / 2));
                            Logs::warn('keywords', 'Adaptive throttling active', [
                                'adjusted_seed_limit' => $max_seeds_per_run,
                            ]);
                        }
                        $seeds = array_slice($seeds, 0, max(1, $max_seeds_per_run));
                        $seed_report['total_seeds'] = count($seeds);
                        $seed_report['total_entities'] = count($entities);
                        Logs::info('keywords', '[TMW-KW] Seed source counts', $seed_report);
                        Logs::info('keywords', 'Seeds', ['count' => count($seeds), 'seeds' => array_slice($seeds, 0, 10)]);

                        if (empty($seeds)) {
                            $registry_diag = SeedRegistry::diagnostics();
                            Logs::warn('keywords', '[TMW-KW] Seed list empty at expansion entry — diagnosing', [
                                'registry_total_seeds'   => (int) ($registry_diag['total_seeds'] ?? 0),
                                'registry_sources'       => $registry_diag['seed_sources'] ?? [],
                                'orchestrated_count'     => count($orchestrated_seeds ?? []),
                                'collect_seeds_fallback' => empty($orchestrated_seeds),
                                'discovery_enabled'      => (bool) ($registry_diag['discovery_enabled'] ?? false),
                            ]);
                            self::record_stop_reason('no_seeds', [
                                'registry_total'    => (int) ($registry_diag['total_seeds'] ?? 0),
                                'discovery_enabled' => (bool) ($registry_diag['discovery_enabled'] ?? false),
                            ]);
                        }
                        $failures = 0;
                        $max_failures = 3;
                        $max_secondary_expansions = 200;
                        $secondary_expansions = 0;
                        $expanded_seeds = [];
                        $queue = [];
                        $seeds_skipped_cooldown = 0;
                        $seeds_expanded_count = 0;
                        $duplicates_total = 0;
                        $cycle_spend_estimate = 0.0;

                        foreach ($seeds as $seed) {
                            $normalized_seed = KeywordValidator::normalize((string) $seed);
                            if ($normalized_seed === '' || isset($expanded_seeds[$normalized_seed])) {
                                continue;
                            }

                            $seed_entity_match = TopicEntityLayer::match_keyword_to_entities($normalized_seed, $entities);
                            if (empty($seed_entity_match['matched'])) {
                                Logs::debug('keywords', sprintf('Rejected keyword "%s" — no entity match.', $normalized_seed), [
                                    'keyword' => $normalized_seed,
                                    'similarity_score' => (float) ($seed_entity_match['similarity_score'] ?? 0),
                                    'minimum_similarity' => TopicEntityLayer::minimum_similarity(),
                                ]);
                                continue;
                            }

                            $queue[] = [
                                'keyword' => $normalized_seed,
                                'depth' => 0,
                                'entity_id' => (int) ($seed_entity_match['entity_id'] ?? 0),
                                'entity_name' => (string) ($seed_entity_match['entity_name'] ?? ''),
                                'entity_type' => (string) ($seed_entity_match['entity_type'] ?? 'authority_node'),
                                'similarity_score' => (float) ($seed_entity_match['similarity_score'] ?? 0),
                            ];
                            $expanded_seeds[$normalized_seed] = true;
                        }

                        for ($i = 0; $i < count($queue); $i++) {
                            if ($inserted >= $new_limit) {
                                break;
                            }

                            $node = $queue[$i];
                            $seed = (string) ($node['keyword'] ?? '');
                            $depth = (int) ($node['depth'] ?? 0);
                            $node_entity_id = (int) ($node['entity_id'] ?? 0);
                            $node_entity_name = (string) ($node['entity_name'] ?? '');
                            $node_entity_type = (string) ($node['entity_type'] ?? 'authority_node');
                            if ($seed === '' || $node_entity_id <= 0 || $node_entity_name === '') {
                                continue;
                            }

                            // ── Phase 1 fix: all seeds (including root depth=0) respect
                            //    the 30-day expansion cooldown. Previously only depth>0
                            //    seeds were gated, so root seeds were re-expanded every
                            //    cycle — the #1 cause of DataForSEO waste.
                            $cooldown_days = (int) Settings::get('seed_expansion_cooldown_days', 30);
                            if (QueryExpansionGraph::was_expanded_recently($seed, $cooldown_days)) {
                                $seeds_skipped_cooldown = ($seeds_skipped_cooldown ?? 0) + 1;
                                continue;
                            }

                            $res = self::fetch_seed_relationships($seed);
                            if (empty($res['ok'])) {
                                $failures++;
                                Logs::warn('keywords', 'DataForSEO failed', ['seed' => $seed]);
                                Logs::warn('keywords', 'DataForSEO keyword_suggestions/related_keywords failed', ['seed' => $seed, 'error' => $res['error'] ?? '']);

                                if (($res['error'] ?? '') === 'dataforseo_budget_exceeded') {
                                    Logs::warn('keywords', '[TMW-KW] Discovery halted — DataForSEO API budget exhausted', ['seed' => $seed]);
                                    self::record_stop_reason('dataforseo_budget_exceeded', ['seed' => $seed]);
                                    break;
                                }

                                if ($failures >= $max_failures) {
                                    Logs::error('keywords', '[TMW-KW] Circuit breaker triggered — too many consecutive DataForSEO failures', [
                                        'failure_count' => $failures,
                                        'seed'          => $seed,
                                    ]);

                                    update_option('tmw_keyword_engine_breaker', [
                                        'last_triggered' => time(),
                                        'failure_count'  => $failures,
                                    ]);

                                    self::record_stop_reason('circuit_breaker_triggered', [
                                        'failure_count' => $failures,
                                        'seed'          => $seed,
                                    ]);

                                    break;
                                }

                                usleep(250000);
                                continue;
                            }

                            QueryExpansionGraph::mark_expanded($seed);
                            $seeds_expanded_count++;
                            $failures = 0;
                            $seed_net_new = 0;
                            $seed_dupes = 0;

                            $items = (array) ($res['items'] ?? []);
                            $discovered_total += count($items);
                            $existing_map = [];
                            $lookup_keywords = [];
                            $relationships_created = 0;

                            foreach ($items as $it) {
                                if (!is_array($it)) {
                                    continue;
                                }
                                $kw = (string)($it['keyword'] ?? '');
                                if ($kw !== '') {
                                    $lookup_keywords[$kw] = true;
                                }

                                $relationship_type = (string) ($it['_tmw_relationship_type'] ?? 'suggestion');
                                if (QueryExpansionGraph::store_relationship($seed, $kw, $relationship_type)) {
                                    $relationships_created++;
                                }
                            }

                            $lookup_keywords = array_keys($lookup_keywords);
                            if (!empty($lookup_keywords)) {
                                $placeholders = implode(', ', array_fill(0, count($lookup_keywords), '%s'));
                                $existing_rows = $wpdb->get_results(
                                    $wpdb->prepare(
                                        "SELECT id, keyword FROM {$cand_table} WHERE keyword IN ({$placeholders})",
                                        ...$lookup_keywords
                                    ),
                                    ARRAY_A
                                );

                                foreach ($existing_rows as $row) {
                                    $existing_map[(string)$row['keyword']] = (int)$row['id'];
                                }
                            }

                            $eligible_secondary = [];
                            foreach ($items as $it) {
                                if ($inserted >= $new_limit) break;
                                $kw = is_array($it) ? (string)($it['keyword'] ?? '') : '';
                                if ($kw === '') continue;
                                if (function_exists('tmw_seo_is_blocked_keyword') && tmw_seo_is_blocked_keyword($kw)) {
                                    continue;
                                }

                                $reason = null;
                                if (!KeywordValidator::is_relevant($kw, $reason)) {
                                    continue;
                                }

                                $entity_match = TopicEntityLayer::match_keyword_to_entities($kw, $entities);
                                if (empty($entity_match['matched'])) {
                                    Logs::debug('keywords', sprintf('Rejected keyword "%s" — no entity match.', $kw), [
                                        'keyword' => $kw,
                                        'seed' => $seed,
                                        'similarity_score' => (float) ($entity_match['similarity_score'] ?? 0),
                                        'minimum_similarity' => TopicEntityLayer::minimum_similarity(),
                                    ]);
                                    continue;
                                }

                                $topical = TopicalRelevanceFilter::evaluate($kw, [
                                    'serp_items' => (array) ($it['items'] ?? $it['serp_items'] ?? []),
                                    'entity_name' => (string) ($entity_match['entity_name'] ?? $node_entity_name),
                                    'similarity_score' => (float) ($entity_match['similarity_score'] ?? 0),
                                ]);
                                if (empty($topical['allowed'])) {
                                    TopicalRelevanceFilter::log_rejection($kw, $topical);
                                    continue;
                                }

                                $accepted_total++;
            
                                $metrics = $it['keyword_info'] ?? [];
                                $vol = isset($metrics['search_volume']) ? (int)$metrics['search_volume'] : null;
                                $cpc = isset($metrics['cpc']) ? (float)$metrics['cpc'] : null;
                                $comp = isset($metrics['competition']) ? (float)$metrics['competition'] : null;
                                $difficulty = isset($metrics['keyword_difficulty']) ? (float) $metrics['keyword_difficulty'] : (float) ($it['keyword_difficulty'] ?? 0);
            
                                // Raw insert (ignore duplicates)
                                $raw_source = (string) ( $it['_tmw_volume_source'] ?? 'dataforseo_suggest' );
                                $wpdb->query($wpdb->prepare(
                                    "INSERT IGNORE INTO {$raw_table} (keyword, source, source_ref, volume, cpc, competition, raw, discovered_at)
                                     VALUES (%s, %s, %s, %d, %f, %f, %s, %s)",
                                    $kw, $raw_source, $seed,
                                    (int)($vol ?? 0), (float)($cpc ?? 0), (float)($comp ?? 0),
                                    wp_json_encode($it), current_time('mysql')
                                ));
            
                                // Candidate upsert
                                $canonical = KeywordValidator::normalize($kw);
                                $intent = KeywordValidator::infer_intent($kw);
                                $classification = KeywordClassifier::classify($kw);
                                $resolved_entity_type = 'topic_entity';
                                $resolved_entity_id = (int) ($entity_match['entity_id'] ?? 0);

                                // skip very low volume early (still store raw)
                                if ($vol !== null && $vol < $min_volume) continue;
            
                                $existing = $existing_map[$kw] ?? null;
                                if ($existing) {
                                    $seed_dupes++;
                                    $duplicates_total++;
                                    // update sources + provenance (capped to prevent unbounded growth)
                                    $vol_src      = (string) ( $it['_tmw_volume_source'] ?? 'dataforseo' );
                                    $cpc_src      = (string) ( $it['_tmw_cpc_source']    ?? $vol_src );
                                    $existing_src = (string) $wpdb->get_var( $wpdb->prepare(
                                        "SELECT sources FROM {$cand_table} WHERE id = %d",
                                        (int) $existing
                                    ) );
                                    $capped_sources = self::cap_sources_string( $existing_src, $vol_src . ':' . $seed );
                                    $wpdb->query($wpdb->prepare(
                                        "UPDATE {$cand_table} SET sources = %s, volume_source=%s, cpc_source=%s, intent_type=%s, entity_type=%s, entity_id=%d, needs_recluster=1, needs_rescore=1, updated_at=%s WHERE id=%d",
                                        $capped_sources,
                                        $vol_src,
                                        $cpc_src,
                                        (string) ($classification['intent_type'] ?? 'generic'),
                                        $resolved_entity_type,
                                        $resolved_entity_id,
                                        current_time('mysql'),
                                        (int)$existing
                                    ));
                                    // Apply trend overlay if present.
                                    if ( isset( $it['_tmw_trend_score'] ) && (int) $it['_tmw_trend_score'] > 0 ) {
                                        $wpdb->update( $cand_table, [
                                            'trend_score'       => (int) $it['_tmw_trend_score'],
                                            'needs_rescore'     => 1,
                                        ], [ 'id' => (int) $existing ], [ '%d', '%d' ], [ '%d' ] );
                                    }
                                } else {
                                    if (!DiscoveryGovernor::can_increment('keywords_discovered', 1)) {
                                        Logs::warn('keywords', 'Discovery governor triggered: keyword limit reached.');
                                        break;
                                    }

                                    $wpdb->insert($cand_table, [
                                        'keyword'       => $kw,
                                        'canonical'     => $canonical,
                                        'status'        => 'discovered',
                                        'intent'        => $intent,
                                        'intent_type'   => (string) ($classification['intent_type'] ?? 'generic'),
                                        'entity_type'   => $resolved_entity_type,
                                        'entity_id'     => $resolved_entity_id,
                                        'volume'        => $vol,
                                        'cpc'           => $cpc,
                                        'difficulty'    => null,
                                        'opportunity'   => null,
                                        'sources'       => ( (string)($it['_tmw_volume_source'] ?? 'dataforseo') ) . ':' . $seed,
                                        'notes'         => null,
                                        'needs_recluster' => 1,
                                        'needs_rescore' => 1,
                                        'trend_score'   => (int) ($it['_tmw_trend_score'] ?? 0),
                                        'volume_source' => (string) ($it['_tmw_volume_source'] ?? 'dataforseo'),
                                        'cpc_source'    => (string) ($it['_tmw_cpc_source']    ?? $it['_tmw_volume_source'] ?? 'dataforseo'),
                                        'updated_at'    => current_time('mysql'),
                                    ], [
                                        '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%f', '%f', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s'
                                    ]);
            
                                    $inserted++;
                                    $seed_net_new++;
                                    DiscoveryGovernor::increment('keywords_discovered', 1);
                                }

                                TopicEntityLayer::persist_keyword_mapping($kw, $resolved_entity_id, (float) ($entity_match['similarity_score'] ?? 0));

                                if ($depth < 2 && $secondary_expansions < $max_secondary_expansions && $vol !== null && $vol >= 20 && $difficulty <= 50) {
                                    $candidate_seed = KeywordValidator::normalize($kw);
                                    if ($candidate_seed !== '' && !isset($expanded_seeds[$candidate_seed]) && TopicalRelevanceFilter::should_expand($candidate_seed)) {
                                        $eligible_secondary[] = [
                                            'keyword' => $candidate_seed,
                                            'entity_id' => $resolved_entity_id,
                                            'entity_name' => (string) ($entity_match['entity_name'] ?? $node_entity_name),
                                            'entity_type' => 'authority_node',
                                            'similarity_score' => (float) ($entity_match['similarity_score'] ?? 0),
                                        ];
                                    } elseif ($candidate_seed !== '' && !TopicalRelevanceFilter::should_expand($candidate_seed)) {
                                        TopicalRelevanceFilter::log_rejection($candidate_seed, [
                                            'score' => 0,
                                            'similarity' => 0,
                                            'reasons' => ['topic similarity below threshold'],
                                        ]);
                                    }
                                }
                            }

                            foreach ($eligible_secondary as $candidate_node) {
                                if ($secondary_expansions >= $max_secondary_expansions) {
                                    break;
                                }

                                $candidate_seed = (string) ($candidate_node['keyword'] ?? '');
                                if ($candidate_seed === '' || isset($expanded_seeds[$candidate_seed])) {
                                    continue;
                                }

                                $expanded_seeds[$candidate_seed] = true;
                                $queue[] = [
                                    'keyword' => $candidate_seed,
                                    'depth' => $depth + 1,
                                    'entity_id' => (int) ($candidate_node['entity_id'] ?? 0),
                                    'entity_name' => (string) ($candidate_node['entity_name'] ?? ''),
                                    'entity_type' => (string) ($candidate_node['entity_type'] ?? 'authority_node'),
                                    'similarity_score' => (float) ($candidate_node['similarity_score'] ?? 0),
                                ];
                                $secondary_expansions++;
                            }

                            Logs::info('keywords', '[TMW-GRAPH] Query expansion node processed', [
                                'seed' => $seed,
                                'depth' => $depth,
                                'nodes_created' => count($lookup_keywords),
                                'relationships_created' => $relationships_created,
                                'secondary_expansions' => $secondary_expansions,
                                'seed_net_new' => $seed_net_new,
                                'seed_dupes' => $seed_dupes,
                            ]);

                            // ── Phase 2: record per-seed expansion metrics for ROI tracking
                            if ($depth === 0) {
                                SeedRegistry::record_expansion_result($seed, [
                                    'net_new'        => $seed_net_new,
                                    'duplicates'     => $seed_dupes,
                                    'provider'       => 'aggregator',
                                    'estimated_cost' => 0.01,
                                ]);
                            }

                            usleep(250000);
                        }
            } else {
                Logs::info('keywords', 'Discovery skipped (import_only mode)');
                self::record_stop_reason('import_only_mode');
            }
        } finally {
            $runtime = round(microtime(true) - $job_started, 2);

            Logs::info('keywords', 'Cycle metrics', [
                'runtime_seconds'        => $runtime,
                'inserted'               => $inserted ?? 0,
                'failures'               => $failures ?? 0,
                'seeds_expanded'         => $seeds_expanded_count ?? 0,
                'seeds_skipped_cooldown' => $seeds_skipped_cooldown ?? 0,
                'duplicates_total'       => $duplicates_total ?? 0,
            ]);

            self::update_cycle_metrics([
                'last_run'               => time(),
                'runtime_seconds'        => $runtime,
                'inserted'               => $inserted ?? 0,
                'failures'               => $failures ?? 0,
                'seeds_expanded'         => $seeds_expanded_count ?? 0,
                'seeds_skipped_cooldown' => $seeds_skipped_cooldown ?? 0,
                'duplicates_total'       => $duplicates_total ?? 0,
            ]);

            // Auto-retire seeds that have proven unproductive.
            SeedRegistry::retire_exhausted_seeds(5);

            // Release the DB-level lock.
            $wpdb->delete( $wpdb->options, [ 'option_name' => $lock_key ], [ '%s' ] );
        }

Logs::info('keywords', 'Inserted candidates', ['count' => $inserted]);

        // ── Phase 1 fix: the previous KeywordDiscoveryService::discover_from_seeds()
        //    call here was a SECOND paid expansion of the same seeds in the same cycle.
        //    Google Autosuggest is now integrated into the aggregator (free provider),
        //    so nothing is lost. We still update last_discovery_run for admin visibility.
        if ($mode !== 'import_only') {
            self::update_cycle_metrics([
                'last_discovery_run'     => time(),
                'seeds_expanded'         => $seeds_expanded_count ?? 0,
                'seeds_skipped_cooldown' => $seeds_skipped_cooldown ?? 0,
                'duplicates_total'       => $duplicates_total ?? 0,
            ]);

            if (($inserted ?? 0) > 0) {
                self::update_cycle_metrics([
                    'last_stop_reason'    => '',
                    'last_stop_reason_at' => 0,
                ]);
            }
        }

        // ── Phase 3: KD Refresh ───────────────────────────────────────────────
        self::phase_kd_refresh( $kd_limit, $max_kd );

        // ── Phase 3b: Google Keyword Planner Volume Enrichment ────────────────
        self::phase_gkp_enrichment();

        // ── Phase 4: Promote Scored Keywords → Review Queue ───────────────────
        $promoted = self::promote_scored_to_review_queue();
        Logs::info('keywords', '[TMW-KW] Review queue promotion', [
            'promoted_to_queue' => $promoted,
        ]);

        // ── Phase 5: Incremental Clustering + Page Suggestion Projection ──────
        self::run_cluster_projection_steps($pages_per_day);

        Logs::info('keywords', 'Keyword cycle completed');
        $summary_report = [
            'seeds_generated' => (int) ($seed_report['total_seeds'] ?? 0),
            'keywords_discovered' => (int) $discovered_total,
            'keywords_accepted' => (int) $accepted_total,
            'seed_breakdown' => $seed_report,
        ];
        update_option('tmw_keyword_last_discovery_report', $summary_report, false);
        Logs::info('keywords', '[TMW-KW] Discovery report', $summary_report);
        self::log_classification_counts();

    }

    /**
     * Phase 3 — KD refresh for candidates missing difficulty scores.
     *
     * Fetches keyword difficulty from DataForSEO (or uses recent cache) for any
     * keyword that has status='discovered'|'scored'|'queued_for_review'|'approved'
     * but no difficulty value yet. High-KD keywords are auto-rejected; the rest
     * move to 'scored' status for human review.
     *
     * @param int   $kd_limit Max keywords to refresh per cycle.
     * @param float $max_kd   Auto-reject threshold (KD > max_kd → rejected).
     */
    private static function phase_kd_refresh( int $kd_limit, float $max_kd ): void {
        global $wpdb;
        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';

        // 3) KD refresh for candidates missing difficulty
        // ── v5.1: query includes all pre-review states (discovered, scored, queued_for_review)
        //    plus approved for backward compat with items that were approved before v5.1.
        $to_score = $wpdb->get_col($wpdb->prepare(
            "SELECT keyword FROM {$cand_table} WHERE (difficulty IS NULL OR difficulty=0) AND status IN ('discovered','scored','queued_for_review','approved','new') ORDER BY updated_at DESC LIMIT %d",
            $kd_limit
        ));

        if (!empty($to_score)) {
            $to_score = array_values(array_unique($to_score));

            $placeholders = implode(',', array_fill(0, count($to_score), '%s'));
            $kw_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT keyword, volume, intent FROM {$cand_table} WHERE keyword IN ({$placeholders})",
                    ...$to_score
                ),
                ARRAY_A
            );

            $kw_map = [];
            foreach ($kw_rows as $kw_row) {
                $kw_map[(string)$kw_row['keyword']] = [
                    'volume' => isset($kw_row['volume']) ? (int)$kw_row['volume'] : 0,
                    'intent' => isset($kw_row['intent']) ? (string)$kw_row['intent'] : 'mixed',
                ];
            }

            $recent_metrics = KeywordDatabase::get_recent_metrics_map($to_score, 30);
            $to_refresh = [];
            $updated = 0;

            foreach ($to_score as $kw) {
                $cached = $recent_metrics[$kw] ?? null;
                if (is_array($cached)) {
                    $kd = (float) ($cached['difficulty'] ?? 0);
                    $vol = isset($kw_map[$kw]['volume']) && (int) $kw_map[$kw]['volume'] > 0
                        ? (int) $kw_map[$kw]['volume']
                        : (int) ($cached['search_volume'] ?? 0);
                    $intent = isset($kw_map[$kw]['intent']) ? (string) $kw_map[$kw]['intent'] : 'mixed';

                    // ── v5.1: KD scoring no longer auto-approves.
                    // Keywords that pass KD threshold move to 'scored', not 'approved'.
                    // Only human review in the Command Center moves them to 'approved'.
                    $status = ($kd > $max_kd) ? 'rejected' : 'scored';
                    $opp = KDFilter::opportunity_score($kd, $vol, $intent);

                    $wpdb->update($cand_table, [
                        'difficulty' => $kd,
                        'opportunity' => $opp,
                        'status' => $status,
                        'notes' => ($kd > $max_kd) ? 'auto_reject:kD' : 'scored:awaiting_review',
                        'updated_at' => current_time('mysql'),
                    ], ['keyword' => $kw]);

                    $updated++;
                    continue;
                }

                $to_refresh[] = $kw;
            }

            Logs::info('keywords', '[TMW-KW] Reused keyword metrics cache', [
                'cached_keywords' => $updated,
                'refresh_keywords' => count($to_refresh),
            ]);

            if (!empty($to_refresh)) {
                $chunks = array_chunk($to_refresh, 100);
                $had_failures = false;

                foreach ($chunks as $chunk) {
                    $kd_res = DataForSEO::bulk_keyword_difficulty($chunk);
                    if (!$kd_res['ok']) {
                        $had_failures = true;
                        Logs::warn('keywords', '[TMW-KW] KD refresh batch failed', [
                            'error' => $kd_res['error'] ?? '',
                            'batch_size' => count($chunk),
                        ]);
                        continue;
                    }

                    $map = $kd_res['map'] ?? [];
                    foreach ($chunk as $kw) {
                        $kwn = mb_strtolower($kw, 'UTF-8');
                        $kd = $map[$kwn] ?? null;
                        if ($kd === null) continue;

                        $vol = isset($kw_map[$kw]['volume']) ? (int)$kw_map[$kw]['volume'] : 0;
                        $intent = isset($kw_map[$kw]['intent']) ? (string)$kw_map[$kw]['intent'] : 'mixed';

                        // ── v5.1: auto-reject high-KD, but do NOT auto-approve.
                        // 'scored' means ready for human review queue.
                        $status = ($kd > $max_kd) ? 'rejected' : 'scored';
                        $opp = KDFilter::opportunity_score((float)$kd, $vol, $intent);

                        $wpdb->update($cand_table, [
                            'difficulty' => (float)$kd,
                            'opportunity' => $opp,
                            'status' => $status,
                            'notes' => ($kd > $max_kd) ? 'auto_reject:kD' : 'scored:awaiting_review',
                            'needs_recluster' => 1,
                            'needs_rescore' => 1,
                            'metrics_updated_at' => current_time('mysql'),
                            'updated_at' => current_time('mysql'),
                        ], ['keyword' => $kw]);

                        $classification = KeywordClassifier::classify($kw);

                        KeywordDatabase::upsert_metrics([
                            'keyword' => $kw,
                            'search_volume' => $vol,
                            'difficulty' => (float) $kd,
                            'serp_weakness' => 0,
                            'opportunity_score' => (float) $opp,
                            'source' => 'dataforseo',
                            'intent_type' => (string) ($classification['intent_type'] ?? 'generic'),
                            'entity_type' => (string) ($classification['entity_type'] ?? 'generic'),
                            'entity_id' => (int) ($classification['entity_id'] ?? 0),
                        ]);

                        $updated++;
                    }
                }

                Logs::info('keywords', '[TMW-KW] KD refreshed', [
                    'updated' => $updated,
                    'scored' => count($to_score),
                    'batched_requests' => count($chunks),
                ]);

                if ($had_failures) {
                    Logs::warn('keywords', '[TMW-KW] One or more KD batches failed', [
                        'batch_count' => count($chunks),
                    ]);
                }
            } else {
                Logs::info('keywords', '[TMW-KW] KD refresh skipped — all keywords recently checked');
            }
        }
    }

    /**
     * Phase 3b — Google Keyword Planner volume enrichment.
     *
     * Runs only when Google Ads Keyword Planner is configured. Fills in
     * volume + CPC for candidates that DataForSEO left null. Additive:
     * never overwrites existing non-null DataForSEO volume.
     */
    private static function phase_gkp_enrichment(): void {
        global $wpdb;
        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';

        // 3b) Google Keyword Planner enrichment pass — volume + CPC.
        // Only runs when Google Ads Keyword Planner is configured and enabled.
        // Enriches approved candidates that have low/null volume with Planner data,
        // then re-scores opportunity. This is additive — DataForSEO data is not replaced
        // if it already provided a non-null volume.
        if ( \TMWSEO\Engine\Integrations\GoogleAdsKeywordPlannerApi::is_configured() ) {
            Logs::info('keywords', '[TMW-SEO-AUTO] Google Ads enrichment enabled');
            $gkp_candidates = $wpdb->get_col( $wpdb->prepare(
                "SELECT keyword FROM {$cand_table}
                 WHERE status IN ('discovered','scored','queued_for_review','approved','new')
                   AND (volume IS NULL OR volume = 0)
                   AND (sources NOT LIKE %s)
                 ORDER BY updated_at DESC
                 LIMIT 200",
                '%google_keyword_planner%'
            ) );

            if ( ! empty( $gkp_candidates ) ) {
                $gkp_result = \TMWSEO\Engine\Integrations\GoogleAdsKeywordPlannerApi::enrich_metrics( $gkp_candidates );
                $gkp_metrics = is_array($gkp_result) && isset($gkp_result['metrics']) ? (array) $gkp_result['metrics'] : (array) $gkp_result;

                if (is_array($gkp_result) && !empty($gkp_result['error_reason'])) {
                    self::record_stop_reason((string) $gkp_result['error_reason'], [
                        'diagnostic' => (string) ($gkp_result['diagnostic_message'] ?? ''),
                    ]);
                }

                foreach ( $gkp_metrics as $kw => $metrics ) {
                    $gkp_vol = (int) ($metrics['volume'] ?? 0);
                    $gkp_cpc = (float) ($metrics['cpc'] ?? 0.0);
                    if ( $gkp_vol === 0 ) { continue; }

                    $intent_row = (array) $wpdb->get_row( $wpdb->prepare(
                        "SELECT intent, opportunity FROM {$cand_table} WHERE keyword = %s LIMIT 1",
                        $kw
                    ), ARRAY_A );

                    $intent_val = (string) ($intent_row['intent'] ?? 'mixed');
                    $gkp_kd     = 0.0; // KP doesn't provide KD; keep existing difficulty.
                    $existing_kd = (float) $wpdb->get_var( $wpdb->prepare(
                        "SELECT difficulty FROM {$cand_table} WHERE keyword = %s LIMIT 1", $kw
                    ) );
                    $opp = \TMWSEO\Engine\Keywords\KDFilter::opportunity_score( $existing_kd ?: $gkp_kd, $gkp_vol, $intent_val );

                    $gkp_existing_src = (string) $wpdb->get_var( $wpdb->prepare(
                        "SELECT sources FROM {$cand_table} WHERE keyword = %s LIMIT 1", $kw
                    ) );

                    $wpdb->update( $cand_table, [
                        'volume'        => $gkp_vol,
                        'cpc'           => $gkp_cpc,
                        'opportunity'   => $opp,
                        'volume_source' => 'google_keyword_planner',
                        'cpc_source'    => 'google_keyword_planner',
                        'sources'       => self::cap_sources_string( $gkp_existing_src, 'google_keyword_planner:enrichment' ),
                        'needs_rescore'   => 1,
                        'needs_recluster' => 1,
                        'updated_at'      => current_time('mysql'),
                    ], [ 'keyword' => $kw ], [ '%d', '%f', '%f', '%s', '%s', '%s', '%d', '%d', '%s' ], [ '%s' ] );
                }

                Logs::info('keywords', '[TMW-KW] Google Keyword Planner enrichment pass complete', [
                    'enriched' => count($gkp_metrics),
                    'candidates_checked' => count($gkp_candidates),
                ]);
            } else {
                Logs::info('keywords', '[TMW-KW] Google Keyword Planner enrichment skipped — no eligible candidates', [
                    'eligible_statuses' => ['discovered', 'scored', 'queued_for_review', 'approved'],
                    'requires_missing_volume' => true,
                    'already_enriched_source_marker' => 'google_keyword_planner',
                ]);
            }
        } else {
            Logs::info('keywords', '[TMW-SEO-AUTO] Google Ads enrichment skipped: not configured');
            Logs::info('keywords', '[TMW-KW] Google Keyword Planner enrichment skipped — integration not configured or disabled');
        }
    }


    public static function run_cluster_projection_job(array $job = []): void {
        $pages_per_day = (int) Settings::get('keyword_pages_per_day', 3);

        QueryExpansionGraph::create_table();

        Logs::info('keywords', 'Cluster/projection job started', [
            'job_id' => $job['id'] ?? null,
            'source' => $job['source'] ?? null,
        ]);

        self::run_cluster_projection_steps($pages_per_day);

        Logs::info('keywords', 'Cluster/projection job completed', [
            'job_id' => $job['id'] ?? null,
            'source' => $job['source'] ?? null,
        ]);
    }

    private static function run_cluster_projection_steps(int $pages_per_day): void {
        self::enqueue_dirty_keywords();
        DirtyQueue::process_batches(80, 40, 20);
        $graph_stats = QueryExpansionGraph::generate_topic_clusters();
        Logs::info('keywords', '[TMW-GRAPH] Graph metrics persisted', $graph_stats);

        // Suggestion-first workflow: queue suggested pages only (no auto-creation).
        self::store_suggested_pages_from_clusters($pages_per_day);
        self::store_topic_suggestions($pages_per_day);
    }


    private static function update_cycle_metrics(array $updates): void {
        $metrics = get_option('tmw_keyword_engine_metrics', []);
        if (!is_array($metrics)) {
            $metrics = [];
        }

        foreach ($updates as $key => $value) {
            $metrics[(string) $key] = $value;
        }

        update_option('tmw_keyword_engine_metrics', $metrics, false);
    }

    /**
     * Append a new source entry to the sources string, capped at $max_bytes.
     *
     * The `sources` TEXT column stores one "provider:seed" token per line.
     * Without a cap, high-traffic keywords accumulate thousands of lines over
     * many cycles, blowing up row size and making LIKE queries slow.
     *
     * Strategy: keep the TAIL (most recent entries). Older provenance is less
     * useful operationally. We find a clean newline boundary so we never cut
     * mid-token.
     *
     * @param string $existing  Current column value (may be null/empty).
     * @param string $new_entry The token to append, e.g. "dataforseo:webcam".
     * @param int    $max_bytes Hard cap in bytes (default 1500).
     */
    private static function cap_sources_string( string $existing, string $new_entry, int $max_bytes = 1500 ): string {
        $combined = ltrim( $existing . "\n" . $new_entry, "\n" );
        if ( strlen( $combined ) <= $max_bytes ) {
            return $combined;
        }
        // Keep the most-recent tail. Find a clean \n boundary to avoid cutting mid-token.
        $tail = substr( $combined, -$max_bytes );
        $nl   = strpos( $tail, "\n" );
        return $nl !== false ? substr( $tail, $nl + 1 ) : $tail;
    }

    private static function record_stop_reason(string $reason, array $context = []): void {
        self::update_cycle_metrics([
            'last_stop_reason' => $reason,
            'last_stop_reason_at' => time(),
        ]);

        Logs::warn('keywords', '[TMW-KW] Keyword cycle stop reason', [
            'reason' => $reason,
            'context' => $context,
        ]);
    }



    private static function log_classification_counts(): void {
        global $wpdb;

        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';

        $intent_counts = (array) $wpdb->get_results("SELECT intent_type, COUNT(*) as total FROM {$cand_table} GROUP BY intent_type", ARRAY_A);
        $entity_counts = (array) $wpdb->get_results("SELECT entity_type, COUNT(*) as total FROM {$cand_table} GROUP BY entity_type", ARRAY_A);

        Logs::info('keywords', '[TMW-KW] Intent classification counts', [
            'counts' => $intent_counts,
        ]);

        Logs::info('keywords', '[TMW-KW] Entity classification counts', [
            'counts' => $entity_counts,
        ]);
    }

    // ─── v5.1: Review Queue Promotion ─────────────────────────────────────

    /**
     * Maximum number of keywords in the actionable review queue at once.
     * This is the COMBINED hard cap across BOTH tracks:
     *   - tmw_keyword_candidates WHERE status = 'queued_for_review'
     *   - tmw_seed_expansion_candidates WHERE status IN ('pending','fast_track')
     *
     * v5.2: This is ONE queue of 50 total, not 50 per track.
     */
    /**
     * Maximum combined items in the review queue (discovery + generator tracks).
     *
     * Default: 200 — large enough to not stall a normal operation.
     * Configurable via Settings: keyword_review_queue_cap.
     *
     * Operators can lower this if they want a smaller manual review batch size.
     * Raising it above 500 is not recommended — the Command Center table
     * becomes unwieldy and review quality drops.
     */
    private static function get_review_queue_cap(): int {
        $cap = (int) Settings::get( 'keyword_review_queue_cap', 200 );
        return max( 20, min( 1000, $cap ) ); // hard bounds: 20–1000
    }

    /**
     * Promote top-scored keywords from 'scored' to 'queued_for_review'.
     *
     * v5.2: Respects a COMBINED cap of 50 across both the discovery queue
     * (queued_for_review in tmw_keyword_candidates) and the generator queue
     * (pending+fast_track in tmw_seed_expansion_candidates). If the generator
     * track already has 30 items, only 20 discovery items can be queued.
     *
     * @return int Number of items promoted in this run.
     */
    public static function promote_scored_to_review_queue(): int {
        global $wpdb;

        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cand_table ) ) !== $cand_table ) {
            return 0;
        }

        // Count current discovery-track queue
        $discovery_queue = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$cand_table} WHERE status = 'queued_for_review'"
        );

        // Count current generator-track queue (pending + fast_track)
        $generator_queue = 0;
        if ( class_exists( ExpansionCandidateRepository::class ) ) {
            $gen_counts = ExpansionCandidateRepository::count_by_status();
            $generator_queue = (int) ( $gen_counts['pending'] ?? 0 ) + (int) ( $gen_counts['fast_track'] ?? 0 );
        }

        $combined_queue = $discovery_queue + $generator_queue;
        $available_slots = max( 0, self::get_review_queue_cap() - $combined_queue );

        $cap = self::get_review_queue_cap();

        if ( $available_slots <= 0 ) {
            Logs::warn( 'keywords', '[TMW-KW] ⚠ Review queue FULL — keyword pipeline is running but results are being discarded.', [
                'discovery_queue' => $discovery_queue,
                'generator_queue' => $generator_queue,
                'combined'        => $combined_queue,
                'cap'             => $cap,
                'action_needed'   => 'Review and approve or reject keywords in Command Center → Keyword Review Queue',
            ] );
            // Persist a visible flag so the admin notice hook can surface it.
            update_option( 'tmwseo_kw_queue_full_since', time(), false );
            return 0;
        }

        // Near-capacity warning (> 80% full)
        if ( $combined_queue >= (int) round( $cap * 0.8 ) ) {
            Logs::warn( 'keywords', '[TMW-KW] Review queue near capacity', [
                'fill_pct'        => round( ( $combined_queue / max( 1, $cap ) ) * 100 ) . '%',
                'discovery_queue' => $discovery_queue,
                'generator_queue' => $generator_queue,
                'combined'        => $combined_queue,
                'cap'             => $cap,
            ] );
        } else {
            // Clear the full-since flag if queue has room again.
            delete_option( 'tmwseo_kw_queue_full_since' );
        }

        // Select top-scoring 'scored' items to promote
        $to_promote = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$cand_table}
             WHERE status = 'scored'
               AND volume > 0
               AND difficulty IS NOT NULL
               AND opportunity IS NOT NULL
             ORDER BY opportunity DESC, volume DESC
             LIMIT %d",
            $available_slots
        ) );

        if ( empty( $to_promote ) ) {
            return 0;
        }

        $placeholders = implode( ',', array_fill( 0, count( $to_promote ), '%d' ) );
        $affected = (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$cand_table}
             SET status = 'queued_for_review',
                 notes = CONCAT(IFNULL(notes,''), ' | promoted_to_queue:" . current_time( 'mysql' ) . "'),
                 updated_at = %s
             WHERE id IN ({$placeholders})",
            current_time( 'mysql' ),
            ...$to_promote
        ) );

        if ( $affected > 0 ) {
            Logs::info( 'keywords', '[TMW-KW] Promoted scored keywords to review queue', [
                'promoted'        => $affected,
                'available_slots' => $available_slots,
                'discovery_queue' => $discovery_queue + $affected,
                'generator_queue' => $generator_queue,
                'combined_after'  => $combined_queue + $affected,
            ] );
        }

        return $affected;
    }

    /**
     * Fetch keyword ideas for $seed via the multi-provider aggregator.
     *
     * Previously this was hard-wired to DataForSEO only. Now it fans out to
     * all configured providers (DataForSEO + Google Keyword Planner + Google Trends)
     * and merges results. DataForSEO remains fully supported — no regressions.
     *
     * Results are cached for 1 hour (same as the old single-provider behaviour).
     *
     * @return array{ok:bool,items?:array<int,array<string,mixed>>,error?:string}
     */
    private static function fetch_seed_relationships(string $seed): array {
        $cache_key = 'tmw_seed_suggestions_' . md5($seed);
        $cached    = get_transient($cache_key);

        if ($cached !== false && is_array($cached)) {
            return $cached;
        }

        $limit = (int) Settings::get('keyword_suggestions_limit', 200);

        // Multi-provider aggregator — DataForSEO + Google KP + Google Trends.
        // Falls back gracefully: if all providers are unavailable the original
        // budget-exceeded / credentials-missing error is propagated.
        $agg = new KeywordIdeaAggregator();
        $res = $agg->fetch($seed, $limit);

        if (!empty($res['ok'])) {
            set_transient($cache_key, $res, HOUR_IN_SECONDS);
        }

        return $res;
    }

    private static function collect_seeds(int $limit): array {
        // ── Architecture v5.0: Static seeds are NO LONGER re-registered here.
        //    The hardcoded static seed array has been removed. Static curated
        //    seeds are now installed ONCE via the Architecture Reset process
        //    (ArchitectureReset::execute_reset()) or the Starter Pack setup.
        //    This eliminates the "zombie seed" problem where purged seeds
        //    silently returned every cycle.
        //
        //    If you need to add root seeds, use the Command Center → Add Manual Seed,
        //    or run the Architecture Reset from the Health panel.

        $source_counts = [
            'base_seeds'       => 0,
            'static_seeds'     => 0,
            'model_seeds'      => 0,
            'tag_seeds'        => 0,
            'video_seeds'      => 0,
            'category_seeds'   => 0,
            'competitor_seeds' => 0,
            'trend_seeds'      => 0,
            'total_seeds'      => 0,
        ];

        TopicEntityLayer::ensure_default_entities();

        // Generated phrase methods (model/tag/video/category/competitor) have been
        // moved to their own cron/run cycles and now write to the preview layer.
        // They are intentionally NOT called here any more.
        // Use the Auto Builders Control Center admin page to trigger/manage them.

        // ── Google Trends: daily trending seeds ───────────────────────────
        // Adds rising/trending phrases as candidate phrases (preview layer) when
        // Google Trends is enabled. These never write directly to tmwseo_seeds.
        if ( \TMWSEO\Engine\Services\GoogleTrends::is_enabled() ) {
            $trending = \TMWSEO\Engine\Services\GoogleTrends::get_daily_trending( 30 );
            $trend_batch_id = ExpansionCandidateRepository::make_batch_id('trend_daily');
            foreach ($trending as $trend_item) {
                $phrase = (string) ($trend_item['keyword'] ?? '');
                if ($phrase === '') { continue; }
                $ok = SeedRegistry::register_candidate_phrase(
                    $phrase,
                    'trend_rising_query',
                    'google_trends_daily',
                    'system',
                    0,
                    $trend_batch_id,
                    [ 'trend_score' => (int) ($trend_item['trend_score'] ?? 0) ]
                );
                if ($ok) { $source_counts['trend_seeds']++; }
            }
            Logs::info('keywords', '[TMW-KW] Google Trends daily seeds added to preview layer', [
                'count' => $source_counts['trend_seeds'],
            ]);
        }

        $orchestrated = DiscoveryOrchestrator::run(['source' => 'keyword_cycle']);
        $final_seeds = array_slice((array) ($orchestrated['seeds'] ?? []), 0, min(300, max(1, $limit)));
        $entities = (array) ($orchestrated['entities'] ?? TopicEntityLayer::get_entities_for_discovery(100));

        $source_counts['total_seeds'] = count($final_seeds);
        $source_counts['total_entities'] = count($entities);

        return [
            'seeds'  => $final_seeds,
            'entities' => $entities,
            'counts' => $source_counts,
        ];
    }

    /**
     * Generate model-name phrase candidates and write them to the preview layer.
     * These no longer write directly to tmwseo_seeds.
     *
     * Kill switch: tmwseo_builder_model_phrases_enabled (default 0 = OFF).
     * Enable via Auto Builders Control Center before running.
     */
    public static function register_model_seeds(array &$source_counts = []): array {
        if ( !(bool) get_option('tmwseo_builder_model_phrases_enabled', 0) ) {
            return ['skipped' => true, 'reason' => 'kill_switch_off'];
        }

        $models = get_posts([
            'post_type' => 'model',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);

        if (!is_array($models) || empty($models)) {
            return ['inserted' => 0, 'skipped' => 0];
        }

        $batch_id = ExpansionCandidateRepository::make_batch_id('model_phrase');
        $inserted = 0;
        $skipped  = 0;

        foreach ($models as $model_id) {
            $model_id   = (int) $model_id;
            $model_name = trim((string) get_the_title($model_id));
            if ($model_name === '') {
                continue;
            }

            $variants = [
                $model_name . ' webcam',
                $model_name . ' live cam',
                $model_name . ' cam girl',
                $model_name . ' cam model',
                $model_name . ' webcam chat',
            ];

            foreach ($variants as $phrase) {
                $ok = SeedRegistry::register_candidate_phrase(
                    $phrase,
                    'model_phrase',
                    'model_name_x_modifier',
                    'model',
                    $model_id,
                    $batch_id,
                    ['model_name' => $model_name]
                );
                if ($ok) {
                    $inserted++;
                    DiscoveryGovernor::increment('keywords_discovered', 1);
                    $source_counts['model_seeds'] = ($source_counts['model_seeds'] ?? 0) + 1;
                } else {
                    $skipped++;
                }
            }
        }

        return ['batch_id' => $batch_id, 'inserted' => $inserted, 'skipped' => $skipped];
    }

    /**
     * Generate tag-based phrase candidates and write them to the preview layer.
     * These no longer write directly to tmwseo_seeds.
     *
     * Kill switch: tmwseo_builder_tag_phrases_enabled (default 0 = OFF).
     */
    public static function register_tag_seeds(array &$source_counts = []): array {
        if ( !(bool) get_option('tmwseo_builder_tag_phrases_enabled', 0) ) {
            return ['skipped' => true, 'reason' => 'kill_switch_off'];
        }

        $terms = get_terms([
            'taxonomy' => 'post_tag',
            'hide_empty' => false,
            'number' => 200,
            'orderby' => 'count',
            'order' => 'DESC',
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return ['inserted' => 0, 'skipped' => 0];
        }

        $batch_id = ExpansionCandidateRepository::make_batch_id('tag_phrase');
        $inserted = 0;
        $skipped  = 0;

        foreach ($terms as $term) {
            $name = trim((string) ($term->name ?? ''));
            if (mb_strlen($name, 'UTF-8') < 3) {
                continue;
            }

            $variants = [
                $name . ' cam girl',
                $name . ' webcam model',
                $name . ' live cam',
                $name . ' cam model',
            ];

            foreach ($variants as $phrase) {
                $ok = SeedRegistry::register_candidate_phrase(
                    $phrase,
                    'tag_phrase',
                    'tag_name_x_modifier',
                    'post_tag',
                    (int) ($term->term_id ?? 0),
                    $batch_id,
                    ['tag_name' => $name]
                );
                if ($ok) {
                    $inserted++;
                    DiscoveryGovernor::increment('keywords_discovered', 1);
                    $source_counts['tag_seeds'] = ($source_counts['tag_seeds'] ?? 0) + 1;
                } else {
                    $skipped++;
                }
            }
        }

        return ['batch_id' => $batch_id, 'inserted' => $inserted, 'skipped' => $skipped];
    }

    /**
     * Generate video-title phrase candidates and write them to the preview layer.
     *
     * Kill switch: tmwseo_builder_video_phrases_enabled (default 0 = OFF).
     */
    public static function register_video_seeds(array &$source_counts = []): array {
        if ( !(bool) get_option('tmwseo_builder_video_phrases_enabled', 0) ) {
            return ['skipped' => true, 'reason' => 'kill_switch_off'];
        }

        $videos = get_posts([
            'post_type' => 'video',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);

        if (!is_array($videos) || empty($videos)) {
            return ['inserted' => 0, 'skipped' => 0];
        }

        $batch_id = ExpansionCandidateRepository::make_batch_id('video_phrase');
        $inserted = 0;
        $skipped  = 0;

        foreach ($videos as $video_id) {
            $video_id = (int) $video_id;
            $title    = trim((string) get_the_title($video_id));
            if ($title === '') {
                continue;
            }

            $variants = [
                $title . ' cam show',
                $title . ' live cam',
                $title . ' cam video',
            ];

            foreach ($variants as $phrase) {
                $ok = SeedRegistry::register_candidate_phrase(
                    $phrase,
                    'video_phrase',
                    'video_title_x_modifier',
                    'video',
                    $video_id,
                    $batch_id,
                    ['video_title' => $title]
                );
                if ($ok) {
                    $inserted++;
                    DiscoveryGovernor::increment('keywords_discovered', 1);
                    $source_counts['video_seeds'] = ($source_counts['video_seeds'] ?? 0) + 1;
                } else {
                    $skipped++;
                }
            }
        }

        return ['batch_id' => $batch_id, 'inserted' => $inserted, 'skipped' => $skipped];
    }

    /**
     * Generate category-based phrase candidates and write them to the preview layer.
     *
     * Kill switch: tmwseo_builder_category_phrases_enabled (default 0 = OFF).
     */
    public static function register_category_seeds(array &$source_counts = []): array {
        if ( !(bool) get_option('tmwseo_builder_category_phrases_enabled', 0) ) {
            return ['skipped' => true, 'reason' => 'kill_switch_off'];
        }

        $terms = get_terms([
            'taxonomy' => 'category',
            'hide_empty' => false,
            'number' => 200,
            'orderby' => 'count',
            'order' => 'DESC',
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return ['inserted' => 0, 'skipped' => 0];
        }

        $batch_id = ExpansionCandidateRepository::make_batch_id('category_phrase');
        $inserted = 0;
        $skipped  = 0;

        foreach ($terms as $term) {
            $name = trim((string) ($term->name ?? ''));
            if ($name === '') {
                continue;
            }

            $variants = [
                $name . ' cam girl',
                $name . ' webcam model',
                'best ' . $name . ' cam girls',
            ];

            foreach ($variants as $phrase) {
                $ok = SeedRegistry::register_candidate_phrase(
                    $phrase,
                    'category_phrase',
                    'category_name_x_modifier',
                    'category',
                    (int) ($term->term_id ?? 0),
                    $batch_id,
                    ['category_name' => $name]
                );
                if ($ok) {
                    $inserted++;
                    DiscoveryGovernor::increment('keywords_discovered', 1);
                    $source_counts['category_seeds'] = ($source_counts['category_seeds'] ?? 0) + 1;
                } else {
                    $skipped++;
                }
            }
        }

        return ['batch_id' => $batch_id, 'inserted' => $inserted, 'skipped' => $skipped];
    }

    /**
     * Fetch competitor ranked keywords and write them to the preview layer.
     * Competitor domains may be trusted inputs but their ranked keywords are
     * still discovered phrases — they must NOT enter tmwseo_seeds directly.
     *
     * Kill switch: tmwseo_builder_competitor_phrases_enabled (default 0 = OFF).
     */
    public static function register_competitor_seeds(array &$source_counts = []): array {
        if ( !(bool) get_option('tmwseo_builder_competitor_phrases_enabled', 0) ) {
            return ['skipped' => true, 'reason' => 'kill_switch_off'];
        }

        $competitors = Settings::competitor_domains();
        if (empty($competitors) || !DataForSEO::is_configured()) {
            return ['skipped' => true, 'reason' => 'no_competitors_or_dfs_not_configured'];
        }

        $rot    = (int) get_option('tmwseo_engine_competitor_rot', 0);
        $domain = $competitors[$rot % count($competitors)];
        update_option('tmwseo_engine_competitor_rot', $rot + 1, false);

        $rk = DataForSEO::ranked_keywords($domain, 50);
        if (!$rk['ok']) {
            return ['skipped' => true, 'reason' => 'dfs_error'];
        }

        $batch_id = ExpansionCandidateRepository::make_batch_id('competitor_ranked');
        $inserted = 0;
        $skipped  = 0;

        foreach (($rk['items'] ?? []) as $it) {
            $kw = '';
            if (is_array($it) && isset($it['keyword_data']['keyword'])) {
                $kw = (string) $it['keyword_data']['keyword'];
            }

            if ($kw === '') {
                continue;
            }

            $ok = SeedRegistry::register_candidate_phrase(
                $kw,
                'competitor_ranked',
                'competitor_domain_ranked_keywords',
                'domain',
                0,
                $batch_id,
                ['competitor_domain' => $domain]
            );

            if ($ok) {
                $inserted++;
                DiscoveryGovernor::increment('keywords_discovered', 1);
                $source_counts['competitor_seeds'] = ($source_counts['competitor_seeds'] ?? 0) + 1;
            } else {
                $skipped++;
            }
        }

        return ['batch_id' => $batch_id, 'domain' => $domain, 'inserted' => $inserted, 'skipped' => $skipped];
    }

    public static function full_rebuild_projections(): void {
        global $wpdb;
        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';

        // FIX: $cluster_map_table_exists was undefined in this scope (declared in run_cycle_job(),
        // invisible inside a separate static method). Re-declare locally so cluster keyword map
        // inserts are not silently skipped on every rebuild run.
        $cluster_keyword_map_table = $wpdb->prefix . 'tmw_keyword_cluster_map';
        $cluster_map_table_exists  = (string) $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $cluster_keyword_map_table )
        ) === $cluster_keyword_map_table;

        $rows = $wpdb->get_results(
            "SELECT keyword, volume, difficulty, opportunity, intent, intent_type, entity_type, entity_id
             FROM {$cand_table}
             WHERE status='approved' AND opportunity IS NOT NULL
             ORDER BY opportunity DESC
             LIMIT 2000",
            ARRAY_A
        );

        if (empty($rows)) return;

        $clusters = [];

        foreach ($rows as $r) {
            $kw = (string)($r['keyword'] ?? '');
            if ($kw === '') continue;
            $key = KeywordValidator::cluster_key($kw);
            $entity_type = (string) ($r['entity_type'] ?? 'generic');
            $entity_id = (int) ($r['entity_id'] ?? 0);
            $intent_type = (string) ($r['intent_type'] ?? 'generic');
            if ($entity_type !== 'generic' && $entity_id > 0) {
                $key = sprintf('entity:%s:%d:%s', $entity_type, $entity_id, $intent_type);
            } else {
                $key = sprintf('%s:intent:%s', $key, $intent_type);
            }

            if (!isset($clusters[$key])) {
                $clusters[$key] = [
                    'keywords' => [],
                    'total_volume' => 0,
                    'sum_kd' => 0.0,
                    'kd_n' => 0,
                    'best_kw' => $kw,
                    'best_opp' => (float)($r['opportunity'] ?? 0),
                ];
            }

            $clusters[$key]['keywords'][] = $kw;
            $clusters[$key]['total_volume'] += (int)($r['volume'] ?? 0);

            if (!empty($r['difficulty'])) {
                $clusters[$key]['sum_kd'] += (float)$r['difficulty'];
                $clusters[$key]['kd_n'] += 1;
            }

            $opp = (float)($r['opportunity'] ?? 0);
            if ($opp > $clusters[$key]['best_opp']) {
                $clusters[$key]['best_opp'] = $opp;
                $clusters[$key]['best_kw'] = $kw;
            }
        }

        $cluster_map = [];
        $cluster_keys = array_values(array_unique(array_keys($clusters)));
        if (!empty($cluster_keys)) {
            $placeholders = implode(', ', array_fill(0, count($cluster_keys), '%s'));
            $existing_clusters = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, cluster_key FROM {$cluster_table} WHERE cluster_key IN ({$placeholders})",
                    ...$cluster_keys
                ),
                ARRAY_A
            );

            foreach ($existing_clusters as $existing_cluster) {
                $cluster_map[(string)$existing_cluster['cluster_key']] = (int)$existing_cluster['id'];
            }
        }

        // Upsert clusters
        foreach ($clusters as $key => $c) {
            $avg_kd = ($c['kd_n'] > 0) ? round($c['sum_kd'] / $c['kd_n'], 2) : null;
            $opportunity = (float)$c['best_opp'];
            $normalized_keywords = array_values(array_unique(array_filter(array_map(static function ($keyword) {
                return KeywordValidator::normalize((string) $keyword);
            }, (array) ($c['keywords'] ?? [])))));

            $exists = $cluster_map[$key] ?? null;
            if ($exists) {
                $existing_cluster = (array) $wpdb->get_row($wpdb->prepare(
                    "SELECT id, page_id FROM {$cluster_table} WHERE id = %d LIMIT 1",
                    (int) $exists
                ), ARRAY_A);
                $existing_page_id = (int) ($existing_cluster['page_id'] ?? 0);

                $wpdb->update($cluster_table, [
                    'representative' => $c['best_kw'],
                    'keywords' => wp_json_encode($normalized_keywords),
                    'total_volume' => (int)$c['total_volume'],
                    'avg_difficulty' => $avg_kd,
                    'opportunity' => $opportunity,
                    'status' => $existing_page_id > 0 ? 'built' : 'new',
                    'updated_at' => current_time('mysql'),
                ], ['id' => (int)$exists]);

                if ($cluster_map_table_exists) {
                    foreach ($normalized_keywords as $keyword) {
                        $wpdb->insert(
                            $cluster_keyword_map_table,
                            [
                                'keyword' => $keyword,
                                'cluster_id' => (int) $exists,
                                'page_id' => $existing_page_id > 0 ? $existing_page_id : null,
                                'updated_at' => current_time('mysql'),
                            ],
                            ['%s', '%d', '%d', '%s']
                        );
                    }
                }
            } else {
                $wpdb->insert($cluster_table, [
                    'cluster_key' => $key,
                    'representative' => $c['best_kw'],
                    'keywords' => wp_json_encode($normalized_keywords),
                    'total_volume' => (int)$c['total_volume'],
                    'avg_difficulty' => $avg_kd,
                    'opportunity' => $opportunity,
                    'page_id' => null,
                    'status' => 'new',
                    'updated_at' => current_time('mysql'),
                ], [
                    '%s', '%s', '%s', '%d', '%f', '%f', '%d', '%s', '%s'
                ]);

                $new_cluster_id = (int) $wpdb->insert_id;
                if ($cluster_map_table_exists && $new_cluster_id > 0) {
                    foreach ($normalized_keywords as $keyword) {
                        $wpdb->insert(
                            $cluster_keyword_map_table,
                            [
                                'keyword' => $keyword,
                                'cluster_id' => $new_cluster_id,
                                'page_id' => null,
                                'updated_at' => current_time('mysql'),
                            ],
                            ['%s', '%d', '%d', '%s']
                        );
                    }
                }
            }
        }

        self::refresh_cluster_stats(array_values($cluster_map));
        Logs::info('keywords', 'Clusters rebuilt', ['clusters' => count($clusters)]);
    }


    private static function enqueue_dirty_keywords(): void {
        global $wpdb;
        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';

        $rows = $wpdb->get_results(
            "SELECT id FROM {$cand_table} WHERE needs_recluster=1 OR needs_rescore=1 ORDER BY updated_at ASC LIMIT 500",
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            $keyword_id = (int) ($row['id'] ?? 0);
            if ($keyword_id <= 0) {
                continue;
            }
            DirtyQueue::enqueue('keyword', $keyword_id, 'keyword_changed');
        }
    }

    public static function process_dirty_keyword(int $keyword_id, string $reason = 'keyword_changed'): void {
        global $wpdb;

        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';
        $cluster_keyword_map_table = $wpdb->prefix . 'tmw_keyword_cluster_map';

        $keyword = (array) $wpdb->get_row($wpdb->prepare("SELECT * FROM {$cand_table} WHERE id=%d LIMIT 1", $keyword_id), ARRAY_A);
        if (empty($keyword)) {
            return;
        }

        $kw = (string) ($keyword['keyword'] ?? '');
        if ($kw === '') {
            return;
        }

        $entity_type = (string) ($keyword['entity_type'] ?? 'generic');
        $entity_id = (int) ($keyword['entity_id'] ?? 0);
        $intent_type = (string) ($keyword['intent_type'] ?? 'generic');
        $base_key = KeywordValidator::cluster_key($kw);
        $bucket_key = ($entity_type !== 'generic' && $entity_id > 0)
            ? sprintf('entity:%s:%d:%s', $entity_type, $entity_id, $intent_type)
            : sprintf('%s:intent:%s', $base_key, $intent_type);

        $bucket_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT keyword, volume, difficulty, opportunity
             FROM {$cand_table}
             WHERE status='approved'
               AND (CASE WHEN %s <> 'generic' AND %d > 0 THEN entity_type=%s AND entity_id=%d AND intent_type=%s ELSE intent_type=%s AND canonical LIKE %s END)",
            $entity_type,
            $entity_id,
            $entity_type,
            $entity_id,
            $intent_type,
            $intent_type,
            $base_key . '%'
        ), ARRAY_A);

        if (empty($bucket_rows)) {
            return;
        }

        $keywords = [];
        $total_volume = 0;
        $sum_kd = 0.0;
        $kd_n = 0;
        $best_kw = $kw;
        $best_opp = 0.0;

        foreach ($bucket_rows as $row) {
            $k = (string) ($row['keyword'] ?? '');
            if ($k === '') { continue; }
            $keywords[] = $k;
            $total_volume += (int) ($row['volume'] ?? 0);
            $d = (float) ($row['difficulty'] ?? 0);
            if ($d > 0) { $sum_kd += $d; $kd_n++; }
            $opp = (float) ($row['opportunity'] ?? 0);
            if ($opp >= $best_opp) { $best_opp = $opp; $best_kw = $k; }
        }

        $avg_kd = ($kd_n > 0) ? round($sum_kd / $kd_n, 2) : 0;
        $existing_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$cluster_table} WHERE cluster_key=%s LIMIT 1", $bucket_key));

        if ($existing_id > 0) {
            $wpdb->update($cluster_table, [
                'representative' => $best_kw,
                'keywords' => wp_json_encode(array_values(array_unique($keywords))),
                'total_volume' => $total_volume,
                'avg_difficulty' => $avg_kd,
                'opportunity' => $best_opp,
                'needs_recluster' => 0,
                'needs_rescore' => 1,
                'clustered_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ], ['id' => $existing_id]);
            $cluster_id = $existing_id;
        } else {
            $wpdb->insert($cluster_table, [
                'cluster_key' => $bucket_key,
                'representative' => $best_kw,
                'keywords' => wp_json_encode(array_values(array_unique($keywords))),
                'total_volume' => $total_volume,
                'avg_difficulty' => $avg_kd,
                'opportunity' => $best_opp,
                'status' => 'new',
                'needs_recluster' => 0,
                'needs_rescore' => 1,
                'clustered_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
            $cluster_id = (int) $wpdb->insert_id;
        }

        if ($cluster_id > 0) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$cluster_keyword_map_table} WHERE cluster_id=%d", $cluster_id));
            foreach (array_values(array_unique($keywords)) as $mapped_kw) {
                $wpdb->insert($cluster_keyword_map_table, [
                    'keyword' => $mapped_kw,
                    'cluster_id' => $cluster_id,
                    'page_id' => null,
                    'updated_at' => current_time('mysql'),
                ], ['%s','%d','%d','%s']);
            }

            DirtyQueue::enqueue('cluster', $cluster_id, $reason);
        }

        $wpdb->update($cand_table, [
            'needs_recluster' => 0,
            'needs_rescore' => 0,
            'clustered_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => $keyword_id]);
    }

    public static function process_dirty_cluster(int $cluster_id, string $reason = 'cluster_changed'): void {
        self::refresh_cluster_stats([$cluster_id]);

        DirtyQueue::enqueue('page', $cluster_id, $reason);
    }

    public static function process_dirty_page(int $cluster_id, string $reason = 'cluster_stats_changed'): void {
        // Keep existing business flow: queue opportunities for page drafting from updated clusters.
        self::store_suggested_pages_from_clusters(3);
    }

    /** @param int[] $cluster_ids */
    private static function refresh_cluster_stats(array $cluster_ids): void {
        global $wpdb;

        $cluster_ids = array_values(array_unique(array_filter(array_map('intval', $cluster_ids))));
        if (empty($cluster_ids)) {
            return;
        }

        $stats_table = $wpdb->prefix . 'tmw_seo_cluster_stats';
        $map_table = $wpdb->prefix . 'tmw_keyword_cluster_map';
        $rank_table = $wpdb->prefix . 'tmw_seo_ranking_probability';
        $cand_table = $wpdb->prefix . 'tmw_keyword_candidates';
        $serp_table = $wpdb->prefix . 'tmw_seo_serp_analysis';
        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';

        foreach ($cluster_ids as $cluster_id) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT m.keyword, kc.volume, kc.difficulty, kc.opportunity, rp.ranking_probability, sa.serp_weakness_score
                 FROM {$map_table} m
                 LEFT JOIN {$cand_table} kc ON kc.keyword = m.keyword
                 LEFT JOIN {$rank_table} rp ON rp.keyword = m.keyword
                 LEFT JOIN {$serp_table} sa ON sa.cluster_id = m.cluster_id
                 WHERE m.cluster_id=%d",
                $cluster_id
            ), ARRAY_A);

            $count = count($rows);
            $volume_sum = 0;
            $kd_sum = 0.0;
            $kd_n = 0;
            $serp_sum = 0.0;
            $rank_sum = 0.0;
            foreach ($rows as $row) {
                $volume_sum += (int) ($row['volume'] ?? 0);
                $kd = (float) ($row['difficulty'] ?? 0);
                if ($kd > 0) { $kd_sum += $kd; $kd_n++; }
                $serp_sum += (float) ($row['serp_weakness_score'] ?? 0);
                $rank_sum += (float) ($row['ranking_probability'] ?? 0);
            }

            $avg_kd = $kd_n > 0 ? round($kd_sum / $kd_n, 2) : 0;
            $serp_weakness = $count > 0 ? round($serp_sum / $count, 4) : 0;
            $ranking_probability = $count > 0 ? round($rank_sum / $count, 2) : 0;
            $opportunity_score = round(($volume_sum * $ranking_probability) + ($serp_weakness * 100) + $count, 4);

            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$stats_table} (cluster_id, keyword_count, volume_sum, avg_kd, serp_weakness, ranking_probability, opportunity_score, updated_at)
                 VALUES (%d,%d,%d,%f,%f,%f,%f,%s)
                 ON DUPLICATE KEY UPDATE
                    keyword_count=VALUES(keyword_count),
                    volume_sum=VALUES(volume_sum),
                    avg_kd=VALUES(avg_kd),
                    serp_weakness=VALUES(serp_weakness),
                    ranking_probability=VALUES(ranking_probability),
                    opportunity_score=VALUES(opportunity_score),
                    updated_at=VALUES(updated_at)",
                $cluster_id, $count, $volume_sum, $avg_kd, $serp_weakness, $ranking_probability, $opportunity_score, current_time('mysql')
            ));

            $wpdb->update($cluster_table, [
                'needs_rescore' => 0,
                'metrics_updated_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ], ['id' => $cluster_id]);
        }
    }


    private static function store_topic_suggestions(int $limit): void {
        $models = get_posts([
            'post_type' => 'model',
            'post_status' => 'publish',
            'posts_per_page' => max(1, $limit),
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids',
        ]);

        if (!is_array($models) || empty($models)) {
            return;
        }

        $engine = new \TMW_Topic_Engine();
        $stored = 0;
        foreach ($models as $model_id) {
            $stored += $engine->queue_topic_suggestions_for_model((int) $model_id);
        }

        Logs::info('keywords', '[TMW-KW] Topic suggestions queued', ['count' => $stored]);
    }

    private static function store_suggested_pages_from_clusters(int $limit): void {
        global $wpdb;
        $cluster_table = $wpdb->prefix . 'tmw_keyword_clusters';

        if ($limit <= 0) return;

        $clusters = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$cluster_table}
             WHERE page_id IS NULL
             ORDER BY opportunity DESC, total_volume DESC
             LIMIT %d",
            $limit
        ), ARRAY_A);

        if (empty($clusters)) {
            Logs::info('keywords', '[TMW-KW] No clusters available for suggested pages');
            return;
        }

        $db = new \TMWSEO\Engine\Opportunities\OpportunityDatabase();
        $rows = [];
        foreach ($clusters as $c) {
            $keyword = (string)($c['representative'] ?? '');
            if ($keyword === '') {
                continue;
            }

            $rows[] = [
                'keyword' => strtolower($keyword),
                'search_volume' => (int) ($c['total_volume'] ?? 0),
                'difficulty' => (float) ($c['avg_difficulty'] ?? 0),
                'opportunity_score' => (float) ($c['opportunity'] ?? 0),
                'competitor_url' => 'cluster:' . (int) ($c['id'] ?? 0),
                'source' => 'keyword_cycle',
                'type' => 'keyword_cluster',
                'recommended_action' => 'Create Draft',
            ];
        }

        $stored = $db->store($rows);

        Logs::info('keywords', '[TMW-KW] Suggested pages queued from keyword clusters', [
            'count' => $stored,
        ]);
    }
}
