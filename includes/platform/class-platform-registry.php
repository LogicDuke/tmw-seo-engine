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
            'name'                   => 'Linktree',
            'slug'                   => 'linktree',
            'profile_url_pattern'    => 'https://linktr.ee/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 5,
            'group'                  => 'linkhub',
            'affiliate_supported'    => false,
        ],
        'allmylinks' => [
            'name'                   => 'AllMyLinks',
            'slug'                   => 'allmylinks',
            'profile_url_pattern'    => 'https://allmylinks.com/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 6,
            'group'                  => 'linkhub',
            'affiliate_supported'    => false,
        ],
        'beacons' => [
            'name'                   => 'Beacons',
            'slug'                   => 'beacons',
            'profile_url_pattern'    => 'https://beacons.ai/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 7,
            'group'                  => 'linkhub',
            'affiliate_supported'    => false,
        ],
        'solo_to' => [
            'name'                   => 'solo.to',
            'slug'                   => 'solo_to',
            'profile_url_pattern'    => 'https://solo.to/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 8,
            'group'                  => 'linkhub',
            'affiliate_supported'    => false,
        ],
        'carrd' => [
            'name'                   => 'Carrd',
            'slug'                   => 'carrd',
            'profile_url_pattern'    => 'https://{username}.carrd.co/',
            'affiliate_link_pattern' => '',
            'priority'               => 9,
            'group'                  => 'linkhub',
            'affiliate_supported'    => false,
        ],
        'twitter' => [
            'name'                   => 'X (Twitter)',
            'slug'                   => 'twitter',
            'profile_url_pattern'    => 'https://x.com/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 12,
            'group'                  => 'social',
            'affiliate_supported'    => false,
        ],
        'livejasmin' => [
            'name'                   => 'LiveJasmin',
            'slug'                   => 'livejasmin',
            'profile_url_pattern'    => 'https://www.livejasmin.com/en/chat/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 10,
            'group'                  => 'cam',
            'affiliate_supported'    => true,
        ],
        'fansly' => [
            'name'                   => 'Fansly',
            'slug'                   => 'fansly',
            'profile_url_pattern'    => 'https://fansly.com/{username}/posts',
            'affiliate_link_pattern' => '',
            'priority'               => 15,
            'group'                  => 'fansite',
        ],
        'fancentro' => [
            'name'                   => 'FanCentro',
            'slug'                   => 'fancentro',
            'profile_url_pattern'    => 'https://fancentro.com/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 16,
            'group'                  => 'fansite',
        ],
        'stripchat' => [
            'name'                   => 'Stripchat',
            'slug'                   => 'stripchat',
            'profile_url_pattern'    => 'https://stripchat.com/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 20,
            'group'                  => 'cam',
            'affiliate_supported'    => true,
        ],
        'chaturbate' => [
            'name'                   => 'Chaturbate',
            'slug'                   => 'chaturbate',
            'profile_url_pattern'    => 'https://chaturbate.com/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 30,
            'group'                  => 'cam',
        ],
        'myfreecams' => [
            'name'                   => 'MyFreeCams',
            'slug'                   => 'myfreecams',
            'profile_url_pattern'    => 'https://www.myfreecams.com/#{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 40,
            'group'                  => 'cam',
        ],
        'camsoda' => [
            'name'                   => 'CamSoda',
            'slug'                   => 'camsoda',
            'profile_url_pattern'    => 'https://www.camsoda.com/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 50,
            'group'                  => 'cam',
        ],
        'bonga' => [
            'name'                   => 'BongaCams',
            'slug'                   => 'bonga',
            'profile_url_pattern'    => 'https://bongacams.com/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 60,
            'group'                  => 'cam',
        ],
        'cam4' => [
            'name'                   => 'Cam4',
            'slug'                   => 'cam4',
            'profile_url_pattern'    => 'https://www.cam4.com/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 70,
            'group'                  => 'cam',
        ],
        'imlive' => [
            'name'                   => 'ImLive',
            'slug'                   => 'imlive',
            'profile_url_pattern'    => 'https://imlive.com/live-sex-chats/cam-girls/video-chats/{username}/',
            'affiliate_link_pattern' => '',
            'priority'               => 80,
            'group'                  => 'cam',
        ],
        'streamate' => [
            'name'                   => 'Streamate',
            'slug'                   => 'streamate',
            'profile_url_pattern'    => 'https://www.streamate.com/cam/{username}/',
            'affiliate_link_pattern' => '',
            'priority'               => 90,
            'group'                  => 'cam',
        ],
        'flirt4free' => [
            'name'                   => 'Flirt4Free',
            'slug'                   => 'flirt4free',
            'profile_url_pattern'    => 'https://www.flirt4free.com/?model={username}',
            'affiliate_link_pattern' => '',
            'priority'               => 100,
            'group'                  => 'cam',
        ],
        'jerkmate' => [
            'name'                   => 'Jerkmate',
            'slug'                   => 'jerkmate',
            'profile_url_pattern'    => 'https://jerkmatelive.com/cam/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 110,
            'group'                  => 'cam',
        ],
        'camscom' => [
            'name'                   => 'Cams.com',
            'slug'                   => 'camscom',
            'profile_url_pattern'    => 'https://www.cams.com/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 120,
            'group'                  => 'cam',
        ],
        'sinparty' => [
            'name'                   => 'SinParty',
            'slug'                   => 'sinparty',
            'profile_url_pattern'    => 'https://sinparty.com/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 130,
            'group'                  => 'fansite',
        ],
        'xtease' => [
            'name'                   => 'XTease',
            'slug'                   => 'xtease',
            'profile_url_pattern'    => 'https://xtease.com/cam/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 140,
            'group'                  => 'cam',
        ],
        'olecams' => [
            'name'                   => 'OleCams',
            'slug'                   => 'olecams',
            'profile_url_pattern'    => 'https://www.olecams.com/webcam/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 150,
            'group'                  => 'cam',
        ],
        'camera_prive' => [
            'name'                   => 'Camera Prive',
            'slug'                   => 'camera_prive',
            'profile_url_pattern'    => 'https://cameraprive.com/us/room/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 160,
            'group'                  => 'cam',
        ],
        'camirada' => [
            'name'                   => 'Camirada',
            'slug'                   => 'camirada',
            'profile_url_pattern'    => 'https://camirada.com/webcam/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 170,
            'group'                  => 'cam',
        ],
        'delhi_sex_chat' => [
            'name'                   => 'Delhi Sex Chat',
            'slug'                   => 'delhi_sex_chat',
            'profile_url_pattern'    => 'https://www.dscgirls.live/model/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 180,
            'group'                  => 'cam',
        ],
        'livefreefun' => [
            'name'                   => 'LiveFreeFun',
            'slug'                   => 'livefreefun',
            'profile_url_pattern'    => 'https://livefreefun.org/cam/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 190,
            'group'                  => 'cam',
        ],
        'revealme' => [
            'name'                   => 'RevealMe',
            'slug'                   => 'revealme',
            'profile_url_pattern'    => 'https://revealme.com/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 200,
            'group'                  => 'fansite',
        ],
        'royal_cams' => [
            'name'                   => 'Royal Cams',
            'slug'                   => 'royal_cams',
            'profile_url_pattern'    => 'https://royalcamslive.com/cam/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 210,
            'group'                  => 'cam',
        ],
        'sakuralive' => [
            'name'                   => 'SakuraLive',
            'slug'                   => 'sakuralive',
            'profile_url_pattern'    => 'https://www.sakuralive.com/preview.shtml?{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 220,
            'group'                  => 'cam',
        ],
        'slut_roulette' => [
            'name'                   => 'Slut Roulette',
            'slug'                   => 'slut_roulette',
            'profile_url_pattern'    => 'https://slutroulette.com/cams/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 230,
            'group'                  => 'cam',
        ],
        'sweepsex' => [
            'name'                   => 'Sweepsex',
            'slug'                   => 'sweepsex',
            'profile_url_pattern'    => 'https://sweepsex.com/cam/{username}',
            'affiliate_link_pattern' => '',
            'priority'               => 240,
            'group'                  => 'cam',
        ],
        'xcams' => [
            'name'                   => 'Xcams',
            'slug'                   => 'xcams',
            'profile_url_pattern'    => 'https://www.xcams.com/fr/chat/{username}/',
            'affiliate_link_pattern' => '',
            'priority'               => 250,
            'group'                  => 'cam',
        ],
        'xlovecam' => [
            'name'                   => 'XLoveCam',
            'slug'                   => 'xlovecam',
            'profile_url_pattern'    => 'https://www.xlovecam.com/nl/chat/{username}/',
            'affiliate_link_pattern' => '',
            'priority'               => 260,
            'group'                  => 'cam',
        ],
    ];

    public static function get_platforms(): array {
        return array_map([__CLASS__, 'normalize_platform'], array_values(self::$platforms));
    }

    public static function get(string $slug): ?array {
        $slug = sanitize_key($slug);
        if (!isset(self::$platforms[$slug])) {
            return null;
        }

        return self::normalize_platform(self::$platforms[$slug]);
    }

    public static function get_slugs(): array {
        return array_keys(self::$platforms);
    }

    /**
     * Return the group key for a platform slug.
     * Falls back to 'other' for unregistered slugs.
     *
     * @param string $slug
     * @return string  'social'|'fansite'|'cam'|'linkhub'|'other'
     */
    public static function get_group( string $slug ): string {
        $slug = sanitize_key( $slug );
        return self::$platforms[ $slug ]['group'] ?? 'other';
    }

    /**
     * Ensure stable metadata keys are always available.
     *
     * @param array<string,mixed> $platform
     * @return array<string,mixed>
     */
    private static function normalize_platform(array $platform): array {
        if (!array_key_exists('affiliate_supported', $platform)) {
            $platform['affiliate_supported'] = false;
        }
        return $platform;
    }
}
