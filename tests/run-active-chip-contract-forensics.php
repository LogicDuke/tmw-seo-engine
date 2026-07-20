<?php
/**
 * Forensic reproduction — selected-keyword contract audit (July 2026 PDF).
 *
 * Runs the exact live CategoryChipFeasibility + CategoryKeywordPlanner code
 * against the six real stored Rank Math chip sets shown in the production PDF
 * (Big Boob Cam, Blonde Cam Models, Latina Cam Models, Free Cam Chat,
 * Amateur Cams, Live Anal Cams) and prints, per keyword:
 *
 *   stored-in-Rank-Math? -> rendered / covered_by_primary / tracking_only (+reason)
 *
 * This proves statically which selected keywords the generator plans to place
 * and which remain active Rank Math chips that the page will never carry.
 *
 * Run: php tests/run-active-chip-contract-forensics.php
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/'); }

$pipeline_dir = dirname(__DIR__) . '/includes/content/category-pipeline/';
require_once $pipeline_dir . 'class-category-keyword-planner.php';
require_once $pipeline_dir . 'class-category-chip-feasibility.php';
require_once $pipeline_dir . 'class-category-semantic-profile.php';
require_once $pipeline_dir . 'class-category-semantic-sections.php';
require_once $pipeline_dir . 'class-category-interchangeability-guard.php';

use TMWSEO\Engine\Content\CategoryPipeline\CategoryChipFeasibility;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryKeywordPlanner;

$cases = [
    'Big Boob Cam' => ['big breast webcam','big boobs webcam','biggest boobs webcam','massive boob webcam','massive boobs cam','massive breasts webcam','big boobs live cam','big boobs live camera'],
    'Blonde Cam Models' => ['blonde cams','blonde live sex','blonde sex cam','blonde live webcam','live blonde cams','blonde nude cam','free blonde cams','blonde live chat'],
    'Latina Cam Models' => ['latina sex cam','latina live sex','live latina cams','latina nude webcam','latina sex chat','latina live nude','latina live webcam','latina cam nude'],
    'Free Cam Chat' => ['free cam to cam chat','free live cams','free webcam chat','cam to cam chat','cam chat rooms','webcam chat rooms','free live cam chat','free webcam shows'],
    'Amateur Cams' => ['amateur webcam','amateur tv cams','live amateur sex cams','amateur sex chat','amateur live cams'],
    'Live Anal Cams' => ['anal cams','live anal webcam','free anal cams','live anal chat','anal chat cam','best anal cams','free anal webcams','free live anal'],
];

// Visible generated text per the PDF (headings + phrases actually present),
// distilled to the exact chip phrases that appear on each live page.
$pdf_present = [
    'Big Boob Cam' => ['big breast webcam','big boobs webcam','big boobs live camera'],
    'Blonde Cam Models' => ['blonde cams','blonde live webcam','blonde live sex','blonde sex cam','blonde nude cam','blonde live chat'],
    'Latina Cam Models' => ['latina sex cam','latina live sex','live latina cams','latina nude webcam','latina sex chat','latina live webcam','latina cam nude'],
    'Free Cam Chat' => ['free cam to cam chat','free webcam chat','free live cams','free webcam shows'],
    'Amateur Cams' => ['amateur webcam','amateur live cams','amateur tv cams','live amateur sex cams','amateur sex chat'],
    'Live Anal Cams' => ['live anal webcam','free anal cams','live anal chat','anal chat cam','free live anal'],
];

foreach ($cases as $primary => $chips) {
    $fa = CategoryChipFeasibility::analyze($primary, $chips);
    $rendered = array_map(static fn($r) => (string)$r['keyword'], (array)$fa['rendered_chips']);
    $covered  = array_map(static fn($r) => (string)$r['keyword'], (array)$fa['covered_by_primary_chips']);
    $tracking = [];
    foreach ((array)$fa['tracking_only_chips'] as $r) { $tracking[(string)$r['keyword']] = (string)$r['reason']; }

    echo "== $primary  (stored chips: " . count(array_unique(array_map('strtolower', $chips))) . ")\n";
    printf("   feasible=%s  projected_density=%.3f%%  heading_demand=%d  faq_demand=%d\n",
        $fa['feasible'] ? 'yes' : 'NO', $fa['projected_density'], $fa['heading_demand'], $fa['faq_demand']);
    foreach (array_values(array_unique($chips)) as $kw) {
        $status = in_array($kw, $rendered, true) ? 'RENDERED'
                : (in_array($kw, $covered, true) ? 'COVERED_BY_PRIMARY'
                : (isset($tracking[$kw]) ? 'TRACKING_ONLY (' . $tracking[$kw] . ')' : 'UNCLASSIFIED'));
        $family = CategoryKeywordPlanner::root_family($kw);
        $onpage = in_array($kw, $pdf_present[$primary] ?? [], true) ? 'on live page' : 'ABSENT from live page';
        printf("   %-24s family=%-18s -> %-55s | %s\n", $kw, $family, $status, $onpage);
    }
    echo "\n";
}
