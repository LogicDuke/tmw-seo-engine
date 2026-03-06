<?php
$points = [
    "{live_brand} streams stay focused on the performer, keeping the room calm and personal.",
    "{live_brand} lets fans talk to {name} in real time with HD clarity.",
    "Private shows keep attention on genuine interaction and a relaxed pace.",
    "{live_brand} quality controls keep lighting and audio stable so every smile and whisper is crisp.",
    "Fans say {live_brand}'s private shows feel more personal and attentive.",
    "{live_brand} replays and highlight reels let viewers revisit favorite moments without waiting for uploads.",
    "A {live_brand} chat with {name} feels spontaneous and human.",
    "{live_brand} moderators keep the vibe respectful, giving {name} room to guide the conversation.",
    "{live_brand}'s strength is immediate interaction with {name} in HD quality.",
    "{live_brand} viewers chat with {name} live and request specific moments.",
    "{live_brand} creates fresh experiences every session with {name}.",
];

$templates = [];
for ($i = 0; $i < 40; $i++) {
    shuffle($points);
    $body = implode(' ', array_slice($points, 0, 4));
    $templates[] = "<h2>Why Watch {name} on {live_brand}</h2>" . $body;
}
return $templates;
