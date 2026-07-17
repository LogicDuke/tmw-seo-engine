<?php
declare(strict_types=1);
/** Focused regression checks for transaction identity and byte-safe hashes. */
define('ABSPATH', __DIR__);
require_once dirname(__DIR__) . '/includes/content/class-category-generation-transaction.php';
use TMWSEO\Engine\Content\CategoryGenerationTransaction;
$pass = 0; $fail = 0;
$check = static function (string $label, bool $ok) use (&$pass, &$fail): void { echo ($ok ? 'ok ' : 'FAIL ') . $label . "\n"; $ok ? $pass++ : $fail++; };
$pre = "<!-- wp:html -->\n<pre>one  \n two\t </pre>\n<!-- /wp:html -->\n";
$check('CRLF transport normalizes to LF', CategoryGenerationTransaction::hash(str_replace("\n", "\r\n", $pre)) === CategoryGenerationTransaction::hash($pre));
$check('pre trailing spaces remain meaningful', CategoryGenerationTransaction::hash($pre) !== CategoryGenerationTransaction::hash(str_replace('one  ', 'one ', $pre)));
$check('code block whitespace remains meaningful', CategoryGenerationTransaction::hash('<code>x  y</code>') !== CategoryGenerationTransaction::hash('<code>x y</code>'));
$source = file_get_contents(dirname(__DIR__) . '/includes/content/class-content-engine.php');
$check('transaction uses explicit expected run ID', strpos($source, 'string $expected_run_id') !== false);
$check('canonical result includes verified content_written rollback fields', strpos($source, "'content_written' => false") !== false && strpos($source, "'rollback_status'") !== false);
$transaction_start = strpos($source, 'private static function commit_category_generation_transaction');
$transaction_end = strpos($source, 'private static function record_category_transaction_blocked', $transaction_start);
$transaction = $transaction_start !== false && $transaction_end !== false
    ? substr($source, $transaction_start, $transaction_end - $transaction_start)
    : '';
$check('transaction validates the final CTA document before persistence', strpos($transaction, 'enforce_category_persistence_guard($final_content') !== false);
$readback_check = strpos($transaction, 'persisted_readback_hash\'] !== $result[\'intended_content_hash\']');
$readiness_check = strpos($transaction, 'IndexReadinessGate::evaluate_post');
$noindex_check = strpos($transaction, 'maybe_clear_rank_math_noindex');
$check('readiness and noindex finalization follow verified readback',
    $readback_check !== false && $readiness_check !== false && $noindex_check !== false
    && $readback_check < $readiness_check && $readiness_check < $noindex_check
);
echo "PASS: {$pass} FAIL: {$fail}\n";
exit($fail ? 1 : 0);
