<?php
use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Keywords\VideoKeywordCandidateRepository;

require_once __DIR__ . '/../includes/keywords/class-video-keyword-candidate-repository.php';

final class VideoKeywordCandidateRepositoryTest extends TestCase {
    private $original_wpdb;

    protected function setUp(): void {
        parent::setUp();
        $this->original_wpdb = $GLOBALS['wpdb'] ?? null;
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->original_wpdb;
        parent::tearDown();
    }

    public function test_repository_table_name_resolution(): void {
        $GLOBALS['wpdb'] = new VideoKeywordCandidateRepositoryFakeWpdb('wp580_', true);
        $repo = new VideoKeywordCandidateRepository();

        $this->assertSame('wp580_tmw_keyword_candidates', $repo->table_name());
    }

    public function test_keyword_normalization(): void {
        $GLOBALS['wpdb'] = new VideoKeywordCandidateRepositoryFakeWpdb('wp581_', true);
        $repo = new VideoKeywordCandidateRepository();

        $this->assertSame('model name webcam video', $repo->normalize_keyword('  <b>Model Name</b> — Webcam   Video!!! '));
    }

    public function test_video_intent_accepts_model_name_led_phrases(): void {
        $GLOBALS['wpdb'] = new VideoKeywordCandidateRepositoryFakeWpdb('wp582_', true);
        $repo = new VideoKeywordCandidateRepository();

        $this->assertTrue($repo->is_video_intent_keyword('Anisyia webcam video', 'Anisyia'));
        $this->assertTrue($repo->is_video_intent_keyword('Anisyia video chat', 'Anisyia'));
        $this->assertTrue($repo->is_video_intent_keyword('Anisyia live webcam clip', 'Anisyia'));
        $this->assertTrue($repo->is_video_intent_keyword('Anisyia cam show', 'Anisyia'));
        $this->assertTrue($repo->is_video_intent_keyword('Anisyia live webcam session', 'Anisyia'));
    }

    public function test_video_intent_rejects_standalone_model_name(): void {
        $GLOBALS['wpdb'] = new VideoKeywordCandidateRepositoryFakeWpdb('wp583_', true);
        $repo = new VideoKeywordCandidateRepository();

        $this->assertFalse($repo->is_video_intent_keyword('Anisyia', 'Anisyia'));
        $this->assertSame(0, $repo->upsert_for_video(123, 'Anisyia', ['model_name' => 'Anisyia']));
    }

    public function test_video_intent_rejects_unsafe_terms(): void {
        $GLOBALS['wpdb'] = new VideoKeywordCandidateRepositoryFakeWpdb('wp584_', true);
        $repo = new VideoKeywordCandidateRepository();

        foreach (['cam porn', 'porn', 'xxx', 'sex', 'fuck', 'nude', 'naked'] as $term) {
            $this->assertFalse($repo->is_video_intent_keyword('Anisyia ' . $term . ' video', 'Anisyia'), $term);
        }
        $this->assertFalse($repo->is_video_intent_keyword('Anisyia adult webcam', 'Anisyia'));
        $this->assertFalse($repo->is_video_intent_keyword('Anisyia webcam earnings', 'Anisyia'));
        $this->assertFalse($repo->is_video_intent_keyword('Anisyia cam profile', 'Anisyia'));
    }

    public function test_approved_candidate_listing_returns_max_five_and_primary_first_ordering(): void {
        $wpdb = new VideoKeywordCandidateRepositoryFakeWpdb('wp585_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $repo = new VideoKeywordCandidateRepository();

        $rows = $repo->list_approved_for_video(123, 10);

        $this->assertCount(5, $rows);
        $this->assertSame('primary webcam video', $rows[0]['keyword']);
        $this->assertStringContainsString('LIMIT 5', $wpdb->last_results_sql);
        $this->assertStringContainsString('primary', $wpdb->last_results_sql);
    }

    public function test_missing_table_fails_safely(): void {
        $GLOBALS['wpdb'] = new VideoKeywordCandidateRepositoryFakeWpdb('wp586_', false);
        $repo = new VideoKeywordCandidateRepository();

        $this->assertFalse($repo->table_exists());
        $this->assertSame(0, $repo->upsert_for_video(123, 'Anisyia webcam video', ['model_name' => 'Anisyia']));
        $this->assertSame([], $repo->list_for_video(123));
    }

    public function test_repository_does_not_write_rank_math_or_post_content(): void {
        $wpdb = new VideoKeywordCandidateRepositoryFakeWpdb('wp587_', true);
        $GLOBALS['wpdb'] = $wpdb;
        $repo = new VideoKeywordCandidateRepository();

        $id = $repo->upsert_for_video(123, 'Anisyia webcam video', ['model_name' => 'Anisyia', 'notes' => ['primary' => true]]);

        $this->assertSame(1001, $id);
        $queries = implode("\n", $wpdb->queries);
        $this->assertStringNotContainsString('postmeta', $queries);
        $this->assertStringNotContainsString('rank_math', $queries);
        $this->assertStringNotContainsString('post_content', $queries);
        $this->assertSame('wp587_tmw_keyword_candidates', $wpdb->last_insert_table);
    }
}

final class VideoKeywordCandidateRepositoryFakeWpdb {
    public string $prefix;
    public int $insert_id = 1000;
    public array $queries = [];
    public string $last_results_sql = '';
    public string $last_insert_table = '';
    private bool $table_exists;

    public function __construct(string $prefix, bool $table_exists) {
        $this->prefix = $prefix;
        $this->table_exists = $table_exists;
    }

    public function esc_like(string $text): string {
        return addcslashes($text, '_%\\');
    }

    public function prepare(string $sql, ...$args): string {
        $i = 0;
        return preg_replace_callback('/%[sdf]/', function () use ($args, &$i) {
            $value = $args[$i++] ?? '';
            return is_string($value) ? "'" . addslashes($value) . "'" : (string) $value;
        }, $sql);
    }

    public function get_var(string $sql) {
        $this->queries[] = $sql;
        if (str_starts_with($sql, 'SHOW TABLES LIKE')) {
            return $this->table_exists ? $this->prefix . 'tmw_keyword_candidates' : null;
        }
        if (str_starts_with($sql, 'SELECT id FROM')) {
            return null;
        }
        return null;
    }

    public function get_results(string $sql, string $output = 'OBJECT'): array {
        $this->queries[] = $sql;
        $this->last_results_sql = $sql;
        if (str_starts_with($sql, 'SHOW COLUMNS FROM')) {
            return array_map(fn($field) => ['Field' => $field], [
                'id', 'keyword', 'canonical', 'status', 'intent', 'intent_type', 'entity_type', 'entity_id',
                'volume', 'cpc', 'difficulty', 'opportunity', 'sources', 'notes', 'updated_at',
            ]);
        }
        if (str_starts_with($sql, 'SELECT * FROM')) {
            return [
                ['keyword' => 'primary webcam video', 'notes' => '{"primary":true}', 'status' => 'approved'],
                ['keyword' => 'secondary webcam video 1', 'notes' => '', 'status' => 'approved'],
                ['keyword' => 'secondary webcam video 2', 'notes' => '', 'status' => 'approved'],
                ['keyword' => 'secondary webcam video 3', 'notes' => '', 'status' => 'approved'],
                ['keyword' => 'secondary webcam video 4', 'notes' => '', 'status' => 'approved'],
            ];
        }
        return [];
    }

    public function insert(string $table, array $data, array $format = []) {
        $this->queries[] = 'INSERT:' . $table . ':' . json_encode($data);
        if (!str_contains($table, 'tmw_logs')) {
            $this->last_insert_table = $table;
        }
        $this->insert_id++;
        return 1;
    }

    public function update(string $table, array $data, array $where, array $format = [], array $where_format = []) {
        $this->queries[] = 'UPDATE:' . $table . ':' . json_encode($data) . ':' . json_encode($where);
        return 1;
    }

    public function delete(string $table, array $where, array $where_format = []) {
        $this->queries[] = 'DELETE:' . $table . ':' . json_encode($where);
        return 1;
    }
}
