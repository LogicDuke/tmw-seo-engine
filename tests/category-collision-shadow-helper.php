<?php
/** Shared production-equivalent collision-shadow generation for category tests. */
declare(strict_types=1);

use TMWSEO\Engine\Content\CategoryPipeline\CategoryDifferentiationScorer;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationPipeline;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationResult;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryParagraphUniquenessGuard;

if (!function_exists('tmwseo_category_collision_shadows')) {
    /**
     * @param array<string,mixed> $context
     * @param string[] $tracking
     * @return array<int,array<string,mixed>>
     */
    function tmwseo_category_collision_shadows(array $context, array $tracking = []): array {
        $shadows = [];
        for ($salt = 0; $salt < CategoryGenerationPipeline::MAX_ATTEMPTS; $salt++) {
            $draft = CategoryGenerationPipeline::generate_from_context($context, [
                'tracking' => $tracking,
                'use_store' => false,
                'salt' => $salt,
                'comparisons' => [],
                'single_salt' => true,
            ]);
            $html = (string) ($draft['html'] ?? '');
            if ($html === '') { continue; }
            $fp = CategoryDifferentiationScorer::fingerprint($html, [], 'shadow-' . $salt);
            $fp['uniqueness'] = CategoryParagraphUniquenessGuard::fingerprint($html, [], []);
            $shadows[] = $fp;
        }
        return $shadows;
    }
}
