<?php
/**
 * Immutable helpers shared by the authoritative category save transaction.
 *
 * Keeping canonicalisation here prevents a save path from comparing the raw
 * editor value while another path compares a normalised Gutenberg value.
 */
namespace TMWSEO\Engine\Content;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CategoryGenerationTransaction {
    /** Canonical form used for the intended/readback hash comparison. */
    public static function canonical_content( string $content ): string {
        $content = str_replace( [ "\r\n", "\r" ], "\n", $content );
        // WordPress may only vary insignificant whitespace around block comments.
        $content = preg_replace( '/[ \t]+\n/', "\n", $content ) ?? $content;
        return trim( $content );
    }

    public static function hash( string $content ): string {
        return hash( 'sha256', self::canonical_content( $content ) );
    }
}
