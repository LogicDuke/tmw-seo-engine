<?php
/**
 * Claude-compatible template pool for future model-page content generation.
 *
 * This foundation class only reads local JSON template pools and resolves
 * placeholders against supplied model data. It does not render frontend output,
 * update posts, update Rank Math fields, or call external services.
 *
 * @package TMWSEO\Engine\Model
 */

namespace TMWSEO\Engine\Model;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Local JSON-backed template pool.
 */
class TemplatePool {
    /**
     * Directory containing template pool JSON files.
     *
     * @var string
     */
    protected $data_dir;

    /**
     * Cached decoded section pool.
     *
     * @var array|null
     */
    protected $section_pool = null;

    /**
     * Cached decoded FAQ pool.
     *
     * @var array|null
     */
    protected $faq_pool = null;

    /**
     * Constructor.
     *
     * @param string|null $data_dir Optional data directory override.
     */
    public function __construct($data_dir = null) {
        if ($data_dir === null && defined('TMWSEO_ENGINE_DATA_DIR')) {
            $data_dir = TMWSEO_ENGINE_DATA_DIR;
        }

        $this->data_dir = rtrim((string) $data_dir, '/\\');
    }

    /**
     * Return one resolved section variant for a section key.
     *
     * @param string $section_key Section key.
     * @param int    $post_id     Post ID used only for deterministic selection.
     * @param array  $model_data  Placeholder values.
     * @return array|null
     */
    public function get_section($section_key, $post_id, array $model_data) {
        $section_key = (string) $section_key;
        $sections    = $this->get_sections();

        if ($section_key === '' || !isset($sections[$section_key])) {
            return null;
        }

        $section = $sections[$section_key];
        $label   = '';

        if (is_array($section) && isset($section['label'])) {
            $label = $this->resolve($section['label'], $model_data);
        }

        $variants = $this->extract_variants($section);
        $count    = count($variants);

        if ($count < 1) {
            return null;
        }

        $variant = $variants[$this->pick_index($post_id, $count)];

        if (is_array($variant)) {
            $id   = isset($variant['id']) ? (string) $variant['id'] : '';
            $h2   = isset($variant['h2']) ? $this->resolve($variant['h2'], $model_data) : '';
            $body = isset($variant['body']) ? $this->resolve($variant['body'], $model_data) : '';

            return [
                'key'     => $section_key,
                'id'      => $id,
                'label'   => $label,
                'h2'      => $h2,
                'body'    => $body,
                'content' => $body,
            ];
        }

        $body = $this->resolve($variant, $model_data);

        return [
            'key'     => $section_key,
            'id'      => '',
            'label'   => $label,
            'h2'      => '',
            'body'    => $body,
            'content' => $body,
        ];
    }

    /**
     * Return resolved FAQs, taking one from each bucket before cycling.
     *
     * @param int   $post_id    Post ID used only for deterministic selection.
     * @param array $model_data Placeholder values.
     * @param int   $per_page   Maximum FAQ count.
     * @return array
     */
    public function get_faqs($post_id, array $model_data, $per_page = 5) {
        $per_page = (int) $per_page;

        if ($per_page < 1) {
            return [];
        }

        $buckets = $this->get_faq_buckets();

        if (empty($buckets)) {
            return [];
        }

        $faqs            = [];
        $bucket_position = 0;

        foreach ($buckets as $bucket_key => $bucket) {
            if (count($faqs) >= $per_page) {
                break;
            }

            $questions = $this->extract_questions($bucket);
            $count     = count($questions);

            if ($count < 1) {
                $bucket_position++;
                continue;
            }

            $question = $questions[$this->pick_index(((int) $post_id) + $bucket_position, $count)];
            $faq      = $this->normalize_faq($bucket_key, $question, $model_data);

            if ($faq !== null) {
                $faqs[] = $faq;
            }

            $bucket_position++;
        }

        return $faqs;
    }

    /**
     * Return all available resolved sections keyed by section key.
     *
     * @param int   $post_id    Post ID used only for deterministic selection.
     * @param array $model_data Placeholder values.
     * @return array
     */
    public function get_all_sections($post_id, array $model_data) {
        $resolved = [];

        foreach ($this->get_section_keys() as $section_key) {
            $section = $this->get_section($section_key, $post_id, $model_data);

            if ($section !== null) {
                $resolved[$section_key] = $section;
            }
        }

        return $resolved;
    }

    /**
     * Return available section keys.
     *
     * @return array
     */
    public function get_section_keys() {
        return array_keys($this->get_sections());
    }

    /**
     * Return available FAQ bucket keys.
     *
     * @return array
     */
    public function get_faq_bucket_keys() {
        return array_keys($this->get_faq_buckets());
    }

    /**
     * Resolve {{placeholder}} tokens from supplied model data.
     *
     * Unknown placeholders are intentionally left visible.
     *
     * @param mixed $template   Template string.
     * @param array $model_data Placeholder values.
     * @return string
     */
    public function resolve($template, array $model_data) {
        if (is_array($template) || is_object($template)) {
            return '';
        }

        $template = (string) $template;

        if ($template === '') {
            return '';
        }

        return (string) preg_replace_callback('/{{\s*([a-zA-Z0-9_\-]+)\s*}}/', function ($matches) use ($model_data) {
            $key = $matches[1];

            if (!array_key_exists($key, $model_data)) {
                return $matches[0];
            }

            if (is_array($model_data[$key]) || is_object($model_data[$key])) {
                return $matches[0];
            }

            return (string) $model_data[$key];
        }, $template);
    }

    /**
     * Return decoded sections.
     *
     * @return array
     */
    protected function get_sections() {
        $pool = $this->load_section_pool();

        return (isset($pool['sections']) && is_array($pool['sections'])) ? $pool['sections'] : [];
    }

    /**
     * Return decoded FAQ buckets.
     *
     * @return array
     */
    protected function get_faq_buckets() {
        $pool = $this->load_faq_pool();

        return (isset($pool['buckets']) && is_array($pool['buckets'])) ? $pool['buckets'] : [];
    }

    /**
     * Load and cache section pool JSON.
     *
     * @return array
     */
    protected function load_section_pool() {
        if ($this->section_pool === null) {
            $this->section_pool = $this->load_json_file('section-templates.json');
        }

        return $this->section_pool;
    }

    /**
     * Load and cache FAQ pool JSON.
     *
     * @return array
     */
    protected function load_faq_pool() {
        if ($this->faq_pool === null) {
            $this->faq_pool = $this->load_json_file('faq-pool.json');
        }

        return $this->faq_pool;
    }

    /**
     * Load a local JSON file from the configured data directory.
     *
     * @param string $filename File name within the data directory.
     * @return array
     */
    protected function load_json_file($filename) {
        if ($this->data_dir === '') {
            return [];
        }

        $path = $this->data_dir . DIRECTORY_SEPARATOR . basename((string) $filename);

        if (!is_readable($path)) {
            return [];
        }

        $raw = file_get_contents($path);

        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Extract section variants from supported pool shapes.
     *
     * @param mixed $section Section data.
     * @return array
     */
    protected function extract_variants($section) {
        if (is_array($section) && isset($section['variants']) && is_array($section['variants'])) {
            return array_values($section['variants']);
        }

        if (is_array($section)) {
            return array_values($section);
        }

        return [];
    }

    /**
     * Extract FAQ questions from supported bucket shapes.
     *
     * @param mixed $bucket Bucket data.
     * @return array
     */
    protected function extract_questions($bucket) {
        if (is_array($bucket) && isset($bucket['questions']) && is_array($bucket['questions'])) {
            return array_values($bucket['questions']);
        }

        if (is_array($bucket)) {
            return array_values($bucket);
        }

        return [];
    }

    /**
     * Normalize and resolve one FAQ item.
     *
     * @param string $bucket_key Bucket key.
     * @param mixed  $question   FAQ item.
     * @param array  $model_data Placeholder values.
     * @return array|null
     */
    protected function normalize_faq($bucket_key, $question, array $model_data) {
        if (is_array($question)) {
            $id = isset($question['id']) ? (string) $question['id'] : '';
            $q  = isset($question['q']) ? $question['q'] : (isset($question['question']) ? $question['question'] : '');
            $a  = isset($question['a']) ? $question['a'] : (isset($question['answer']) ? $question['answer'] : '');

            $q = $this->resolve($q, $model_data);
            $a = $this->resolve($a, $model_data);

            if ($q === '' && $a === '') {
                return null;
            }

            return [
                'bucket'   => (string) $bucket_key,
                'id'       => $id,
                'q'        => $q,
                'a'        => $a,
                'question' => $q,
                'answer'   => $a,
            ];
        }

        return null;
    }

    /**
     * Pick a deterministic index without risking modulo-by-zero.
     *
     * @param int $seed  Selection seed.
     * @param int $count Available item count.
     * @return int
     */
    protected function pick_index($seed, $count) {
        $count = (int) $count;

        if ($count < 1) {
            return 0;
        }

        return abs((int) $seed) % $count;
    }
}
