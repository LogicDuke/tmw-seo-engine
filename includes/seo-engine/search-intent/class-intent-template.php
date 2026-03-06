<?php

if (!defined('ABSPATH')) { exit; }

class TMW_Intent_Template {
    /**
     * @param array<string,string> $context
     */
    public function render(string $template, array $context = []): string {
        $replacements = [
            '[MODEL]' => (string) ($context['MODEL'] ?? ''),
            '[PLATFORM]' => (string) ($context['PLATFORM'] ?? ''),
            '[TAGS]' => (string) ($context['TAGS'] ?? ''),
            '[CATEGORY]' => (string) ($context['CATEGORY'] ?? ''),
        ];

        return strtr($template, $replacements);
    }
}
