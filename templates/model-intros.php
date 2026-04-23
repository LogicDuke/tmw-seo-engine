<?php
/**
 * Single-platform intro template pool.
 *
 * Each intro should immediately help the reader solve the first problem:
 * finding the real room quickly and avoiding copied profiles.
 */

$openers = [
    "{name} is active on {live_brand}, and the verified links on this page point to the real profile first.",
    "Need the official {name} room on {live_brand}? Start with the confirmed links below instead of mirror listings.",
    "{name} is currently available on {live_brand}; use this page to open the trusted profile without guesswork.",
    "To find the real {name} room on {live_brand}, use the direct verified profile links here first.",
    "The quickest trusted route to {name} on {live_brand} is the official profile set listed below.",
];

$utility_lines = [
    "Check live status on the official profile first, then decide whether to stay or come back later.",
    "This page is built for fast decisions: real profile first, platform details second.",
    "If you are on mobile, open the room first and quickly confirm playback and chat controls.",
    "Use the watch links first when speed matters; they reduce wrong clicks from copied profiles.",
    "You can verify the same username and branding after click-through to avoid fake or stale pages.",
];

$intros = [];
for ($i = 0; $i < 60; $i++) {
    shuffle($openers);
    shuffle($utility_lines);
    $intros[] = $openers[0] . ' ' . $utility_lines[0];
}

return $intros;
