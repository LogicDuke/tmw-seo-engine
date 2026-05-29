<?php
/**
 * Tests for shared keyword pool CSV parsing.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\KeywordPoolCsvParser;

require_once __DIR__ . '/../includes/keywords/class-keyword-pool-csv-parser.php';

class KeywordPoolCsvParserTest extends TestCase {

    public function test_header_alias_normalization_maps_common_columns(): void {
        $parser = new KeywordPoolCsvParser();
        $csv    = "Query,Avg. monthly searches,KD,Avg_CPC,Comp,Search Intent,Provider,Performer,Term Name,WP_Post_ID,Target URL,Video Slug,Post Title,Review Notes,Pipeline Status\n";
        $csv   .= "Lexy Ness webcam model,1,22,0.40,0.7,commercial,DataForSEO,Lexy Ness,Blonde,123,https://example.test/v,lexy-video,Lexy Video,note,approved\n";

        $result = $parser->parse_text($csv);
        $row    = $result['rows'][0];

        $this->assertSame('keyword', $result['header_map'][0]);
        $this->assertSame('volume', $result['header_map'][1]);
        $this->assertSame('difficulty', $result['header_map'][2]);
        $this->assertSame('cpc', $result['header_map'][3]);
        $this->assertSame('competition', $result['header_map'][4]);
        $this->assertSame('intent', $result['header_map'][5]);
        $this->assertSame('source', $result['header_map'][6]);
        $this->assertSame('model_name', $result['header_map'][7]);
        $this->assertSame('category', $result['header_map'][8]);
        $this->assertSame('post_id', $result['header_map'][9]);
        $this->assertSame('url', $result['header_map'][10]);
        $this->assertSame('slug', $result['header_map'][11]);
        $this->assertSame('title', $result['header_map'][12]);
        $this->assertSame('notes', $result['header_map'][13]);
        $this->assertSame('status', $result['header_map'][14]);
        $this->assertSame('Lexy Ness webcam model', $row['keyword']);
    }

    public function test_header_separator_variants_map_to_same_canonical_fields(): void {
        $parser = new KeywordPoolCsvParser();
        $result = $parser->parse_text("search-term,Search Intent,Term Name,Target URL,Avg Monthly Searches\nLexy Ness webcam model,commercial,Blonde,https://example.test/blonde,1200\n");
        $row    = $result['rows'][0];

        $this->assertSame('keyword', $result['header_map'][0]);
        $this->assertSame('intent', $result['header_map'][1]);
        $this->assertSame('category', $result['header_map'][2]);
        $this->assertSame('url', $result['header_map'][3]);
        $this->assertSame('volume', $result['header_map'][4]);
        $this->assertSame('Lexy Ness webcam model', $row['keyword']);
        $this->assertSame('commercial', $row['intent']);
        $this->assertSame('Blonde', $row['category']);
        $this->assertSame('https://example.test/blonde', $row['url']);
        $this->assertSame('1200', $row['volume']);

        $underscore_result = $parser->parse_text("keyword_text,search_intent\nalternate keyword,informational\n");
        $this->assertSame('keyword', $underscore_result['header_map'][0]);
        $this->assertSame('intent', $underscore_result['header_map'][1]);
    }

    public function test_spaced_headers_produce_canonical_row_keys_for_dry_run_input(): void {
        $parser = new KeywordPoolCsvParser();
        $result = $parser->parse_text("Search Term,Search Intent,Target URL,Avg Monthly Searches\nblonde webcam models,commercial,https://example.test/blonde,2400\n");
        $row    = $result['rows'][0];

        $this->assertSame('blonde webcam models', $row['keyword']);
        $this->assertSame('commercial', $row['intent']);
        $this->assertSame('https://example.test/blonde', $row['url']);
        $this->assertSame('2400', $row['volume']);
    }

    public function test_header_normalization_equates_spaces_underscores_hyphens_and_slashes(): void {
        $expected = KeywordPoolCsvParser::normalize_header('search intent');

        $this->assertSame($expected, KeywordPoolCsvParser::normalize_header('Search Intent'));
        $this->assertSame($expected, KeywordPoolCsvParser::normalize_header('search_intent'));
        $this->assertSame($expected, KeywordPoolCsvParser::normalize_header('search-intent'));
        $this->assertSame($expected, KeywordPoolCsvParser::normalize_header('search/intent'));
        $this->assertSame($expected, KeywordPoolCsvParser::normalize_header('search   intent'));
    }


    /**
     * @return array<int, array{0:string,1:string,2:string}>
     */
    public function metric_alias_provider(): array {
        return [
            [ 'Avg CPC', 'cpc', '5.99' ],
            [ 'Average CPC', 'cpc', '5.99' ],
            [ 'CPC (USD)', 'cpc', '5.99' ],
            [ 'SEO Score', 'seo_score', '81' ],
            [ 'Traffic Value', 'traffic_value', '123.45' ],
            [ 'Ad Difficulty', 'ad_difficulty', '17' ],
        ];
    }

    /**
     * @dataProvider metric_alias_provider
     */
    public function test_metric_alias_headers_map_to_preview_only_fields(string $header, string $canonical, string $value): void {
        $parser = new KeywordPoolCsvParser();
        $result = $parser->parse_text('Keyword,"' . $header . '"' . "\nasian cam models," . $value . "\n");
        $row    = $result['rows'][0];

        $this->assertSame('keyword', $result['header_map'][0]);
        $this->assertSame($canonical, $result['header_map'][1]);
        $this->assertSame($value, $row[$canonical]);
        if ('SEO Score' === $header) {
            $this->assertArrayNotHasKey('difficulty', $row);
        }
    }

    public function test_ad_difficulty_whitespace_cell_cleans_to_blank(): void {
        $parser = new KeywordPoolCsvParser();
        $nbsp   = chr(194) . chr(160);
        $result = $parser->parse_text("keyword,Ad Difficulty\nasian cam models, " . $nbsp . " \n");
        $row    = $result['rows'][0];

        $this->assertSame('', $row['ad_difficulty']);
    }

    public function test_parses_pasted_text_with_result_contract(): void {
        $parser = new KeywordPoolCsvParser();
        $result = $parser->parse_text("keyword,volume\nalpha,10\nbeta,20\n");

        $this->assertSame(2, $result['row_count']);
        $this->assertSame(2, $result['accepted_row_count']);
        $this->assertSame(0, $result['skipped_row_count']);
        $this->assertSame([], $result['errors']);
        $this->assertArrayHasKey('rows', $result);
        $this->assertArrayHasKey('headers', $result);
        $this->assertArrayHasKey('header_map', $result);
        $this->assertArrayHasKey('warnings', $result);
    }

    public function test_row_cap_skips_rows_after_cap(): void {
        $parser = new KeywordPoolCsvParser();
        $result = $parser->parse_text("keyword\none\ntwo\nthree\n", 2);

        $this->assertSame(3, $result['row_count']);
        $this->assertSame(2, $result['accepted_row_count']);
        $this->assertSame(1, $result['skipped_row_count']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertSame('one', $result['rows'][0]['keyword']);
        $this->assertSame('two', $result['rows'][1]['keyword']);
    }
}
