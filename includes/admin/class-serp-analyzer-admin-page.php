<?php
namespace TMWSEO\Engine\Admin;

use TMWSEO\Engine\Services\DataForSEO;
use TMWSEO\Engine\Services\OpenAI;

if (!defined('ABSPATH')) { exit; }

class SerpAnalyzerAdminPage {
    private const OPTION_OPENAI_DAILY_LIMIT = 'tmwseo_openai_daily_limit';

    public static function init(): void {
        add_action('admin_post_tmwseo_analyze_serp', [__CLASS__, 'handle_analyze']);
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $result_key = sanitize_key((string) ($_GET['result_key'] ?? ''));
        $result = is_string($result_key) && $result_key !== '' ? get_transient('tmwseo_serp_ui_' . $result_key) : null;
        if (is_array($result)) {
            delete_transient('tmwseo_serp_ui_' . $result_key);
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('SERP Analyzer', 'tmwseo') . '</h1>';

        echo '<div class="tmwseo-card" style="max-width:1100px;">';
        echo '<h2>' . esc_html__('SERP Reverse Engineering Engine', 'tmwseo') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="tmwseo-serp-analyzer-form">';
        wp_nonce_field('tmwseo_serp_analyzer_action');
        echo '<input type="hidden" name="action" value="tmwseo_analyze_serp" />';
        echo '<p><label for="tmwseo_serp_keyword"><strong>' . esc_html__('Keyword', 'tmwseo') . '</strong></label></p>';
        echo '<input type="text" id="tmwseo_serp_keyword" name="keyword" class="regular-text" required placeholder="e.g. best hiking boots" /> ';
        submit_button(__('Analyze SERP', 'tmwseo'), 'primary', 'submit', false, ['id' => 'tmwseo-serp-submit']);
        echo ' <span id="tmwseo-serp-loading" style="display:none; margin-left:8px;">' . esc_html__('Analyzing top 10 results…', 'tmwseo') . '</span>';
        echo '</form>';
        echo '</div>';

        if (is_array($result)) {
            if (!empty($result['error'])) {
                echo '<div class="notice notice-error"><p>' . esc_html((string) $result['error']) . '</p></div>';
            } else {
                self::render_results($result);
            }
        }

        echo '</div>';
        echo '<script>document.getElementById("tmwseo-serp-analyzer-form")?.addEventListener("submit",function(){document.getElementById("tmwseo-serp-loading").style.display="inline";document.getElementById("tmwseo-serp-submit").disabled=true;});</script>';
    }

    public static function handle_analyze(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('tmwseo_serp_analyzer_action');

        $keyword = sanitize_text_field((string) ($_POST['keyword'] ?? ''));
        if ($keyword === '') {
            self::redirect_with_result(['error' => 'Keyword is required.']);
        }

        $cache_key = 'tmwseo_serp_analyzer_' . md5(mb_strtolower($keyword, 'UTF-8'));
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            self::redirect_with_result($cached);
        }

        $serp = DataForSEO::serp_organic_live($keyword, 10);
        if (empty($serp['ok'])) {
            self::redirect_with_result(['error' => 'DataForSEO request failed: ' . (string) ($serp['error'] ?? 'unknown')]);
        }

        $items = array_slice((array) ($serp['items'] ?? []), 0, 10);
        $analyzed = [];
        $heading_freq = [];
        $entity_freq = [];
        $word_counts = [];

        foreach ($items as $item) {
            $url = esc_url_raw((string) ($item['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $page_stats = self::analyze_url($url);
            $word_counts[] = (int) ($page_stats['word_count'] ?? 0);

            foreach ((array) ($page_stats['headings'] ?? []) as $h) {
                $h = trim((string) $h);
                if ($h === '') {
                    continue;
                }
                $heading_freq[$h] = ($heading_freq[$h] ?? 0) + 1;
            }

            foreach ((array) ($page_stats['entities'] ?? []) as $entity) {
                $entity = trim((string) $entity);
                if ($entity === '') {
                    continue;
                }
                $entity_freq[$entity] = ($entity_freq[$entity] ?? 0) + 1;
            }

            $analyzed[] = [
                'position' => (int) ($item['position'] ?? 0),
                'domain' => (string) ($item['domain'] ?? parse_url($url, PHP_URL_HOST)),
                'url' => $url,
                'word_count' => (int) ($page_stats['word_count'] ?? 0),
                'h1_count' => (int) ($page_stats['h1_count'] ?? 0),
                'h2_count' => (int) ($page_stats['h2_count'] ?? 0),
                'h3_count' => (int) ($page_stats['h3_count'] ?? 0),
                'title_length' => (int) ($page_stats['title_length'] ?? 0),
                'schema_present' => !empty($page_stats['schema_present']),
                'internal_links' => (int) ($page_stats['internal_links'] ?? 0),
                'external_links' => (int) ($page_stats['external_links'] ?? 0),
            ];
        }

        arsort($heading_freq);
        arsort($entity_freq);

        $recommended_word_count = 0;
        $valid_wc = array_values(array_filter($word_counts, static fn($wc) => $wc > 0));
        if (!empty($valid_wc)) {
            sort($valid_wc);
            $median = $valid_wc[(int) floor(count($valid_wc) / 2)];
            $recommended_word_count = (int) round($median * 1.1);
        }

        $result = [
            'keyword' => $keyword,
            'rows' => $analyzed,
            'blueprint' => [
                'recommended_word_count' => $recommended_word_count,
                'common_headings' => array_slice(array_keys($heading_freq), 0, 8),
                'entities' => array_slice(array_keys($entity_freq), 0, 12),
            ],
            'raw_json' => (array) ($serp['raw'] ?? []),
        ];

        set_transient($cache_key, $result, DAY_IN_SECONDS);
        self::redirect_with_result($result);
    }

    private static function redirect_with_result(array $result): void {
        $token = wp_generate_password(20, false, false);
        set_transient('tmwseo_serp_ui_' . $token, $result, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=tmwseo-serp-analyzer&result_key=' . rawurlencode($token)));
        exit;
    }

    private static function analyze_url(string $url): array {
        $resp = wp_remote_get($url, ['timeout' => 20, 'redirection' => 3]);
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) >= 400) {
            return [];
        }

        $html = (string) wp_remote_retrieve_body($resp);
        if ($html === '') {
            return [];
        }

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        $text = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($html)));
        $word_count = str_word_count($text);

        $title = '';
        $titles = $doc->getElementsByTagName('title');
        if ($titles->length > 0) {
            $title = (string) $titles->item(0)->textContent;
        }

        $h1 = $doc->getElementsByTagName('h1');
        $h2 = $doc->getElementsByTagName('h2');
        $h3 = $doc->getElementsByTagName('h3');

        $headings = [];
        foreach ([$h2, $h3] as $list) {
            foreach ($list as $node) {
                $value = trim((string) $node->textContent);
                if ($value !== '') {
                    $headings[] = $value;
                }
            }
        }

        $internal = 0;
        $external = 0;
        $host = (string) parse_url($url, PHP_URL_HOST);
        foreach ($doc->getElementsByTagName('a') as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:')) {
                continue;
            }
            $link_host = (string) parse_url($href, PHP_URL_HOST);
            if ($link_host === '' || $link_host === $host) {
                $internal++;
            } else {
                $external++;
            }
        }

        $schema_nodes = $xpath->query("//script[contains(@type,'ld+json')]");
        $schema_present = ($schema_nodes instanceof \DOMNodeList && $schema_nodes->length > 0)
            || (bool) $xpath->query('//*[@itemscope and @itemtype]')?->length;

        return [
            'word_count' => (int) $word_count,
            'h1_count' => (int) $h1->length,
            'h2_count' => (int) $h2->length,
            'h3_count' => (int) $h3->length,
            'headings' => $headings,
            'title_length' => mb_strlen(trim($title), 'UTF-8'),
            'internal_links' => $internal,
            'external_links' => $external,
            'schema_present' => $schema_present,
            'entities' => self::extract_entities($text),
        ];
    }

    private static function extract_entities(string $text): array {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        if (!OpenAI::is_configured() || !self::openai_within_limit()) {
            preg_match_all('/\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+){0,2})\b/u', $text, $matches);
            $entities = array_values(array_unique(array_slice((array) ($matches[1] ?? []), 0, 12)));
            return $entities;
        }

        $prompt = mb_substr($text, 0, 3000, 'UTF-8');
        $res = OpenAI::chat_json([
            ['role' => 'system', 'content' => 'Extract up to 12 SEO-relevant named entities from the provided text. Return JSON object with key entities as string array.'],
            ['role' => 'user', 'content' => $prompt],
        ], 'gpt-4o-mini', ['max_tokens' => 240, 'temperature' => 0.2]);

        if (!empty($res['ok']) && is_array($res['json']['entities'] ?? null)) {
            self::increment_openai_counter();
            return array_values(array_filter(array_map('sanitize_text_field', $res['json']['entities'])));
        }

        return [];
    }

    private static function openai_within_limit(): bool {
        $limit = (int) get_option(self::OPTION_OPENAI_DAILY_LIMIT, 20);
        $key = 'tmwseo_openai_usage_' . gmdate('Ymd');
        $count = (int) get_option($key, 0);
        return $count < max(1, $limit);
    }

    private static function increment_openai_counter(): void {
        $key = 'tmwseo_openai_usage_' . gmdate('Ymd');
        $count = (int) get_option($key, 0);
        update_option($key, $count + 1, false);
    }

    private static function render_results(array $result): void {
        $rows = (array) ($result['rows'] ?? []);

        echo '<div class="tmwseo-card" style="max-width:1100px;margin-top:16px;">';
        echo '<h2>' . sprintf(esc_html__('Top 10 Results for "%s"', 'tmwseo'), esc_html((string) ($result['keyword'] ?? ''))) . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Rank</th><th>Domain</th><th>Word Count</th><th>H1 Count</th><th>H2 Count</th><th>Internal Links</th><th>External Links</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . esc_html((string) ($row['position'] ?? '')) . '</td>';
            echo '<td><a href="' . esc_url((string) ($row['url'] ?? '')) . '" target="_blank" rel="noopener">' . esc_html((string) ($row['domain'] ?? '')) . '</a></td>';
            echo '<td>' . esc_html((string) ($row['word_count'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) ($row['h1_count'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) ($row['h2_count'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) ($row['internal_links'] ?? 0)) . '</td>';
            echo '<td>' . esc_html((string) ($row['external_links'] ?? 0)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';

        $blueprint = (array) ($result['blueprint'] ?? []);
        echo '<div class="tmwseo-card" style="max-width:1100px;margin-top:16px;">';
        echo '<h2>' . esc_html__('Recommended Content Blueprint', 'tmwseo') . '</h2>';
        echo '<p><strong>' . esc_html__('Recommended word count:', 'tmwseo') . '</strong> ' . esc_html((string) ($blueprint['recommended_word_count'] ?? 0)) . '</p>';
        echo '<p><strong>' . esc_html__('Common headings found:', 'tmwseo') . '</strong> ' . esc_html(implode(' | ', (array) ($blueprint['common_headings'] ?? []))) . '</p>';
        echo '<p><strong>' . esc_html__('Entities to include:', 'tmwseo') . '</strong> ' . esc_html(implode(', ', (array) ($blueprint['entities'] ?? []))) . '</p>';
        echo '</div>';

        echo '<div class="tmwseo-card" style="max-width:1100px;margin-top:16px;">';
        echo '<details><summary><strong>' . esc_html__('Debug: Raw SERP JSON', 'tmwseo') . '</strong></summary>';
        echo '<pre style="max-height:400px; overflow:auto; background:#f6f7f7; padding:12px;">' . esc_html((string) wp_json_encode($result['raw_json'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
        echo '</details>';
        echo '</div>';
    }
}
