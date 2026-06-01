<?php
namespace TMWSEO\Engine\Categories;

if (!defined('ABSPATH')) { exit; }

class CategoryRegistry {
    public const FAMILY_SEO_PILLAR = 'seo_pillar';
    public const FAMILY_PLATFORM = 'platform';
    public const FAMILY_INTERACTION = 'interaction';
    public const FAMILY_CONTENT_FORMAT = 'content_format';
    public const FAMILY_STYLE_REVIEW = 'style_review';
    public const FAMILY_ETHNICITY_REVIEW = 'ethnicity_review';
    public const FAMILY_REGION_REVIEW = 'region_review';
    public const FAMILY_NATIONALITY_REVIEW = 'nationality_review';
    public const FAMILY_LANGUAGE_REVIEW = 'language_review';
    public const FAMILY_APPEARANCE_REVIEW = 'appearance_review';
    public const FAMILY_ADULT_INTENT_REVIEW = 'adult_intent_review';
    public const FAMILY_EXPLICIT_INTENT_REVIEW = 'explicit_intent_review';
    public const FAMILY_INTERNAL_STATUS = 'internal_status';
    public const FAMILY_BLOCKED = 'blocked';

    public const RISK_LOW = 'low';
    public const RISK_REVIEW_REQUIRED = 'review_required';
    public const RISK_HIGH = 'high';
    public const RISK_BLOCKED = 'blocked';

    private const REQUIRED_FIELDS = [
        'key', 'label', 'family', 'risk_level', 'allowed_surfaces', 'taxonomy',
        'generator_eligible', 'requires_evidence', 'notes',
    ];

    /** @var array<int, array<string, mixed>>|null */
    private static ?array $validated_items = null;

    /** @return array<int, array<string, mixed>> */
    public static function all(): array { return self::validated_items(); }

    /** @return array<string, mixed>|null */
    public static function get(string $key): ?array {
        foreach (self::validated_items() as $item) {
            if ($item['key'] === $key) { return $item; }
        }
        return null;
    }

    public static function exists(string $key): bool { return self::get($key) !== null; }

    /** @return array<int, array<string, mixed>> */
    public static function public_safe(): array {
        return array_values(array_filter(self::validated_items(), static function (array $item): bool {
            return $item['generator_eligible'] === true
                && $item['requires_evidence'] === false
                && $item['risk_level'] === self::RISK_LOW
                && !self::is_review_family($item)
                && !self::is_blocked($item);
        }));
    }

    /** @return array<int, array<string, mixed>> */
    public static function review_required(): array {
        return array_values(array_filter(self::validated_items(), static function (array $item): bool {
            return $item['requires_evidence'] === true
                || in_array($item['risk_level'], [self::RISK_REVIEW_REQUIRED, self::RISK_HIGH, self::RISK_BLOCKED], true)
                || self::is_review_family($item)
                || self::is_blocked($item);
        }));
    }

    /** @return array<int, array<string, mixed>> */
    public static function excluded_from_generator(): array {
        return array_values(array_filter(self::validated_items(), static function (array $item): bool {
            return $item['generator_eligible'] === false
                || in_array($item['risk_level'], [self::RISK_REVIEW_REQUIRED, self::RISK_HIGH, self::RISK_BLOCKED], true)
                || self::is_review_family($item)
                || self::is_blocked($item);
        }));
    }

    /** @return array<int, array<string, mixed>> */
    public static function public_category_candidates(): array {
        return array_values(array_filter(self::validated_items(), static function (array $item): bool {
            return $item['public_category_candidate'] === true
                && !self::is_blocked($item);
        }));
    }

    /** @return array<int, array<string, mixed>> */
    public static function generator_safe_candidates(): array {
        return array_values(array_filter(self::validated_items(), static function (array $item): bool {
            return $item['generator_eligible'] === true
                && $item['requires_evidence'] === false
                && $item['risk_level'] === self::RISK_LOW
                && !self::is_review_family($item)
                && !self::is_blocked($item);
        }));
    }

    /** @return array<int, array<string, mixed>> */
    public static function seo_research_candidates(): array {
        return array_values(array_filter(self::validated_items(), static function (array $item): bool {
            return $item['seo_research_candidate'] === true;
        }));
    }

    /** @return array<int, array<string, mixed>> */
    public static function for_surface(string $surface): array {
        $surface = trim(strtolower($surface));
        if ($surface === '') { return []; }
        return array_values(array_filter(self::validated_items(), static function (array $item) use ($surface): bool {
            return in_array($surface, $item['allowed_surfaces'], true);
        }));
    }

    private static function is_review_family(array $item): bool {
        $family = (string) ($item['family'] ?? '');
        return str_ends_with($family, '_review') || in_array($family, [
            self::FAMILY_ADULT_INTENT_REVIEW,
            self::FAMILY_EXPLICIT_INTENT_REVIEW,
            self::FAMILY_APPEARANCE_REVIEW,
            self::FAMILY_ETHNICITY_REVIEW,
            self::FAMILY_REGION_REVIEW,
            self::FAMILY_NATIONALITY_REVIEW,
            self::FAMILY_LANGUAGE_REVIEW,
        ], true);
    }

    private static function is_blocked(array $item): bool {
        return ($item['risk_level'] ?? null) === self::RISK_BLOCKED
            || ($item['family'] ?? null) === self::FAMILY_BLOCKED;
    }

    /** @return array<int, array<string, mixed>> */
    private static function validated_items(): array {
        if (self::$validated_items !== null) { return self::$validated_items; }

        $seen_keys = [];
        $validated = [];

        foreach (self::raw_items() as $item) {
            foreach (self::REQUIRED_FIELDS as $field) {
                if (!array_key_exists($field, $item)) {
                    self::log_once('[TMW-CAT] Missing required field in category registry item', ['field' => $field, 'item' => $item]);
                    continue 2;
                }
            }

            $item = array_merge([
                'auto_assign' => false,
                'model_fact_allowed' => false,
                'public_category_candidate' => false,
                'seo_research_candidate' => false,
            ], $item);

            $key = trim((string) $item['key']);
            if ($key === '' || isset($seen_keys[$key])) {
                self::log_once('[TMW-CAT] Duplicate or empty category registry key skipped', ['key' => $key]);
                continue;
            }
            $item['key'] = $key;

            if (!is_array($item['allowed_surfaces'])) {
                self::log_once('[TMW-CAT] allowed_surfaces must be an array; item skipped', ['key' => $key]);
                continue;
            }

            foreach (['generator_eligible', 'requires_evidence', 'auto_assign', 'model_fact_allowed', 'public_category_candidate', 'seo_research_candidate'] as $bool_field) {
                $item[$bool_field] = (bool) $item[$bool_field];
            }

            if ($item['auto_assign'] === true) {
                self::log_once('[TMW-CAT] auto_assign=true is not allowed; coercing to false', ['key' => $key]);
                $item['auto_assign'] = false;
            }

            if (self::is_review_family($item) && $item['model_fact_allowed'] === true) {
                self::log_once('[TMW-CAT] review-required items cannot allow model facts; coercing to false', ['key' => $key]);
                $item['model_fact_allowed'] = false;
            }

            if (self::is_blocked($item) && $item['generator_eligible'] === true) {
                self::log_once('[TMW-CAT] blocked items cannot be generator eligible; coercing to false', ['key' => $key]);
                $item['generator_eligible'] = false;
            }

            $seen_keys[$key] = true;
            $validated[] = $item;
        }

        self::$validated_items = $validated;
        return self::$validated_items;
    }

    /** @return array<int, array<string, mixed>> */
    private static function raw_items(): array {
        $t = 'post_tag';
        return [
            ['key'=>'livejasmin','label'=>'LiveJasmin','family'=>self::FAMILY_PLATFORM,'risk_level'=>self::RISK_LOW,'allowed_surfaces'=>['model_page','category_page','admin_filter'],'taxonomy'=>$t,'generator_eligible'=>true,'requires_evidence'=>false,'notes'=>'Platform label only; model/platform association needs verified link evidence.','public_category_candidate'=>true,'seo_research_candidate'=>true],
            ['key'=>'camsoda','label'=>'CamSoda','family'=>self::FAMILY_PLATFORM,'risk_level'=>self::RISK_LOW,'allowed_surfaces'=>['model_page','category_page','admin_filter'],'taxonomy'=>$t,'generator_eligible'=>true,'requires_evidence'=>false,'notes'=>'Platform label only; model/platform association needs verified link evidence.','public_category_candidate'=>true,'seo_research_candidate'=>true],
            ['key'=>'chaturbate','label'=>'Chaturbate','family'=>self::FAMILY_PLATFORM,'risk_level'=>self::RISK_LOW,'allowed_surfaces'=>['model_page','category_page','admin_filter'],'taxonomy'=>$t,'generator_eligible'=>true,'requires_evidence'=>false,'notes'=>'Platform label only; model/platform association needs verified link evidence.','public_category_candidate'=>true,'seo_research_candidate'=>true],
            ['key'=>'stripchat','label'=>'Stripchat','family'=>self::FAMILY_PLATFORM,'risk_level'=>self::RISK_LOW,'allowed_surfaces'=>['model_page','category_page','admin_filter'],'taxonomy'=>$t,'generator_eligible'=>true,'requires_evidence'=>false,'notes'=>'Platform label only; model/platform association needs verified link evidence.','public_category_candidate'=>true,'seo_research_candidate'=>true],
            ['key'=>'cam4','label'=>'Cam4','family'=>self::FAMILY_PLATFORM,'risk_level'=>self::RISK_LOW,'allowed_surfaces'=>['model_page','category_page','admin_filter'],'taxonomy'=>$t,'generator_eligible'=>true,'requires_evidence'=>false,'notes'=>'Platform label only; model/platform association needs verified link evidence.','public_category_candidate'=>true,'seo_research_candidate'=>true],
            ['key'=>'bongacams','label'=>'BongaCams','family'=>self::FAMILY_PLATFORM,'risk_level'=>self::RISK_LOW,'allowed_surfaces'=>['model_page','category_page','admin_filter'],'taxonomy'=>$t,'generator_eligible'=>true,'requires_evidence'=>false,'notes'=>'Platform label only; model/platform association needs verified link evidence.','public_category_candidate'=>true,'seo_research_candidate'=>true],
            ['key'=>'jerkmate','label'=>'Jerkmate','family'=>self::FAMILY_PLATFORM,'risk_level'=>self::RISK_LOW,'allowed_surfaces'=>['model_page','category_page','admin_filter'],'taxonomy'=>$t,'generator_eligible'=>true,'requires_evidence'=>false,'notes'=>'Platform label only; model/platform association needs verified link evidence.','public_category_candidate'=>true,'seo_research_candidate'=>true],
            ['key'=>'myfreecams','label'=>'MyFreeCams','family'=>self::FAMILY_PLATFORM,'risk_level'=>self::RISK_LOW,'allowed_surfaces'=>['model_page','category_page','admin_filter'],'taxonomy'=>$t,'generator_eligible'=>true,'requires_evidence'=>false,'notes'=>'Platform label only; model/platform association needs verified link evidence.','public_category_candidate'=>true,'seo_research_candidate'=>true],
            ['key'=>'flirt4free','label'=>'Flirt4Free','family'=>self::FAMILY_PLATFORM,'risk_level'=>self::RISK_LOW,'allowed_surfaces'=>['model_page','category_page','admin_filter'],'taxonomy'=>$t,'generator_eligible'=>true,'requires_evidence'=>false,'notes'=>'Platform label only; model/platform association needs verified link evidence.','public_category_candidate'=>true,'seo_research_candidate'=>true],
            ['key'=>'imlive','label'=>'imlive','family'=>self::FAMILY_PLATFORM,'risk_level'=>self::RISK_LOW,'allowed_surfaces'=>['model_page','category_page','admin_filter'],'taxonomy'=>$t,'generator_eligible'=>true,'requires_evidence'=>false,'notes'=>'Platform label only; model/platform association needs verified link evidence.','public_category_candidate'=>true,'seo_research_candidate'=>true],

            ['key'=>'live_chat','label'=>'Live Chat','family'=>self::FAMILY_INTERACTION,'risk_level'=>self::RISK_LOW,'allowed_surfaces'=>['model_page','category_page'],'taxonomy'=>$t,'generator_eligible'=>true,'requires_evidence'=>false,'notes'=>'General interaction descriptor.','public_category_candidate'=>true],
            ['key'=>'private_chat_available','label'=>'Private Chat Available','family'=>self::FAMILY_INTERACTION,'risk_level'=>self::RISK_LOW,'allowed_surfaces'=>['model_page','category_page'],'taxonomy'=>$t,'generator_eligible'=>true,'requires_evidence'=>false,'notes'=>'Platform feature descriptor.','public_category_candidate'=>true],
            ['key'=>'fan_club_updates','label'=>'Fan Club Updates','family'=>self::FAMILY_INTERACTION,'risk_level'=>self::RISK_LOW,'allowed_surfaces'=>['model_page','category_page'],'taxonomy'=>$t,'generator_eligible'=>true,'requires_evidence'=>false,'notes'=>'General interaction descriptor.','public_category_candidate'=>true],

            ['key'=>'video_highlights','label'=>'Video Highlights','family'=>self::FAMILY_CONTENT_FORMAT,'risk_level'=>self::RISK_LOW,'allowed_surfaces'=>['model_page','category_page'],'taxonomy'=>$t,'generator_eligible'=>true,'requires_evidence'=>false,'notes'=>'Format descriptor.','public_category_candidate'=>true],
            ['key'=>'photo_galleries','label'=>'Photo Galleries','family'=>self::FAMILY_CONTENT_FORMAT,'risk_level'=>self::RISK_LOW,'allowed_surfaces'=>['model_page','category_page'],'taxonomy'=>$t,'generator_eligible'=>true,'requires_evidence'=>false,'notes'=>'Format descriptor.','public_category_candidate'=>true],
            ['key'=>'profile_updates','label'=>'Profile Updates','family'=>self::FAMILY_CONTENT_FORMAT,'risk_level'=>self::RISK_LOW,'allowed_surfaces'=>['model_page','category_page'],'taxonomy'=>$t,'generator_eligible'=>true,'requires_evidence'=>false,'notes'=>'Format descriptor.','public_category_candidate'=>true],

            ['key'=>'adult_video_chat','label'=>'Adult Video Chat','family'=>self::FAMILY_ADULT_INTENT_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'SEO research only.','seo_research_candidate'=>true],
            ['key'=>'adult_chat_rooms','label'=>'Adult Chat Rooms','family'=>self::FAMILY_ADULT_INTENT_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'SEO research only.','seo_research_candidate'=>true],
            ['key'=>'adult_cam_chat','label'=>'Adult Cam Chat','family'=>self::FAMILY_ADULT_INTENT_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'SEO research only.','seo_research_candidate'=>true],
            ['key'=>'adult_live_chat','label'=>'Adult Live Chat','family'=>self::FAMILY_ADULT_INTENT_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'SEO research only.','seo_research_candidate'=>true],
            ['key'=>'adult_webcam_chat','label'=>'Adult Webcam Chat','family'=>self::FAMILY_ADULT_INTENT_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'SEO research only.','seo_research_candidate'=>true],
            ['key'=>'cam_to_cam_chat','label'=>'Cam-to-Cam Chat','family'=>self::FAMILY_ADULT_INTENT_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'SEO research only.','seo_research_candidate'=>true],
            ['key'=>'nsfw_video_chat','label'=>'NSFW Video Chat','family'=>self::FAMILY_EXPLICIT_INTENT_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'SEO research only.','seo_research_candidate'=>true],

            ['key'=>'webcam_chat_rooms','label'=>'Webcam Chat Rooms','family'=>self::FAMILY_SEO_PILLAR,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review','category_page'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'SEO planning concept requiring review.','seo_research_candidate'=>true],
            ['key'=>'webcam_models','label'=>'Webcam Models','family'=>self::FAMILY_SEO_PILLAR,'risk_level'=>self::RISK_LOW,'allowed_surfaces'=>['internal_review','category_page'],'taxonomy'=>$t,'generator_eligible'=>true,'requires_evidence'=>false,'notes'=>'SEO planning concept.','public_category_candidate'=>true,'seo_research_candidate'=>true],
            ['key'=>'cam_models','label'=>'Cam Models','family'=>self::FAMILY_SEO_PILLAR,'risk_level'=>self::RISK_LOW,'allowed_surfaces'=>['internal_review','category_page'],'taxonomy'=>$t,'generator_eligible'=>true,'requires_evidence'=>false,'notes'=>'SEO planning concept.','public_category_candidate'=>true,'seo_research_candidate'=>true],

            ['key'=>'asian','label'=>'Asian','family'=>self::FAMILY_ETHNICITY_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Review-required modifier.','seo_research_candidate'=>true],
            ['key'=>'latina','label'=>'Latina','family'=>self::FAMILY_ETHNICITY_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Review-required modifier.','seo_research_candidate'=>true],
            ['key'=>'ebony','label'=>'Ebony','family'=>self::FAMILY_ETHNICITY_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Review-required modifier.','seo_research_candidate'=>true],
            ['key'=>'indian','label'=>'Indian','family'=>self::FAMILY_NATIONALITY_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Review-required modifier.','seo_research_candidate'=>true],
            ['key'=>'arab','label'=>'Arab','family'=>self::FAMILY_REGION_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Review-required modifier.','seo_research_candidate'=>true],
            ['key'=>'brazilian','label'=>'Brazilian','family'=>self::FAMILY_NATIONALITY_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Review-required modifier.','seo_research_candidate'=>true],
            ['key'=>'russian','label'=>'Russian','family'=>self::FAMILY_NATIONALITY_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Review-required modifier.','seo_research_candidate'=>true],
            ['key'=>'european','label'=>'European','family'=>self::FAMILY_REGION_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Review-required modifier.','seo_research_candidate'=>true],
            ['key'=>'english_language','label'=>'English Language','family'=>self::FAMILY_LANGUAGE_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Review-required language modifier.','seo_research_candidate'=>true],

            ['key'=>'lingerie','label'=>'Lingerie','family'=>self::FAMILY_STYLE_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Review-required modifier.','seo_research_candidate'=>true],
            ['key'=>'glamour','label'=>'Glamour','family'=>self::FAMILY_STYLE_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Review-required modifier.','seo_research_candidate'=>true],
            ['key'=>'cosplay','label'=>'Cosplay','family'=>self::FAMILY_STYLE_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Review-required modifier.','seo_research_candidate'=>true],
            ['key'=>'fitness','label'=>'Fitness','family'=>self::FAMILY_STYLE_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Review-required modifier.','seo_research_candidate'=>true],
            ['key'=>'tattooed','label'=>'Tattooed','family'=>self::FAMILY_APPEARANCE_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Review-required modifier.','seo_research_candidate'=>true],
            ['key'=>'blonde','label'=>'Blonde','family'=>self::FAMILY_APPEARANCE_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Review-required modifier.','seo_research_candidate'=>true],
            ['key'=>'brunette','label'=>'Brunette','family'=>self::FAMILY_APPEARANCE_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Review-required modifier.','seo_research_candidate'=>true],
            ['key'=>'redhead','label'=>'Redhead','family'=>self::FAMILY_APPEARANCE_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Review-required modifier.','seo_research_candidate'=>true],
            ['key'=>'curvy','label'=>'Curvy','family'=>self::FAMILY_APPEARANCE_REVIEW,'risk_level'=>self::RISK_REVIEW_REQUIRED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Review-required modifier.','seo_research_candidate'=>true],

            ['key'=>'ethnicity_inferred','label'=>'Ethnicity Inferred','family'=>self::FAMILY_BLOCKED,'risk_level'=>self::RISK_BLOCKED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Blocked class.'],
            ['key'=>'location_inferred','label'=>'Location Inferred','family'=>self::FAMILY_BLOCKED,'risk_level'=>self::RISK_BLOCKED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Blocked class.'],
            ['key'=>'age_adjacent','label'=>'Age Adjacent','family'=>self::FAMILY_BLOCKED,'risk_level'=>self::RISK_BLOCKED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Blocked class.'],
            ['key'=>'explicit_tag','label'=>'Explicit Tag','family'=>self::FAMILY_BLOCKED,'risk_level'=>self::RISK_BLOCKED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Blocked class.'],
            ['key'=>'leak_or_piracy','label'=>'Leak or Piracy','family'=>self::FAMILY_BLOCKED,'risk_level'=>self::RISK_BLOCKED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Blocked class.'],
            ['key'=>'model_name_only','label'=>'Model Name Only','family'=>self::FAMILY_INTERNAL_STATUS,'risk_level'=>self::RISK_BLOCKED,'allowed_surfaces'=>['internal_review'],'taxonomy'=>$t,'generator_eligible'=>false,'requires_evidence'=>true,'notes'=>'Blocked class.'],
        ];
    }

    private static function log_once(string $message, array $context = []): void {
        static $logged = [];
        $fingerprint = md5($message . wp_json_encode($context));
        if (isset($logged[$fingerprint])) { return; }
        $logged[$fingerprint] = true;

        if (class_exists('TMWSEO\\Engine\\Logs')) {
            \TMWSEO\Engine\Logs::warning('categories', $message, $context);
            return;
        }

        error_log($message . ' ' . wp_json_encode($context));
    }
}
