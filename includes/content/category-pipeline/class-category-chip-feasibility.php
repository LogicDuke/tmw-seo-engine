<?php
/**
 * CategoryChipFeasibility — universal stored-chip feasibility analysis.
 *
 * The production generator can safely render only the subset of stored Rank
 * Math extras that fits the family-cluster, heading, FAQ, sentence, and
 * density contracts. Extra same-family operator-selected chips are retained as
 * tracking-only with explicit reasons instead of being silently deleted.
 */
namespace TMWSEO\Engine\Content\CategoryPipeline;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CategoryChipFeasibility {
    public const SAFE_RENDERED_PER_FAMILY = 2;
    public const H2_SLOTS = 3;
    public const FAQ_SLOTS = 4;
    public const TARGET_WORDS = 700;
    public const DENSITY_CEILING = 2.2;

    /** @param string[] $chips @return array<string,mixed> */
    public static function analyze(string $primary, array $chips, int $target_words = self::TARGET_WORDS): array {
        $target_words = max(450, $target_words);
        $reasons=[]; $coverage_reasons=[];
        $seen=[]; $clean=[]; $covered=[]; $relationships=[];
        foreach ($chips as $chip) {
            $chip=trim((string)$chip); $k=self::lc($chip);
            if($chip==='' || isset($seen[$k]) || strcasecmp($chip,$primary)===0){ continue; }
            $seen[$k]=true;
            if (self::is_contiguous_subsequence($chip, $primary)) {
                $covered[]=['keyword'=>$chip,'family'=>self::root_family($chip),'reason'=>'covered_by_primary','covered_by'=>$primary];
                $relationships[]=['container'=>$primary,'contained'=>$chip,'type'=>'contiguous_token_subsequence'];
                $coverage_reasons[]=$chip.': covered_by_primary';
                continue;
            }
            $clean[]=$chip;
        }
        $groups=[];
        foreach ($clean as $i=>$chip) { $fam=self::root_family($chip); $groups[$fam][]=['keyword'=>$chip,'priority'=>$i,'words'=>self::word_count($chip)]; }
        $rendered=[]; $tracking=[]; $limits=[]; $matches=1; $heading=0; $faq=0;
        foreach ($groups as $fam=>$items) {
            usort($items, static fn($a,$b)=>($a['priority']<=>$b['priority']) ?: ($b['words']<=>$a['words']));
            $budget=min(self::SAFE_RENDERED_PER_FAMILY, count($items));
            $limits[$fam]=['safe_rendered_limit'=>self::SAFE_RENDERED_PER_FAMILY,'rendered_budget'=>$budget,'available_h2_slots'=>self::H2_SLOTS,'available_faq_slots'=>self::FAQ_SLOTS];
            foreach ($items as $n=>$item) {
                $chip=(string)$item['keyword'];
                if ($n < $budget && ($heading + $faq) < (self::H2_SLOTS + self::FAQ_SLOTS)) {
                    $role = $heading < self::H2_SLOTS ? ['type'=>'heading','slot'=>++$heading] : ['type'=>'faq','slot'=>++$faq];
                    $rendered[]=['keyword'=>$chip,'family'=>$fam,'reason'=>'safely_placeable_family_representative'] + $role; $matches++;
                } else {
                    $why = $n >= self::SAFE_RENDERED_PER_FAMILY ? 'same_family_render_budget_exceeded' : 'heading_faq_placement_budget_exceeded';
                    $tracking[]=['keyword'=>$chip,'family'=>$fam,'reason'=>$why];
                    $reasons[]=$chip.': '.$why;
                }
            }
        }
        $density=round(($matches / $target_words) * 100, 3);
        $feasible = $density <= self::DENSITY_CEILING && $heading <= self::H2_SLOTS && $faq <= self::FAQ_SLOTS;
        if (!$feasible) { $reasons[]='Rendered subset would exceed density or placement budgets.'; }
        return [
            'feasible'=>$feasible,'failure_code'=>$feasible?'':'chip_set_unsatisfiable','reasons'=>array_values(array_unique($reasons)),
            'rendered_chips'=>$rendered,'tracking_only_chips'=>$tracking,'covered_by_primary_chips'=>$covered,'containment_relationships'=>$relationships,'coverage_reasons'=>array_values(array_unique($coverage_reasons)),'family_groups'=>$groups,'family_limits'=>$limits,
            'projected_min_matches'=>$matches,'projected_density'=>$density,'heading_demand'=>$heading,'faq_demand'=>$faq,
        ];
    }
    /**
     * v5.9.15 — ACTIVE RANK MATH KEYWORD SET (the universal keyword contract).
     *
     * The Rank Math focus-keyword CSV may contain ONLY keywords the generator
     * will genuinely place (rendered) or that Rank Math provably counts
     * through the primary phrase (covered_by_primary, contiguous-substring
     * semantics of the shipped analyzer). Everything else is EXCLUDED from
     * the active set — with an explicit operator-visible reason — instead of
     * living on as an active-but-unplaced chip (the July 2026 audit defect).
     *
     * This is the single source of truth consumed by:
     *   - ContentEngine::apply_category_rankmath_extras (CSV write, pre-generation)
     *   - the generation pipeline (stored_chips == active set on the production path)
     *   - the readiness gate (coverage of the live CSV)
     *   - the chip report (RankMathChipAnalyzer reads the same CSV)
     *
     * Determinism guarantees all four agree without shared mutable state.
     *
     * @param string   $primary
     * @param string[] $candidates Operator-selected extras in priority order.
     * @return array{active:string[],covered:array<int,array<string,mixed>>,excluded:array<int,array<string,mixed>>,feasible:bool,failure_code:string,analysis:array<string,mixed>}
     */
    public static function active_set(string $primary, array $candidates, int $target_words = self::TARGET_WORDS): array {
        $analysis = self::analyze($primary, $candidates, $target_words);
        $rendered = [];
        foreach ((array) $analysis['rendered_chips'] as $row) { $rendered[self::lc((string) $row['keyword'])] = true; }
        $covered_map = [];
        foreach ((array) $analysis['covered_by_primary_chips'] as $row) { $covered_map[self::lc((string) $row['keyword'])] = $row; }

        // Preserve the operator's priority order in the active list.
        $active = []; $covered = []; $seen = [self::lc($primary) => true];
        foreach ($candidates as $kw) {
            $kw = trim((string) $kw); $k = self::lc($kw);
            if ($kw === '' || isset($seen[$k])) { continue; }
            $seen[$k] = true;
            if (isset($rendered[$k])) { $active[] = $kw; continue; }
            if (isset($covered_map[$k])) { $active[] = $kw; $covered[] = (array) $covered_map[$k]; continue; }
        }
        $excluded = [];
        foreach ((array) $analysis['tracking_only_chips'] as $row) {
            $excluded[] = [
                'keyword' => (string) ($row['keyword'] ?? ''),
                'family'  => (string) ($row['family'] ?? ''),
                'reason'  => (string) ($row['reason'] ?? ''),
            ];
        }
        return [
            'active'       => $active,
            'covered'      => $covered,
            'excluded'     => $excluded,
            'feasible'     => (bool) $analysis['feasible'],
            'failure_code' => (string) $analysis['failure_code'],
            'analysis'     => $analysis,
        ];
    }

    public static function root_family(string $keyword): string { return CategoryKeywordPlanner::root_family($keyword); }
    private static function word_count(string $s): int { return count(preg_split('/\s+/u', trim($s)) ?: []); }
    private static function is_contiguous_subsequence(string $needle, string $haystack): bool {
        $n=self::tokens($needle); $h=self::tokens($haystack); if(empty($n) || count($n)>=count($h)){ return false; }
        for($i=0; $i<=count($h)-count($n); $i++){ if(array_slice($h,$i,count($n))===$n){ return true; } }
        return false;
    }
    private static function tokens(string $s): array { $s=preg_replace('/[^a-z0-9\s]+/u',' ',self::lc($s)) ?: ''; return array_values(array_filter(preg_split('/\s+/u', trim($s)) ?: [], 'strlen')); }
    private static function lc(string $s): string { return function_exists('mb_strtolower') ? mb_strtolower($s,'UTF-8') : strtolower($s); }
}
