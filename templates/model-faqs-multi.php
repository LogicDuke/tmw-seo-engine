<?php
$question_pool = [
    'Where can I find official profiles for {name}?',
    'Which platforms does {name} use?',
    'How do I watch {name} live on {platform_a}?',
    'Is {platform_b} the same experience as {platform_a}?',
    'How do I get notified when {name} goes live?',
    'Does {name} have a schedule across {active_platforms_text}?',
    'Can I use mobile to watch {name}?',
    'Does {name} offer private sessions?',
    'What tags describe {name}’s shows?',
    'Where should I click to join {name}’s official rooms?',
];

$answer_pool = [
    "Use the official profile links below to reach {name}'s verified pages on {active_platforms_text}.",
    "Each platform has its own schedule, so follow {name} on {active_platforms_text} for alerts and updates.",
    "On {platform_a}, log in a few minutes early so you can join the room as soon as {name} goes live.",
    "The core experience stays interactive across {active_platforms_text}, with live chat and real-time reactions.",
    "Stream times rotate; enable notifications on each official platform to get alerts before {name} goes live.",
    "Mobile viewers can tap the official links, log in, and still access HD streams with chat controls.",
    "Private shows are available when the room is calm—request a slot and {name} will confirm if timing works.",
    "Tags like {tags} describe the vibe, and you can nudge new ideas politely during the show.",
    "Click the official links for {active_platforms_text} to join {name}'s verified rooms.",
];

shuffle($question_pool);
shuffle($answer_pool);

$faqs = [];
$count = min(count($question_pool), count($answer_pool));
for ($i = 0; $i < $count; $i++) {
    $faqs[] = [
        'q' => $question_pool[$i],
        'a' => $answer_pool[$i % count($answer_pool)],
    ];
}

return $faqs;
