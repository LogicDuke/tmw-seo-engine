<?php
/**
 * SensitiveTagPolicy — Blocked label / key enforcement for Category Formulas.
 *
 * Maintains a hardcoded v1 blocked list of phrases that must never appear as
 * public-facing labels, keys, or generated category names inside this module.
 *
 * The blocked list is intentionally case-insensitive and substring-matched so
 * that partial matches (e.g. "Teen Cam") are also caught.
 *
 * @package TMWSEO\Engine\CategoryFormulas
 * @since   5.2.0
 */
namespace TMWSEO\Engine\CategoryFormulas;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SensitiveTagPolicy {

    /**
     * V1 blocked phrases.
     * Each entry is matched case-insensitively anywhere in the candidate string.
     * Extend this array in future versions — do NOT silently rewrite matches.
     *
     * @var string[]
     */
    /**
     * @var string[]
     */
    private static $blocked_phrases = [
        'teen',
        'schoolgirl',
        'school girl',
    ];

    /**
     * Check whether a candidate string contains a blocked phrase.
     *
     * @param string $candidate The label, key, or text to check.
     * @return bool True if the string is blocked.
     */
    public static function is_blocked( string $candidate ): bool {
        $lower = mb_strtolower( $candidate );
        foreach ( self::$blocked_phrases as $phrase ) {
            if ( strpos( $lower, $phrase ) !== false ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return the first blocked phrase found in $candidate, or null if clean.
     *
     * @param string $candidate
     * @return string|null
     */
    public static function get_matched_phrase( string $candidate ): ?string {
        $lower = mb_strtolower( $candidate );
        foreach ( self::$blocked_phrases as $phrase ) {
            if ( strpos( $lower, $phrase ) !== false ) {
                return $phrase;
            }
        }
        return null;
    }

    /**
     * Validate a label / key and return a WP_Error if blocked, or true if clean.
     *
     * @param string $candidate
     * @param string $field_name Human-readable name of the field being checked (for the error message).
     * @return true|\WP_Error
     */
    public static function validate( string $candidate, string $field_name = 'label' ) {
        $phrase = self::get_matched_phrase( $candidate );
        if ( $phrase !== null ) {
            return new \WP_Error(
                'tmwseo_blocked_label',
                sprintf(
                    /* translators: 1: field name, 2: the blocked phrase found */
                    __( 'The %1$s contains a blocked term ("%2$s"). This module cannot create public-facing labels that include this phrase.', 'tmwseo' ),
                    esc_html( $field_name ),
                    esc_html( $phrase )
                )
            );
        }
        return true;
    }

    /**
     * Return a copy of the blocked phrases list (read-only snapshot).
     *
     * @return string[]
     */
    public static function get_blocked_phrases(): array {
        return self::$blocked_phrases;
    }
}
