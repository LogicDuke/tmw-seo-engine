<?php
/**
 * TMW SEO Engine — Trust Policy
 *
 * Defines the hard-coded trust constraints for this plugin.
 *
 * Two separate concepts are in play:
 *
 * 1. manual_only (TrustPolicy::is_manual_only())
 *    - HARD-CODED to true; cannot be changed by operators.
 *    - Controls: no auto-publish, no auto-link-insertion, no cron-driven content writing.
 *    - Governs: ContentEngine init, SmartQueue, Cron scheduling, all suggestion automation.
 *    - Think of this as the "content safety fence" — it never moves.
 *
 * 2. safe_mode (Settings::is_safe_mode())
 *    - Operator-configurable; defaults to ON (1) for new installs.
 *    - When ON, blocks ALL external API calls and AI activity:
 *      - Google Indexing API pings (suppressed)
 *      - OpenAI / Anthropic AI generation calls (suppressed)
 *      - PageSpeed Insights cycles (suppressed)
 *      - Model optimizer AI flows (suppressed)
 *    - Think of this as the "external API and AI fence" — operators lower it once they are
 *      satisfied their credentials and content quality are ready.
 *    - Does NOT affect manual_only behavior or content mutation guards.
 *    - Recommended: keep ON during initial setup and review period.
 *
 * Summary:
 *   manual_only = true  → plugin NEVER auto-publishes or auto-mutates content (always true)
 *   safe_mode   = true  → all external API/AI calls are suppressed (operator-controlled)
 *   safe_mode   = false → AI generation, Indexing API, and PageSpeed cycles are active
 *
 * @package TMWSEO\Engine\Services
 * @since   4.2.2
 */
namespace TMWSEO\Engine\Services;

if (!defined('ABSPATH')) { exit; }

class TrustPolicy {

    public static function flags(): array {
        return [
            'human_approval_required' => true,
            'auto_publish' => false,
            'auto_link_insertion' => false,
            'draft_creation_allowed' => true,
            'manual_only' => true,
            'cron_enabled' => false,
        ];
    }

    public static function is_manual_only(): bool {
        $flags = self::flags();
        return !empty($flags['manual_only']);
    }

    /**
     * Returns true if safe_mode is enabled.
     * When true: all external API and AI calls (Indexing API, OpenAI, PageSpeed) are suppressed.
     * Delegates to Settings. Provided here so callers can check both trust flags from one place.
     */
    public static function is_safe_mode(): bool {
        return Settings::is_safe_mode();
    }

    public static function is_human_approval_required(): bool {
        $flags = self::flags();
        return !empty($flags['human_approval_required']);
    }

    public static function safety_summary(): string {
        return 'Safety layer is always enforced: human approval required, never auto-publish, never auto-insert links. Every action requires explicit user approval.';
    }

    public static function draft_creation_summary(): string {
        return 'The plugin only suggests opportunities and creates drafts when you explicitly choose to do so.';
    }

    public static function insert_link_notice(): string {
        return 'Manual-only safety rule active. No automatic link insertion occurs.';
    }

    public static function bool_text(bool $value): string {
        return $value ? 'true' : 'false';
    }
}
