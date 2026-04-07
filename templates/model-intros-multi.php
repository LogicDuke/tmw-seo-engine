<?php
/**
 * Multi-platform intro template pool.
 *
 * Used when a model has profiles on two or more platforms.
 */

$search_hooks = [
    "{name} is easier to track when the active profiles on {active_platforms_text} are kept together in one place.",
    "Most people landing here for {name} are trying to figure out which profile is current on {active_platforms_text} without opening a pile of stale pages.",
    "People usually land here with a practical question: which room is live, and which platform feels like the better fit tonight.",
    "The useful part of a multi-platform page is clarity. {name} has active profiles on {active_platforms_text}, and the links below point to the real rooms.",
    "If {name} is already familiar, this page saves the step of hunting through copied profile listings across {active_platforms_text}.",
    "Viewers comparing {active_platforms_text} generally want one clean list of current rooms and a quick sense of how each platform feels.",
];

$live_benefits = [
    "Multiple platforms give viewers a choice between room styles without changing the core live-chat feel.",
    "The main advantage here is flexibility. One platform may feel quieter, another may feel more open, and having both listed saves time.",
    "Stable HD video, readable chat, and predictable room tools matter more when people are actively comparing platforms.",
    "Following more than one room is useful when schedules rotate, especially for viewers checking in from different time zones.",
];

$style_notes = [
    "The tone still depends on interaction, not just the platform. The room works best when chat stays engaged and the pace has room to breathe.",
    "Even across different platforms, the stronger sessions usually keep the same qualities: clean reactions, steady pacing, and room awareness.",
    "A good multi-platform setup gives people options without making the page harder to use. That is the idea here.",
    "What changes most from platform to platform is the room feel, not the personality behind it.",
];

$cta_lines = [
    "Use the links below to compare the active profiles on {active_platforms_text} directly.",
    "Start with the platform you already prefer, then use the second link if you want to compare room feel and features.",
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
