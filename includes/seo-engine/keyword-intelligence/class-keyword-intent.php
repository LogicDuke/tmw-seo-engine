<?php
/**
 * KeywordIntent — classifies keywords by search intent.
 *
 * v4.2: Upgraded to GPT batch classification (50 keywords per call).
 * Falls back to regex-pattern heuristics if AI is unavailable or over budget.
 *
 * @since 4.2.0
 */
namespace TMWSEO\Engine\KeywordIntelligence;

if (!defined('ABSPATH')) { exit; }

use TMWSEO\Engine\AI\AIRouter;
use TMWSEO\Engine\Logs;

class KeywordIntent {

    const CACHE_PREFIX = 'tmwseo_kw_intent_';
    const VALID_INTENTS = ['watch_intent', 'brand_intent', 'model_intent', 'informational', 'navigational', 'commercial', 'transactional', 'generic_intent'];

    /**
     * Classifies a single keyword. Uses cache, then AI batch, then heuristic fallback.
     */
    public function classify(string $keyword, array $context = []): string {
        $keyword = strtolower(trim($keyword));
        if ($keyword === '') return 'generic_intent';

        $cached = get_transient(self::CACHE_PREFIX . md5($keyword));
        if ($cached !== false && in_array($cached, self::VALID_INTENTS, true)) {
            return (string) $cached;
        }

        // Single classification — use heuristic (batch AI is more efficient)
        $intent = $this->heuristic_classify($keyword, $context);
        set_transient(self::CACHE_PREFIX . md5($keyword), $intent, WEEK_IN_SECONDS);
        return $intent;
    }

    /**
     * Classifies a batch of keywords via GPT.
     * Returns map[keyword => intent].
     *
     * @param string[] $keywords
     * @return array<string,string>
     */
    public function classify_batch(array $keywords, array $context = []): array {
        $keywords = array_values(array_unique(array_filter(array_map('strtolower', array_map('trim', $keywords)))));
        if (empty($keywords)) return [];

        // Check cache first
        $uncached = [];
        $results  = [];
        foreach ($keywords as $kw) {
            $cached = get_transient(self::CACHE_PREFIX . md5($kw));
            if ($cached !== false && in_array($cached, self::VALID_INTENTS, true)) {
                $results[$kw] = (string) $cached;
            } else {
                $uncached[] = $kw;
            }
        }

        if (empty($uncached)) return $results;

        // Process in batches of 50
        $chunks = array_chunk($uncached, 50);
        foreach ($chunks as $chunk) {
            $batch_results = $this->ai_classify_batch($chunk, $context);
            foreach ($chunk as $kw) {
                $intent = $batch_results[$kw] ?? $this->heuristic_classify($kw, $context);
                if (!in_array($intent, self::VALID_INTENTS, true)) {
                    $intent = 'generic_intent';
                }
                $results[$kw] = $intent;
                set_transient(self::CACHE_PREFIX . md5($kw), $intent, WEEK_IN_SECONDS);
            }
        }

        return $results;
    }

    // ── AI batch classifier ────────────────────────────────────────────────

    private function ai_classify_batch(array $keywords, array $context): array {
        if (AIRouter::is_over_budget()) {
            return [];
        }

        $site_type = 'adult cam model directory';
        $context_str = '';
        if (!empty($context['model_name'])) {
            $context_str = 'Site model name context: ' . $context['model_name'] . '. ';
        }

        $kw_json  = wp_json_encode(array_values($keywords));
        $messages = [
            [
                'role'    => 'system',
                'content' => "You are an SEO keyword intent classifier for a {$site_type} website. Classify each keyword into exactly one of these intents: watch_intent (user wants to watch a live cam show), brand_intent (user is searching for a specific platform like Chaturbate), model_intent (user is searching for a specific model by name), informational (user wants to learn), navigational (user wants a specific site), commercial (user is comparing or evaluating), transactional (user wants to take action/buy), generic_intent (unclear or mixed intent). Respond ONLY with a JSON object where keys are the exact keywords provided and values are the intent string. No other text.",
            ],
            [
                'role'    => 'user',
                'content' => "{$context_str}Classify these keywords:\n{$kw_json}",
            ],
        ];

        $res = AIRouter::chat_json($messages, ['quality' => false, 'max_tokens' => 512]);
        if (!$res['ok'] || empty($res['json'])) {
            Logs::warn('keyword_intent', 'AI batch classification failed', ['error' => $res['error'] ?? 'unknown']);
            return [];
        }

        $map = [];
        foreach ($res['json'] as $kw => $intent) {
            $kw     = strtolower(trim((string)$kw));
            $intent = strtolower(trim((string)$intent));
            if ($kw !== '' && in_array($intent, self::VALID_INTENTS, true)) {
                $map[$kw] = $intent;
            }
        }

        return $map;
    }

    // ── Heuristic fallback ─────────────────────────────────────────────────

    private function heuristic_classify(string $keyword, array $context): string {
        $model_name = strtolower((string) ($context['model_name'] ?? ''));
        $platform   = strtolower((string) ($context['platform_name'] ?? ''));

        if ($model_name !== '' && strpos($keyword, $model_name) !== false) {
            if (preg_match('/\b(watch|live|stream|cam|show|webcam)\b/u', $keyword)) {
                return 'watch_intent';
            }
            if ($platform !== '' && strpos($keyword, $platform) !== false) {
                return 'brand_intent';
            }
            return 'model_intent';
        }

        if ($platform !== '' && strpos($keyword, $platform) !== false) {
            return 'brand_intent';
        }

        $cam_platforms = ['chaturbate', 'stripchat', 'livejasmin', 'myfreecams', 'camsoda', 'cam4', 'bongacams'];
        foreach ($cam_platforms as $p) {
            if (strpos($keyword, $p) !== false) return 'brand_intent';
        }

        if (preg_match('/\b(watch|live|stream|webcam|cam show)\b/', $keyword)) {
            return 'watch_intent';
        }
        if (preg_match('/\b(buy|price|discount|cheap|deal|subscribe|join|sign.?up)\b/', $keyword)) {
            return 'transactional';
        }
        if (preg_match('/\b(best|top|vs|compare|review|rating)\b/', $keyword)) {
            return 'commercial';
        }
        if (preg_match('/\b(how|what|why|when|guide|tutorial|tips|learn)\b/', $keyword)) {
            return 'informational';
        }

        return 'generic_intent';
    }
}
