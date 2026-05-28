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
