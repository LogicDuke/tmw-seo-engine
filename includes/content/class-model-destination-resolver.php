<?php
namespace TMWSEO\Engine\Content;

use TMWSEO\Engine\Model\VerifiedLinks;
use TMWSEO\Engine\Model\VerifiedLinksFamilies;
use TMWSEO\Engine\Platform\AffiliateLinkBuilder;
use TMWSEO\Engine\Platform\PlatformProfiles;
use TMWSEO\Engine\Platform\PlatformRegistry;

if (!defined('ABSPATH')) { exit; }

/**
 * Resolve model destinations from operator-curated sources.
 */
class ModelDestinationResolver {

    private const KNOWN_PLATFORM_SLUGS = [
        'livejasmin', 'stripchat', 'chaturbate',
        'myfreecams', 'camsoda', 'bonga', 'cam4', 'streamate',
    ];

    /** @return array<string,mixed> */
    public static function resolve(int $post_id, ?array $platform_links = null, ?array $verified_links = null, ?array $editor_seed = null): array {
        if ($platform_links === null) {
            PlatformProfiles::sync_to_table($post_id);
            $platform_links = PlatformProfiles::get_links($post_id);
        }
        $platform_links = is_array($platform_links) ? $platform_links : [];

        if ($verified_links === null && class_exists(VerifiedLinks::class)) {
            $verified_links = VerifiedLinks::get_links($post_id);
        }
        $verified_links = is_array($verified_links) ? $verified_links : [];

        if ($editor_seed === null) {
            $editor_seed = TemplateContent::get_editor_seed_data($post_id);
        }

        $platform_fallback = self::build_platform_watch_fallback_destinations($post_id, $platform_links);

        $families = [
            'social_destinations' => [],
            'link_hub_destinations' => [],
            'personal_site_destinations' => [],
            'fan_platform_destinations' => [],
            'tube_destinations' => [],
            'all_verified_destinations' => [],
        ];

        foreach ($verified_links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $url = trim((string)($link['url'] ?? ''));
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }
            $type = sanitize_key((string)($link['type'] ?? 'other'));
            $family = VerifiedLinksFamilies::family_for($type);
            $label = trim((string)($link['label'] ?? ''));
            if ($label === '') {
                $labels = VerifiedLinksFamilies::type_labels();
                $label = (string)($labels[$type] ?? ucfirst(str_replace('_', ' ', $type)));
            }
            $activity = self::normalize_activity_state($link);
            $is_active_legacy = !empty($link['is_active']);
            $is_cta_eligible = $family === VerifiedLinksFamilies::FAMILY_CAM
                && in_array($activity['activity_level'], ['active', 'very_active'], true);
            $entry = [
                'type' => $type,
                'family' => $family,
                'label' => $label,
                'url' => $url,
                'routed_url' => class_exists(VerifiedLinks::class) ? (string) VerifiedLinks::get_routed_url($link) : $url,
                'platform_key' => $type,
                'is_primary' => !empty($link['is_primary']),
                'is_active_legacy' => $is_active_legacy,
                'is_cta_eligible' => $is_cta_eligible,
                'is_verified_curated' => true,
                'source' => 'verified_links',
                'activity_level' => $activity['activity_level'],
                'activity_note' => $activity['activity_note'],
                'activity_checked_at' => $activity['activity_checked_at'],
                'activity_evidence_url' => $activity['activity_evidence_url'],
            ];
            $families['all_verified_destinations'][] = $entry;

            if ($family === VerifiedLinksFamilies::FAMILY_LINK_HUB) {
                $families['link_hub_destinations'][] = $entry;
            }
            if ($family === VerifiedLinksFamilies::FAMILY_SOCIAL) {
                $families['social_destinations'][] = $entry;
            }
            if ($family === VerifiedLinksFamilies::FAMILY_PERSONAL) {
                $families['personal_site_destinations'][] = $entry;
            }
            if ($family === VerifiedLinksFamilies::FAMILY_FANSITE) {
                $families['fan_platform_destinations'][] = $entry;
            }
            if ($family === VerifiedLinksFamilies::FAMILY_TUBE) {
                $families['tube_destinations'][] = $entry;
            }
        }

        $watch_cta = self::build_watch_cta_destinations($post_id, $platform_fallback, (array) $families['all_verified_destinations']);
        $active_labels = array_values(array_unique(array_filter(array_map(static fn(array $r): string => trim((string)($r['label'] ?? '')), $watch_cta), 'strlen')));

        $verified_active = 0;
        $verified_inactive = 0;
        foreach ($families['all_verified_destinations'] as $entry) {
            $level = (string) ($entry['activity_level'] ?? 'unknown');
            if (in_array($level, ['active', 'very_active'], true)) {
                $verified_active++;
            } else {
                $verified_inactive++;
            }
        }

        $summary = [
            'watch_cta_count' => count($watch_cta),
            'verified_count' => count($families['all_verified_destinations']),
            'verified_active_count' => $verified_active,
            'verified_inactive_or_unknown_count' => $verified_inactive,
            'verified_watch_eligible_count' => count(array_filter($families['all_verified_destinations'], static fn(array $entry): bool => !empty($entry['is_cta_eligible']))),
            'social_count' => count($families['social_destinations']),
            'link_hub_count' => count($families['link_hub_destinations']),
            'personal_site_count' => count($families['personal_site_destinations']),
            'fan_platform_count' => count($families['fan_platform_destinations']),
            'tube_count' => count($families['tube_destinations']),
            'seed_summary' => trim((string)($editor_seed['summary'] ?? '')),
            'seed_platform_notes' => array_values(array_slice(array_filter(array_map('strval', (array)($editor_seed['platform_notes'] ?? [])), 'strlen'), 0, 6)),
            'seed_confirmed_facts' => array_values(array_slice(array_filter(array_map('strval', (array)($editor_seed['confirmed_facts'] ?? [])), 'strlen'), 0, 6)),
        ];

        return array_merge([
            'watch_cta_destinations' => $watch_cta,
            'active_platform_labels' => $active_labels,
            'source_of_truth_summary' => $summary,
        ], $families);
    }

    /** @return array<int,array<string,mixed>> */
    private static function build_watch_cta_destinations(int $post_id, array $platform_fallback, array $all_verified_destinations): array {
        $resolved = [];
        $blocked_platforms = [];
        $primary_platform = '';

        foreach ($all_verified_destinations as $entry) {
            if (!is_array($entry)) { continue; }
            $platform = sanitize_key((string)($entry['platform_key'] ?? $entry['type'] ?? ''));
            $family = (string)($entry['family'] ?? '');
            if ($platform === '' || $family !== VerifiedLinksFamilies::FAMILY_CAM) {
                continue;
            }

            if (empty($entry['is_cta_eligible'])) {
                $blocked_platforms[$platform] = true;
                continue;
            }

            $username = trim((string) get_post_meta($post_id, '_tmwseo_platform_username_' . $platform, true));
            if ($username === '') {
                $username = PlatformProfiles::extract_username_from_profile_url($platform, (string)($entry['url'] ?? ''));
            }

            $fallback_go = '';
            foreach ($platform_fallback as $row) {
                if (sanitize_key((string)($row['platform'] ?? '')) === $platform) {
                    $fallback_go = trim((string)($row['go_url'] ?? ''));
                    if ($username === '') {
                        $username = trim((string)($row['username'] ?? ''));
                    }
                    break;
                }
            }

            $go = $fallback_go;
            if ($go === '' && $username !== '') {
                $go = AffiliateLinkBuilder::go_url($platform, $username);
            }
            if ($go === '') {
                $go = trim((string)($entry['routed_url'] ?? $entry['url'] ?? ''));
            }
            if ($go === '') {
                continue;
            }

            $label = (string)(PlatformRegistry::get($platform)['name'] ?? trim((string)($entry['label'] ?? ucfirst($platform))));
            $is_primary = !empty($entry['is_primary']);
            if ($primary_platform === '' && $is_primary) {
                $primary_platform = $platform;
            }

            $resolved[$platform] = [
                'platform' => $platform,
                'label' => $label,
                'go_url' => $go,
                'is_primary' => $is_primary,
                'username' => $username,
                'source' => 'verified_links',
                'verified_url' => (string)($entry['url'] ?? ''),
            ];
        }

        foreach ($platform_fallback as $row) {
            $platform = sanitize_key((string)($row['platform'] ?? ''));
            if ($platform === '' || isset($resolved[$platform]) || isset($blocked_platforms[$platform])) {
                continue;
            }
            $resolved[$platform] = $row;
        }

        $out = array_values($resolved);
        if (!empty($out) && $primary_platform === '') {
            $out[0]['is_primary'] = true;
        } elseif ($primary_platform !== '') {
            foreach ($out as $idx => $row) {
                $out[$idx]['is_primary'] = (sanitize_key((string)($row['platform'] ?? '')) === $primary_platform);
            }
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function build_platform_watch_fallback_destinations(int $post_id, array $platform_links): array {
        $out = [];
        $seen = [];

        foreach ($platform_links as $row) {
            $platform = sanitize_key((string)($row['platform'] ?? ''));
            if ($platform === '' || isset($seen[$platform])) {
                continue;
            }
            $group = (string)(PlatformRegistry::get($platform)['group'] ?? '');
            if ($group !== 'cam') {
                continue;
            }
            $username = trim((string)get_post_meta($post_id, '_tmwseo_platform_username_' . $platform, true));
            if ($username === '') {
                $username = trim((string)($row['username'] ?? ''));
            }
            if ($username === '') {
                continue;
            }
            $go = trim((string)($row['go_url'] ?? ''));
            if ($go === '') {
                $go = AffiliateLinkBuilder::go_url($platform, $username);
            }
            if ($go === '') {
                $go = trim((string)($row['url'] ?? ''));
            }
            if ($go === '') {
                continue;
            }
            $label = (string)(PlatformRegistry::get($platform)['name'] ?? ucfirst($platform));
            $out[] = [
                'platform' => $platform,
                'label' => $label,
                'go_url' => $go,
                'is_primary' => !empty($row['is_primary']),
                'username' => $username,
                'source' => 'platform_profiles',
            ];
            $seen[$platform] = true;
        }

        if (empty($out)) {
            $meta_first = true;
            foreach (self::KNOWN_PLATFORM_SLUGS as $platform) {
                if (isset($seen[$platform])) { continue; }
                $meta_username = trim((string) get_post_meta($post_id, '_tmwseo_platform_username_' . $platform, true));
                if ($meta_username === '') { continue; }
                $group = (string)(PlatformRegistry::get($platform)['group'] ?? '');
                if ($group !== 'cam') { continue; }
                $go = AffiliateLinkBuilder::go_url($platform, $meta_username);
                if ($go === '') { continue; }
                $label = (string)(PlatformRegistry::get($platform)['name'] ?? ucfirst($platform));
                $out[] = [
                    'platform' => $platform,
                    'label' => $label,
                    'go_url' => $go,
                    'is_primary' => $meta_first,
                    'username' => $meta_username,
                    'source' => 'platform_profiles_meta',
                ];
                $meta_first = false;
                $seen[$platform] = true;
            }
        }

        return $out;
    }

    /** @return array<string,string> */
    private static function normalize_activity_state(array $link): array {
        $level = strtolower(trim((string)($link['activity_level'] ?? '')));
        if (!in_array($level, ['unknown', 'inactive', 'active', 'very_active'], true)) {
            $level = !empty($link['is_active']) ? 'active' : 'inactive';
        }
        return [
            'activity_level' => $level,
            'activity_note' => trim((string)($link['activity_note'] ?? '')),
            'activity_checked_at' => trim((string)($link['activity_checked_at'] ?? '')),
            'activity_evidence_url' => trim((string)($link['activity_evidence_url'] ?? '')),
        ];
    }
}
