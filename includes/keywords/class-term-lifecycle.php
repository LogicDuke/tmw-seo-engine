<?php
/**
 * TMW SEO Engine — Term Lifecycle (v5.2)
 *
 * Formal keyword lifecycle state definitions. Single source of truth.
 *
 * TWO TRACKS, ONE OUTCOME:
 *
 * TRACK A: Discovery Track (seed expansion → working keywords)
 *   discovered → scored → queued_for_review → (human approve) → approved
 *   Review point: queued_for_review. Human review is mandatory.
 *
 * TRACK B: Generator Track (tag/model/video phrase generators)
 *   pending → (human approve) → approved
 *   Review point: pending. Generator approval IS final keyword approval.
 *   Approved items enter tmw_keyword_candidates as 'approved' directly.
 *   No second review. No double-approval loop.
 *
 * AFTER APPROVAL (both tracks):
 *   approved → assigned (ownership-checked) → content_ready → indexed
 *
 * ONLY 'approved' keywords are assignable to pages.
 * ONLY assigned keywords can trigger content generation.
 *
 * COMBINED QUEUE: Max 50 actionable items total across both tracks.
 *
 * @package TMWSEO\Engine\Keywords
 * @since   5.2.0
 */

namespace TMWSEO\Engine\Keywords;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TermLifecycle {

    // ═══════════════════════════════════════════════════════════
    // EXPANSION CANDIDATE STATES (Track B: generator → review)
    // Stored in: tmw_seed_expansion_candidates.status
    // ═══════════════════════════════════════════════════════════

    /** Newly generated, awaiting human review. */
    public const CANDIDATE_PENDING    = 'pending';

    /** From a high-signal source (e.g. GSC), needs only sanity check. */
    public const CANDIDATE_FAST_TRACK = 'fast_track';

    /** Operator approved; promoted to working keyword pipeline. */
    public const CANDIDATE_APPROVED   = 'approved';

    /** Operator explicitly rejected. */
    public const CANDIDATE_REJECTED   = 'rejected';

    /** Soft-removed (parked) without explicit rejection. */
    public const CANDIDATE_ARCHIVED   = 'archived';

    // ═══════════════════════════════════════════════════════════
    // WORKING KEYWORD STATES (Track A + Track B converge here)
    // Stored in: tmw_keyword_candidates.status
    //
    // v5.1 lifecycle:
    //   discovered → scored → queued_for_review → (human review) → approved → (assignment)
    //   Also: rejected, and 'new' (legacy pre-v5.1, treated as 'discovered')
    // ═══════════════════════════════════════════════════════════

    /** Just discovered from seed expansion. No KD/volume scoring yet. */
    public const KEYWORD_DISCOVERED       = 'discovered';

    /** KD scored, volume present. Awaiting promotion to review queue. */
    public const KEYWORD_SCORED           = 'scored';

    /** Promoted to the actionable review queue (max 50). Awaiting human decision. */
    public const KEYWORD_QUEUED_FOR_REVIEW = 'queued_for_review';

    /** Human-approved. Can be assigned to a page. */
    public const KEYWORD_APPROVED          = 'approved';

    /** Auto-rejected: KD too high, or validator block. */
    public const KEYWORD_REJECTED          = 'rejected';

    /** Legacy status from pre-v5.1. Treated as 'discovered' by the engine. */
    public const KEYWORD_NEW_LEGACY        = 'new';

    // ═══════════════════════════════════════════════════════════
    // SEED STATES
    // Stored in: tmwseo_seeds (implicit via ROI columns)
    // ═══════════════════════════════════════════════════════════

    /** Active seed, eligible for expansion. */
    public const SEED_ACTIVE    = 'active';

    /** In cooldown, not expanded this cycle. */
    public const SEED_COOLDOWN  = 'cooldown';

    /** Retired: 5+ consecutive zero-yield expansions. */
    public const SEED_RETIRED   = 'retired';

    // ═══════════════════════════════════════════════════════════
    // PAGE-LEVEL STATES (stored in post meta)
    // ═══════════════════════════════════════════════════════════

    /** Primary keyword assigned to page. */
    public const PAGE_KEYWORD_ASSIGNED = 'assigned';

    /** Content generated, awaiting operator review. */
    public const PAGE_CONTENT_PENDING = 'pending';

    /** Content approved by operator. */
    public const PAGE_CONTENT_APPROVED = 'approved';

    /** Content held for revision. */
    public const PAGE_CONTENT_HOLD = 'hold';

    /** Content rejected. */
    public const PAGE_CONTENT_REJECTED = 'rejected';

    /** Page passes all readiness gates, eligible for indexing. */
    public const PAGE_INDEX_READY = 'index_ready';

    /** Page does not pass readiness gates, noindex. */
    public const PAGE_INDEX_NOT_READY = 'not_ready';

    // ═══════════════════════════════════════════════════════════
    // TAG STATES (stored in tmw_tag_quality.status)
    // ═══════════════════════════════════════════════════════════

    public const TAG_BLOCKED      = 'blocked';
    public const TAG_GENERIC      = 'generic';
    public const TAG_LOW_QUALITY  = 'low_quality';
    public const TAG_CANDIDATE    = 'candidate';
    public const TAG_QUALIFIED    = 'qualified';
    public const TAG_PROMOTED     = 'promoted';
    public const TAG_UNSCORED     = 'unscored';

    // ═══════════════════════════════════════════════════════════
    // SEED SOURCES (tmwseo_seeds.source)
    // ═══════════════════════════════════════════════════════════

    /** Sources that can write directly to tmwseo_seeds. */
    public const TRUSTED_SEED_SOURCES = [
        'manual',           // Operator-entered root phrase
        'model_root',       // Bare model name on publish
        'static_curated',   // Versioned starter pack (registered once)
        'static',           // Legacy alias for static_curated
        'approved_import',  // Operator-reviewed CSV/API import
        'csv_import',       // Legacy alias for approved_import
    ];

    /** Sources that go to the preview/candidate layer, never directly to seeds. */
    public const CANDIDATE_ONLY_SOURCES = [
        'tag_qualified',        // TagQualityEngine promotion
        'tag_phrase',           // Mechanical tag phrase generator
        'model_phrase',         // Mechanical model phrase generator
        'video_phrase',         // Mechanical video phrase generator
        'category_phrase',      // Mechanical category phrase generator
        'competitor_ranked',    // Competitor mining
        'trend_rising_query',   // Google Trends
        'gsc',                  // Google Search Console
        'legacy_register_seed', // Legacy callers redirected to preview
    ];

    // ═══════════════════════════════════════════════════════════
    // OWNERSHIP PRIORITY
    // ═══════════════════════════════════════════════════════════

    public const OWNERSHIP_PRIORITY = [
        'model'             => 10,  // Highest: name-based queries
        'tmw_category_page' => 7,   // Category head terms
        'post'              => 5,   // Video/scene-specific
        'page'              => 3,   // Generic / tag landing
    ];

    // ═══════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════

    /**
     * Describe the full lifecycle path for a keyword from discovery to indexing.
     *
     * @return string[] Ordered list of lifecycle stages with descriptions.
     */
    public static function describe_lifecycle(): array {
        return [
            'signal'             => 'Raw intelligence from tags, trends, competitors. Not actionable yet.',
            'discovered'         => 'Found by seed expansion API. In tmw_keyword_candidates. No KD score yet.',
            'scored'             => 'Has volume/KD/opportunity data. Waiting for review queue space (max 50).',
            'queued_for_review'  => 'In the actionable operator review queue. Awaiting human approve/reject/park.',
            'approved'           => 'Passed human review. Can be assigned to a page.',
            'assigned'           => 'Mapped to a specific page (ownership validated). Can trigger content generation.',
            'content_ready'      => 'Content generated as preview. Quality gates evaluated.',
            'indexed'            => 'Live, indexable page. All readiness gates passed.',
            'rejected'           => 'Will never be used. Terminal state (KD too high or human rejection).',
            'parked'             => 'Moved back to scored. Not rejected, will be re-promoted when space opens.',
        ];
    }

    /**
     * Check if a seed source is trusted (can write to tmwseo_seeds).
     */
    public static function is_trusted_seed_source( string $source ): bool {
        return in_array( $source, self::TRUSTED_SEED_SOURCES, true );
    }

    /**
     * Get ownership priority for a page type.
     */
    public static function ownership_priority( string $post_type ): int {
        return self::OWNERSHIP_PRIORITY[ $post_type ] ?? 1;
    }
}
