<?php
/**
 * Local regression helper for model research evidence humanizers.
 *
 * Run: php tests/run-model-research-evidence-humanizers.php
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once dirname(__DIR__) . '/includes/content/class-model-research-evidence.php';

use TMWSEO\Engine\Content\ModelResearchEvidence;

function tmw_evidence_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function tmw_evidence_forbidden_terms(): array {
    return [
        'verified notes' . ' point to',
        'personable cam ' . 'delivery',
        'consistent on-camera ' . 'presence',
        'do you ' . 'accept?',
        'Use these notes as profile ' . 'context',
    ];
}

function tmw_evidence_assert_forbidden_clean(string $text, string $label): void {
    $lower = strtolower($text);
    foreach (tmw_evidence_forbidden_terms() as $term) {
        tmw_evidence_assert(!str_contains($lower, strtolower($term)), $label . ' leaked forbidden term: ' . $term);
    }
}


foreach (['?', ':', ' -', ' –', ' —'] as $noise_suffix) {
    $noise_turn_ons = ModelResearchEvidence::humanize_turn_ons('Do you ' . 'accept' . $noise_suffix);
    tmw_evidence_assert($noise_turn_ons === '', 'Noise-only turn-ons should return an empty string for suffix: ' . $noise_suffix);
}

$punctuated_turn_ons = ModelResearchEvidence::humanize_turn_ons('Do you ' . 'accept: Roleplay, Cosplay');
tmw_evidence_assert_forbidden_clean($punctuated_turn_ons, 'Punctuated turn-ons');
tmw_evidence_assert(str_contains($punctuated_turn_ons, 'Roleplay'), 'Punctuated turn-ons should keep Roleplay after cleanup.');
tmw_evidence_assert(str_contains($punctuated_turn_ons, 'Cosplay'), 'Punctuated turn-ons should keep Cosplay after cleanup.');
tmw_evidence_assert(stripos($punctuated_turn_ons, 'Do you ' . 'accept') === false, 'Punctuated turn-ons should remove acceptance prompt wording.');
tmw_evidence_assert(!preg_match('/^\s*[:\-–—]/u', $punctuated_turn_ons), 'Punctuated turn-ons should not start with punctuation artifacts.');

$stacked_punctuation_turn_ons = ModelResearchEvidence::humanize_turn_ons('Do you ' . 'accept: - Roleplay, Cosplay');
tmw_evidence_assert(str_contains($stacked_punctuation_turn_ons, 'Roleplay'), 'Stacked punctuation cleanup should keep Roleplay.');
tmw_evidence_assert(str_contains($stacked_punctuation_turn_ons, 'Cosplay'), 'Stacked punctuation cleanup should keep Cosplay.');
tmw_evidence_assert(!preg_match('/^\s*[:\-–—]/u', $stacked_punctuation_turn_ons), 'Stacked punctuation cleanup should not leave leading artifacts.');

$abby_bio = 'I am ready for you, join me and do not miss this amazing time. My profile says friendly dancing cosplay private chat with me.';
$abby_turn_ons = 'Do you ' . 'accept?';
$abby_private = 'In Private Chat, I\'m willing to perform: Roleplay, Anal, Striptease, Dancing, Cosplay, Deepthroat, Close up, ASMR, Dildo, Oil, Twerk, Snapshot, High Heels, Stockings';
$abby_output = implode(' ', [
    ModelResearchEvidence::humanize_bio($abby_bio, 'Abby Murray'),
    ModelResearchEvidence::humanize_turn_ons($abby_turn_ons),
    ModelResearchEvidence::humanize_private_chat($abby_private),
]);

tmw_evidence_assert_forbidden_clean($abby_output, 'Abby Murray');
tmw_evidence_assert(
    preg_match('/\b(Roleplay|Striptease|Dancing|Cosplay)\b/', $abby_output) === 1,
    'Abby Murray private chat should retain safe private-chat items.'
);

$aisha_turn_ons = 'I love quality time, helping people, chocolate, morning coffee, and animals.';
$aisha_output = ModelResearchEvidence::humanize_turn_ons($aisha_turn_ons);
tmw_evidence_assert_forbidden_clean($aisha_output, 'Aisha Dupont');
tmw_evidence_assert(
    preg_match('/quality|helping people|chocolate|morning coffee|animals/i', $aisha_output) === 1,
    'Aisha Dupont turn-ons should render natural safe interests.'
);
tmw_evidence_assert(!str_contains(strtolower($aisha_output), 'robotic'), 'Aisha Dupont output should not use robotic evidence wording.');

$anisyia_private = 'Private chat options: Roleplay, Cosplay, Striptease, ASMR, Close up, Foot Fetish, Anal, Deepthroat, Cumshot, Squirt, Dildo';
$anisyia_output = ModelResearchEvidence::humanize_private_chat($anisyia_private);
tmw_evidence_assert_forbidden_clean($anisyia_output, 'Anisyia');
foreach (['Roleplay', 'Cosplay', 'Striptease', 'ASMR', 'Close up', 'Foot Fetish'] as $safe_item) {
    tmw_evidence_assert(str_contains($anisyia_output, $safe_item), 'Anisyia output should retain ' . $safe_item . '.');
}
foreach (['Anal', 'Deepthroat', 'Cumshot', 'Squirt', 'Dildo'] as $unsafe_item) {
    tmw_evidence_assert(stripos($anisyia_output, $unsafe_item) === false, 'Anisyia output should remove unsafe item ' . $unsafe_item . '.');
}
tmw_evidence_assert(!str_contains($anisyia_output, 'private-chat availability, interactive requests, roleplay-style options, and media/chat features'), 'Anisyia output should not use the old generic collapse text.');

echo "✓ Model research evidence humanizer regressions passed\n";
