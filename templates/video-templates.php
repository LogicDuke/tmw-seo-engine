<?php
$intro_hooks = [
    "Fans searching '{name} live cam video' end up here because this {live_brand} recording shows real interaction.",
    "People typing '{name} live recording' discover how {live_brand} keeps the camera steady and the conversation flowing.",
    "Those looking for '{name} highlights' will find this recap proves why {live_brand} private sessions feel personal.",
    "This five-minute reel captures the intimacy of live chat, with {live_brand} audio that feels present.",
];

$video_details = [
    "Duration: five-minute highlight pulled from a recent {live_brand} session where {name} answered live requests.",
    "Quality: filmed in 1080p with stable lighting so every smile and hand gesture is crisp.",
    "Categories: {tags}, blended into a comfortable pace that avoids harsh cuts.",
    "Viewers hear the difference: quiet background, clear whispers, and smooth pacing.",
];

$engagement = [
    "The clip shows how she asks viewers for mood checks before changing songs, keeping the room comfortable.",
    "Live chat reactions guide her pacing, letting shy fans type questions that she answers in real time.",
    "She offers quick reminders to hydrate and stretch, matching the mindful tone of her {live_brand} room.",
    "Expect playful commentary and calm smiles rather than chaotic public chat; this recording highlights that care.",
];

$cta = [
    "Watch the full show by joining {live_brand} now and ask {name} for a fresh request.",
    "Open {live_brand} to experience her next stream live and see the real-time chat in action.",
    "Click through to {live_brand}, follow {name}, and get an alert when the next private room opens.",
];

$templates = [];
for ($i = 0; $i < 40; $i++) {
    shuffle($intro_hooks);
    shuffle($video_details);
    shuffle($engagement);
    shuffle($cta);
    $templates[] = implode(' ', array_merge(
        array_slice($intro_hooks, 0, 1),
        array_slice($video_details, 0, 3),
        array_slice($engagement, 0, 2),
        array_slice($cta, 0, 1)
    ));
}
return $templates;
