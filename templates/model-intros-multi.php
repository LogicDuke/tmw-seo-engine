<?php
/**
 * Multi-platform intro template pool.
 */

$openers = [
    "{name} has active profiles on {active_platforms_text}, and this page keeps the official links in one trusted place.",
    "Trying to find the real {name} room across {active_platforms_text}? Start with the confirmed profile links here.",
    "{name} is active on {active_platforms_text}; use this page to avoid copied profiles and reach official rooms faster.",
    "For {name}, the fastest trusted route across {active_platforms_text} is the verified link set below.",
    "When {name} is live on {active_platforms_text}, this page helps you identify which official room to open first.",
];

$utility_lines = [
    "Start on the platform you already use, then compare the second active room if chat tools or mobile playback matter more.",
    "Check both active profiles with the same checklist: load speed, chat clarity, and room controls.",
    "Both platform sections are decision-focused so you can choose where to watch first without one-platform bias.",
    "If one room is inactive, the second official profile gives you a quick fallback without extra searching.",
    "Use this as a decision page: identify active platforms, verify official links, then click the best starting room.",
];

$intros = [];
for ($i = 0; $i < 60; $i++) {
    shuffle($openers);
    shuffle($utility_lines);
    $intros[] = $openers[0] . ' ' . $utility_lines[0];
}

return $intros;
