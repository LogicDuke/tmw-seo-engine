<?php
/**
 * Tests for CSVManagerAdminPage — inventory builder, streaming CSV reader,
 * and delete-pack payload validator.
 *
 * These tests exercise the pure-PHP logic that can run without a live WordPress
 * database (inventory merging, CSV meta reading, filter logic, batch validation).
 *
 * Action handler tests (handle_delete_pack, handle_delete_seeds, handle_delete)
 * require a full WP integration test environment (wp-phpunit or similar) because
 * they depend on wp_die(), wp_safe_redirect(), $wpdb, and WordPress nonces.
 * Those are covered by the manual QA checklist in PR_DESCRIPTION.md.
 * A future integration test suite should add at least:
 *   - handle_delete_pack with valid payload deletes both file and seeds
 *   - handle_delete_pack with invalid payload deletes neither
 *   - handle_delete_pack with missing nonce is rejected
 *
 * Run with:  ./vendor/bin/phpunit tests/CSVManagerInventoryTest.php
 */

use PHPUnit\Framework\TestCase;

/**
 * We expose the private static helpers under test via a thin reflection shim.
 * This avoids the need to make them public in production code.
 */
class CSVManagerTestShim {
    private static function call(string $method, array $args = []) {
        $ref = new ReflectionMethod(\TMWSEO\Engine\Admin\CSVManagerAdminPage::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }

    public static function read_csv_meta(string $path, int $max_preview): array {
        return self::call('read_csv_meta', [$path, $max_preview]);
    }

    public static function filter_inventory(array $inv, string $search, string $status): array {
        return self::call('filter_inventory', [$inv, $search, $status]);
    }

    public static function status_badge(string $status): string {
        return self::call('status_badge', [$status]);
    }
}

class CSVManagerInventoryTest extends TestCase {

    private string $tmp_dir;

    protected function setUp(): void {
        $this->tmp_dir = sys_get_temp_dir() . '/tmw_csv_test_' . uniqid('', true);
        mkdir($this->tmp_dir, 0755, true);
    }

    protected function tearDown(): void {
        foreach (glob($this->tmp_dir . '/*') ?: [] as $f) {
            is_file($f) && unlink($f);
        }
        rmdir($this->tmp_dir);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function write_csv(string $name, array $rows): string {
        $path = $this->tmp_dir . '/' . $name;
        $fh   = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        fclose($fh);
        return $path;
    }

    private function inventory_row(string $filename, string $status, array $batches = [], bool $file_exists = true): array {
        return [
            'filename'      => $filename,
            'file_path'     => $file_exists ? '/fake/' . $filename : null,
            'file_exists'   => $file_exists,
            'file_size'     => 1024,
            'file_mtime'    => time(),
            'db_batches'    => $batches,
            'db_seed_count' => array_sum(array_column($batches, 'row_count')),
            'csv_row_count' => 10,
            'csv_headers'   => ['keyword', 'category'],
            'count_truncated' => false,
            'status'        => $status,
            '_sort_key'     => time(),
        ];
    }

    // ─── read_csv_meta ────────────────────────────────────────────────────────

    public function test_read_csv_meta_basic_comma(): void {
        $path = $this->write_csv('basic.csv', [
            ['keyword', 'category', 'volume'],
            ['cam girl', 'general', '1000'],
            ['live cam', 'general', '900'],
        ]);

        $meta = CSVManagerTestShim::read_csv_meta($path, 0);

        $this->assertSame(['keyword', 'category', 'volume'], $meta['headers']);
        $this->assertSame(2, $meta['row_count']);
        $this->assertEmpty($meta['preview_rows']);
        $this->assertFalse($meta['count_truncated']);
        $this->assertFalse($meta['preview_truncated']);
        $this->assertSame(0, $meta['malformed_rows']);
    }

    public function test_read_csv_meta_preview_rows_collected(): void {
        $rows = [['keyword', 'score']];
        for ($i = 1; $i <= 60; $i++) {
            $rows[] = ["keyword $i", (string) $i];
        }
        $path = $this->write_csv('big.csv', $rows);

        $meta = CSVManagerTestShim::read_csv_meta($path, 50);

        $this->assertSame(60, $meta['row_count']);
        $this->assertCount(50, $meta['preview_rows']);
        $this->assertTrue($meta['preview_truncated']);
        $this->assertFalse($meta['count_truncated']);
        $this->assertSame(['keyword', 'score'], $meta['headers']);
    }

    public function test_read_csv_meta_returns_all_rows_when_under_limit(): void {
        $path = $this->write_csv('small.csv', [
            ['kw'],
            ['foo'],
            ['bar'],
        ]);

        $meta = CSVManagerTestShim::read_csv_meta($path, 50);

        $this->assertSame(2, $meta['row_count']);
        $this->assertCount(2, $meta['preview_rows']);
        $this->assertFalse($meta['preview_truncated']);
    }

    public function test_read_csv_meta_empty_file(): void {
        $path = $this->tmp_dir . '/empty.csv';
        file_put_contents($path, '');

        $meta = CSVManagerTestShim::read_csv_meta($path, 0);

        $this->assertSame([], $meta['headers']);
        $this->assertSame(0, $meta['row_count']);
    }

    public function test_read_csv_meta_headers_only_no_data_rows(): void {
        $path = $this->write_csv('header_only.csv', [
            ['keyword', 'category'],
        ]);

        $meta = CSVManagerTestShim::read_csv_meta($path, 50);

        $this->assertSame(['keyword', 'category'], $meta['headers']);
        $this->assertSame(0, $meta['row_count']);
        $this->assertEmpty($meta['preview_rows']);
    }

    public function test_read_csv_meta_detects_tab_delimiter(): void {
        $path = $this->tmp_dir . '/tab.csv';
        file_put_contents($path, "keyword\tcategory\nfoo\tbar\nbaz\tqux\n");

        $meta = CSVManagerTestShim::read_csv_meta($path, 10);

        $this->assertSame(['keyword', 'category'], $meta['headers']);
        $this->assertSame(2, $meta['row_count']);
        $this->assertCount(2, $meta['preview_rows']);
        $this->assertSame(['foo', 'bar'], $meta['preview_rows'][0]);
    }

    public function test_read_csv_meta_malformed_row_flagged(): void {
        $path = $this->tmp_dir . '/malformed.csv';
        // Header has 3 cols; one data row has only 2.
        file_put_contents($path, "a,b,c\n1,2\n3,4,5\n");

        $meta = CSVManagerTestShim::read_csv_meta($path, 50);

        $this->assertSame(2, $meta['row_count']);
        $this->assertSame(1, $meta['malformed_rows']);
        // Malformed row should be padded to header length.
        $this->assertCount(3, $meta['preview_rows'][0]);
    }

    public function test_read_csv_meta_nonexistent_file(): void {
        $meta = CSVManagerTestShim::read_csv_meta('/tmp/does_not_exist_tmw_12345.csv', 0);

        $this->assertSame([], $meta['headers']);
        $this->assertSame(0, $meta['row_count']);
    }

    // ─── filter_inventory ─────────────────────────────────────────────────────

    public function test_filter_by_status(): void {
        $inv = [
            $this->inventory_row('a.csv', 'linked'),
            $this->inventory_row('b.csv', 'file_only'),
            $this->inventory_row('c.csv', 'db_only', [], false),
        ];

        $result = CSVManagerTestShim::filter_inventory($inv, '', 'linked');

        $this->assertCount(1, $result);
        $this->assertSame('a.csv', $result[0]['filename']);
    }

    public function test_filter_by_search_filename(): void {
        $inv = [
            $this->inventory_row('latina-longtail.csv', 'file_only'),
            $this->inventory_row('ebony-extra.csv', 'file_only'),
        ];

        $result = CSVManagerTestShim::filter_inventory($inv, 'latina', '');

        $this->assertCount(1, $result);
        $this->assertSame('latina-longtail.csv', $result[0]['filename']);
    }

    public function test_filter_by_search_batch_id(): void {
        $inv = [
            $this->inventory_row('x.csv', 'linked', [
                ['batch_id' => 'batch-abc-123', 'source_label' => 'x.csv', 'source' => 'approved_import', 'row_count' => 5, 'earliest' => '', 'latest' => ''],
            ]),
            $this->inventory_row('y.csv', 'linked', [
                ['batch_id' => 'batch-xyz-999', 'source_label' => 'y.csv', 'source' => 'approved_import', 'row_count' => 3, 'earliest' => '', 'latest' => ''],
            ]),
        ];

        $result = CSVManagerTestShim::filter_inventory($inv, 'abc-123', '');

        $this->assertCount(1, $result);
        $this->assertSame('x.csv', $result[0]['filename']);
    }

    public function test_filter_empty_search_and_status_returns_all(): void {
        $inv = [
            $this->inventory_row('a.csv', 'linked'),
            $this->inventory_row('b.csv', 'file_only'),
            $this->inventory_row('c.csv', 'db_only', [], false),
        ];

        $result = CSVManagerTestShim::filter_inventory($inv, '', '');

        $this->assertCount(3, $result);
    }

    public function test_filter_combined_status_and_search(): void {
        $inv = [
            $this->inventory_row('latina.csv', 'linked'),
            $this->inventory_row('latina-extra.csv', 'file_only'),
            $this->inventory_row('ebony.csv', 'linked'),
        ];

        $result = CSVManagerTestShim::filter_inventory($inv, 'latina', 'linked');

        $this->assertCount(1, $result);
        $this->assertSame('latina.csv', $result[0]['filename']);
    }

    // ─── status_badge ─────────────────────────────────────────────────────────

    public function test_status_badge_linked(): void {
        $html = CSVManagerTestShim::status_badge('linked');
        $this->assertStringContainsString('tmw-badge-linked', $html);
        $this->assertStringContainsString('Linked', $html);
    }

    public function test_status_badge_db_only(): void {
        $html = CSVManagerTestShim::status_badge('db_only');
        $this->assertStringContainsString('tmw-badge-db_only', $html);
        $this->assertStringContainsString('DB Only', $html);
    }

    public function test_status_badge_unknown_falls_back_gracefully(): void {
        $html = CSVManagerTestShim::status_badge('unknown_status');
        // Should not throw; label is the raw status string.
        $this->assertStringContainsString('tmw-badge', $html);
        $this->assertStringContainsString('unknown_status', $html);
    }
}

/**
 * Tests for CSVManagerAdminPage::parse_and_validate_pack_batches().
 *
 * This method is public static and side-effect-free, so it can be called
 * directly without a WordPress environment.
 *
 * What these tests prove for the handle_delete_pack() security fix:
 *
 *  - An empty or missing payload causes parse_and_validate_pack_batches() to
 *    return null, which causes handle_delete_pack() to redirect with an error
 *    notice and touch nothing (no file deleted, no seeds deleted).
 *
 *  - Malformed JSON is rejected the same way.
 *
 *  - A payload where every entry has an invalid source is rejected; the file
 *    and seeds are therefore never touched.
 *
 *  - A valid payload with at least one allowed source returns a sanitized list,
 *    allowing handle_delete_pack() to proceed to deletion.
 *
 * Handler-level integration tests (verifying that the file and DB rows are
 * actually absent after a successful or aborted pack delete) require a full
 * WordPress + database environment and are left for the integration suite.
 */
class CSVManagerDeletePackValidationTest extends TestCase {

    /** Shorthand: call the method under test. */
    private function validate(string $raw): ?array {
        return \TMWSEO\Engine\Admin\CSVManagerAdminPage::parse_and_validate_pack_batches($raw);
    }

    /** Build a minimal valid URL-encoded JSON payload for one batch. */
    private function valid_payload(string $source = 'approved_import'): string {
        return rawurlencode((string) json_encode([[
            'batch_id'     => 'batch-abc-001',
            'source'       => $source,
            'source_label' => 'keywords.csv',
        ]]));
    }

    // ─── Empty / missing payload → null (no deletion should occur) ────────────

    public function test_empty_string_returns_null(): void {
        $this->assertNull($this->validate(''));
    }

    public function test_whitespace_only_returns_null(): void {
        // URL-decoded whitespace is still empty after trim.
        $this->assertNull($this->validate(rawurlencode('   ')));
    }

    public function test_null_byte_string_returns_null(): void {
        $this->assertNull($this->validate(rawurlencode("\x00")));
    }

    // ─── Malformed JSON → null (no deletion should occur) ────────────────────

    public function test_invalid_json_returns_null(): void {
        $this->assertNull($this->validate(rawurlencode('{not valid json')));
    }

    public function test_json_string_scalar_returns_null(): void {
        // Valid JSON but not an array.
        $this->assertNull($this->validate(rawurlencode('"just a string"')));
    }

    public function test_json_integer_returns_null(): void {
        $this->assertNull($this->validate(rawurlencode('42')));
    }

    public function test_json_null_returns_null(): void {
        $this->assertNull($this->validate(rawurlencode('null')));
    }

    public function test_empty_json_array_returns_null(): void {
        $this->assertNull($this->validate(rawurlencode('[]')));
    }

    public function test_empty_json_object_returns_null(): void {
        // json_decode('{}', true) returns [], which is empty → null.
        $this->assertNull($this->validate(rawurlencode('{}')));
    }

    // ─── All-invalid sources → null (no deletion should occur) ───────────────

    public function test_unknown_source_returns_null(): void {
        $payload = rawurlencode((string) json_encode([[
            'batch_id'     => 'b1',
            'source'       => 'manual_reimport',   // not in allowlist
            'source_label' => 'x.csv',
        ]]));
        $this->assertNull($this->validate($payload));
    }

    public function test_injected_source_returns_null(): void {
        // SQL-injection-style source value should be sanitize_key'd to empty string,
        // which is not in the allowlist.
        $payload = rawurlencode((string) json_encode([[
            'batch_id'     => 'b1',
            'source'       => "approved_import'; DROP TABLE wp_tmwseo_seeds; --",
            'source_label' => 'x.csv',
        ]]));
        $this->assertNull($this->validate($payload));
    }

    public function test_empty_source_returns_null(): void {
        $payload = rawurlencode((string) json_encode([[
            'batch_id'     => 'b1',
            'source'       => '',
            'source_label' => 'x.csv',
        ]]));
        $this->assertNull($this->validate($payload));
    }

    public function test_missing_source_key_returns_null(): void {
        $payload = rawurlencode((string) json_encode([[
            'batch_id'     => 'b1',
            'source_label' => 'x.csv',
            // 'source' key absent
        ]]));
        $this->assertNull($this->validate($payload));
    }

    // ─── Valid payloads → non-null sanitized list ─────────────────────────────

    public function test_approved_import_source_accepted(): void {
        $result = $this->validate($this->valid_payload('approved_import'));
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame('approved_import', $result[0]['source']);
    }

    public function test_csv_import_source_accepted(): void {
        $result = $this->validate($this->valid_payload('csv_import'));
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame('csv_import', $result[0]['source']);
    }

    public function test_valid_payload_sanitizes_fields(): void {
        $payload = rawurlencode((string) json_encode([[
            'batch_id'     => "batch-001\x00<script>",
            'source'       => 'approved_import',
            'source_label' => "keywords.csv<img src=x>",
        ]]));
        $result = $this->validate($payload);
        $this->assertIsArray($result);
        // sanitize_text_field strips tags and null bytes.
        $this->assertStringNotContainsString('<script>', $result[0]['batch_id']);
        $this->assertStringNotContainsString('<img',     $result[0]['source_label']);
    }

    public function test_mixed_valid_and_invalid_sources_filtered(): void {
        // Two entries: one valid, one invalid. Returns just the valid one.
        $payload = rawurlencode((string) json_encode([
            ['batch_id' => 'b1', 'source' => 'approved_import', 'source_label' => 'a.csv'],
            ['batch_id' => 'b2', 'source' => 'manual_reimport',  'source_label' => 'b.csv'],
        ]));
        $result = $this->validate($payload);
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame('approved_import', $result[0]['source']);
    }

    public function test_multiple_valid_batches_all_returned(): void {
        $payload = rawurlencode((string) json_encode([
            ['batch_id' => 'b1', 'source' => 'approved_import', 'source_label' => 'a.csv'],
            ['batch_id' => 'b2', 'source' => 'csv_import',      'source_label' => 'b.csv'],
        ]));
        $result = $this->validate($payload);
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    public function test_non_array_entry_within_outer_array_is_skipped(): void {
        // Outer array contains a scalar instead of a sub-array — should be skipped.
        $payload = rawurlencode((string) json_encode([
            'not_an_array_entry',
            ['batch_id' => 'b1', 'source' => 'approved_import', 'source_label' => 'a.csv'],
        ]));
        $result = $this->validate($payload);
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function test_empty_batch_id_is_allowed(): void {
        // Empty batch_id is valid — represents legacy rows with no batch ID.
        $payload = rawurlencode((string) json_encode([[
            'batch_id'     => '',
            'source'       => 'approved_import',
            'source_label' => 'legacy.csv',
        ]]));
        $result = $this->validate($payload);
        $this->assertIsArray($result);
        $this->assertSame('', $result[0]['batch_id']);
    }

    public function test_returned_array_has_expected_keys(): void {
        $result = $this->validate($this->valid_payload());
        $this->assertIsArray($result);
        $entry = $result[0];
        $this->assertArrayHasKey('batch_id',     $entry);
        $this->assertArrayHasKey('source',       $entry);
        $this->assertArrayHasKey('source_label', $entry);
        // No extra keys should leak through.
        $this->assertCount(3, $entry);
    }
}