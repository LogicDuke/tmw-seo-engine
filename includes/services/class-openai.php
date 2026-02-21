<?php
namespace TMWSEO\Engine\Services;

use TMWSEO\Engine\Logs;

if (!defined('ABSPATH')) { exit; }

class OpenAI {

    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    public static function is_configured(): bool {
        $key = (string) Settings::get('openai_api_key', '');
        return trim($key) !== '';
    }

    public static function chat(array $messages, string $model, array $args = []): array {
        if (Settings::is_safe_mode()) {
            return ['ok' => false, 'error' => 'safe_mode_enabled'];
        }
        if (!self::is_configured()) {
            return ['ok' => false, 'error' => 'openai_api_key_missing'];
        }

        $body = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => isset($args['temperature']) ? (float)$args['temperature'] : 0.6,
        ];

        if (!empty($args['max_tokens'])) {
            $body['max_tokens'] = (int)$args['max_tokens'];
        }

        // Prefer JSON mode if requested and supported.
        if (!empty($args['json_mode'])) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $resp = wp_remote_post(self::API_URL, [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . trim((string)Settings::get('openai_api_key', '')),
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($resp)) {
            Logs::error('openai', 'WP error', ['error' => $resp->get_error_message()]);
            return ['ok' => false, 'error' => $resp->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw  = (string) wp_remote_retrieve_body($resp);
        $json = json_decode($raw, true);

        if ($code < 200 || $code >= 300 || !is_array($json)) {
            Logs::error('openai', 'Bad response', ['code' => $code, 'body' => substr($raw, 0, 500)]);
            return ['ok' => false, 'error' => 'bad_response', 'code' => $code, 'body' => $raw];
        }

        return ['ok' => true, 'data' => $json];
    }

    /**
     * Returns decoded JSON array (best effort).
     */
    public static function chat_json(array $messages, string $model, array $args = []): array {
        $args['json_mode'] = true;

        $res = self::chat($messages, $model, $args);
        if (!$res['ok']) {
            // Retry once without json_mode (some accounts/models can reject it).
            if (($res['error'] ?? '') === 'bad_response') {
                $args['json_mode'] = false;
                $res = self::chat($messages, $model, $args);
            }
            if (!$res['ok']) return $res;
        }

        $data = $res['data'];
        $content = $data['choices'][0]['message']['content'] ?? '';
        if (!is_string($content)) $content = '';

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return ['ok' => true, 'json' => $decoded, 'raw' => $content];
        }

        // Fallback: attempt to extract a JSON object from content.
        if (preg_match('/\{.*\}/s', $content, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return ['ok' => true, 'json' => $decoded, 'raw' => $content];
            }
        }

        Logs::warn('openai', 'JSON parse failed', ['snippet' => substr($content, 0, 250)]);
        return ['ok' => false, 'error' => 'json_parse_failed', 'raw' => $content];
    }
}
