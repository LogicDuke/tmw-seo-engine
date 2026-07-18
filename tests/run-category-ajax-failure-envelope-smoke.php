<?php
$f = file_get_contents(dirname(__DIR__) . '/includes/admin/class-admin-ajax-handlers.php');
$staleNeedle = '(string) ( $save_result[\'run_id\'] ?? \'\' ) !== $run_id';
$checks = [
 'canonical queued envelope' => strpos($f, '$canonical_result = [') !== false,
 'run id stale result guard' => strpos($f, $staleNeedle) !== false,
 'legacy failure fallback' => strpos($f, '_tmwseo_category_generation_failure') !== false,
 'generic string unreachable when reasons exist' => strpos($f, "'message' => __( 'Category generation finished but no content was written. Check logs.'") === false,
];
$fail=0; foreach($checks as $label=>$ok){ echo ($ok?'  ok  ':'  FAIL ').$label."\n"; if(!$ok)$fail++; }
if($fail) exit(1); echo "PASS ".count($checks)." checks\n";
