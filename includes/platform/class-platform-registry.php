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
        'linktree' => [
            'name' => 'Linktree',
            'slug' => 'linktree',
            'profile_url_pattern' => 'https://linktr.ee/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 5,
        ],
        'allmylinks' => [
            'name' => 'AllMyLinks',
            'slug' => 'allmylinks',
            'profile_url_pattern' => 'https://allmylinks.com/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 6,
        ],
        'beacons' => [
            'name' => 'Beacons',
            'slug' => 'beacons',
            'profile_url_pattern' => 'https://beacons.ai/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 7,
        ],
        'solo_to' => [
            'name' => 'solo.to',
            'slug' => 'solo_to',
            'profile_url_pattern' => 'https://solo.to/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 8,
        ],
        'carrd' => [
            'name' => 'Carrd',
            'slug' => 'carrd',
            'profile_url_pattern' => 'https://{username}.carrd.co/',
            'affiliate_link_pattern' => '',
            'priority' => 9,
        ],
        'livejasmin' => [
            'name' => 'LiveJasmin',
            'slug' => 'livejasmin',
            'profile_url_pattern' => 'https://www.livejasmin.com/en/chat/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 10,
        ],
        'fansly' => [
            'name' => 'Fansly',
            'slug' => 'fansly',
            'profile_url_pattern' => 'https://fansly.com/{username}/posts',
            'affiliate_link_pattern' => '',
            'priority' => 15,
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
            'profile_url_pattern' => 'https://bongacams.com/{username}',
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
            'profile_url_pattern' => 'https://imlive.com/live-sex-chats/cam-girls/video-chats/{username}/',
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
            'profile_url_pattern' => 'https://www.flirt4free.com/?model={username}',
            'affiliate_link_pattern' => '',
            'priority' => 100,
        ],
        'jerkmate' => [
            'name' => 'Jerkmate',
            'slug' => 'jerkmate',
            'profile_url_pattern' => 'https://jerkmatelive.com/cam/{username}',
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
        'sinparty' => [
            'name' => 'SinParty',
            'slug' => 'sinparty',
            'profile_url_pattern' => 'https://sinparty.com/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 130,
        ],
        'xtease' => [
            'name' => 'XTease',
            'slug' => 'xtease',
            'profile_url_pattern' => 'https://xtease.com/cam/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 140,
        ],
        'olecams' => [
            'name' => 'OleCams',
            'slug' => 'olecams',
            'profile_url_pattern' => 'https://www.olecams.com/webcam/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 150,
        ],
        'camera_prive' => [
            'name' => 'Camera Prive',
            'slug' => 'camera_prive',
            'profile_url_pattern' => 'https://cameraprive.com/us/room/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 160,
        ],
        'camirada' => [
            'name' => 'Camirada',
            'slug' => 'camirada',
            'profile_url_pattern' => 'https://camirada.com/webcam/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 170,
        ],
        'delhi_sex_chat' => [
            'name' => 'Delhi Sex Chat',
            'slug' => 'delhi_sex_chat',
            'profile_url_pattern' => 'https://www.dscgirls.live/model/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 180,
        ],
        'livefreefun' => [
            'name' => 'LiveFreeFun',
            'slug' => 'livefreefun',
            'profile_url_pattern' => 'https://livefreefun.org/cam/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 190,
        ],
        'revealme' => [
            'name' => 'RevealMe',
            'slug' => 'revealme',
            'profile_url_pattern' => 'https://revealme.com/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 200,
        ],
        'royal_cams' => [
            'name' => 'Royal Cams',
            'slug' => 'royal_cams',
            'profile_url_pattern' => 'https://royalcamslive.com/cam/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 210,
        ],
        'sakuralive' => [
            'name' => 'SakuraLive',
            'slug' => 'sakuralive',
            'profile_url_pattern' => 'https://www.sakuralive.com/preview.shtml?{username}',
            'affiliate_link_pattern' => '',
            'priority' => 220,
        ],
        'slut_roulette' => [
            'name' => 'Slut Roulette',
            'slug' => 'slut_roulette',
            'profile_url_pattern' => 'https://slutroulette.com/cams/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 230,
        ],
        'sweepsex' => [
            'name' => 'Sweepsex',
            'slug' => 'sweepsex',
            'profile_url_pattern' => 'https://sweepsex.com/cam/{username}',
            'affiliate_link_pattern' => '',
            'priority' => 240,
        ],
        'xcams' => [
            'name' => 'Xcams',
            'slug' => 'xcams',
            'profile_url_pattern' => 'https://www.xcams.com/fr/chat/{username}/',
            'affiliate_link_pattern' => '',
            'priority' => 250,
        ],
        'xlovecam' => [
            'name' => 'XLoveCam',
            'slug' => 'xlovecam',
            'profile_url_pattern' => 'https://www.xlovecam.com/nl/chat/{username}/',
            'affiliate_link_pattern' => '',
            'priority' => 260,
        ],
    ];

    /**
     * Input parsing rules by platform slug.
     *
     * canonical_pattern remains the output builder source of truth.
     * input_hosts/input_aliases are used for resilient username extraction.
     *
     * @var array<string, array{
     *   input_hosts: string[],
     *   input_aliases?: string[],
     *   locale_host?: bool
     * }>
     */
    private static array $parser_rules = [
        'chaturbate' => [
            'input_hosts' => [ 'chaturbate.com', 'www.chaturbate.com' ],
        ],
        'camscom' => [
            'input_hosts' => [ 'cams.com', 'www.cams.com' ],
        ],
        'flirt4free' => [
            'input_hosts' => [ 'flirt4free.com', 'www.flirt4free.com' ],
        ],
        'livejasmin' => [
            'input_hosts' => [ 'livejasmin.com', 'www.livejasmin.com' ],
        ],
        'stripchat' => [
            'input_hosts' => [ 'stripchat.com', 'www.stripchat.com' ],
            'locale_host' => true,
        ],
        'fansly' => [
            'input_hosts' => [ 'fansly.com', 'www.fansly.com' ],
        ],
        'myfreecams' => [
            'input_hosts' => [ 'myfreecams.com', 'www.myfreecams.com' ],
        ],
        'carrd' => [
            'input_hosts' => [ 'carrd.co', 'www.carrd.co' ],
            'input_aliases' => [ '.carrd.co' ],
        ],
        'sakuralive' => [
            'input_hosts' => [ 'sakuralive.com', 'www.sakuralive.com' ],
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

    public static function get_parser_rule(string $slug): array {
        $slug = sanitize_key($slug);
        return self::$parser_rules[$slug] ?? [];
    }

    public static function find_slug_by_host(string $host): string {
        $normalized = strtolower(trim($host));
        if ($normalized === '') {
            return '';
        }

        $stripped = preg_replace('/^www\./', '', $normalized);
        foreach (self::$platforms as $slug => $platform) {
            $pattern = (string) ($platform['profile_url_pattern'] ?? '');
            $parts = wp_parse_url($pattern);
            $pattern_host = strtolower((string) ($parts['host'] ?? ''));
            $pattern_host = preg_replace('/^www\./', '', $pattern_host);
            if ($pattern_host !== '' && ($stripped === $pattern_host || str_ends_with($stripped, '.' . $pattern_host))) {
                return (string) $slug;
            }
        }

        return '';
    }
}
