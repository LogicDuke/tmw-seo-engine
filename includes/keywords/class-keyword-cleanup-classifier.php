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
            'adult cam', 'adult cams', 'adult webcam', 'adult webcams', 'live cam', 'live cams',
            'cam model', 'cam models', 'cam girl', 'cam girls', 'webcam model', 'webcam models',
            'cam site', 'cam sites', 'private cam', 'video chat', 'adult video chat', 'sex chat',
            'live chat', 'live adult chat', 'adult chat', 'webcam chat', 'cam show', 'webcam show',
        ];

        $info_terms = [ 'meaning', 'means', 'name meaning', 'meaning of', 'how old', 'age', 'birthday', 'net worth', 'wiki', 'wikipedia', 'biography', 'bio', 'ethnicity', 'real name', 'cursive', 'husband', 'name' ];
        $name_terms = [ 'arianna', 'smith', 'alessi', 'craviotto', 'bailey', 'reyes', 'rivas', 'huffington', 'huff', 'turturro', 'hailey', 'fontana', 'jackson', 'mcgregor', 'roberson', 'afsar', 'perez', 'rosario', 'gajraj', 'abdul', 'grace', 'fox' ];
        $local_terms = [ 'grill', 'cafe', 'lakeside', 'boutique', 'hair', 'flowers', 'zucchini' ];
        $celeb_terms = [ 'bloodborne', 'diegesis', 'movie', 'movies', 'imdb', 'wwe', 'actor', 'actress', 'politician', 'greek', 'queen', 'football', 'soccer' ];
        $hardware_terms = [ 'logitech', 'webcam driver', 'webcam drivers', 'driver download', 'camera app', 'security camera', 'surveillance', 'ip camera', 'webcam test', 'test webcam', 'zoom', 'teams', 'obs', 'software' ];
        $piracy_terms = [ 'leaked', 'leaks', 'torrent', 'download', 'free download', 'reddit', 'discord', 'telegram', 'onlyfans leak', 'mega', 'dropbox' ];

        $has_protected = self::contains_any( $normalized, $protected_terms );

        foreach ( [
            'info_intent' => $info_terms,
            'entity_name_intent' => $name_terms,
            'wrong_local_intent' => $local_terms,
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
