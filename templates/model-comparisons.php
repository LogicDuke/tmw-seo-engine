<?php
/**
 * Platform comparison template pool.
 */

$points = [
    "Choose your starting platform by three checks first: room uptime, chat readability, and mobile playback.",
    "If both platforms are active, open each room briefly and keep the one that loads faster with clearer chat controls.",
    "Run the same checklist on each platform so your decision stays practical and fair.",
    "Platform choice is usually about user constraints: speed, trust, controls, and familiarity.",
    "A strong comparison names both active platforms directly and explains what to click first.",
    "When one platform loads faster but the other has cleaner moderation, start where your priority matters most.",
    "If you mostly watch on mobile, prioritize stable playback and readable chat on smaller screens.",
    "For repeat viewers, alert reliability and official profile consistency matter as much as stream quality.",
];

$templates = [];
for ($i = 0; $i < 40; $i++) {
    shuffle($points);
    $templates[] = implode(' ', array_slice($points, 0, 3));
}

return $templates;
