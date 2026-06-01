<?php
/**
 * Tests for safe model entity resolution.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Models\ModelEntityResolver;

require_once __DIR__ . '/../includes/models/class-model-entity-resolver.php';

final class ModelEntityResolverTest extends TestCase {
    public function test_resolves_lowercase_owner_to_existing_model_title_as_normalized_title(): void {
        $result = $this->resolver([ $this->post(101, 'Anisyia', 'anisyia-archive') ])->resolve('anisyia');

        $this->assertTrue($result['found']);
        $this->assertSame(101, $result['entity_id']);
        $this->assertSame('normalized_title', $result['match_type']);
    }

    public function test_resolves_exact_title(): void {
        $result = $this->resolver([ $this->post(102, 'Anisyia', 'anisyia') ])->resolve('Anisyia');

        $this->assertTrue($result['found']);
        $this->assertSame(102, $result['post_id']);
        $this->assertSame('exact_title', $result['match_type']);
    }

    public function test_resolves_slug_with_literal_raw_slug_match(): void {
        $result = $this->resolver([ $this->post(103, 'Anisyia Official', 'anisyia') ])->resolve('anisyia');

        $this->assertTrue($result['found']);
        $this->assertSame(103, $result['entity_id']);
        $this->assertSame('exact_slug', $result['match_type']);
    }

    public function test_resolves_normalized_title_variant_after_exact_buckets(): void {
        $result = $this->resolver([ $this->post(104, 'Lexy Ness', 'different-slug') ])->resolve('lexy_ness');

        $this->assertTrue($result['found']);
        $this->assertSame(104, $result['entity_id']);
        $this->assertSame('normalized_title', $result['match_type']);
    }

    public function test_resolves_normalized_slug_variant_after_exact_buckets(): void {
        $result = $this->resolver([ $this->post(107, 'Different Title', 'lexy-ness') ])->resolve('lexy_ness');

        $this->assertTrue($result['found']);
        $this->assertSame(107, $result['entity_id']);
        $this->assertSame('normalized_slug', $result['match_type']);
    }

    public function test_returns_not_found_without_creating_or_updating_posts(): void {
        $result = $this->resolver([])->resolve('Anisyia');

        $this->assertFalse($result['found']);
        $this->assertSame(0, $result['entity_id']);
        $this->assertSame('not_found', $result['match_type']);
        $this->assertContains('model_entity_not_found', $result['reason_codes']);
    }

    public function test_returns_ambiguous_when_multiple_matches_exist(): void {
        $result = $this->resolver([ $this->post(105, 'Anisyia', 'anisyia'), $this->post(106, 'Anisyia', 'anisyia-2') ])->resolve('Anisyia');

        $this->assertFalse($result['found']);
        $this->assertSame(0, $result['entity_id']);
        $this->assertSame('ambiguous', $result['match_type']);
        $this->assertContains('model_entity_ambiguous', $result['reason_codes']);
    }

    private function resolver(array $posts): ModelEntityResolver {
        return new ModelEntityResolver(static fn() => $posts);
    }

    private function post(int $id, string $title, string $slug): object {
        return (object) [ 'ID' => $id, 'post_title' => $title, 'post_name' => $slug, 'post_type' => 'model' ];
    }
}
