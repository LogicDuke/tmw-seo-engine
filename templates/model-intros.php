<?php
/**
 * Single-platform intro template pool.
 *
 * Each intro should immediately help the reader solve the first problem:
 * finding the real room quickly and avoiding copied profiles.
 */

$openers = [
    "This page prioritizes verified live-room access on {live_brand}, so the first click goes to a checked destination.",
    "Start here when you need a trusted route on {live_brand}; links are reviewed to reduce copied-room mistakes.",
    "When access speed matters, use the verified destination list first and skip mirror listings.",
    "The opening links are arranged for quick room verification on {live_brand}, not for hype copy.",
    "Use this listing as a practical shortcut: check verified room access first, then decide whether to stay.",
];

$utility_lines = [
    "After click-through, confirm username match and recent room indicators before spending credits or tips.",
    "For mobile sessions, test playback and chat readability quickly before committing to one room.",
    "Inactive or unclear listings stay separated from live-room links so room status remains truthful.",
    "Use this page as a decision tool: verify destination, check room quality, and keep a backup option.",
    "If a destination looks stale, use another verified option from the other sections instead of search results.",
];

$intros = [];
for ($i = 0; $i < 60; $i++) {
    shuffle($openers);
    shuffle($utility_lines);
    $intros[] = $openers[0] . ' ' . $utility_lines[0];
}

return $intros;
