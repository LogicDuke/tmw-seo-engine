<?php
/**
 * Smoke check for Keyword Pools admin menu registration and menu ordering.
 */

declare(strict_types=1);

$adminFile = __DIR__ . '/../includes/admin/class-admin.php';
$source = file_get_contents($adminFile);

if (!is_string($source)) {
    fwrite(STDERR, "unable to read includes/admin/class-admin.php\n");
    exit(1);
}

$hasRegistration = preg_match(
    '/add_submenu_page\s*\(.*?Keyword Pools.*?KeywordPoolsAdminPage::slug\(\).*?KeywordPoolsAdminPage::class.*?render/s',
    $source
) === 1;

if (!$hasRegistration) {
    fwrite(STDERR, "add_submenu_page missing tmwseo-keyword-pools registration\n");
    exit(1);
}

if (!preg_match('/\$desired_order\s*=\s*\[(?P<order>.*?)\];/s', $source, $matches)) {
    fwrite(STDERR, "unable to inspect reorder_admin_menus desired order\n");
    exit(1);
}

if (strpos($matches['order'], "'tmwseo-keyword-pools'") === false) {
    fwrite(STDERR, "desired order missing tmwseo-keyword-pools\n");
    exit(1);
}

echo "keyword pools admin menu reorder smoke checks passed\n";
