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
        $seen=[]; $clean=[];
        foreach ($chips as $chip) { $chip=trim((string)$chip); $k=self::lc($chip); if($chip!=='' && !isset($seen[$k]) && strcasecmp($chip,$primary)!==0){$seen[$k]=true; $clean[]=$chip;} }
        $groups=[];
        foreach ($clean as $i=>$chip) { $fam=self::root_family($chip); $groups[$fam][]=['keyword'=>$chip,'priority'=>$i,'words'=>self::word_count($chip)]; }
        $rendered=[]; $tracking=[]; $limits=[]; $reasons=[]; $matches=1; $heading=0; $faq=0;
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
            'rendered_chips'=>$rendered,'tracking_only_chips'=>$tracking,'family_groups'=>$groups,'family_limits'=>$limits,
            'projected_min_matches'=>$matches,'projected_density'=>$density,'heading_demand'=>$heading,'faq_demand'=>$faq,
        ];
    }
    public static function root_family(string $keyword): string { return CategoryKeywordPlanner::root_family($keyword); }
    private static function word_count(string $s): int { return count(preg_split('/\s+/u', trim($s)) ?: []); }
    private static function lc(string $s): string { return function_exists('mb_strtolower') ? mb_strtolower($s,'UTF-8') : strtolower($s); }
}
