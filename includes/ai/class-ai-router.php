<?php
/**
 * AI Router — unified AI gateway with OpenAI primary + Anthropic Claude fallback.
 *
 * Usage:
 *   $res = AIRouter::chat_json($messages, ['task' => 'brief']);
 *   if ($res['ok']) { $json = $res['json']; }
 *
 * Settings:
 *   tmwseo_anthropic_api_key  — Anthropic API key
 *   tmwseo_ai_primary         — 'openai' | 'anthropic'  (default: openai)
 *   tmwseo_openai_budget_usd  — monthly USD cap (default: 20)
 *   tmwseo_openai_tokens_used — rolling monthly token log option key
 *
 * @package TMWSEO\Engine\AI
 */
namespace TMWSEO\Engine\AI;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use TMWSEO\Engine\Services\OpenAI;
use TMWSEO\Engine\Services\Settings;
use TMWSEO\Engine\Logs;

class AIRouter {

    // ── Model maps ─────────────────────────────────────────────────────────
    // Anthropic model to use for quality / bulk tasks
    const ANTHROPIC_QUALITY = 'claude-sonnet-4-20250514';
    const ANTHROPIC_BULK    = 'claude-haiku-4-5-20251001';
    const ANTHROPIC_API_URL = 'https://api.anthropic.com/v1/messages';

    // Approximate cost per 1K tokens (USD) — used for budget tracking
    private const COST_PER_1K = [
        'gpt-4o'            => 0.005,
        'gpt-4o-mini'       => 0.000150,
        'claude-sonnet-4-20250514' => 0.003,
        'claude-haiku-4-5-20251001' => 0.00025,
    ];

    // ── Public API ─────────────────────────────────────────────────────────

    /**
     * Routes a JSON-response chat request to the best available AI.
     *
     * @param array  $messages   OpenAI-style [{role, content}] array
     * @param array  $args       task, max_tokens, temperature, json_mode, force_provider
     * @return array{ok:bool, json?:array, raw?:string, provider?:string, error?:string}
     */
    public static function chat_json( array $messages, array $args = [] ): array {
        if ( self::is_over_budget() ) {
            Logs::warn( 'ai_router', 'Monthly AI budget exceeded — request blocked.' );
            return [ 'ok' => false, 'error' => 'ai_budget_exceeded' ];
        }

        $primary = (string) ( $args['force_provider'] ?? Settings::get( 'tmwseo_ai_primary', 'openai' ) );

        $providers = $primary === 'anthropic'
            ? [ 'anthropic', 'openai' ]
            : [ 'openai', 'anthropic' ];

        foreach ( $providers as $provider ) {
            $result = self::try_provider( $provider, $messages, $args );
            if ( $result['ok'] ) {
                return $result;
            }
            Logs::warn( 'ai_router', "Provider {$provider} failed — trying next.", [
                'error' => $result['error'] ?? 'unknown',
            ] );
        }

        return [ 'ok' => false, 'error' => 'all_ai_providers_failed' ];
    }

    // ── Budget ─────────────────────────────────────────────────────────────

    public static function is_over_budget(): bool {
        $budget = (float) Settings::get( 'tmwseo_openai_budget_usd', 20.0 );
        if ( $budget <= 0 ) return false; // 0 = unlimited
        return self::get_month_spend() >= $budget;
    }

    public static function get_month_spend(): float {
        $key  = 'tmwseo_ai_spend_' . date( 'Y_m' );
        return (float) get_option( $key, 0.0 );
    }

    public static function get_token_stats(): array {
        $key    = 'tmwseo_ai_tokens_' . date( 'Y_m' );
        $tokens = get_option( $key, [] );
        $spend  = self::get_month_spend();
        $budget = (float) Settings::get( 'tmwseo_openai_budget_usd', 20.0 );
        return [
            'month'        => date( 'F Y' ),
            'tokens'       => is_array( $tokens ) ? $tokens : [],
            'spend_usd'    => round( $spend, 4 ),
            'budget_usd'   => $budget,
            'remaining'    => $budget > 0 ? max( 0, round( $budget - $spend, 4 ) ) : null,
            'over_budget'  => $budget > 0 && $spend >= $budget,
        ];
    }

    // ── Internal ───────────────────────────────────────────────────────────

    private static function try_provider( string $provider, array $messages, array $args ): array {
        switch ( $provider ) {
            case 'openai':
                return self::try_openai( $messages, $args );
            case 'anthropic':
                return self::try_anthropic( $messages, $args );
            default:
                return [ 'ok' => false, 'error' => "unknown_provider_{$provider}" ];
        }
    }

    private static function try_openai( array $messages, array $args ): array {
        if ( ! OpenAI::is_configured() ) {
            return [ 'ok' => false, 'error' => 'openai_not_configured' ];
        }

        $model = isset( $args['quality'] ) && $args['quality']
            ? Settings::openai_model_for_quality()
            : Settings::openai_model_for_bulk();

        $res = OpenAI::chat_json( $messages, $model, $args );
        if ( ! $res['ok'] ) {
            return $res;
        }

        // Track tokens
        $usage = $res['data']['usage'] ?? [];
        if ( ! empty( $usage ) ) {
            self::record_tokens( 'openai', $model, (int) ( $usage['prompt_tokens'] ?? 0 ), (int) ( $usage['completion_tokens'] ?? 0 ) );
        }

        return array_merge( $res, [ 'provider' => 'openai' ] );
    }

    private static function try_anthropic( array $messages, array $args ): array {
        $key = trim( (string) Settings::get( 'tmwseo_anthropic_api_key', '' ) );
        if ( $key === '' ) {
            return [ 'ok' => false, 'error' => 'anthropic_not_configured' ];
        }

        $model = isset( $args['quality'] ) && $args['quality']
            ? self::ANTHROPIC_QUALITY
            : self::ANTHROPIC_BULK;

        // Convert OpenAI-style messages to Anthropic format
        $system_content = '';
        $anthropic_msgs = [];
        foreach ( $messages as $m ) {
            if ( $m['role'] === 'system' ) {
                $system_content .= $m['content'] . "\n";
            } else {
                $anthropic_msgs[] = [
                    'role'    => $m['role'] === 'assistant' ? 'assistant' : 'user',
                    'content' => $m['content'],
                ];
            }
        }

        $body = [
            'model'      => $model,
            'max_tokens' => (int) ( $args['max_tokens'] ?? 1024 ),
            'messages'   => $anthropic_msgs,
        ];
        if ( $system_content !== '' ) {
            $body['system'] = trim( $system_content );
        }

        $resp = wp_remote_post( self::ANTHROPIC_API_URL, [
            'timeout' => 60,
            'headers' => [
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $resp ) ) {
            return [ 'ok' => false, 'error' => $resp->get_error_message() ];
        }

        $code = (int) wp_remote_retrieve_response_code( $resp );
        $raw  = (string) wp_remote_retrieve_body( $resp );
        $json = json_decode( $raw, true );

        if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
            Logs::error( 'ai_router', 'Anthropic bad response', [ 'code' => $code, 'body' => substr( $raw, 0, 300 ) ] );
            return [ 'ok' => false, 'error' => 'anthropic_bad_response', 'code' => $code ];
        }

        $content = $json['content'][0]['text'] ?? '';

        // Track tokens
        $usage = $json['usage'] ?? [];
        if ( ! empty( $usage ) ) {
            self::record_tokens( 'anthropic', $model, (int) ( $usage['input_tokens'] ?? 0 ), (int) ( $usage['output_tokens'] ?? 0 ) );
        }

        // Parse JSON from response
        $decoded = json_decode( $content, true );
        if ( ! is_array( $decoded ) ) {
            if ( preg_match( '/\{.*\}/s', $content, $m ) ) {
                $decoded = json_decode( $m[0], true );
            }
        }

        if ( ! is_array( $decoded ) ) {
            return [ 'ok' => false, 'error' => 'anthropic_json_parse_failed', 'raw' => $content ];
        }

        return [ 'ok' => true, 'json' => $decoded, 'raw' => $content, 'provider' => 'anthropic', 'data' => $json ];
    }

    private static function record_tokens( string $provider, string $model, int $in, int $out ): void {
        $total     = $in + $out;
        $cost_rate = self::COST_PER_1K[ $model ] ?? 0.002;
        $cost      = ( $total / 1000 ) * $cost_rate;

        // Token log
        $token_key  = 'tmwseo_ai_tokens_' . date( 'Y_m' );
        $token_data = (array) get_option( $token_key, [] );
        $token_data[] = [
            'ts'       => current_time( 'mysql' ),
            'provider' => $provider,
            'model'    => $model,
            'in'       => $in,
            'out'      => $out,
            'cost'     => round( $cost, 6 ),
        ];
        // Keep only last 500 entries in option
        if ( count( $token_data ) > 500 ) {
            $token_data = array_slice( $token_data, -500 );
        }
        update_option( $token_key, $token_data, false );

        // Running spend
        $spend_key = 'tmwseo_ai_spend_' . date( 'Y_m' );
        $spend     = (float) get_option( $spend_key, 0.0 );
        update_option( $spend_key, round( $spend + $cost, 6 ), false );
    }
}
