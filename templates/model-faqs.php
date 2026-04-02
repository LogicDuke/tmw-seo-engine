<?php
/**
 * Single-platform FAQ template pool.
 *
 * All placeholders ({name}, {live_brand}, {tags}, {active_platforms_text}) are
 * rendered by TemplateEngine::render() in TemplateContent::build_model() before
 * these items reach the renderer. No raw placeholder should appear in final output.
 *
 * Answers are written as complete, natural sentences — not single-line fragments.
 */

$question_pool = [
    'How do I watch {name} live on {live_brand}?',
    'Where can I find verified profiles for {name}?',
    'What kind of shows does {name} typically stream?',
    'How do I get notified when {name} goes live?',
    'Can I watch {name} on a mobile device?',
    'Does {name} offer private sessions on {live_brand}?',
    'Is {live_brand} a safe platform for new viewers?',
    'What themes or tags describe {name}\'s shows?',
    'How does {name} handle viewer requests during a session?',
    'What makes {name}\'s {live_brand} shows different from a typical broadcast?',
    'Can I interact with {name} during a live session?',
    'How far in advance does {name} announce upcoming streams?',
    'What should a first-time viewer expect from {name}\'s room?',
    'Are {name}\'s shows available to replay after they end?',
    'Does {name} interact with chat throughout the session?',
    'What is the best way to join {name}\'s room for the first time?',
    'Which platform gives the best viewing experience for {name}\'s shows?',
];

$answer_pool = [
    "Click the {live_brand} link above, log in to your account, and navigate to {name}'s room. Arriving a couple of minutes early gives you the best chance of catching the opening of the session, which is often when the most interaction happens.",
    "The verified profile links listed on this page point directly to {name}'s official {live_brand} room. These are the only links you need — they bypass any unofficial or third-party aggregator pages.",
    "Sessions typically explore themes connected to {tags}, with the specific tone and pace guided by chat responses in real time. The format rewards viewers who participate rather than just observe.",
    "The most reliable method is to enable notifications on {live_brand} after following {name}'s profile. The platform sends an alert when a session starts, which means you do not need to check back manually.",
    "Yes — {live_brand} is fully optimised for mobile. Tap the official link on this page, log into your account, and the session loads with full HD and chat controls available on any modern smartphone or tablet.",
    "Private sessions are available on {live_brand} when the main room is not actively broadcasting. The process is straightforward: request a private slot through the platform and {name} will confirm availability.",
    "{live_brand} operates with active moderation and clear community guidelines, making it a reliable choice for new viewers who want a controlled, respectful environment. Account creation is quick, and the platform does not require sharing unnecessary personal information.",
    "Tags like {tags} give the best indication of show tone and content direction. These reflect the kind of interaction and atmosphere {name} brings to each session, though the specifics shift depending on what the room is responding to.",
    "Viewer requests are welcome within the guidelines posted on {name}'s profile. Suggesting themes related to {tags} is the most effective approach — ideas that align with the existing show direction are more likely to be woven into the session naturally.",
    "The combination of consistent production quality, genuine chat engagement, and a clear sense of pacing separates {name}'s sessions from less structured broadcasts. The room has a defined atmosphere rather than a free-for-all format.",
    "Interaction is central to the format. {name} reads chat throughout each session, acknowledges viewer messages, and adjusts the show's direction based on what the room is responding to. Participation makes the experience significantly better.",
    "Timing notes are usually posted to {name}'s {live_brand} profile before each session. Enabling notifications is the most reliable way to stay updated without having to check back repeatedly.",
    "Expect a structured session with a clear warm-up, a conversational middle section where chat drives most of the direction, and a gradual close. The overall atmosphere is welcoming, and new viewers are usually acknowledged early.",
    "Live sessions on {live_brand} are not available as permanent replays in the same way pre-recorded content is. Some highlights may be available through the platform, but the live experience itself is designed to be attended in real time.",
    "Chat engagement is a consistent part of every session. {name} reads messages throughout, uses viewer names, and responds to suggestions — which is one of the reasons live sessions feel distinct from pre-recorded content.",
    "Use the official {live_brand} link on this page to access the room directly. If it is your first time on the platform, creating an account takes under a minute. Arriving a few minutes before the scheduled start time is the smoothest way to begin.",
    "{live_brand} offers the most complete experience for {name}'s sessions, with HD quality, stable streaming, and the full range of interactive features available. Other platforms may be available depending on {name}'s current profile setup.",
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
