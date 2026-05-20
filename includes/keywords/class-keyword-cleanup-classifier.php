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
            'adult video chat', 'sex chat', 'live chat',
        ];

        $info_terms = [ 'meaning', 'means', 'name meaning', 'how old', 'age', 'birthday', 'net worth', 'wiki', 'wikipedia', 'biography', 'bio', 'ethnicity', 'real name' ];
        $celeb_terms = [ 'huffington', 'actor', 'actress', 'movie', 'movies', 'imdb', 'wwe', 'politician', 'greek', 'football', 'soccer', 'bloodborne', 'cafe', 'boutique', 'hair boutique', 'flowers', 'queen' ];
        $hardware_terms = [ 'logitech', 'webcam driver', 'webcam drivers', 'driver download', 'camera app', 'security camera', 'surveillance', 'ip camera', 'webcam test', 'test webcam', 'zoom', 'teams', 'obs', 'software' ];
        $piracy_terms = [ 'leaked', 'leaks', 'torrent', 'download', 'free download', 'reddit', 'discord', 'telegram', 'onlyfans leak', 'mega', 'dropbox' ];

        $has_protected = self::contains_any( $normalized, $protected_terms );

        foreach ( [
            'info_intent' => $info_terms,
            'celebrity_non_webcam' => $celeb_terms,
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
}
