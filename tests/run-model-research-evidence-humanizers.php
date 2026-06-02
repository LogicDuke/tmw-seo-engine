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

$anisyia_private = 'Private chat options: Roleplay, Cosplay, Striptease, ASMR, Close up, Anal, Deepthroat, Cumshot, Squirt, Dildo';
$anisyia_output = ModelResearchEvidence::humanize_private_chat($anisyia_private);
tmw_evidence_assert_forbidden_clean($anisyia_output, 'Anisyia');
foreach (['Roleplay', 'Cosplay', 'Striptease', 'ASMR', 'Close up'] as $safe_item) {
    tmw_evidence_assert(str_contains($anisyia_output, $safe_item), 'Anisyia output should retain ' . $safe_item . '.');
}
foreach (['Anal', 'Deepthroat', 'Cumshot', 'Squirt', 'Dildo'] as $unsafe_item) {
    tmw_evidence_assert(!str_contains($anisyia_output, $unsafe_item), 'Anisyia output should remove unsafe item ' . $unsafe_item . '.');
}

echo "✓ Model research evidence humanizer regressions passed\n";
