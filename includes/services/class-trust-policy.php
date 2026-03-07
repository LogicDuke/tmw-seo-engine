<?php
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
