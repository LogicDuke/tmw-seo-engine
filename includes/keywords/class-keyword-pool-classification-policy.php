<?php
/**
 * Authoritative keyword-pool classification policy.
 *
 * @package TMWSEO\Engine\Keywords
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

/**
 * Authoritative, side-effect-free classifier for keyword-pool import rows.
 *
 * The policy receives a normalized dry-run/import row plus target context and returns the
 * canonical pool-fit, archive/safety, commercial, difficulty, eligibility, decision, and
 * reason-code fields consumed by dry-run previews, scoring, import replay, and repair
 * reports. It never writes candidates, posts, terms, metadata, or indexing state.
 */
class KeywordPoolClassificationPolicy {
    public const BROAD_NON_TMW_CHAT_INTENTS = [ 'free cam chat', 'free video chat', 'online video chat', 'adult video chat' ];
    public const BROWSE_DIRECTORY_INTENTS = [ 'free cam chat rooms', 'free cam chat sites', 'webcam chat rooms', 'cam chat sites', 'webcam models', 'cam models', 'live cam models', 'live webcam models', 'asian webcam models', 'latina webcam models', 'blonde webcam models', 'busty webcam models', 'milf webcam models' ];
    public const UNSAFE_KEYWORDS = [ 'schoolgirl roleplay', 'spy cam shows' ];
    public const GEO_LOCAL_PHRASES = [ ' near me', 'local webcam', 'local cam', 'local ' ];

    /**
     * Classify one keyword row against the requested pool and target context.
     *
     * Expected `$row` keys include `keyword` or `normalized_keyword`, optional metrics
     * (`difficulty`, `competition`, `cpc`, `traffic_value`), and optional `model_name`.
     * Expected `$context` keys include target descriptors such as `target_title`,
     * `target_name`, `target_slug`, `target_topic`, or `model_name`.
     *
     * Returns `pool_fit`, `archive_class`, `commercial_intent`, `difficulty_band`,
     * `difficulty_source`, `eligibility`, `decision`, and `reason_codes`. The method is
     * deterministic and side-effect free; it is classification-only and cannot persist
     * candidates or mutate stored import rows.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function classify(array $row, string $pool, array $context = []): array {
        $pool = in_array($pool, [ 'model', 'video', 'category' ], true) ? $pool : 'category';
        $keyword = self::normalize((string) ($row['normalized_keyword'] ?? $row['keyword'] ?? ''));
        $target = $this->target_topic($row, $context);
        $reasons = [];
        $pool_fit = 'unclear';
        $archive_class = 'none';
        $eligibility = 'review';
        $decision = 'review';

        if ('' === $keyword) {
            return $this->result('irrelevant', 'noise', 'unknown', 'unknown', 'unknown', 'blocked', 'block', [ 'missing_keyword' ]);
        }
        if ($this->matches_any($keyword, self::UNSAFE_KEYWORDS)) {
            $reasons[] = 'unsafe_keyword';
            if ('schoolgirl roleplay' === $keyword) { $reasons[] = 'rename_recommended'; }
            return $this->result('irrelevant', 'unsafe', $this->commercial_intent($row), $this->difficulty_band($row), $this->difficulty_source($row), 'blocked', 'block', $reasons);
        }
        if ($this->matches_any($keyword, self::GEO_LOCAL_PHRASES)) {
            return $this->result('irrelevant', 'geo_local', $this->commercial_intent($row), $this->difficulty_band($row), $this->difficulty_source($row), 'blocked', 'block', [ 'geo_local_intent' ]);
        }

        $model_name = self::normalize((string) ($row['model_name'] ?? $context['model_name'] ?? ''));
        if ('video' === $pool && '' !== $model_name && $keyword === $model_name) {
            return $this->result('irrelevant', 'none', $this->commercial_intent($row), $this->difficulty_band($row), $this->difficulty_source($row), 'blocked', 'reject', [ 'standalone_model_name', 'video_intent_required' ]);
        }

        $target_match = $this->target_match($keyword, $target);
        if ('exact' === $target_match) {
            $pool_fit = 'exact_target_topic';
            $eligibility = 'candidate';
            $decision = 'accept';
            $reasons[] = 'exact_target_topic';
        } elseif ('close' === $target_match) {
            if ('category' === $pool && $this->matches_any($keyword, [ 'rooms', 'sites', 'directory', 'directories' ])) {
                $pool_fit = 'browse_supporting';
                $archive_class = 'browse_directory';
                $eligibility = 'review';
                $decision = 'review';
                $reasons[] = 'target_context_browse_supporting_intent';
            } else {
                $pool_fit = 'category' === $pool ? 'category_intent' : $pool . '_intent';
                $eligibility = 'candidate';
                $decision = 'accept';
                $reasons[] = 'target_topic_match';
            }
        } elseif ('category' === $pool && $this->matches_any($keyword, self::BROWSE_DIRECTORY_INTENTS)) {
            $pool_fit = 'browse_supporting';
            $archive_class = 'browse_directory';
            $eligibility = 'review';
            $decision = 'review';
            $reasons[] = 'browse_supporting_intent';
        } elseif ($this->matches_any($keyword, self::BROAD_NON_TMW_CHAT_INTENTS)) {
            $pool_fit = 'irrelevant';
            $archive_class = 'broad_non_tmw_chat';
            $eligibility = 'archive';
            $decision = 'archive';
            $reasons[] = 'broad_non_tmw_chat_intent';
        }

        if ('unclear' === $pool_fit) {
            if ('category' === $pool && $this->matches_any($keyword, [ 'category', 'categories', 'browse', 'archive', 'topic', 'model', 'models', 'cam model', 'cam models', 'webcam model', 'webcam models', 'cam girls', 'webcam girls', 'live cam', 'cam chat', 'cam shows', 'livejasmin', 'jasmin', 'couples live webcam', 'private cam shows', 'blonde', 'brunette', 'latina', 'lesbian', 'ebony', 'asian', 'indian', 'busty', 'teen', 'mature' ])) {
                $pool_fit = 'category_intent'; $eligibility = 'candidate'; $decision = 'accept'; $reasons[] = 'category_intent_detected';
            } elseif ('video' === $pool && $this->matches_any($keyword, [ 'video', 'videos', 'webcam video', 'clip', 'clips', 'session', 'scene', 'watch', 'stream' ])) {
                $pool_fit = 'video_intent'; $eligibility = 'candidate'; $decision = 'accept'; $reasons[] = 'video_intent_detected';
            } elseif ('model' === $pool && $this->matches_any($keyword, [ 'model', 'models', 'profile', 'bio', 'biography', 'performer', 'talent', 'cam girl', 'webcam model' ])) {
                $pool_fit = 'model_intent'; $eligibility = 'candidate'; $decision = 'accept'; $reasons[] = 'model_intent_detected';
            } else {
                $reasons[] = $pool . '_intent_unclear';
            }
        }
        $commercial = $this->commercial_intent($row);
        if ('low' === $commercial && in_array($archive_class, [ 'broad_non_tmw_chat', 'none' ], true) && 'candidate' !== $eligibility) { $reasons[] = 'low_commercial_intent'; }
        return $this->result($pool_fit, $archive_class, $commercial, $this->difficulty_band($row), $this->difficulty_source($row), $eligibility, $decision, $reasons);
    }

    /** @return array<string,mixed> */
    private function result(string $pool_fit, string $archive_class, string $commercial, string $difficulty, string $difficulty_source, string $eligibility, string $decision, array $reasons): array {
        return [ 'pool_fit'=>$pool_fit, 'archive_class'=>$archive_class, 'commercial_intent'=>$commercial, 'difficulty_band'=>$difficulty, 'difficulty_source'=>$difficulty_source, 'eligibility'=>$eligibility, 'decision'=>$decision, 'reason_codes'=>array_values(array_unique(array_filter($reasons))) ];
    }
    public static function normalize(string $v): string { $v = strtolower(trim(strip_tags($v))); $v = preg_replace('/[^a-z0-9]+/', ' ', $v) ?? $v; return trim(preg_replace('/\s+/', ' ', $v) ?? $v); }
    private function target_topic(array $row, array $context): string { foreach ([ 'target_topic','normalized_target_topic','target_title','target_name','title','target_slug','slug','category' ] as $k) { $v = self::normalize((string)($context[$k] ?? $row[$k] ?? '')); if ('' !== $v) return $v; } return ''; }
    private function target_match(string $kw, string $target): string { if ('' === $target) return 'none'; if ($kw === $target || str_contains($kw, $target) || str_contains($target, $kw)) return 'exact'; $kt = array_values(array_diff(explode(' ', $kw), [ 'free','adult','live','to','the','best' ])); $tt = array_values(array_diff(explode(' ', $target), [ 'free','adult','live','to','the','best' ])); if (count($tt) >= 2 && count(array_intersect($tt, $kt)) >= max(2, count($tt)-1)) return 'close'; return 'none'; }
    private function matches_any(string $kw, array $needles): bool { foreach ($needles as $n) { if (str_contains($kw, $n)) return true; } return false; }
    private function number($v): ?float { if ($v === null || $v === '' || (is_string($v) && in_array(strtolower(trim($v)), ['','null','n/a','na','-'], true))) return null; if (is_int($v)||is_float($v)) return is_nan((float)$v) ? null : (float)$v; $n=str_replace([',','$','%','+'],'',strtolower(trim((string)$v))); return is_numeric($n)?(float)$n:null; }
    private function commercial_intent(array $row): string { $cpc=$this->number($row['cpc']??null); $tv=$this->number($row['traffic_value']??null); if ($cpc===null && $tv===null) return 'unknown'; if (($cpc!==null && $cpc>=2.0)||($tv!==null && $tv>=1000)) return 'high'; if (($cpc!==null && $cpc>=1.0)||($tv!==null && $tv>=100)) return 'medium'; return 'low'; }
    private function difficulty_source(array $row): string { return null !== $this->number($row['difficulty']??null) ? 'keyword_difficulty' : (null !== $this->number($row['competition']??null) ? 'competition_proxy' : 'unknown'); }
    private function difficulty_band(array $row): string { $d=$this->number($row['difficulty']??null); if ($d===null) { $d=$this->number($row['competition']??null); if ($d===null) return 'unknown'; if ($d<=0.10) return 'very_easy'; if ($d<=0.20) return 'easy'; if ($d<=0.40) return 'medium'; return 'hard'; } if ($d<=20) return 'very_easy'; if ($d<=40) return 'easy'; if ($d<=60) return 'medium'; return 'hard'; }
}
