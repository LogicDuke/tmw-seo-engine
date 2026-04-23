<?php
/**
 * Multi-platform intro template pool.
 */

$openers = [
    "{name} has active profiles on {active_platforms_text}, and this page keeps the confirmed room links together.",
    "If you follow {name} across {active_platforms_text}, use the direct profile links here to avoid stale mirrors.",
    "{name} is live across {active_platforms_text}; this page helps you pick the right room without extra searching.",
    "For {name}, the fastest route to real profiles on {active_platforms_text} is the link set below.",
    "This page tracks active profiles for {name} on {active_platforms_text} so you can compare options quickly.",
];

$utility_lines = [
    "Start with your preferred platform, then compare the second room if chat tools or pacing matter to you.",
    "Use the links to confirm which room is active first, then choose based on the platform features you prefer.",
    "Both platform sections are written for side-by-side comparison rather than one-platform bias.",
    "The sections below focus on practical differences: access flow, controls, and room behavior across platforms.",
    "When schedules rotate, keeping both official profiles in one place makes check-ins faster.",
];

$intros = [];
for ($i = 0; $i < 60; $i++) {
    shuffle($openers);
    shuffle($utility_lines);
    $intros[] = $openers[0] . ' ' . $utility_lines[0];
}

return $intros;
