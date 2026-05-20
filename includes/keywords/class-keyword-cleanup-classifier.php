<?php
namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class KeywordCleanupClassifier {

    /** @return array{match:bool,reason:string,protected:bool,hard_bad:bool} */
    public static function classify( string $keyword ): array {
        $normalized = strtolower( trim( preg_replace( '/\s+/', ' ', $keyword ) ?? '' ) );
        if ( $normalized === '' ) {
            return [ 'match' => false, 'reason' => '', 'protected' => false, 'hard_bad' => false ];
        }

        $protected_terms = [
            'adult cam', 'adult cams', 'adult webcam', 'live cam', 'cam model', 'cam girl',
            'webcam model', 'cam site', 'cam sites', 'private cam', 'video chat',
            'adult video chat', 'sex chat', 'live chat', 'webcam chat', 'cam show',
        ];

        $info_terms = [ 'meaning', 'means', 'name meaning', 'meaning of', 'how old', 'age', 'birthday', 'net worth', 'wiki', 'wikipedia', 'biography', 'bio', 'ethnicity', 'real name', 'cursive', 'husband', 'name' ];
        $celeb_terms = [ 'huffington', 'huff', 'actor', 'actress', 'movie', 'movies', 'imdb', 'wwe', 'politician', 'greek', 'football', 'soccer', 'bloodborne', 'diegesis', 'queen' ];
        $entity_terms = [ 'grill', 'cafe', 'lakeside', 'boutique', 'hair', 'flowers', 'zucchini' ];
        $hardware_terms = [ 'logitech', 'webcam driver', 'webcam drivers', 'driver download', 'camera app', 'security camera', 'surveillance', 'ip camera', 'webcam test', 'test webcam', 'zoom', 'teams', 'obs', 'software' ];
        $piracy_terms = [ 'leaked', 'leaks', 'torrent', 'download', 'free download', 'reddit', 'discord', 'telegram', 'onlyfans leak', 'mega', 'dropbox' ];
        $person_name_tokens = [
            'arianna', 'smith', 'alessi', 'craviotto', 'bailey', 'reyes', 'rivas', 'huffington', 'huff', 'turturro',
            'hailey', 'fontana', 'jackson', 'mcgregor', 'roberson', 'afsar', 'perez', 'rosario', 'gajraj',
            'abdul', 'grace', 'fox',
        ];

        $has_protected = self::contains_any( $normalized, $protected_terms );

        foreach ( [
            'info_intent' => $info_terms,
            'celebrity_non_webcam' => array_merge( $celeb_terms, $entity_terms ),
            'hardware_intent' => $hardware_terms,
            'piracy_wrong_intent' => $piracy_terms,
        ] as $reason => $terms ) {
            if ( self::contains_any( $normalized, $terms ) ) {
                if ( $has_protected ) {
                    return [ 'match' => false, 'reason' => 'protected_commercial_cam_intent', 'protected' => true, 'hard_bad' => true ];
                }
                return [ 'match' => true, 'reason' => $reason, 'protected' => false, 'hard_bad' => true ];
            }
        }

        if ( $has_protected ) {
            return [ 'match' => false, 'reason' => 'protected_commercial_cam_intent', 'protected' => true, 'hard_bad' => false ];
        }

        if ( self::is_person_name_noncommercial( $normalized, $person_name_tokens ) ) {
            return [ 'match' => true, 'reason' => 'celebrity_non_webcam', 'protected' => false, 'hard_bad' => false ];
        }

        return [ 'match' => false, 'reason' => '', 'protected' => false, 'hard_bad' => false ];
    }

    /** @param string[] $terms */
    private static function contains_any( string $keyword, array $terms ): bool {
        foreach ( $terms as $term ) {
            if ( $term !== '' && strpos( $keyword, $term ) !== false ) {
                return true;
            }
        }
        return false;
    }

    /** @param string[] $tokens */
    private static function is_person_name_noncommercial( string $keyword, array $tokens ): bool {
        $parts = preg_split( '/[^a-z0-9]+/', $keyword ) ?: [];
        $parts = array_values( array_filter( $parts, static fn( string $part ): bool => $part !== '' ) );
        if ( count( $parts ) < 1 || count( $parts ) > 5 ) {
            return false;
        }

        $hit_count = 0;
        foreach ( $parts as $part ) {
            if ( in_array( $part, $tokens, true ) ) {
                $hit_count++;
            }
        }

        if ( $hit_count >= 2 ) {
            return true;
        }

        return count( $parts ) <= 2 && in_array( 'arianna', $parts, true );
    }
}
