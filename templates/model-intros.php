<?php
/**
 * Single-platform intro template pool.
 *
 * Keep the language direct and human. Avoid stiff search-engine framing,
 * repeated "people typing" openers, and padded brochure copy.
 */

$search_hooks = [
    "{name} is easiest to follow on {live_brand} when you want the real room instead of copied listings or stale embeds.",
    "Anyone looking up {name} usually wants two things quickly: the right profile and a feel for how the room actually runs once it opens.",
    "Search results for {name} are often messy, so keeping the current {live_brand} room close by saves time.",
    "If {name} is already on your radar, {live_brand} is the place to check for the live room and the latest session alerts.",
    "Most visitors landing here for {name} are trying to skip the fake mirrors and get straight to the current {live_brand} page.",
    "People often end up here after searching for {name} and wanting a cleaner route to the real room.",
    "{name} tends to draw viewers who care about a room that feels active and responsive instead of running on autopilot.",
    "Most visitors are trying to answer a practical question, not chase hype: which room is current, and what kind of chat should they expect?",
];

$live_benefits = [
    "{live_brand} keeps the stream clear and the room easy to follow, which matters more over a full session than any single flashy moment.",
    "Private options, stable HD video, and a readable chat make the room feel usable rather than noisy.",
    "The platform does the basics well: quick room loading, dependable notifications, and moderation that keeps the tone manageable.",
    "Good live rooms are built on small practical wins. Clean audio, steady framing, and quick reactions matter more than overproduced sales language.",
    "The interactive tools on {live_brand} add to the session without crowding it. The room still feels like a conversation first.",
];

$style_notes = [
    "The pacing usually starts relaxed, picks up once the room settles in, and stays readable instead of jumping around without warning.",
    "Chat tends to shape the mood in small ways, so the session feels responsive rather than locked to a script.",
    "Music and lighting stay understated, which leaves the focus on reactions, timing, and the back-and-forth in the room.",
    "New arrivals can usually settle in quickly because the room has enough structure to feel intentional without turning rigid.",
    "The best moments are often the unscripted ones: a quick reply, a running joke, or a shift in pace because the room nudged it there.",
];

$cta_lines = [
    "Use the {live_brand} button below if you want the direct route into the room.",
    "Open the {live_brand} link below and you can check the profile before the next session starts.",
    "The easiest place to start is the {live_brand} link below, especially if you want alerts before a room opens.",
    "If the goal is simply to find the live room without guesswork, start with the {live_brand} link below.",
    "Choose the {live_brand} link below when you are ready to check the room directly.",
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
