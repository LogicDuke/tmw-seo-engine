<?php
/**
 * Multi-platform intro template pool.
 *
 * Used when a model has profiles on two or more platforms.
 */

$search_hooks = [
    "{name} has active profiles on {active_platforms_text}, so you can choose the room that fits how you like to watch.",
    "If you are checking {name} across {active_platforms_text}, the links below go straight to the current rooms.",
    "The goal is simple: make it easy to open the real profiles on {active_platforms_text} without digging through copied listings.",
    "Viewers comparing {active_platforms_text} usually want current links first and a short sense of what each room is like.",
    "When someone follows {name} on more than one site, having both active profiles together saves a lot of back-and-forth.",
    "This guide keeps the active {active_platforms_text} profiles in one place so platform choice is easier.",
];

$live_benefits = [
    "Multiple platforms let you pick between different chat pace and interface style while following the same performer.",
    "One room may move faster while the other stays more conversational, which is useful when you are deciding where to spend time.",
    "When people compare platforms, they usually care about stream stability, chat readability, and how easy moderation tools are to use.",
    "Following both profiles helps when schedules shift, especially if you check in from different time zones.",
];

$style_notes = [
    "The performer style stays familiar, but each platform can shape how fast chat moves and how visible messages feel.",
    "Across both platforms, the strongest sessions keep responsive chat and clear pacing without turning noisy.",
    "Good multi-platform pages are most helpful when they compare access and chat experience instead of repeating hype.",
    "The differences are usually practical: navigation, moderation, and how easy it is to settle in once the room gets busy.",
];

$cta_lines = [
    "Use the links below to compare the active profiles on {active_platforms_text} directly.",
    "Start on the platform you use most, then open the second room if you want to compare chat flow and tools.",
    "The links below are the fastest way to check which room suits you best today.",
    "If you just want the current rooms without extra searching, the platform links below are the place to start.",
];

$intros = [];
for ($i = 0; $i < 60; $i++) {
    shuffle($search_hooks);
    shuffle($live_benefits);
    shuffle($style_notes);
    shuffle($cta_lines);

    $sentences = array_merge(
        array_slice($search_hooks, 0, 2),
        array_slice($live_benefits, 0, 2),
        array_slice($style_notes, 0, 1),
        array_slice($cta_lines, 0, 1)
    );

    $intros[] = implode(' ', $sentences);
}

return $intros;
