<?php
namespace TMWSEO\Engine\Keywords;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class DiscoveryOrchestrator {
    private const MAX_SEEDS_PER_RUN = 300;

    public static function run(array $context = []): array {
        $source = (string) ($context['source'] ?? 'unknown');
        $seeds = SeedRegistry::get_seeds_for_discovery(self::MAX_SEEDS_PER_RUN);
        $seed_values = [];
        $seed_ids = [];

        foreach ($seeds as $row) {
            $seed = (string) ($row['seed'] ?? '');
            $id = (int) ($row['id'] ?? 0);
            if ($seed === '' || $id <= 0) {
                continue;
            }
            $seed_values[] = $seed;
            $seed_ids[] = $id;
        }

        SeedRegistry::mark_seeds_used($seed_ids);
        update_option('tmw_seed_registry_last_cycle_used', count($seed_ids), false);
        update_option('tmw_seed_registry_last_discovery_run', current_time('mysql'), false);

        $diagnostics = SeedRegistry::diagnostics();
        Logs::info('keywords', '[TMW-KW] Discovery orchestrator run', [
            'source' => $source,
            'selected_seeds' => count($seed_values),
            'max_seeds_per_run' => self::MAX_SEEDS_PER_RUN,
            'seed_sources' => $diagnostics['seed_sources'] ?? [],
            'duplicates_prevented' => $diagnostics['duplicate_prevention_count'] ?? 0,
        ]);

        return [
            'seeds' => $seed_values,
            'seed_count' => count($seed_values),
            'max_seeds_per_run' => self::MAX_SEEDS_PER_RUN,
        ];
    }
}
