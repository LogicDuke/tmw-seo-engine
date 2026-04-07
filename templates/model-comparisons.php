<?php
/**
 * Platform comparison template pool.
 *
 * Pure prose only. Do not add extra headings here because the renderer already
 * prints the section heading and stripping inline headings creates broken text.
 */

$points = [
    "{live_brand} is often the easiest place to start because the room stays readable and the stream quality holds together when chat gets busy.",
    "The platform suits people who want direct interaction without losing the calmer room feel that makes live sessions easier to enjoy.",
    "A lot of the appeal comes from reliability. When video, moderation, and notifications all behave, the performer has more room to actually interact.",
    "Private options on {live_brand} are useful for anyone wanting a more focused version of the same live-chat style.",
    "Compared with generic feed browsing, a settled room on {live_brand} usually feels more personal and less disposable.",
    "The better reason to choose {live_brand} is not hype. It is the combination of clean video, workable chat, and a room that feels organised.",
    "{live_brand} makes it easier to follow the rhythm of the session, which matters when viewers care about more than a quick drop-in.",
    "For people comparing platforms, {live_brand} tends to stand out on the practical side: stable playback, clear controls, and a room that stays usable on mobile.",
    "The platform gives the room enough structure that the conversation can move naturally instead of getting buried under clutter.",
    "Anyone choosing between multiple profiles will usually notice that {live_brand} keeps the session feel closer to a real exchange than a passive stream.",
];

$templates = [];
for ($i = 0; $i < 40; $i++) {
    shuffle($points);
    $templates[] = implode(' ', array_slice($points, 0, 4));
}

return $templates;
