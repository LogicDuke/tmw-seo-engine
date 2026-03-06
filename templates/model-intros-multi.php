<?php
$search_hooks = [
    "Fans searching '{name} live shows' see verified links across {active_platforms_text} for consistent access.",
    "People typing '{name} cam model' often land here and realize her official profiles span {active_platforms_text}.",
    "Viewers comparing where to watch {name} can follow the trusted profile links below for each platform.",
    "Followers tracking {name} across {active_platforms_text} appreciate having all official destinations in one place.",
    "Searchers who follow '{name} live cam' tags discover the verified {active_platforms_text} profiles for real-time sessions.",
    "Fans looking for {name}'s shows find the official profile list for quick access to every platform she uses.",
];

$live_benefits = [
    "Live streaming keeps the interaction immediate, with chat feedback shaping the pace of each session.",
    "Viewers enjoy reliable HD quality and respectful moderation that keeps the focus on the performer.",
    "Real-time conversation creates a more personal connection than static content feeds.",
    "Private shows offer a focused experience when viewers want one-on-one attention.",
];

$style_notes = [
    "Expect balanced pacing: a warm greeting, a few playful prompts, and gradual buildup that keeps the room engaged.",
    "She weaves light roleplay hints with genuine conversation, adjusting based on chat reactions.",
    "Lighting shifts from soft glow to accent tones as {name} transitions between themes.",
    "Music stays mellow so viewers focus on expressions and movement.",
];

$cta_lines = [
    "Use the official links below to follow {name} on {active_platforms_text}.",
    "Check the verified profiles to join {name} wherever she streams next.",
    "Tap the official platform links to keep up with {name}'s live schedule.",
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
        array_slice($style_notes, 0, 2),
        array_slice($cta_lines, 0, 1)
    );
    $intros[] = implode(' ', $sentences);
}

return $intros;
