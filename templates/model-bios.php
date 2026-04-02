<?php
/**
 * Model bio template pool.
 *
 * All platform references use {live_brand} so the template engine substitutes
 * the actual platform name at render time. The context key live_brand is always
 * a real platform name or the safe prose fallback "live cam" — never a placeholder.
 */

$bio_openers = [
    "{name} treats each {live_brand} session like a guided experience, mixing deliberate pacing with real-time check-ins so newcomers and regulars both feel at home.",
    "Viewers who follow {name} consistently describe a thoughtful approach to live performance — attentive to the room, consistent with pacing, and careful not to let things feel rushed.",
    "Community members describe {name} as an entertainer who keeps private chats personal and focused, with a conversational quality that sets the tone early in every broadcast.",
    "What makes {name}'s shows worth returning to is a combination of genuine audience awareness and a natural ability to sustain energy across a full session rather than front-loading the first few minutes.",
    "Long-time followers of {name} point to a performer who clearly enjoys the live format — the spontaneous moments, the chat-driven detours, and the exchanges that take a session somewhere unexpected.",
    "{name} approaches each broadcast with the same energy whether the room has a handful of viewers or a full crowd, which is something regulars notice and newcomers pick up on quickly.",
    "The consistency fans mention most is not purely technical — though the production quality is high — but a willingness to acknowledge the room and adapt when the conversation shifts direction.",
    "Those who join {name}'s room for the first time often describe a warmer welcome than anticipated: the show is built around making people comfortable before anything else.",
];

$specialties = [
    "Themes inspired by {tags} move through each session naturally, introduced through conversation rather than announced as fixed segments, which keeps the flow easy to follow.",
    "The {tags} thread that runs through most broadcasts gives returning viewers a reference point while leaving room for spontaneous detours driven by what the chat is feeling in the moment.",
    "Tag themes like {tags} inform the mood and pacing without scripting the entire show — the approach leaves space for genuine exchanges that template-driven sessions rarely allow.",
    "Sessions draw from {tags} as a creative reference rather than a rigid format, meaning the content feels curated without being predictable.",
    "The {tags} influence comes through in atmosphere and pacing rather than explicit structure, giving the show a coherence that casual visitors appreciate and regular viewers understand intuitively.",
];

$platform_angles = [
    "{live_brand} delivers real-time reactions and consistent HD quality, keeping every session immersive regardless of the time zone a viewer joins from.",
    "The {live_brand} environment suits {name}'s style because the platform's moderation keeps chat respectful enough for genuine conversation to happen without constant interruption.",
    "Fans note that {live_brand} sessions carry a level of audio and video clarity that makes the interaction feel closer — small details in expression and tone come through cleanly.",
    "Joining {name} on {live_brand} shifts the experience from passive viewing to active participation, which is the core of what the live format is supposed to offer.",
    "{live_brand}'s room controls give {name} tools to manage pace and tone without breaking the flow, which is part of why sessions feel more directed than most free-roaming broadcasts.",
    "Regular viewers appreciate how {live_brand} sessions respond to the room's energy in real time — the platform's stability means technical interruptions rarely cut into what would otherwise be a natural moment.",
    "The HD streaming and two-way chat on {live_brand} means viewers get more than a video feed — there is an actual exchange happening, which {name} leans into throughout every session.",
    "{live_brand}'s moderation tools keep the atmosphere focused so conversation stays on track and the mood does not drift mid-session.",
];

$style_blocks = [
    "Lighting shifts move through the session gradually — from softer ambient tones at the start to more defined accents as the mood builds — giving each broadcast a visual arc viewers can feel even if they cannot fully articulate it.",
    "Transitions between segments are narrated rather than abrupt, so viewers always have a sense of where the session is heading, which reduces uncertainty for anyone joining mid-show.",
    "The chat rhythm established early in each broadcast — acknowledging messages, asking questions back, reacting rather than simply performing — creates a collaborative tone that most viewers respond to within the first few minutes.",
    "Viewers who have attended multiple sessions describe the pacing as something learnable: once the rhythm is understood, the show becomes easier to enjoy fully rather than just follow.",
    "Production choices stay consistent across broadcasts: steady framing, clear audio levels, and lighting that complements rather than competes. These details matter more over a full session than in a highlight clip.",
    "There is an attentiveness to chat that regular viewers describe as central to what makes the sessions worthwhile — messages are read, names are used, and conversation moves in two directions rather than broadcasting at the room.",
];

$schedule = [
    "Most sessions run during evening hours with weekend pop-ups, and timing notes are posted beforehand so followers across time zones can plan without guessing.",
    "The broadcast schedule is not rigid, but {name} is consistent enough that regular viewers have learned roughly when to check — {live_brand} notification alerts fill in the rest.",
    "Evening slots make up most of the schedule, with occasional midday sessions added when the room has been particularly active. {live_brand} alerts give followers advance notice before each start.",
    "{name} posts session reminders before going live so followers in different time zones can plan ahead rather than refresh the page repeatedly.",
];

$ctas = [
    "Follow {name} on {live_brand} to receive session alerts and avoid missing the more spontaneous broadcasts that often happen outside the regular schedule.",
    "Bookmark this page and use the {live_brand} link to join when the room opens — arriving in the first few minutes gives new viewers the best introduction to {name}'s usual rhythm.",
    "Open {live_brand} in a separate tab so access is ready the moment {name} starts the next session without any login delay.",
    "The easiest way to stay current is to follow {name} on {live_brand} directly — the notification system is more reliable than checking the schedule manually.",
];

$bios = [];
for ( $i = 0; $i < 60; $i++ ) {
    shuffle( $bio_openers );
    shuffle( $specialties );
    shuffle( $platform_angles );
    shuffle( $style_blocks );
    shuffle( $schedule );
    shuffle( $ctas );
    $sentences = array_merge(
        array_slice( $bio_openers, 0, 2 ),
        array_slice( $specialties, 0, 1 ),
        array_slice( $platform_angles, 0, 2 ),
        array_slice( $style_blocks, 0, 2 ),
        array_slice( $schedule, 0, 1 ),
        array_slice( $ctas, 0, 1 )
    );
    $bios[] = implode( ' ', $sentences );
}

return $bios;
