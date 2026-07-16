<?php
declare(strict_types=1);

use TMWSEO\Engine\Content\CategoryPipeline\CategoryContentPlanner;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryDifferentiationScorer;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryDraftComposer;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryFaqPlanner;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryFactualSafety;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGenerationPipeline;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryGrammarGuard;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryIntentClassifier;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryKeywordPlacement;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryKeywordPlanner;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryParagraphUniquenessGuard;
use TMWSEO\Engine\Content\CategoryPipeline\CategoryQualityGuard;

function tmwseo_category_collision_shadows(array $context, array $approved_keywords): array {
    $cls = CategoryIntentClassifier::classify($context);
    $kwp = CategoryKeywordPlanner::plan((string) ($context['primary_keyword'] ?? ''), $approved_keywords, []);
    $mask = array_values(array_filter([(string) ($context['category_name'] ?? ''), (string) ($context['primary_keyword'] ?? '')]));
    $shadows = [];
    for ($salt = 0; $salt < CategoryGenerationPipeline::MAX_ATTEMPTS; $salt++) {
        $plan = CategoryContentPlanner::plan($context, (string) $cls['intent'], $salt, $kwp);
        $comp = CategoryDraftComposer::compose($context, $plan, $kwp);
        $html = (string) $comp['html'] . CategoryFaqPlanner::render(CategoryFaqPlanner::plan($context, (string) $cls['intent'], $salt));
        $guard_keywords = array_values(array_unique(array_merge([(string) $kwp['primary']], (array) $kwp['rankmath_tracking'], (array) $kwp['body_use'], $approved_keywords)));
        $html = (string) CategoryQualityGuard::repair($html, $guard_keywords)['html'];
        $html = (string) CategoryFactualSafety::repair($html, (array) ($context['verified_flags'] ?? []))['html'];
        $html = (string) CategoryGrammarGuard::repair($html)['html'];
        $html = (string) CategoryKeywordPlacement::repair($html, (string) $kwp['primary'])['html'];
        $fp = CategoryDifferentiationScorer::fingerprint($html, $mask, 'shadow-' . $salt);
        $fp['uniqueness'] = CategoryParagraphUniquenessGuard::fingerprint($html, $mask, []);
        $shadows[] = $fp;
    }
    return $shadows;
}
