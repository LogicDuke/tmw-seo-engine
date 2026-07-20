<?php
/**
 * Tests for CSV keyword approval workflow helper behavior.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Admin\AdminFormHandlers;

if (!defined('ABSPATH')) { define('ABSPATH', dirname(__DIR__) . '/'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }
if (!function_exists('__')) { function __($text, $domain = 'default') { return (string) $text; } }
if (!function_exists('wp_generate_password')) { function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) { return str_repeat('x', (int) $length); } }

require_once __DIR__ . '/../includes/admin/class-admin-form-handlers.php';

final class CsvKeywordApprovalWorkflowTest extends TestCase {
    public function test_canonical_pre_approval_statuses_are_eligible(): void {
        $this->assertSame(
            [ 'new', 'discovered', 'scored', 'queued_for_review' ],
            AdminFormHandlers::queued_keyword_candidate_statuses()
        );
    }

    public function test_terminal_statuses_are_not_eligible(): void {
        $eligible = AdminFormHandlers::queued_keyword_candidate_statuses();

        foreach ([ 'approved', 'rejected', 'ignored', 'deleted', 'pending', 'pending_review', 'pending review', 'queued' ] as $status) {
            $this->assertNotContains($status, $eligible);
        }
    }

    public function test_rollback_rows_accumulate_for_same_preview_token(): void {
        $existing = [
            'token' => 'download-token',
            'preview_token' => 'preview-a',
            'generated_at' => '2026-06-01 00:00:00',
            'rows' => [ [ 'candidate_id' => 1 ], [ 'candidate_id' => 2 ] ],
        ];

        $combined = AdminFormHandlers::build_cumulative_csv_keyword_approval_rollback(
            $existing,
            [ [ 'candidate_id' => 3 ], [ 'candidate_id' => 4 ] ],
            'preview-a',
            '2026-06-01 00:05:00'
        );

        $this->assertSame('download-token', $combined['token']);
        $this->assertSame('preview-a', $combined['preview_token']);
        $this->assertSame([ 1, 2, 3, 4 ], array_column($combined['rows'], 'candidate_id'));
    }

    public function test_rollback_rows_do_not_mix_between_preview_tokens(): void {
        $existing = [
            'token' => 'old-download-token',
            'preview_token' => 'preview-a',
            'rows' => [ [ 'candidate_id' => 1 ] ],
        ];

        $combined = AdminFormHandlers::build_cumulative_csv_keyword_approval_rollback(
            $existing,
            [ [ 'candidate_id' => 9 ] ],
            'preview-b',
            '2026-06-01 00:10:00'
        );

        $this->assertNotSame('old-download-token', $combined['token']);
        $this->assertSame('preview-b', $combined['preview_token']);
        $this->assertSame([ 9 ], array_column($combined['rows'], 'candidate_id'));
    }
}
