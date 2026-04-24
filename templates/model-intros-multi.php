<?php
/**
 * Multi-platform intro template pool.
 */

$openers = [
    "Active live-room destinations are currently mapped across {active_platforms_text}, with verified links grouped in one place.",
    "When several rooms are available, this page keeps the first-click options practical and reviewable.",
    "Use the verified list to compare active platforms without jumping between copied listings.",
    "This page separates live access from follow/support destinations so platform choices stay clear.",
    "The goal is quick, trustworthy routing across {active_platforms_text} with less search friction.",
];

$utility_lines = [
    "Use the same checklist on each room: uptime signals, playback stability, chat readability, and moderation comfort.",
    "Start on your familiar platform, then test the second option as a fallback when one room is slow or offline.",
    "Non-live destinations are listed separately for follow and support actions, not as room-entry shortcuts.",
    "This structure helps you compare platforms fairly before choosing a default room.",
    "If status changes later, re-check the verified links section because activity snapshots can shift.",
];

$intros = [];
for ($i = 0; $i < 60; $i++) {
    shuffle($openers);
    shuffle($utility_lines);
    $intros[] = $openers[0] . ' ' . $utility_lines[0];
}

return $intros;
