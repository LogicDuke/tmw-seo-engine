<?php
/**
 * Single-platform intro template pool.
 *
 * Each intro should immediately help the reader find the real profile and
 * understand why this page is useful.
 */

$openers = [
    "{name} is currently active on {live_brand}, and the direct room links on this page help you skip copied profile mirrors.",
    "To watch {name} without hunting through reposted listings, start with the confirmed {live_brand} profile links below.",
    "If you want the official room for {name}, this page keeps the current {live_brand} profile access in one place.",
    "{name} has an active profile on {live_brand}; this page is built to get you to the live room quickly.",
    "Looking for {name} live on {live_brand}? Use the verified room links here instead of third-party copies.",
];

$utility_lines = [
    "You can check profile status first, then open the room directly when the stream is live.",
    "The goal is simple: reduce wrong clicks and send you to the confirmed profile fast.",
    "Use the watch buttons first, then the comparison notes if you want to review access details.",
    "Everything below is focused on practical access: where to click, what is active, and which links are official.",
    "When listings conflict, the verified links here are the quickest way to confirm the correct room.",
];

$intros = [];
for ($i = 0; $i < 60; $i++) {
    shuffle($openers);
    shuffle($utility_lines);
    $intros[] = $openers[0] . ' ' . $utility_lines[0];
}

return $intros;
