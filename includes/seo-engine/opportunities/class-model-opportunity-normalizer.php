<?php
namespace TMWSEO\Engine\Opportunities;

if (!defined('ABSPATH')) { exit; }

class ModelOpportunityNormalizer {
    public static function strip_accents(string $value): string { return remove_accents($value); }
    public static function split_camel_case_model_name(string $value): string { return preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $value) ?? $value; }
    public static function normalize_keyword(string $value): string {
        $value = strtolower(trim(self::strip_accents(self::split_camel_case_model_name($value))));
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
    public static function normalize_model_name(string $value): string { return ucwords(self::normalize_keyword($value)); }
    public static function compact_name_key(string $value): string { return str_replace(' ', '', self::normalize_keyword($value)); }
    public static function canonical_entity_key(string $value): string { return self::compact_name_key($value); }
    public static function is_email(string $value): bool { return (bool) filter_var(trim($value), FILTER_VALIDATE_EMAIL); }
    public static function is_url_or_domain(string $value): bool {
        $v = trim(strtolower($value));
        return (bool) preg_match('/^(https?:\/\/|www\.|[a-z0-9.-]+\.[a-z]{2,})/', $v);
    }
    public static function is_numeric_only(string $value): bool { return (bool) preg_match('/^\d+$/', trim($value)); }
    public static function is_one_char(string $value): bool { return mb_strlen(trim($value)) <= 1; }
    public static function is_noise(string $value): bool {
        $k = self::normalize_keyword($value);
        if ($k === '' || self::is_email($k) || self::is_url_or_domain($k) || self::is_numeric_only($k) || self::is_one_char($k)) return true;
        foreach (['site moved','deleted','premium','support','domain','email','hot girls','shooting star','wild thing','studio 69','infinity','flower'] as $needle) {
            if (str_contains($k, $needle)) return true;
        }
        return false;
    }
}
