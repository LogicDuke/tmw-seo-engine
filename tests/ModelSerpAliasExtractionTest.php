<?php
declare(strict_types=1);

namespace TMWSEO\Engine\Model\Tests;

use PHPUnit\Framework\TestCase;
use TMWSEO\Engine\Model\ModelFullAuditProvider;

final class ModelSerpAliasExtractionTest extends TestCase {
    private function callExtract(array $items): array {
        $provider = new ModelFullAuditProvider();
        $ref = new \ReflectionMethod($provider, 'extract_serp_alias_candidates');
        $ref->setAccessible(true);
        return $ref->invoke($provider, $items, 'Aisha Dupont');
    }

    public function test_extracts_parenthetical_handle(): void {
        $r = $this->callExtract([[ 'title' => 'Aisha Dupont (@aishadupont)', 'description' => '', 'url' => 'https://instagram.com/aishadupont', '_query' => 'Aisha Dupont' ]]);
        $aliases = array_column($r['accepted'], 'normalized_alias');
        $this->assertContains('aishadupont', $aliases);
    }

    public function test_extracts_aliases_from_platform_context(): void {
        $r = $this->callExtract([[ 'title' => 'LiveJasmin AishaDupont', 'description' => 'Stripchat OhhAisha profile Chaturbate ohhaisha', 'url' => 'https://fr.stripchat.com/OhhAisha', '_query' => 'AishaDupont' ]]);
        $aliases = array_column($r['accepted'], 'normalized_alias');
        $this->assertContains('aishadupont', $aliases);
        $this->assertContains('ohhaisha', $aliases);
    }

    public function test_rejects_generic_terms(): void {
        $r = $this->callExtract([[ 'title' => 'profile photos videos webcam chat live model', 'description' => '', 'url' => 'https://example.com/profile', '_query' => 'Aisha Dupont' ]]);
        $aliases = array_column($r['accepted'], 'normalized_alias');
        $this->assertNotContains('profile', $aliases);
        $this->assertNotContains('webcam', $aliases);
    }

    public function test_deduplicates_case_variants(): void {
        $r = $this->callExtract([[ 'title' => 'AishaDupont aishadupont', 'description' => '', 'url' => 'https://livejasmin.com/aishadupont', '_query' => 'Aisha Dupont' ]]);
        $accepted = array_values(array_filter($r['accepted'], fn(array $a): bool => ($a['normalized_alias'] ?? '') === 'aishadupont'));
        $this->assertCount(1, $accepted);
    }
}
