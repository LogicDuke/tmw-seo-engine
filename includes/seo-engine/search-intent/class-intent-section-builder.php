<?php

if (!defined('ABSPATH')) { exit; }

class TMW_Intent_Section_Builder {
    private $template;

    public function __construct(?TMW_Intent_Template $template = null) {
        $this->template = $template ?: new TMW_Intent_Template();
    }

    /**
     * @param array<string,bool> $intents
     * @param array<string,string> $context
     * @param string[] $keywords
     * @return string[]
     */
    public function build(array $intents, array $context, array $keywords = []): array {
        $sections = [];

        if (!empty($intents['model_intent'])) {
            $sections[] = $this->render_section(
                'Who Is [MODEL]',
                '<p>[MODEL] is a featured performer in the [CATEGORY] category, known for [TAGS].</p>',
                $context
            );
        }

        if (!empty($intents['watch_intent'])) {
            $sections[] = $this->render_section(
                'Watch [MODEL] Live Cam',
                '<p>Watch [MODEL] live and discover the latest [CATEGORY] shows across trusted platforms.</p>',
                $context
            );
        }

        if (!empty($intents['platform_intent'])) {
            $sections[] = $this->render_section(
                'Where [MODEL] Streams',
                '<p>[MODEL] streams on [PLATFORM]. Browse profiles, schedule updates, and active show times.</p>',
                $context
            );
        }

        if (!empty($intents['discovery_intent'])) {
            $sections[] = $this->render_section(
                'Similar Cam Models',
                '<p>If you like [MODEL], explore similar creators by [TAGS] and [CATEGORY] preferences.</p>',
                $context
            );
        }

        if (!empty($keywords)) {
            $sections[] = $this->build_cluster_section($keywords);
        }

        return $sections;
    }

    /** @param string[] $keywords */
    private function build_cluster_section(array $keywords): string {
        $items = [];
        foreach (array_slice($keywords, 0, 8) as $keyword) {
            $items[] = '<li>' . esc_html($keyword) . '</li>';
        }

        if (empty($items)) {
            return '';
        }

        return '<section class="tmw-intent-clusters"><h3>Keyword Cluster Topics</h3><ul>' . implode('', $items) . '</ul></section>';
    }

    /** @param array<string,string> $context */
    private function render_section(string $title_template, string $body_template, array $context): string {
        $title = $this->template->render($title_template, $context);
        $body = $this->template->render($body_template, $context);

        return sprintf(
            '<section class="tmw-intent-section"><h2>%s</h2>%s</section>',
            esc_html($title),
            wp_kses_post($body)
        );
    }
}
