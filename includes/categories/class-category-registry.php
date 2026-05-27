<?php
namespace TMWSEO\Engine\Categories;

if (!defined('ABSPATH')) { exit; }

class CategoryRegistry {
    private const REQUIRED_FIELDS = [
        'key',
        'label',
        'family',
        'risk_level',
        'allowed_surfaces',
        'taxonomy',
        'generator_eligible',
        'requires_evidence',
        'notes',
    ];

    /** @var array<int, array<string, mixed>>|null */
    private static ?array $validated_items = null;

    /** @return array<int, array<string, mixed>> */
    public static function all(): array {
        return self::validated_items();
    }

    /** @return array<string, mixed>|null */
    public static function get(string $key): ?array {
        foreach (self::validated_items() as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        return null;
    }

    public static function exists(string $key): bool {
        return self::get($key) !== null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function public_safe(): array {
        return array_values(array_filter(self::validated_items(), static function (array $item): bool {
            return $item['generator_eligible'] === true
                && $item['risk_level'] === 'low'
                && $item['requires_evidence'] === false;
        }));
    }

    /** @return array<int, array<string, mixed>> */
    public static function review_required(): array {
        return array_values(array_filter(self::validated_items(), static function (array $item): bool {
            return $item['requires_evidence'] === true || in_array($item['risk_level'], ['high', 'blocked'], true);
        }));
    }

    /** @return array<int, array<string, mixed>> */
    public static function excluded_from_generator(): array {
        return array_values(array_filter(self::validated_items(), static function (array $item): bool {
            return $item['generator_eligible'] === false;
        }));
    }

    /** @return array<int, array<string, mixed>> */
    public static function for_surface(string $surface): array {
        $surface = trim(strtolower($surface));
        if ($surface === '') {
            return [];
        }

        return array_values(array_filter(self::validated_items(), static function (array $item) use ($surface): bool {
            return in_array($surface, $item['allowed_surfaces'], true);
        }));
    }

    /** @return array<int, array<string, mixed>> */
    private static function validated_items(): array {
        if (self::$validated_items !== null) {
            return self::$validated_items;
        }

        $items = self::raw_items();
        $seen_keys = [];
        $validated = [];

        foreach ($items as $item) {
            foreach (self::REQUIRED_FIELDS as $field) {
                if (!array_key_exists($field, $item)) {
                    self::log_once('[TMW-CAT] Missing required field in category registry item', [
                        'field' => $field,
                        'item'  => $item,
                    ]);
                    continue 2;
                }
            }

            $key = (string) $item['key'];
            if ($key === '' || isset($seen_keys[$key])) {
                self::log_once('[TMW-CAT] Duplicate or empty category registry key skipped', ['key' => $key]);
                continue;
            }

            $seen_keys[$key] = true;
            $validated[] = $item;
        }

        self::$validated_items = $validated;
        return self::$validated_items;
    }

    /** @return array<int, array<string, mixed>> */
    private static function raw_items(): array {
        return [
            ['key' => 'livejasmin','label' => 'LiveJasmin','family' => 'platform','risk_level' => 'low','allowed_surfaces' => ['model_page','category_page','admin_filter'],'taxonomy' => 'post_tag','generator_eligible' => true,'requires_evidence' => false,'notes' => 'Platform association only; no sensitive inference.'],
            ['key' => 'camsoda','label' => 'CamSoda','family' => 'platform','risk_level' => 'low','allowed_surfaces' => ['model_page','category_page','admin_filter'],'taxonomy' => 'post_tag','generator_eligible' => true,'requires_evidence' => false,'notes' => 'Platform association only; no sensitive inference.'],
            ['key' => 'chaturbate','label' => 'Chaturbate','family' => 'platform','risk_level' => 'low','allowed_surfaces' => ['model_page','category_page','admin_filter'],'taxonomy' => 'post_tag','generator_eligible' => true,'requires_evidence' => false,'notes' => 'Platform association only; no sensitive inference.'],
            ['key' => 'stripchat','label' => 'Stripchat','family' => 'platform','risk_level' => 'low','allowed_surfaces' => ['model_page','category_page','admin_filter'],'taxonomy' => 'post_tag','generator_eligible' => true,'requires_evidence' => false,'notes' => 'Platform association only; no sensitive inference.'],
            ['key' => 'cam4','label' => 'Cam4','family' => 'platform','risk_level' => 'low','allowed_surfaces' => ['model_page','category_page','admin_filter'],'taxonomy' => 'post_tag','generator_eligible' => true,'requires_evidence' => false,'notes' => 'Platform association only; no sensitive inference.'],

            ['key' => 'live_chat','label' => 'Live Chat','family' => 'interaction','risk_level' => 'low','allowed_surfaces' => ['model_page','category_page'],'taxonomy' => 'post_tag','generator_eligible' => true,'requires_evidence' => false,'notes' => 'General interaction capability descriptor.'],
            ['key' => 'private_chat_available','label' => 'Private Chat Available','family' => 'interaction','risk_level' => 'low','allowed_surfaces' => ['model_page','category_page'],'taxonomy' => 'post_tag','generator_eligible' => true,'requires_evidence' => false,'notes' => 'Only for platform-exposed feature states.'],
            ['key' => 'fan_club_updates','label' => 'Fan Club Updates','family' => 'interaction','risk_level' => 'low','allowed_surfaces' => ['model_page','category_page'],'taxonomy' => 'post_tag','generator_eligible' => true,'requires_evidence' => false,'notes' => 'Newsletter / fan-club content cadence signal.'],

            ['key' => 'video_highlights','label' => 'Video Highlights','family' => 'content_format','risk_level' => 'low','allowed_surfaces' => ['model_page','category_page'],'taxonomy' => 'post_tag','generator_eligible' => true,'requires_evidence' => false,'notes' => 'Content-format metadata only.'],
            ['key' => 'photo_galleries','label' => 'Photo Galleries','family' => 'content_format','risk_level' => 'low','allowed_surfaces' => ['model_page','category_page'],'taxonomy' => 'post_tag','generator_eligible' => true,'requires_evidence' => false,'notes' => 'Content-format metadata only.'],
            ['key' => 'profile_updates','label' => 'Profile Updates','family' => 'content_format','risk_level' => 'low','allowed_surfaces' => ['model_page','category_page'],'taxonomy' => 'post_tag','generator_eligible' => true,'requires_evidence' => false,'notes' => 'Content-format metadata only.'],

            ['key' => 'glamour_style','label' => 'Glamour Style','family' => 'style_safe','risk_level' => 'low','allowed_surfaces' => ['model_page','category_page'],'taxonomy' => 'post_tag','generator_eligible' => true,'requires_evidence' => false,'notes' => 'Non-sensitive style descriptor.'],
            ['key' => 'fashion_forward','label' => 'Fashion Forward','family' => 'style_safe','risk_level' => 'low','allowed_surfaces' => ['model_page','category_page'],'taxonomy' => 'post_tag','generator_eligible' => true,'requires_evidence' => false,'notes' => 'Non-sensitive style descriptor.'],
            ['key' => 'conversational','label' => 'Conversational','family' => 'style_safe','risk_level' => 'low','allowed_surfaces' => ['model_page','category_page'],'taxonomy' => 'post_tag','generator_eligible' => true,'requires_evidence' => false,'notes' => 'Tone descriptor only.'],

            ['key' => 'verified_profile','label' => 'Verified Profile','family' => 'internal_status','risk_level' => 'low','allowed_surfaces' => ['admin_filter','internal_review'],'taxonomy' => 'post_tag','generator_eligible' => true,'requires_evidence' => false,'notes' => 'Internal trust workflow marker.'],
            ['key' => 'active_platform_profile','label' => 'Active Platform Profile','family' => 'internal_status','risk_level' => 'low','allowed_surfaces' => ['admin_filter','internal_review'],'taxonomy' => 'post_tag','generator_eligible' => true,'requires_evidence' => false,'notes' => 'Internal freshness workflow marker.'],

            ['key' => 'ethnicity_inferred','label' => 'Ethnicity Inferred','family' => 'internal_status','risk_level' => 'blocked','allowed_surfaces' => ['internal_review'],'taxonomy' => 'post_tag','generator_eligible' => false,'requires_evidence' => true,'notes' => 'Blocked: inferred sensitive attribute.'],
            ['key' => 'location_inferred','label' => 'Location Inferred','family' => 'internal_status','risk_level' => 'high','allowed_surfaces' => ['internal_review'],'taxonomy' => 'post_tag','generator_eligible' => false,'requires_evidence' => true,'notes' => 'High risk: requires explicit verified evidence.'],
            ['key' => 'age_adjacent','label' => 'Age Adjacent','family' => 'internal_status','risk_level' => 'blocked','allowed_surfaces' => ['internal_review'],'taxonomy' => 'post_tag','generator_eligible' => false,'requires_evidence' => true,'notes' => 'Blocked: age-adjacent categorization is disallowed.'],
            ['key' => 'explicit_tag','label' => 'Explicit Tag','family' => 'internal_status','risk_level' => 'high','allowed_surfaces' => ['internal_review'],'taxonomy' => 'post_tag','generator_eligible' => false,'requires_evidence' => true,'notes' => 'High risk: excluded from generator usage.'],
            ['key' => 'leak_or_piracy','label' => 'Leak or Piracy','family' => 'internal_status','risk_level' => 'blocked','allowed_surfaces' => ['internal_review'],'taxonomy' => 'post_tag','generator_eligible' => false,'requires_evidence' => true,'notes' => 'Blocked: content piracy indicators are disallowed.'],
            ['key' => 'model_name_only','label' => 'Model Name Only','family' => 'internal_status','risk_level' => 'high','allowed_surfaces' => ['internal_review'],'taxonomy' => 'post_tag','generator_eligible' => false,'requires_evidence' => true,'notes' => 'High risk: weak signal, excluded from generation.'],
        ];
    }

    private static function log_once(string $message, array $context = []): void {
        static $logged = [];
        $fingerprint = md5($message . wp_json_encode($context));
        if (isset($logged[$fingerprint])) {
            return;
        }
        $logged[$fingerprint] = true;

        if (class_exists('TMWSEO\\Engine\\Logs')) {
            \TMWSEO\Engine\Logs::warning('categories', $message, $context);
            return;
        }

        error_log($message . ' ' . wp_json_encode($context));
    }
}
