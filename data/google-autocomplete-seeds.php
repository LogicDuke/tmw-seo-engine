<?php
/**
 * GOOGLE AUTOCOMPLETE SEEDS - All 30 Categories
 * 
 * Strategy: Google Autocomplete is MORE permissive than Serper
 * We can use semi-explicit seeds that trigger real user autocomplete
 * 
 * Format: Seeds are actual query starters that Google users type
 * Google will auto-complete them with popular searches
 * 
 * Expected Results: 80-200 keywords per category
 * 
 * Usage:
 * Query: "asian cam" → Google returns: ["asian cam girls", "asian cam sites", "asian cam models", ...]
 */

return [
    /**
     * ETHNIC/REGIONAL CATEGORIES
     */
    'asian' => [
        // Direct but safe
        'asian cam girl',
        'asian cam model',
        'asian live cam',
        'asian webcam model',
        'asian cam chat',
        'asian cam sites',
        
        // Geographic specifics
        'japanese cam girl',
        'korean webcam model',
        'thai live cam',
        'chinese cam model',
        'filipino cam girl',
        
        // Combo searches
        'asian webcam sites',
        'asian cam chat',
    ],
    
    'latina' => [
        'latina cam girl',
        'latina cam model',
        'latina live cam',
        'latina webcam model',
        'latin cam model',
        
        // Country-specific
        'colombian cam girl',
        'mexican webcam model',
        'brazilian live cam',
        'argentinian cam model',
        
        // Variations
        'spanish cam girl',
        'hispanic webcam model',
    ],
    
    'ebony' => [
        'ebony cam girl',
        'ebony cam model',
        'ebony live cam',
        'ebony webcam model',
        'black cam girl',
        'black cam model',
        'african cam girl',
        'ebony cam chat',
    ],
    
    'white' => [
        'white cam girl',
        'white webcam model',
        'caucasian cam girl',
        'european webcam model',
        'white cam model',
        'western cam girl',
    ],
    
    'interracial' => [
        'interracial cam model',
        'mixed webcam model',
        'interracial live cam',
        'diverse cam girl',
        'multicultural webcam model',
    ],
    
    /**
     * PHYSICAL ATTRIBUTES
     */
    'big-boobs' => [
        // Google allows these
        'big boobs cam girl',
        'big tits webcam model',
        'busty cam girl',
        'busty webcam model',
        'big breasts cam model',
        'busty cam model',
        'big boobs live cam',
    ],
    
    'big-butt' => [
        'big ass cam girl',
        'big booty webcam model',
        'thick cam girl',
        'thick webcam model',
        'big butt live cam',
        'booty cam model',
        'pawg webcam model',
    ],
    
    'curvy' => [
        'curvy cam girl',
        'curvy webcam model',
        'curvy cam model',
        'thick curvy cam girl',
        'plus size cam model',
        'bbw webcam model',
        'curvy live cam',
    ],
    
    'athletic' => [
        'athletic cam girl',
        'fit webcam model',
        'athletic cam model',
        'fitness cam girl',
        'athletic girl cam',
        'sporty webcam model',
        'fit cam model',
    ],
    
    'petite' => [
        'petite cam girl',
        'petite webcam model',
        'petite cam model',
        'small cam girl',
        'petite girl webcam model',
        'tiny cam girl',
        'petite live cam',
    ],
    
    /**
     * HAIR COLOR
     */
    'blonde' => [
        'blonde cam girl',
        'blonde webcam model',
        'blonde cam model',
        'blonde live cam',
        'blonde girl cam',
        'platinum blonde webcam model',
    ],
    
    'brunette' => [
        'brunette cam girl',
        'brunette webcam model',
        'brunette cam model',
        'dark hair cam girl',
        'brown hair webcam model',
        'brunette live cam',
    ],
    
    'redhead' => [
        'redhead cam girl',
        'redhead webcam model',
        'redhead cam model',
        'ginger cam girl',
        'red hair webcam model',
        'redhead live cam',
    ],
    
    /**
     * PERSONALITY/INTERACTION
     */
    'chatty' => [
        'chatty cam girl',
        'talkative webcam model',
        'chat cam girl',
        'interactive webcam model',
        'friendly cam girl',
        'chatty cam model',
    ],
    
    'dominant' => [
        'dominant cam girl',
        'domme webcam model',
        'dominatrix cam girl',
        'femdom webcam model',
        'dominant cam model',
        'mistress cam girl',
    ],
    
    'romantic' => [
        'romantic cam girl',
        'sensual webcam model',
        'romantic cam model',
        'girlfriend cam girl',
        'intimate webcam model',
        'romantic live cam',
    ],
    
    /**
     * ACTIVITY/THEME
     */
    'cosplay' => [
        'cosplay cam girl',
        'cosplay webcam model',
        'cosplay cam model',
        'anime cam girl',
        'costume webcam model',
        'cosplay girl cam',
    ],
    
    'dance' => [
        'dance cam girl',
        'dancing webcam model',
        'dancer cam girl',
        'dance cam model',
        'strip cam girl',
        'twerk webcam model',
    ],
    
    'fitness' => [
        'fitness cam girl',
        'workout webcam model',
        'fitness cam model',
        'gym webcam model',
        'fitness girl cam',
        'yoga webcam model',
    ],
    
    'glamour' => [
        'glamour cam girl',
        'glamour cam model',
        'glamorous webcam model',
        'glam cam girl',
        'luxury webcam model',
        'high fashion cam girl',
    ],
    
    'outdoor' => [
        'outdoor cam girl',
        'outdoor webcam model',
        'public cam girl',
        'outside webcam model',
        'outdoor cam model',
        'nature cam girl',
    ],
    
    'roleplay' => [
        'roleplay cam girl',
        'roleplay webcam model',
        'fantasy cam girl',
        'roleplay cam model',
        'acting webcam model',
    ],
    
    'tattoo-piercing' => [
        'tattoo cam girl',
        'tattooed webcam model',
        'tattoo cam model',
        'pierced cam girl',
        'inked webcam model',
        'alt cam girl',
        'alternative webcam model',
    ],
    
    'uniforms' => [
        'uniform cam girl',
        'nurse webcam model',
        'schoolgirl cam girl',
        'maid webcam model',
        'secretary cam girl',
        'costume webcam model',
    ],
    
    /**
     * RELATIONSHIP/DYNAMIC
     */
    'couples' => [
        'couples live cam',
        'couple cam show',
        'couples webcam show',
        'duo cam show',
        'pair webcam show',
    ],
    
    /**
     * NICHE/SPECIAL
     */
    'fetish-lite' => [
        'fetish cam girl',
        'fetish webcam model',
        'kink cam girl',
        'fetish cam model',
        'specialty cam girl',
        'alternative webcam model',
    ],
    
    /**
     * PLATFORM-SPECIFIC
     */
    'livejasmin' => [
        // Brand queries
        'livejasmin',
        'live jasmin',
        'livejasmin models',
        'livejasmin site',
        'livejasmin vs',
        'livejasmin review',
        'livejasmin free',
        'livejasmin alternative',
        'livejasmin cam',
    ],
    
    'compare-platforms' => [
        // Comparison queries
        'livejasmin vs chaturbate',
        'chaturbate vs stripchat',
        'best cam sites',
        'cam site comparison',
        'cam sites like',
        'chaturbate alternative',
        'stripchat vs',
        'top cam sites',
        'cam sites review',
    ],
    
    /**
     * GENERAL/CATCH-ALL
     */
    'general' => [
        // Broad queries
        'cam sites',
        'webcam sites',
        'live cam',
        'cam models',
        'live webcam model',
        'cam girls',
        'webcam chat',
        'cam show',
        'private cam chat',
        'cam to cam chat',
        'free cam sites',
        'live cam chat',
    ],

    'long-hair' => [
        'long hair cam model',
        'long hair webcam model',
        'long hair live cam',
        'long hair cam',
        'long hair live stream',
        'long hair webcam',
    ],

    'short-hair' => [
        'short hair cam model',
        'short hair webcam model',
        'short hair live cam',
        'short hair cam',
        'short hair live stream',
        'short hair webcam',
    ],

    'shoulder-length-hair' => [
        'shoulder length hair cam model',
        'shoulder length hair webcam model',
        'medium hair live cam',
        'mid length hair cam',
        'shoulder length hair webcam',
        'medium hair cam model',
    ],

    'bald' => [
        'bald cam model',
        'bald webcam model',
        'bald live cam',
        'shaved head cam model',
        'clean shave webcam model',
        'bald live stream',
    ],

    'muscular' => [
        'muscular cam model',
        'muscular webcam model',
        'muscular live cam',
        'strong cam model',
        'fit muscular webcam model',
        'muscular live stream',
    ],

    'tall' => [
        'tall cam model',
        'tall webcam model',
        'tall live cam',
        'tall model live cam',
        'tall webcam show',
        'tall live stream',
    ],

    'slim' => [
        'slim cam model',
        'slim webcam model',
        'slim live cam',
        'slim cam show',
        'slim live stream',
        'slim webcam show',
    ],

    'large-build' => [
        'large build cam model',
        'large build webcam model',
        'strong build live cam',
        'broad build cam model',
        'large build live stream',
        'large build webcam show',
    ],

    'natural-look' => [
        'natural look cam model',
        'natural look webcam model',
        'natural look live cam',
        'fresh look cam model',
        'minimal makeup webcam model',
        'natural look live stream',
    ],

    'blue-eyes' => [
        'blue eyes cam model',
        'blue eyed webcam model',
        'blue eyes live cam',
        'bright eyes cam model',
        'blue eyes webcam show',
        'blue eyes live stream',
    ],

    'brown-eyes' => [
        'brown eyes cam model',
        'brown eyed webcam model',
        'brown eyes live cam',
        'warm eyes cam model',
        'brown eyes webcam show',
        'brown eyes live stream',
    ],

    'green-eyes' => [
        'green eyes cam model',
        'green eyed webcam model',
        'green eyes live cam',
        'emerald eyes cam model',
        'green eyes webcam show',
        'green eyes live stream',
    ],

    'grey-eyes' => [
        'grey eyes cam model',
        'gray eyed webcam model',
        'grey eyes live cam',
        'silver eyes cam model',
        'grey eyes webcam show',
        'grey eyes live stream',
    ],

    'black-eyes' => [
        'black eyes cam model',
        'dark eyes webcam model',
        'black eyes live cam',
        'deep eyes cam model',
        'black eyes webcam show',
        'black eyes live stream',
    ],

    'glasses' => [
        'glasses cam model',
        'glasses webcam model',
        'model with glasses live cam',
        'glasses cam show',
        'glasses webcam show',
        'glasses live stream',
    ],

    'eye-contact' => [
        'eye contact cam model',
        'direct gaze webcam model',
        'eye contact live cam',
        'steady gaze cam model',
        'eye contact webcam show',
        'eye contact live stream',
    ],

    'jeans' => [
        'jeans cam model',
        'denim cam model',
        'jeans webcam model',
        'jeans live cam',
        'denim webcam show',
        'jeans live stream',
    ],

    'latex' => [
        'latex outfit cam model',
        'latex look webcam model',
        'latex style live cam',
        'latex cam model',
        'latex webcam show',
        'latex live stream',
    ],

    'leather' => [
        'leather jacket cam model',
        'leather look webcam model',
        'leather style live cam',
        'leather cam model',
        'leather webcam show',
        'leather live stream',
    ],

    'high-heels' => [
        'high heels cam model',
        'heels webcam model',
        'high heels live cam',
        'stiletto heels cam model',
        'high heels webcam show',
        'high heels live stream',
    ],

    'boots' => [
        'boots cam model',
        'boots webcam model',
        'boots live cam',
        'stylish boots cam model',
        'boots webcam show',
        'boots live stream',
    ],

    'cute' => [
        'cute cam model',
        'cute webcam model',
        'cute live cam',
        'cute cam show',
        'cute webcam show',
        'cute live stream',
    ],

    'elegant' => [
        'elegant cam model',
        'elegant webcam model',
        'elegant live cam',
        'classy cam model',
        'elegant webcam show',
        'elegant live stream',
    ],

    'sensual' => [
        'sensual cam model',
        'sensual webcam model',
        'sensual live cam',
        'sensual cam show',
        'sensual webcam show',
        'sensual live stream',
    ],

    'innocent' => [
        'innocent look cam model',
        'innocent look webcam model',
        'innocent live cam',
        'fresh faced cam model',
        'innocent webcam show',
        'innocent live stream',
    ],

    'shy' => [
        'shy cam model',
        'shy webcam model',
        'shy live cam',
        'bashful cam model',
        'shy webcam show',
        'shy live stream',
    ],

    'curious' => [
        'curious cam model',
        'curious webcam model',
        'curious live cam',
        'playful cam model',
        'curious webcam show',
        'curious live stream',
    ],

    'confident' => [
        'confident cam model',
        'confident webcam model',
        'confident live cam',
        'bold cam model',
        'confident webcam show',
        'confident live stream',
    ],

    'latin' => [
        'latin cam model',
        'latin webcam model',
        'latin live cam',
        'latin cam show',
        'latin webcam show',
        'latin live stream',
    ],

    'black' => [
        'black cam model',
        'black webcam model',
        'black live cam',
        'black cam show',
        'black webcam show',
        'black live stream',
    ],

    'model' => [
        'professional model',
        'fashion model',
        'studio model',
        'model live cam',
        'model webcam show',
        'model live stream',
    ],

    'performer' => [
        'live performer cam',
        'studio performer webcam',
        'performer live cam',
        'show performer cam',
        'performer webcam show',
        'performer live stream',
    ],

    'maid' => [
        'maid outfit cam model',
        'maid costume webcam model',
        'maid look live cam',
        'maid cam model',
        'maid webcam show',
        'maid live stream',
    ],

    'office' => [
        'office look cam model',
        'office outfit webcam model',
        'office style live cam',
        'office cam model',
        'office webcam show',
        'office live stream',
    ],

    'celebrity-lookalike' => [
        'celebrity lookalike cam model',
        'celebrity inspired webcam model',
        'celebrity style live cam',
        'celebrity lookalike cam',
        'celebrity webcam show',
        'celebrity live stream',
    ],

    'bedroom' => [
        'bedroom cam model',
        'bedroom webcam model',
        'bedroom live cam',
        'bedroom webcam show',
        'bedroom live stream',
        'bedroom cam show',
    ],

    'kitchen' => [
        'kitchen cam model',
        'kitchen webcam model',
        'kitchen live cam',
        'kitchen webcam show',
        'kitchen live stream',
        'kitchen cam show',
    ],

    'room' => [
        'room cam model',
        'room webcam model',
        'room live cam',
        'room webcam show',
        'room live stream',
        'room cam show',
    ],

    'pool' => [
        'pool cam model',
        'poolside webcam model',
        'pool live cam',
        'pool webcam show',
        'pool live stream',
        'pool cam show',
    ],

    'party' => [
        'party cam model',
        'party webcam model',
        'party live cam',
        'party webcam show',
        'party live stream',
        'party cam show',
    ],

    'home' => [
        'home cam model',
        'home webcam model',
        'home live cam',
        'home webcam show',
        'home live stream',
        'home cam show',
    ],

    'studio' => [
        'studio cam model',
        'studio webcam model',
        'studio live cam',
        'studio webcam show',
        'studio live stream',
        'studio cam show',
    ],

    'live' => [
        'live cam model',
        'live webcam model',
        'live cam show',
        'live cam',
        'live webcam show',
        'live cam stream',
    ],

    'hd' => [
        'hd cam model',
        'hd webcam model',
        'hd live cam',
        'high definition cam model',
        'hd webcam show',
        'hd live stream',
    ],

    'solo' => [
        'solo cam model',
        'solo webcam model',
        'solo live cam',
        'solo cam show',
        'solo webcam show',
        'solo live stream',
    ],

    'sologirl' => [
        'sologirl cam model',
        'solo girl webcam model',
        'sologirl live cam',
        'sologirl cam show',
        'sologirl webcam show',
        'sologirl live stream',
    ],

    'webcam' => [
        'webcam model',
        'webcam cam model',
        'webcam live show',
        'webcam cam show',
        'webcam live stream',
        'webcam live cam',
    ],

    'live-stream' => [
        'live stream model',
        'model live stream',
        'live stream cam',
        'live stream webcam',
        'live stream show',
        'livestream cam model',
    ],

    'auburn-hair' => [
        'auburn hair cam model',
        'auburn hair webcam model',
        'auburn live cam',
        'auburn cam model',
        'auburn webcam show',
        'auburn live stream',
    ],

    'blue-hair' => [
        'blue hair cam model',
        'blue hair webcam model',
        'blue hair live cam',
        'blue hair cam show',
        'blue hair webcam show',
        'blue hair live stream',
    ],

    'pink-hair' => [
        'pink hair cam model',
        'pink hair webcam model',
        'pink hair live cam',
        'pink hair cam show',
        'pink hair webcam show',
        'pink hair live stream',
    ],

    'orange-hair' => [
        'orange hair cam model',
        'orange hair webcam model',
        'orange hair live cam',
        'orange hair cam show',
        'orange hair webcam show',
        'orange hair live stream',
    ],
];
