<?php
/**
 * Model bio template pool.
 *
 * Keep this section factual and restrained. If detail is limited, shorter copy
 * is preferred over speculative personality writing.
 */

$bio_lines = [
    "Confirmed profile activity for {name} is currently tracked on {live_brand}, with direct access links provided in the watch section.",
    "This section stays focused on verifiable details: active platform presence, profile access, and update reliability.",
    "Where reliable performer-specific details are limited, the copy remains concise so the page stays accurate.",
    "Tags linked to {tags} are used as discovery cues only and should be confirmed in the live room before assumptions are made.",
    "For routine check-ins, following the official {live_brand} profile is usually the fastest way to catch status changes.",
    "When profile names vary by platform, this page calls that out to reduce confusion and wrong-room clicks.",
    "The page avoids speculative style claims and keeps to signals that can be verified from active profiles.",
    "If more confirmed data appears, this section expands; until then, it stays practical and evidence-first.",
];

$bios = [];
for ($i = 0; $i < 60; $i++) {
    shuffle($bio_lines);
    $bios[] = implode(' ', array_slice($bio_lines, 0, 3));
}

return $bios;
