<?php
/**
 * Template pool for deterministic model section and FAQ copy selection.
 *
 * This foundation class only loads local JSON data, selects deterministic
 * variants, and resolves placeholders. It does not render frontend output,
 * write post content, update Rank Math fields, update model posts, call
 * external APIs, or change indexing behavior.
 *
 * @package TMWSEO\Engine\Model
 * @since   5.8.15-template-pool-foundation
 */

namespace TMWSEO\Engine\Model;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministic section-template and FAQ pool.
 */
class TemplatePool {

    /** @var string */
    private $section_templates_file;

    /** @var string */
    private $faq_pool_file;

    /** @var array<string,mixed>|null */
    private $sections_data = null;

    /** @var array<string,mixed>|null */
    private $faq_data = null;

    /**
     * @param string|null $section_templates_file Optional section-template JSON path.
     * @param string|null $faq_pool_file          Optional FAQ-pool JSON path.
     */
    public function __construct( $section_templates_file = null, $faq_pool_file = null ) {
        $data_dir = defined( 'TMWSEO_ENGINE_DATA_DIR' )
            ? rtrim( (string) TMWSEO_ENGINE_DATA_DIR, '/\\' )
            : dirname( __DIR__, 2 ) . '/data';

        $this->section_templates_file = $section_templates_file ?: $data_dir . '/section-templates.json';
        $this->faq_pool_file          = $faq_pool_file ?: $data_dir . '/faq-pool.json';
    }

    /**
     * Get one resolved section variant by key.
     *
     * @param string $section_key Section key.
     * @param int    $post_id     Model post ID used for deterministic rotation.
     * @param array  $model_data  Placeholder data.
     * @return array<string,mixed>|null
     * @throws \RuntimeException When the JSON file is missing or invalid.
     */
    public function get_section( $section_key, $post_id, array $model_data ) {
        $section_key = (string) $section_key;
        $sections    = $this->get_sections();

        if ( ! isset( $sections[ $section_key ] ) || ! is_array( $sections[ $section_key ] ) ) {
            return null;
        }

        $section  = $sections[ $section_key ];
        $variants = isset( $section['variants'] ) && is_array( $section['variants'] ) ? array_values( $section['variants'] ) : array();

        if ( count( $variants ) === 0 ) {
            return null;
        }

        $variant = $variants[ $this->select_index( $post_id, $section_key, count( $variants ) ) ];

        return array(
            'key'     => $section_key,
            'label'   => isset( $section['label'] ) ? $this->resolve( (string) $section['label'], $model_data ) : $section_key,
            'content' => $this->resolve( (string) $variant, $model_data ),
        );
    }

    /**
     * Get resolved FAQs, one per bucket by default.
     *
     * @param int   $post_id    Model post ID used for deterministic rotation.
     * @param array $model_data Placeholder data.
     * @param int   $per_page   Maximum FAQs to return.
     * @return array<int,array<string,string>>
     * @throws \RuntimeException When the JSON file is missing or invalid.
     */
    public function get_faqs( $post_id, array $model_data, $per_page = 5 ) {
        $per_page = max( 0, (int) $per_page );
        if ( $per_page === 0 ) {
            return array();
        }

        $faqs    = array();
        $buckets = $this->get_faq_buckets();
        $offset  = 0;

        foreach ( $buckets as $bucket_key => $bucket ) {
            if ( count( $faqs ) >= $per_page ) {
                break;
            }

            if ( ! is_array( $bucket ) ) {
                ++$offset;
                continue;
            }

            $questions = isset( $bucket['questions'] ) && is_array( $bucket['questions'] ) ? array_values( $bucket['questions'] ) : array();
            if ( count( $questions ) === 0 ) {
                ++$offset;
                continue;
            }

            $question = $questions[ $this->select_index( (int) $post_id + $offset, (string) $bucket_key, count( $questions ) ) ];
            if ( ! is_array( $question ) ) {
                ++$offset;
                continue;
            }

            $faqs[] = array(
                'bucket'   => (string) $bucket_key,
                'question' => $this->resolve( isset( $question['question'] ) ? (string) $question['question'] : '', $model_data ),
                'answer'   => $this->resolve( isset( $question['answer'] ) ? (string) $question['answer'] : '', $model_data ),
            );

            ++$offset;
        }

        return $faqs;
    }

    /**
     * Get every configured section resolved for the supplied model data.
     *
     * @param int   $post_id    Model post ID used for deterministic rotation.
     * @param array $model_data Placeholder data.
     * @return array<string,array<string,mixed>>
     * @throws \RuntimeException When the JSON file is missing or invalid.
     */
    public function get_all_sections( $post_id, array $model_data ) {
        $out = array();

        foreach ( $this->get_section_keys() as $section_key ) {
            $section = $this->get_section( $section_key, $post_id, $model_data );
            if ( null !== $section ) {
                $out[ $section_key ] = $section;
            }
        }

        return $out;
    }

    /**
     * Get configured section keys.
     *
     * @return string[]
     * @throws \RuntimeException When the JSON file is missing or invalid.
     */
    public function get_section_keys() {
        return array_keys( $this->get_sections() );
    }

    /**
     * Get configured FAQ bucket keys.
     *
     * @return string[]
     * @throws \RuntimeException When the JSON file is missing or invalid.
     */
    public function get_faq_bucket_keys() {
        return array_keys( $this->get_faq_buckets() );
    }

    /**
     * Resolve {{placeholder}} tokens from model data.
     *
     * Unknown placeholders remain visible so missing data is obvious during
     * future wiring and testing.
     *
     * @param string $template   Template string.
     * @param array  $model_data Placeholder data.
     * @return string
     */
    public function resolve( $template, array $model_data ) {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_\.\-]+)\s*\}\}/',
            static function ( $matches ) use ( $model_data ) {
                $key = isset( $matches[1] ) ? (string) $matches[1] : '';
                if ( array_key_exists( $key, $model_data ) && ! is_array( $model_data[ $key ] ) && ! is_object( $model_data[ $key ] ) ) {
                    return (string) $model_data[ $key ];
                }

                return isset( $matches[0] ) ? (string) $matches[0] : '';
            },
            (string) $template
        );
    }

    /**
     * @return array<string,array<string,mixed>>
     * @throws \RuntimeException When the JSON file is missing or invalid.
     */
    private function get_sections() {
        $data = $this->load_sections_data();
        return isset( $data['sections'] ) && is_array( $data['sections'] ) ? $data['sections'] : array();
    }

    /**
     * @return array<string,array<string,mixed>>
     * @throws \RuntimeException When the JSON file is missing or invalid.
     */
    private function get_faq_buckets() {
        $data = $this->load_faq_data();
        return isset( $data['buckets'] ) && is_array( $data['buckets'] ) ? $data['buckets'] : array();
    }

    /**
     * @return array<string,mixed>
     * @throws \RuntimeException When the JSON file is missing or invalid.
     */
    private function load_sections_data() {
        if ( null === $this->sections_data ) {
            $this->sections_data = $this->load_json_file( $this->section_templates_file, 'section templates' );
        }

        return $this->sections_data;
    }

    /**
     * @return array<string,mixed>
     * @throws \RuntimeException When the JSON file is missing or invalid.
     */
    private function load_faq_data() {
        if ( null === $this->faq_data ) {
            $this->faq_data = $this->load_json_file( $this->faq_pool_file, 'FAQ pool' );
        }

        return $this->faq_data;
    }

    /**
     * @param string $file_path JSON file path.
     * @param string $label     Human-readable file label.
     * @return array<string,mixed>
     * @throws \RuntimeException When the JSON file is missing or invalid.
     */
    private function load_json_file( $file_path, $label ) {
        if ( ! is_readable( $file_path ) ) {
            throw new \RuntimeException( '[TMW-POOL] Missing or unreadable ' . $label . ' JSON file: ' . $file_path );
        }

        $raw = file_get_contents( $file_path );
        if ( false === $raw ) {
            throw new \RuntimeException( '[TMW-POOL] Unable to read ' . $label . ' JSON file: ' . $file_path );
        }

        $decoded = json_decode( $raw, true );
        if ( JSON_ERROR_NONE !== json_last_error() ) {
            throw new \RuntimeException( '[TMW-POOL] Invalid ' . $label . ' JSON file: ' . json_last_error_msg() . ' (' . $file_path . ')' );
        }

        if ( ! is_array( $decoded ) ) {
            throw new \RuntimeException( '[TMW-POOL] Invalid ' . $label . ' JSON root: expected object (' . $file_path . ')' );
        }

        return $decoded;
    }

    /**
     * Select a deterministic array index without modulo-by-zero risk.
     *
     * @param int    $post_id Post ID.
     * @param string $salt    Stable salt.
     * @param int    $count   Number of available variants.
     * @return int
     */
    private function select_index( $post_id, $salt, $count ) {
        $count = (int) $count;
        if ( $count <= 0 ) {
            return 0;
        }

        $hash = sprintf( '%u', crc32( (string) (int) $post_id . '|' . (string) $salt ) );

        return (int) ( (int) $hash % $count );
    }
}
