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
        'imlive' => [
            'name' => 'ImLive',
            'slug' => 'imlive',
            'profile_url_pattern' => 'https://www.imlive.com/live-sex-cams/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 80,
        ],
        'streamate' => [
            'name' => 'Streamate',
            'slug' => 'streamate',
            'profile_url_pattern' => 'https://www.streamate.com/cam/{username}/',
            'affiliate_link_pattern' => '',
            'priority' => 90,
        ],
        'flirt4free' => [
            'name' => 'Flirt4Free',
            'slug' => 'flirt4free',
            'profile_url_pattern' => 'https://www.flirt4free.com/live-sex-cam-model/{username}/',
            'affiliate_link_pattern' => '',
            'priority' => 100,
        ],
        'jerkmate' => [
            'name' => 'Jerkmate',
            'slug' => 'jerkmate',
            'profile_url_pattern' => 'https://jerkmate.com/cam/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 110,
        ],
        'camscom' => [
            'name' => 'Cams.com',
            'slug' => 'camscom',
            'profile_url_pattern' => 'https://www.cams.com/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 120,
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
