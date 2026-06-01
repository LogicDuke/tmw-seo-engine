<?php
/**
 * Shared CSV parser for keyword pool dry-run imports.
 *
 * @package TMWSEO\Engine\Keywords
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Keywords;

/**
 * Parses uploaded or pasted keyword CSV input into canonical, non-persistent rows.
 */
class KeywordPoolCsvParser {

    public const DEFAULT_ROW_CAP = 2000;

    /**
     * Canonical header aliases accepted by all keyword pool import previews.
     *
     * @var array<string, array<int, string>>
     */
    private const HEADER_ALIASES = [
        'keyword'     => [ 'keyword', 'query', 'search_term', 'seed_keyword', 'keyword_text' ],
        'volume'      => [ 'volume', 'search_volume', 'monthly_searches', 'avg_monthly_searches', 'avg. monthly searches', 'avg monthly searches', 'impressions' ],
        'difficulty'    => [ 'difficulty', 'kd', 'keyword_difficulty', 'keyword difficulty', 'seo_difficulty', 'seo difficulty', 'competition_difficulty', 'keyword difficulty score' ],
        'cpc'           => [ 'cpc', 'avg_cpc', 'average_cpc', 'avg cpc', 'average cpc', 'cost_per_click', 'cost per click', 'average cost per click', 'avg. cpc', 'avg. CPC', 'CPC (USD)', 'CPC', 'Avg CPC', 'Average CPC' ],
        'competition'   => [ 'competition', 'comp', 'competition_index' ],
        'seo_score'     => [ 'seo_score', 'seo score', 'SEO Score' ],
        'opportunity_score' => [ 'opportunity_score', 'opportunity score', 'opportunity', 'Opportunity Score' ],
        'traffic_value' => [ 'traffic_value', 'traffic value', 'Traffic Value' ],
        'trend'         => [ 'trend', '12_month_trend', '12 month trend', 'monthly trend' ],
        'trend_direction' => [ 'trend_direction', 'trend direction', 'trend dir', 'trend dir.', 'Trend Dir.' ],
        'ad_difficulty' => [ 'ad_difficulty', 'ad difficulty', 'Ad Difficulty' ],
        'intent'      => [ 'intent', 'search_intent', 'intent_type' ],
        'source'      => [ 'source', 'tool', 'provider', 'import_source' ],
        'model_name'  => [ 'model_name', 'model', 'performer', 'performer_name', 'talent_name' ],
        'category'    => [ 'category', 'category_name', 'term', 'term_name' ],
        'post_id'     => [ 'post_id', 'video_post_id', 'wp_post_id', 'id' ],
        'url'         => [ 'url', 'video_url', 'permalink', 'target_url', 'page_url' ],
        'slug'        => [ 'slug', 'post_name', 'video_slug', 'target_slug' ],
        'title'       => [ 'title', 'post_title', 'video_title' ],
        'notes'       => [ 'notes', 'note', 'review_notes' ],
        'status'      => [ 'status', 'pipeline_status', 'import_status' ],
    ];

    /**
     * Parse CSV content from an uploaded file path.
     *
     * @param string $file_path Uploaded CSV path.
     * @param int    $row_cap Maximum accepted data rows.
     * @return array<string, mixed>
     */
    public function parse_file(string $file_path, int $row_cap = self::DEFAULT_ROW_CAP): array {
        if ('' === trim($file_path) || ! is_readable($file_path)) {
            return $this->empty_result([ 'CSV file is not readable.' ]);
        }

        $contents = file_get_contents($file_path);
        if (false === $contents) {
            return $this->empty_result([ 'CSV file could not be read.' ]);
        }

        return $this->parse_text($contents, $row_cap);
    }

    /**
     * Parse pasted raw CSV content.
     *
     * @param string $csv_text Pasted CSV text.
     * @param int    $row_cap Maximum accepted data rows.
     * @return array<string, mixed>
     */
    public function parse_text(string $csv_text, int $row_cap = self::DEFAULT_ROW_CAP): array {
        $row_cap  = max(1, $row_cap);
        $warnings = [];
        $errors   = [];
        $rows     = [];

        $csv_text = $this->normalize_line_endings($csv_text);
        if ('' === trim($csv_text)) {
            return $this->empty_result([ 'CSV input is empty.' ]);
        }

        $handle = fopen('php://temp', 'r+');
        if (false === $handle) {
            return $this->empty_result([ 'Temporary CSV stream could not be opened.' ]);
        }

        fwrite($handle, $csv_text);
        rewind($handle);

        $headers = fgetcsv($handle);
        if (false === $headers || null === $headers) {
            fclose($handle);
            return $this->empty_result([ 'CSV header row is missing.' ]);
        }

        $headers    = array_map([ $this, 'clean_cell' ], $headers);
        $header_map = $this->build_header_map($headers);
        $line       = 1;
        $data_rows  = 0;
        $skipped    = 0;

        while (false !== ($fields = fgetcsv($handle))) {
            ++$line;

            if ($this->is_empty_csv_row($fields)) {
                ++$skipped;
                continue;
            }

            ++$data_rows;
            if (count($rows) >= $row_cap) {
                ++$skipped;
                continue;
            }

            $row = [ 'row_number' => $line ];
            foreach ($header_map as $index => $canonical) {
                if (null === $canonical) {
                    continue;
                }
                $row[$canonical] = $this->clean_cell($fields[$index] ?? '');
            }
            $rows[] = $row;
        }

        fclose($handle);

        if ($data_rows > $row_cap) {
            $warnings[] = sprintf('CSV row cap of %d reached; %d data rows were skipped.', $row_cap, $data_rows - $row_cap);
        }

        return [
            'rows'               => $rows,
            'headers'            => $headers,
            'header_map'         => $header_map,
            'row_count'          => $data_rows,
            'accepted_row_count' => count($rows),
            'skipped_row_count'  => $skipped,
            'errors'             => $errors,
            'warnings'           => $warnings,
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function header_aliases(): array {
        return self::HEADER_ALIASES;
    }

    /**
     * Normalize a header label for alias matching.
     */
    public static function normalize_header(string $header): string {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $header = strtolower(trim($header));
        $header = preg_replace('/[\s_\-\/]+/', '_', $header) ?? $header;
        return trim($header, '_');
    }

    /**
     * @param array<int, string> $headers Raw CSV headers.
     * @return array<int, string|null> Numeric header index to canonical key/null.
     */
    private function build_header_map(array $headers): array {
        $lookup = [];
        foreach (self::HEADER_ALIASES as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $lookup[self::normalize_header($alias)] = $canonical;
            }
        }

        $map = [];
        foreach ($headers as $index => $header) {
            $map[$index] = $lookup[self::normalize_header($header)] ?? null;
        }

        return $map;
    }

    /**
     * @param array<int, string> $errors Error diagnostics.
     * @return array<string, mixed>
     */
    private function empty_result(array $errors): array {
        return [
            'rows'               => [],
            'headers'            => [],
            'header_map'         => [],
            'row_count'          => 0,
            'accepted_row_count' => 0,
            'skipped_row_count'  => 0,
            'errors'             => $errors,
            'warnings'           => [],
        ];
    }

    /**
     * Clean a parsed CSV cell.
     */
    private function clean_cell($value): string {
        $value = (string) $value;
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        $value = preg_replace('/[\p{Z}\s]+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    /**
     * @param array<int, mixed> $fields Parsed CSV fields.
     */
    private function is_empty_csv_row(array $fields): bool {
        foreach ($fields as $field) {
            if ('' !== trim((string) $field)) {
                return false;
            }
        }
        return true;
    }

    private function normalize_line_endings(string $text): string {
        return str_replace([ "\r\n", "\r" ], "\n", $text);
    }
}
