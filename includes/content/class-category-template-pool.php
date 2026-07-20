<?php
/**
 * Category-specific template pool for tmw_category_page content generation.
 *
 * This class reads local JSON template pools and resolves placeholders
 * against supplied category data. It does NOT render frontend output,
 * update posts, update Rank Math fields, or call external services.
 *
 * Modelled on the existing TMWSEO\Engine\Model\TemplatePool pattern so the
 * codebase stays consistent. Key differences:
 *   - Reads category-section-templates.json and category-faq-pool.json
 *   - Selection seed includes category_slug for stable per-category variation
 *   - has_unresolved_placeholders() public helper for gate checks
 *
 * @package TMWSEO\Engine\Content
 * @since   5.9.0
 */

namespace TMWSEO\Engine\Content;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Local JSON-backed category template pool.
 */
class CategoryTemplatePool {

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

    // ── Constructor ──────────────────────────────────────────────────────────

    /**
     * @param string|null $data_dir Optional data directory override.
     *                              Defaults to TMWSEO_ENGINE_DATA_DIR constant.
     */
    public function __construct( $data_dir = null ) {
        if ( $data_dir === null && defined( 'TMWSEO_ENGINE_DATA_DIR' ) ) {
            $data_dir = TMWSEO_ENGINE_DATA_DIR;
        }
        $this->data_dir = rtrim( (string) $data_dir, '/\\' );
    }

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Return one resolved section variant for a section key.
     *
     * Uses abs(crc32(post_id . category_slug . section_key)) % variant_count
     * for deterministic, stable selection that still varies per model+section.
     *
     * @param string $section_key   Section key from the JSON pool.
     * @param int    $post_id       Post ID (used only for deterministic selection).
     * @param array  $category_data Placeholder values for {{token}} resolution.
     * @return array|null           Resolved section array or null if unavailable.
     */
    public function get_section( $section_key, $post_id, array $category_data ) {
        $section_key = (string) $section_key;
        $sections    = $this->get_sections();

        if ( $section_key === '' || ! isset( $sections[ $section_key ] ) ) {
            return null;
        }

        $section  = $sections[ $section_key ];
        $label    = '';

        if ( is_array( $section ) && isset( $section['label'] ) ) {
            $label = $this->resolve( $section['label'], $category_data );
        }

        $variants = $this->extract_variants( $section );
        $count    = count( $variants );

        if ( $count < 1 ) {
            return null;
        }

        $slug    = (string) ( $category_data['category_slug'] ?? '' );
        $seed    = (string) $post_id . $slug . $section_key;
        $variant = $variants[ $this->pick_index_crc32( $seed, $count ) ];

        if ( is_array( $variant ) ) {
            $id   = isset( $variant['id'] )   ? (string) $variant['id']                             : '';
            $h2   = isset( $variant['h2'] )   ? $this->resolve( $variant['h2'],   $category_data ) : '';
            $body = isset( $variant['body'] ) ? $this->resolve( $variant['body'], $category_data ) : '';

            return [
                'key'     => $section_key,
                'id'      => $id,
                'label'   => $label,
                'h2'      => $h2,
                'body'    => $body,
                'content' => $body,
            ];
        }

        $body = $this->resolve( $variant, $category_data );

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
     * Return all resolved sections keyed by section key.
     *
     * @param int   $post_id       Post ID for deterministic selection.
     * @param array $category_data Placeholder values.
     * @return array<string, array>
     */
    public function get_all_sections( $post_id, array $category_data ) {
        $resolved = [];

        foreach ( $this->get_section_keys() as $section_key ) {
            $section = $this->get_section( $section_key, $post_id, $category_data );
            if ( $section !== null ) {
                $resolved[ $section_key ] = $section;
            }
        }

        return $resolved;
    }

    /**
     * Return resolved FAQs, taking one from each bucket before cycling.
     *
     * @param int   $post_id       Post ID for deterministic selection.
     * @param array $category_data Placeholder values.
     * @param int   $per_page      Maximum FAQ count (default 4).
     * @return array
     */
    public function get_faqs( $post_id, array $category_data, $per_page = 4 ) {
        $per_page = (int) $per_page;

        if ( $per_page < 1 ) {
            return [];
        }

        $buckets = $this->get_faq_buckets();

        if ( empty( $buckets ) ) {
            return [];
        }

        $faqs            = [];
        $bucket_position = 0;
        $slug            = (string) ( $category_data['category_slug'] ?? '' );

        foreach ( $buckets as $bucket_key => $bucket ) {
            if ( count( $faqs ) >= $per_page ) {
                break;
            }

            $questions = $this->extract_questions( $bucket );
            $count     = count( $questions );

            if ( $count < 1 ) {
                $bucket_position++;
                continue;
            }

            $seed     = (string) $post_id . $slug . $bucket_key . $bucket_position;
            $question = $questions[ $this->pick_index_crc32( $seed, $count ) ];
            $faq      = $this->normalize_faq( $bucket_key, $question, $category_data );

            if ( $faq !== null ) {
                $faqs[] = $faq;
            }

            $bucket_position++;
        }

        return $faqs;
    }

    /**
     * Return available section keys from the loaded pool.
     *
     * @return string[]
     */
    public function get_section_keys() {
        return array_keys( $this->get_sections() );
    }

    /**
     * Return available FAQ bucket keys from the loaded pool.
     *
     * @return string[]
     */
    public function get_faq_bucket_keys() {
        return array_keys( $this->get_faq_buckets() );
    }

    /**
     * Resolve {{placeholder}} tokens from supplied category data.
     *
     * Unknown placeholders are left as-is so has_unresolved_placeholders()
     * can detect them after resolution.
     *
     * @param mixed $template      Template string or scalar.
     * @param array $category_data Placeholder key→value map.
     * @return string
     */
    public function resolve( $template, array $category_data ) {
        if ( is_array( $template ) || is_object( $template ) ) {
            return '';
        }

        $template = (string) $template;

        if ( $template === '' ) {
            return '';
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_\-]+)\s*\}\}/',
            static function ( $matches ) use ( $category_data ) {
                $key = $matches[1];

                if ( ! array_key_exists( $key, $category_data ) ) {
                    return $matches[0]; // leave unresolved
                }

                if ( is_array( $category_data[ $key ] ) || is_object( $category_data[ $key ] ) ) {
                    return $matches[0]; // leave unresolved — value not scalar
                }

                return (string) $category_data[ $key ];
            },
            $template
        );
    }

    /**
     * Return true if the string contains any unresolved {{placeholder}} tokens.
     *
     * Used by the pool gate to validate output before writing.
     *
     * @param string $text
     * @return bool
     */
    public function has_unresolved_placeholders( $text ) {
        return (bool) preg_match( '/\{\{\s*[a-zA-Z0-9_\-]+\s*\}\}/', (string) $text );
    }

    // ── Protected helpers ────────────────────────────────────────────────────

    /**
     * Return decoded sections array.
     *
     * @return array
     */
    protected function get_sections() {
        $pool = $this->load_section_pool();
        return ( isset( $pool['sections'] ) && is_array( $pool['sections'] ) ) ? $pool['sections'] : [];
    }

    /**
     * Return decoded FAQ buckets array.
     *
     * @return array
     */
    protected function get_faq_buckets() {
        $pool = $this->load_faq_pool();
        return ( isset( $pool['buckets'] ) && is_array( $pool['buckets'] ) ) ? $pool['buckets'] : [];
    }

    /**
     * Load and cache section pool JSON.
     *
     * @return array
     */
    protected function load_section_pool() {
        if ( $this->section_pool === null ) {
            $this->section_pool = $this->load_json_file( 'category-section-templates.json' );
        }
        return $this->section_pool;
    }

    /**
     * Load and cache FAQ pool JSON.
     *
     * @return array
     */
    protected function load_faq_pool() {
        if ( $this->faq_pool === null ) {
            $this->faq_pool = $this->load_json_file( 'category-faq-pool.json' );
        }
        return $this->faq_pool;
    }

    /**
     * Load and decode a local JSON file from the configured data directory.
     *
     * Returns [] on any failure — never fatals.
     *
     * @param string $filename File name within the data directory.
     * @return array
     */
    protected function load_json_file( $filename ) {
        if ( $this->data_dir === '' ) {
            return [];
        }

        $path = $this->data_dir . DIRECTORY_SEPARATOR . basename( (string) $filename );

        if ( ! is_readable( $path ) ) {
            return [];
        }

        $raw = file_get_contents( $path );

        if ( $raw === false || $raw === '' ) {
            return [];
        }

        $decoded = json_decode( $raw, true );

        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * Extract section variants from supported pool shapes.
     *
     * @param mixed $section
     * @return array
     */
    protected function extract_variants( $section ) {
        if ( is_array( $section ) && isset( $section['variants'] ) && is_array( $section['variants'] ) ) {
            return array_values( $section['variants'] );
        }
        if ( is_array( $section ) ) {
            return array_values( $section );
        }
        return [];
    }

    /**
     * Extract FAQ questions from supported bucket shapes.
     *
     * @param mixed $bucket
     * @return array
     */
    protected function extract_questions( $bucket ) {
        if ( is_array( $bucket ) && isset( $bucket['questions'] ) && is_array( $bucket['questions'] ) ) {
            return array_values( $bucket['questions'] );
        }
        if ( is_array( $bucket ) ) {
            return array_values( $bucket );
        }
        return [];
    }

    /**
     * Normalize and resolve one FAQ item.
     *
     * @param string $bucket_key
     * @param mixed  $question
     * @param array  $category_data
     * @return array|null
     */
    protected function normalize_faq( $bucket_key, $question, array $category_data ) {
        if ( ! is_array( $question ) ) {
            return null;
        }

        $id = isset( $question['id'] ) ? (string) $question['id'] : '';
        $q  = (string) ( $question['q'] ?? $question['question'] ?? '' );
        $a  = (string) ( $question['a'] ?? $question['answer']   ?? '' );

        $q = $this->resolve( $q, $category_data );
        $a = $this->resolve( $a, $category_data );

        if ( $q === '' && $a === '' ) {
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

    /**
     * Pick a deterministic index using crc32 of a string seed.
     *
     * Using crc32 (same approach as Global Model Pool) gives stable variation
     * per category+section combination without modulo-by-zero risk.
     *
     * @param string $seed  Any string seed.
     * @param int    $count Available item count.
     * @return int
     */
    protected function pick_index_crc32( $seed, $count ) {
        $count = (int) $count;
        if ( $count < 1 ) {
            return 0;
        }
        return abs( crc32( (string) $seed ) ) % $count;
    }
}
