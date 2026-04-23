<?php
/**
 * Platform comparison template pool.
 */

$points = [
    "Compare platforms by concrete factors: room uptime, chat readability, mobile playback, and account controls.",
    "If multiple platforms are active, test both briefly before choosing one as your default room.",
    "Use the same checklist on each platform so the comparison stays fair and useful.",
    "Platform differences are usually operational, not performer identity: navigation, moderation, and room tools matter most.",
    "A balanced comparison should describe each active platform directly instead of promoting one and listing the rest in a table.",
    "When one platform loads faster but another has cleaner chat controls, your best choice depends on how you watch.",
    "If you use mobile often, prioritize the platform with stable playback and easier chat controls on smaller screens.",
    "For repeat viewers, alerts and profile reliability usually matter as much as stream quality.",
];

$templates = [];
for ($i = 0; $i < 40; $i++) {
    shuffle($points);
    $templates[] = implode(' ', array_slice($points, 0, 3));
}

return $templates;
