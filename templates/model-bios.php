<?php
/**
 * Model bio template pool.
 *
 * Keep the prose natural, concrete, and less salesy. The page heading already
 * carries the model name, so the body should not force it into every sentence.
 */

$bio_openers = [
    "{name} usually comes across as comfortable in the live format, with enough room awareness to make even a busy chat feel manageable.",
    "The room tends to work because the energy stays steady. Nothing feels rushed, and the tone rarely flips without warning.",
    "A lot of regular viewers mention the same thing first: the sessions feel present. Reactions land when they should, and the room never feels half asleep.",
    "There is a clear sense of pacing here. The opening is relaxed, the middle stays conversational, and the room has enough structure to keep people settled in.",
    "What makes these sessions easy to return to is consistency. The mood is readable, the interaction is genuine, and the stream does not rely on constant noise.",
    "{name} has the kind of on-camera rhythm that makes a live room easier to trust. The session does not need constant hard selling to hold attention.",
    "The better word for the style here is steady. The room stays engaged without turning frantic, which matters more than a flashy first impression.",
    "Even first-time visitors usually get a clear sense of the room quickly because the atmosphere is settled and the interaction feels real.",
];

$specialties = [
    "Themes linked to {tags} show up more as atmosphere than as a rigid script, which gives each session some identity without making it feel boxed in.",
    "{tags} cues help set expectations, but they do not lock the room into one pattern. There is still space for detours, jokes, and smaller chat moments.",
    "The {tags} side of the show works because it is woven into the room naturally instead of announced like a checklist.",
    "Anyone drawn to {tags} usually gets the tone they came for, but the room still leaves enough space for spontaneous interaction.",
    "Rather than forcing every session into the same shape, the {tags} angle tends to guide the mood and let the room do the rest.",
];

$platform_angles = [
    "{live_brand} suits this style because the room tools stay out of the way. Chat remains readable, the stream stays stable, and the session can breathe.",
    "The platform helps with the boring but important parts: dependable notifications, clear room controls, and HD video that does not wobble mid-session.",
    "A calmer room works better on {live_brand} because moderation keeps the chat usable instead of letting it turn into a wall of noise.",
    "Private-room options on {live_brand} make sense for anyone wanting a quieter, more focused version of the same live-chat feel.",
    "{live_brand} handles the practical side well enough that the performer can focus on interaction instead of fighting the platform.",
    "What viewers usually notice first on {live_brand} is how easy the room is to follow. Video is clean, messages stay readable, and the pace holds together.",
    "The platform is a good fit for a performer who benefits from clear reactions and steady back-and-forth rather than constant chaos.",
    "On {live_brand}, the live experience feels closer because the small details come through: tone of voice, timing, and the little reactions that keep a room human.",
];

$style_blocks = [
    "Transitions feel gradual rather than mechanical, so people joining in the middle can still work out where the room is heading.",
    "The best part of the pacing is that it leaves room for chat to matter. Messages can change the direction a little instead of being treated like background noise.",
    "Lighting, framing, and audio stay consistent without becoming a production in themselves. That kind of steadiness pays off over a full session.",
    "The room usually has a conversational spine to it. Questions are answered, reactions happen in real time, and nothing feels entirely preloaded.",
    "Repeat viewers often say the same thing: the room has a rhythm you can settle into, which makes the whole experience easier to enjoy.",
    "A live room feels better when small responses happen at the right time. That timing is a noticeable strength here.",
];

$schedule = [
    "Most sessions tend to cluster in evening hours, with the occasional extra stream appearing when the room has been especially active.",
    "The schedule is flexible rather than rigid, but regular viewers usually get enough warning to catch the room without constant refreshing.",
    "Notification alerts do a lot of the work here. Following the profile is usually more reliable than checking back by hand.",
    "Timing shifts a little through the week, which makes alerts more useful than trying to guess from memory.",
];

$ctas = [
    "Following the profile on {live_brand} is the easiest way to catch the next room without chasing third-party listings.",
    "Keeping the {live_brand} profile handy makes the next session easier to catch, especially when start times move around a bit.",
    "If you want the least friction, use the {live_brand} link on this page and turn on alerts there.",
    "The simplest routine is to follow on {live_brand} and let the notification system do the timing work for you.",
];

$bios = [];
for ($i = 0; $i < 60; $i++) {
    shuffle($bio_openers);
    shuffle($specialties);
    shuffle($platform_angles);
    shuffle($style_blocks);
    shuffle($schedule);
    shuffle($ctas);

    $sentences = array_merge(
        array_slice($bio_openers, 0, 2),
        array_slice($specialties, 0, 1),
        array_slice($platform_angles, 0, 2),
        array_slice($style_blocks, 0, 2),
        array_slice($schedule, 0, 1),
        array_slice($ctas, 0, 1)
    );

    $bios[] = implode(' ', $sentences);
}

return $bios;
