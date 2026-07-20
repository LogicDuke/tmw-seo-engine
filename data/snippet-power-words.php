<?php
/**
 * Curated power words for SEO snippet titles.
 *
 * @package TMW_SEO
 */

if (!defined('ABSPATH')) {
    exit;
}

return [
    // POSITIVE SENTIMENT WORDS (30 selected)
    'sentiments' => [
        // Sex Appeal (10)
        'Stunning',
        'Seductive',
        'Captivating',
        'Irresistible',
        'Mesmerizing',
        'Sultry',
        'Alluring',
        'Tantalizing',
        'Provocative',
        'Sensual',

        // Exclusivity (6)
        'Exclusive',
        'VIP',
        'Premium',
        'Private',
        'Insider',
        'Elite',

        // Curiosity (6)
        'Revealed',
        'Secrets',
        'Uncovered',
        'Behind-the-scenes',
        'Must-see',
        'Discover',

        // Quality/Trust (5)
        'Verified',
        'Authentic',
        'Top-rated',
        'Fan-favorite',
        'Trusted',

        // Energy/Vibe (6)
        'Dynamic',
        'Confident',
        'Playful',
        'Charming',
        'Engaging',
        'Vibrant',
    ],

    // POWER WORDS for content descriptors
    'power_words' => [
        'Highlights',
        'Spotlight',
        'Profile',
        'Guide',
        'Secrets',
        'Revealed',
        'Uncovered',
        'Must-See',
        'Insider',
        'Behind-the-Scenes',
        'Deep-Dive',
        'VIP Access',
        'Fan Favorites',
        'Best Moments',
        'Top Picks',
        'Exclusive Look',
        'Full Profile',
        'Complete Guide',
    ],

    // NUMBERS for titles
    'numbers' => [3, 5, 7, 10],

    // Rank Math validated (manual live sidebar testing): words confirmed to count as BOTH
    // positive/sentiment + power words for model-page auto title generation.
    'model_title_allowlist' => [
        'Best',
        'Amazing',
        'Proven',
        'Safe',
        'Secure',
        'Powerful',
        'Trustworthy',
        'Exclusive',
        'Popular',
        'Remarkable',
    ],

    // Rank Math validated (manual live sidebar testing): words currently confirmed ONLY
    // as power words. Keep for reserve/manual experiments, but not default auto titles.
    'model_title_reserve_power_only' => [
        'Secret',
        'Expert',
        'Official',
        'Latest',
        'New',
    ],

    // Hard blocklist for model-page title safety checks.
    'model_title_denylist' => [
        'Bloody',
        'Corpse',
        'Murder',
        'Bomb',
        'Nazi',
        'Jail',
        'Toxic',
        'Doom',
        'Deadly',
        'Hoax',
        'Scam',
        'Trap',
        'Victim',
        'Brutal',
    ],
];
