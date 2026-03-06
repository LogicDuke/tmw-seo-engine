<?php
$question_pool = [
    'Where can I find official profiles for {name}?',
    'How do I watch {name} live?',
    'Does {name} have a schedule?',
    'What type of shows does {name} do?',
    'Can I watch replays of {name}\'s streams?',
    'Is {live_brand} a safe place to watch?',
    'What makes {live_brand} special for {name}?',
    'How do I get notified when {name} goes live?',
    'Can I ask for specific themes with {name}?',
    'Does {name} do multilingual shows?',
    'Can I use mobile to watch {name}?',
    'Does {name} offer private sessions?',
    'How does {name} handle custom requests?',
    'What tags describe {name}\'s shows?',
    'Can I ask for specific themes or costumes?',
    'Where should I click to join {name}\'s official room?',
    'Which official platforms does {name} use?',
];

$answer_pool = [
    "Use the official profile links below to reach {name}\'s verified pages and live room.",
    "Click the {live_brand} link above, log in, and join the room a few minutes early to catch the intro.",
    "Stream times rotate; enable notifications on {live_brand} to get alerts before {name} goes live.",
    "Expect themes tied to {tags}, plus interactive polls and mindful pacing so sessions stay comfortable for everyone.",
    "Highlights help, but the best way to experience {name} is live with two-way conversation on {live_brand}.",
    "{live_brand} moderation keeps chat respectful so the focus stays on the performer.",
    "{live_brand} sessions emphasize real-time responses you cannot get from static clips.",
    "Tap the official links below to find the real-time room and verified pages.",
    "Yes, within posted boundaries. Mention ideas linked to {tags} and {name} will guide you through them in chat.",
    "{name} greets viewers in English first, and may swap languages when the room requests it.",
    "{live_brand} tips translate directly into more time with {name}, keeping the room responsive.",
    "Fans notice smoother lighting and fewer interruptions on {live_brand}.",
    "Mobile viewers can tap the link, log in, and still access HD streams with chat controls.",
    "Private shows are available when the room is calm—request a slot and {name} will confirm if timing works.",
    "Custom requests are welcomed when respectful; suggest a song or pace and {name} adapts while keeping chat flowing.",
    "Tags like {tags} describe the vibe, and you can nudge new ideas politely during the show.",
    "You can ask for themes or light cosplay as long as it fits the boundaries {name} posts on {live_brand}.",
    "Click the official {live_brand} link above or the verified profile links below to join {name}\'s room.",
    "Check the official profile links for {active_platforms_text}; those are the verified destinations for {name}\'s live sessions.",
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
