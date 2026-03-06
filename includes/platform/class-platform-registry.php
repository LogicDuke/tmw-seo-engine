<?php
namespace TMWSEO\Engine\Platform;

if (!defined('ABSPATH')) { exit; }

class PlatformRegistry {

    /**
     * Canonical platform metadata.
     *
     * Do not store affiliate IDs in code.
     * affiliate_link_pattern should contain placeholders only and can be empty.
     *
     * @var array<string, array{name:string, slug:string, profile_url_pattern:string, affiliate_link_pattern:string, priority:int}>
     */
    private static array $platforms = [
        'livejasmin' => [
            'name' => 'LiveJasmin',
            'slug' => 'livejasmin',
            'profile_url_pattern' => 'https://www.livejasmin.com/en/chat/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 10,
        ],
        'stripchat' => [
            'name' => 'Stripchat',
            'slug' => 'stripchat',
            'profile_url_pattern' => 'https://stripchat.com/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 20,
        ],
        'chaturbate' => [
            'name' => 'Chaturbate',
            'slug' => 'chaturbate',
            'profile_url_pattern' => 'https://chaturbate.com/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 30,
        ],
        'myfreecams' => [
            'name' => 'MyFreeCams',
            'slug' => 'myfreecams',
            'profile_url_pattern' => 'https://www.myfreecams.com/#{username}',
            'affiliate_link_pattern' => '',
            'priority' => 40,
        ],
        'camsoda' => [
            'name' => 'CamSoda',
            'slug' => 'camsoda',
            'profile_url_pattern' => 'https://www.camsoda.com/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 50,
        ],
        'bonga' => [
            'name' => 'BongaCams',
            'slug' => 'bonga',
            'profile_url_pattern' => 'https://bongacams.com/profile/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 60,
        ],
        'cam4' => [
            'name' => 'Cam4',
            'slug' => 'cam4',
            'profile_url_pattern' => 'https://www.cam4.com/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 70,
        ],
    ];

    public static function get_platforms(): array {
        return array_values(self::$platforms);
    }

    public static function get(string $slug): ?array {
        $slug = sanitize_key($slug);
        if (!isset(self::$platforms[$slug])) {
            return null;
        }

        return self::$platforms[$slug];
    }

    public static function get_slugs(): array {
        return array_keys(self::$platforms);
    }
}
