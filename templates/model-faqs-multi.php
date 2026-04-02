<?php
/**
 * Multi-platform FAQ template pool.
 *
 * Used when a model has profiles on two or more platforms.
 * Placeholders are rendered before these reach the page renderer.
 */

$question_pool = [
    'Which platforms does {name} stream on?',
    'Where can I find verified profiles for {name}?',
    'How do I watch {name} live on {platform_a}?',
    'Is the experience different on {platform_a} versus {platform_b}?',
    'How do I get notified when {name} goes live?',
    'Does {name} keep a consistent schedule across {active_platforms_text}?',
    'Can I watch {name} on a mobile device?',
    'Does {name} offer private sessions?',
    'What themes or tags describe {name}\'s shows?',
    'What is the best platform to start with for a first-time viewer?',
    'How does {name} handle viewer requests?',
    'What should a first-time viewer expect from {name}\'s room?',
];

$answer_pool = [
    "{name} currently streams on {active_platforms_text}. All official profile links are listed on this page — they point directly to verified rooms rather than third-party aggregator pages.",
    "The verified profile links on this page connect to {name}'s official rooms on {active_platforms_text}. These are the fastest routes to a live session without searching through unverified sources.",
    "On {platform_a}, log in to your account and navigate to {name}'s room. Arriving a couple of minutes before the scheduled start gives you the best chance of catching the opening, which is usually the most interactive part of the session.",
    "The core format is consistent across platforms, though {platform_a} tends to offer a more polished private-session setup while {platform_b} can have a more open community feel. The best choice depends on whether you prefer a quieter or more active room.",
    "Enable notifications on each platform after following {name}'s profile on {active_platforms_text}. The notification system sends an alert when a session starts, which is the most reliable way to stay updated without checking back manually.",
    "{name} broadcasts across {active_platforms_text} on a rotating schedule. Following on each platform and enabling notifications is the most reliable way to catch sessions regardless of which one is active.",
    "Yes — {active_platforms_text} all support mobile viewing with HD streaming and full chat controls. Tap the official link on this page, log in, and the session loads correctly on any modern smartphone or tablet.",
    "Private sessions are available on request. The process varies slightly by platform, but the general approach is to reach out through the official room and wait for confirmation that timing and availability line up.",
    "Tags like {tags} describe the tone and content direction of most sessions. These give the best indication of what to expect, though specific themes shift depending on what the room is responding to on a given night.",
    "{platform_a} is a strong starting point for first-time viewers — the platform's moderation and interface make it straightforward to find the room, follow the session, and use interactive features without prior experience.",
    "Requests are welcome within the guidelines posted on each platform's profile. Suggestions tied to themes like {tags} integrate most naturally into the session flow and are more likely to be picked up by {name} during the broadcast.",
    "Expect a structured session with a warm opening, a conversational middle section driven by chat, and a gradual close. The atmosphere is welcoming across all platforms, and new viewers are usually acknowledged early in the session.",
];

shuffle( $question_pool );
shuffle( $answer_pool );

$faqs  = [];
$count = min( count( $question_pool ), count( $answer_pool ) );
for ( $i = 0; $i < $count; $i++ ) {
    $faqs[] = [
        'q' => $question_pool[ $i ],
        'a' => $answer_pool[ $i % count( $answer_pool ) ],
    ];
}

return $faqs;
