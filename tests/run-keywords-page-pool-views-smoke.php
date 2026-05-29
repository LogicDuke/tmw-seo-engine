<?php
/** Smoke checks for PR 594 Keywords page pool views. */

$root = dirname(__DIR__);
$admin = (string) file_get_contents($root . '/includes/admin/class-admin.php');
$table = (string) file_get_contents($root . '/includes/admin/tables/class-keywords-table.php');

$required = [
    'Model Keywords' => $admin,
    'Video Keywords' => $admin,
    'Category Keywords' => $admin,
    "intent_type = %s" => $table,
    "'intent_type' => 'category'" => $admin,
    "'intent_type' => 'video'" => $admin,
    "'intent_type' => 'model'" => $admin,
    'All Candidates' => $admin,
    'Queued for Review' => $admin,
    'Approved' => $admin,
    'Ignored / Rejected' => $admin,
    'Safe Keyword Cleanup' => $admin,
];

foreach ($required as $needle => $haystack) {
    if (false === strpos($haystack, $needle)) {
        fwrite(STDERR, "Missing smoke check needle: {$needle}\n");
        exit(1);
    }
}

function tmwseo_smoke_extract_method(string $source, string $method): string {
    $start = strpos($source, 'function ' . $method . '(');
    if (false === $start) { return ''; }
    $brace = strpos($source, '{', $start);
    if (false === $brace) { return ''; }
    $depth = 0;
    $length = strlen($source);
    for ($i = $brace; $i < $length; $i++) {
        if ($source[$i] === '{') { $depth++; }
        if ($source[$i] === '}') { $depth--; }
        if ($depth === 0) { return substr($source, $start, $i - $start + 1); }
    }
    return '';
}

$forbiddenWrites = [ 'RankMathMapper', 'update_post_meta(', 'wp_update_post(', 'wp_insert_post(', 'post_content', 'ajax_generate_now(' ];
$viewerSlice = tmwseo_smoke_extract_method($admin, 'render_keywords') . tmwseo_smoke_extract_method($table, 'prepare_items') . tmwseo_smoke_extract_method($table, 'get_columns');
foreach ($forbiddenWrites as $needle) {
    if (false !== strpos($viewerSlice, $needle)) {
        fwrite(STDERR, "Forbidden write call found in viewer smoke slice: {$needle}\n");
        exit(1);
    }
}

echo "keywords page pool views smoke checks passed\n";
