<?php
/**
 * Niche / Descriptor Pattern Families — BUILDER LAYER ONLY
 *
 * ============================================================
 * ARCHITECTURE BOUNDARY — READ BEFORE EDITING
 * ============================================================
 *
 * This file is NOT a trusted-root store. It is part of the
 * BUILDER / CANDIDATE-GENERATION layer.
 *
 * TRUSTED ROOTS live in:
 *   SeedRegistry::get_starter_pack()
 *
 * Those are clean, broad, commercial-root phrases ("adult cam",
 * "adult cams", "live adult cam", etc.) registered as
 * source=static_curated into tmwseo_seeds during reset.
 *
 * THIS FILE serves a different purpose:
 *   - Define descriptor + template pattern families per niche branch.
 *   - Feed CuratedKeywordLibrary::generate_builder_candidates() to
 *     produce structured candidate phrases for review.
 *   - Supply research/discovery batch input via the preview queue.
 *
 * CORRECT downstream routing:
 *   SeedRegistry::register_candidate_phrase()
 *   ExpansionCandidateRepository::insert_candidate()
 *   Preview queue bootstrap / research batch input
 *
 * NEVER route output directly to:
 *   SeedRegistry::register_trusted_seed()  ← WRONG
 *   Starter pack / reset foundation packs  ← WRONG
 *
 * ============================================================
 * STRUCTURE
 * ============================================================
 *
 * Each top-level key is a niche BRANCH (ethnicity, body, hair, style).
 * Each branch contains:
 *   'templates'   — pattern skeletons; use [d] as the descriptor placeholder.
 *   'descriptors' — map of category-slug => [ descriptor terms ].
 *
 * CuratedKeywordLibrary::generate_builder_candidates( $category )
 * finds the branch that owns the requested category-slug, then
 * expands templates × descriptors into candidate phrases.
 *
 * CURATION RULES:
 *   - Keep descriptor lists short and meaningful (3–6 per category).
 *   - Prefer terms with clear search intent.
 *   - Do not explode combinatorially into junk.
 *   - Quality > volume.
 *
 * @package TMWSEO\Engine\Data
 * @since   5.1.0
 */

return [

    // ── Ethnicity / Region ──────────────────────────────────────────────────
    // Niche branch: audience queries scoped to ethnicity or regional origin.
    // Templates are broad enough to catch commercial SERP intent.
    'ethnicity' => [
        'templates' => [
            'live [d] cams',
            '[d] webcam models',
            '[d] cam girls',
            '[d] adult cams',
            '[d] live cam',
            '[d] cam model',
        ],
        'descriptors' => [
            'asian'       => [ 'asian', 'japanese', 'korean', 'filipina', 'thai' ],
            'latina'      => [ 'latina', 'colombian', 'brazilian', 'spanish' ],
            'ebony'       => [ 'ebony', 'black' ],
            'white'       => [ 'white', 'european', 'caucasian' ],
            'interracial' => [ 'interracial', 'mixed couple' ],
            'latin'       => [ 'latin' ],
        ],
    ],

    // ── Body / Physique / Age ───────────────────────────────────────────────
    // Niche branch: body-type and age-range audience queries.
    'body' => [
        'templates' => [
            'live [d] cams',
            '[d] webcam models',
            '[d] cam girls',
            '[d] adult cams',
            '[d] live cam',
            '[d] cam model',
        ],
        'descriptors' => [
            'big-boobs'  => [ 'busty', 'big tits', 'huge boobs' ],
            'big-butt'   => [ 'big ass', 'big booty', 'thick booty' ],
            'curvy'      => [ 'curvy', 'bbw', 'thick', 'voluptuous' ],
            'petite'     => [ 'petite', 'tiny' ],
            'milf'       => [ 'milf', 'mature', 'cougar' ],
            'athletic'   => [ 'athletic', 'fit', 'toned' ],
            'muscular'   => [ 'muscular', 'strong build' ],
            'slim'       => [ 'slim', 'slender' ],
            'tall'       => [ 'tall' ],
        ],
    ],

    // ── Hair / Visible Appearance ───────────────────────────────────────────
    // Niche branch: hair colour and distinctive appearance queries.
    'hair' => [
        'templates' => [
            'live [d] cams',
            '[d] webcam models',
            '[d] cam girls',
            '[d] live cam',
            '[d] cam model',
        ],
        'descriptors' => [
            'blonde'           => [ 'blonde', 'blond' ],
            'brunette'         => [ 'brunette', 'dark hair' ],
            'redhead'          => [ 'redhead', 'ginger' ],
            'tattoo-piercing'  => [ 'tattooed', 'inked', 'alternative', 'alt' ],
        ],
    ],

    // ── Style / Interaction Mode ────────────────────────────────────────────
    // Niche branch: show style, interaction type, and persona queries.
    'style' => [
        'templates' => [
            'live [d] cams',
            '[d] webcam models',
            '[d] cam girls',
            '[d] live cam',
            '[d] cam model',
            '[d] cam show',
        ],
        'descriptors' => [
            'dominant'  => [ 'dominant', 'femdom', 'mistress' ],
            'romantic'  => [ 'romantic', 'sensual', 'girlfriend experience' ],
            'roleplay'  => [ 'roleplay', 'fantasy roleplay' ],
            'cosplay'   => [ 'cosplay', 'anime cosplay' ],
            'chatty'    => [ 'chatty', 'talkative', 'friendly' ],
            'dance'     => [ 'dancing', 'twerking' ],
            'glamour'   => [ 'glamour', 'classy', 'elegant' ],
            'toys'      => [ 'lovense', 'interactive toy', 'tip controlled' ],
        ],
    ],

];
