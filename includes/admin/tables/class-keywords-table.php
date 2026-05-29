<?php
namespace TMWSEO\Engine\Admin\Tables;

if (!defined('ABSPATH')) { exit; }

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class KeywordsTable extends \WP_List_Table {

    /** @var string|null Status to filter to (null = all candidates) */
    private ?string $status_filter;

    /** @var string Current view slug — forwarded in pagination/search links */
    private string $current_view;
    /** @var array<string, string|int|float> */
    private array $active_filters = [];
    /** @var bool */
    private bool $has_page_type = false;
    /** @var bool */
    private bool $has_intent_type = false;
    /** @var bool */
    private bool $has_entity_type = false;
    /** @var bool */
    private bool $has_entity_id = false;
    /** @var bool */
    private bool $has_opportunity = false;
    /** @var bool */
    private bool $has_seo_score = false;
    /** @var bool */
    private bool $has_traffic_value = false;
    /** @var bool */
    private bool $has_cpc = false;
    /** @var bool */
    private bool $has_competition = false;
    /** @var bool */
    private bool $has_source = false;
    /** @var bool */
    private bool $has_sources = false;
    /** @var bool */
    private bool $has_notes = false;
    /** @var bool */
    private bool $has_updated_at = false;

    /**
     * @param string|null $status_filter  If set, WHERE status = this value.
     * @param string      $current_view   URL ?view= value — preserved in pagination.
     */
    public function __construct( ?string $status_filter = null, string $current_view = 'candidates' ) {
        parent::__construct([
            'singular' => 'keyword',
            'plural'   => 'keywords',
            'ajax'     => false,
        ]);
        $this->status_filter = $status_filter;
        $this->current_view  = $current_view;
    }

    public function get_columns(): array {
        $columns = [
            'cb'         => '<input type="checkbox" />',
            'keyword'    => __('Keyword', 'tmwseo'),
            'volume'     => __('Volume', 'tmwseo'),
            'difficulty' => __('KD', 'tmwseo'),
        ];

        if ( $this->has_cpc ) { $columns['cpc'] = __('CPC', 'tmwseo'); }
        if ( $this->has_competition ) { $columns['competition'] = __('Competition', 'tmwseo'); }
        if ( $this->has_seo_score ) { $columns['seo_score'] = __('SEO Score', 'tmwseo'); }
        if ( $this->has_opportunity ) { $columns['opportunity'] = __('Opportunity Score', 'tmwseo'); }
        if ( $this->has_traffic_value ) { $columns['traffic_value'] = __('Traffic Value', 'tmwseo'); }

        $columns[ $this->has_intent_type ? 'intent_type' : 'intent' ] = __('Intent', 'tmwseo');
        if ( $this->has_entity_type ) { $columns['entity_type'] = __('Entity Type', 'tmwseo'); }
        if ( $this->has_entity_id ) { $columns['entity_id'] = __('Entity ID', 'tmwseo'); }
        $columns['status'] = __('Status', 'tmwseo');
        if ( $this->has_sources || $this->has_notes ) {
            $columns['model_keyword_owner'] = __('Model Owner', 'tmwseo');
            $columns['model_keyword_usage_scope'] = __('Usage Scope', 'tmwseo');
            $columns['model_keyword_primary'] = __('Primary?', 'tmwseo');
            $columns['model_keyword_strategy'] = __('Strategy', 'tmwseo');
            $columns['model_keyword_recommended_action'] = __('Recommended Action', 'tmwseo');
            $columns['model_keyword_provenance'] = __('Provenance', 'tmwseo');
            $columns['model_entity_link_status'] = __('Entity Link Status', 'tmwseo');
        }
        if ( $this->has_sources ) { $columns['sources'] = __('Sources', 'tmwseo'); }
        elseif ( $this->has_source ) { $columns['source'] = __('Source', 'tmwseo'); }
        $columns[ $this->has_updated_at ? 'updated_at' : 'created_at' ] = $this->has_updated_at ? __('Updated', 'tmwseo') : __('Created', 'tmwseo');

        return $columns;
    }

    public function get_sortable_columns(): array {
        $columns = [
            'keyword'    => ['keyword', true],
            'volume'     => ['volume', false],
            'difficulty' => ['difficulty', false],
            'intent'     => ['intent', false],
            'status'     => ['status', false],
            'created_at' => ['created_at', false],
        ];
        if ( $this->has_intent_type ) {
            $columns['intent_type'] = [ 'intent_type', false ];
        }
        if ( $this->has_updated_at ) {
            $columns['updated_at'] = [ 'updated_at', false ];
        }
        if ( $this->has_cpc ) {
            $columns['cpc'] = [ 'cpc', false ];
        }
        if ( $this->has_competition ) {
            $columns['competition'] = [ 'competition', false ];
        }
        if ( $this->has_seo_score ) {
            $columns['seo_score'] = [ 'seo_score', false ];
        }
        if ( $this->has_opportunity ) {
            $columns['opportunity'] = [ 'opportunity', false ];
        }
        if ( $this->has_traffic_value ) {
            $columns['traffic_value'] = [ 'traffic_value', false ];
        }
        return $columns;
    }

    protected function get_bulk_actions(): array {
        return [
            'tmwseo_kw_bulk_approve' => __( 'Approve', 'tmwseo' ),
            'tmwseo_kw_bulk_reject'  => __( 'Reject', 'tmwseo' ),
            'tmwseo_kw_bulk_delete'  => __( 'Delete', 'tmwseo' ),
        ];
    }

    protected function column_cb($item): string {
        return sprintf('<input type="checkbox" name="keyword_ids[]" value="%d" />', (int) ($item['id'] ?? 0));
    }


    public function column_keyword($item): string {
        $candidate_id = (int) ($item['id'] ?? 0);
        $keyword = (string) ($item['keyword'] ?? '');

        $view_args = $this->current_view !== '' ? [ 'view' => $this->current_view ] : [];

        $inspect_url = add_query_arg(
            array_merge( $view_args, [
                'page'                    => 'tmwseo-keywords',
                'tmwseo_candidate_focus'  => $candidate_id,
            ] ),
            admin_url( 'admin.php' )
        );

        $approve_url = wp_nonce_url( add_query_arg( [
            'action'           => 'tmwseo_keyword_candidate_action',
            'candidate_id'     => $candidate_id,
            'candidate_action' => 'approve',
        ], admin_url( 'admin-post.php' ) ), 'tmwseo_keyword_candidate_action_' . $candidate_id );

        $reject_url = wp_nonce_url( add_query_arg( [
            'action'           => 'tmwseo_keyword_candidate_action',
            'candidate_id'     => $candidate_id,
            'candidate_action' => 'reject',
        ], admin_url( 'admin-post.php' ) ), 'tmwseo_keyword_candidate_action_' . $candidate_id );

        $actions = [
            'inspect' => '<a href="' . esc_url( $inspect_url ) . '">' . esc_html__( 'Inspect', 'tmwseo' ) . '</a>',
            'approve' => '<a href="' . esc_url( $approve_url ) . '">' . esc_html__( 'Approve', 'tmwseo' ) . '</a>',
            'reject'  => '<a href="' . esc_url( $reject_url ) . '">' . esc_html__( 'Reject', 'tmwseo' ) . '</a>',
            'copy'    => '<button type="button" class="button-link" data-tmw-copy-keyword="' . esc_attr( $keyword ) . '">' . esc_html__( 'Copy', 'tmwseo' ) . '</button>',
        ];

        return '<span id="tmw-candidate-' . esc_attr( (string) $candidate_id ) . '">' . esc_html( $keyword ) . '</span>' . $this->row_actions( $actions, false );
    }

    public function column_default($item, $column_name): string {
        $value = $item[$column_name] ?? '';
        if ( $column_name === 'volume' ) {
            $volume = (int) $value;
            $style  = $volume > 0
                ? 'display:inline-block;padding:2px 8px;border-radius:999px;background:#dcfce7;color:#166534;font-weight:600;'
                : 'display:inline-block;padding:2px 8px;border-radius:999px;background:#f3f4f6;color:#6b7280;font-weight:600;';
            return '<span style="' . esc_attr( $style ) . '">' . esc_html( (string) $volume ) . '</span>';
        }
        if ( in_array( $column_name, [ 'difficulty', 'cpc', 'competition', 'opportunity', 'seo_score', 'traffic_value' ], true ) && ( $value === null || $value === '' || (float) $value <= 0.0 ) ) {
            return '&mdash;';
        }
        if ( in_array( $column_name, [ 'cpc', 'traffic_value' ], true ) ) {
            return esc_html( number_format( (float) $value, 2 ) );
        }
        if ( in_array( $column_name, [ 'difficulty', 'competition', 'opportunity', 'seo_score' ], true ) ) {
            return esc_html( rtrim( rtrim( number_format( (float) $value, 4 ), '0' ), '.' ) );
        }
        if ( in_array( $column_name, [ 'model_keyword_owner', 'model_keyword_usage_scope', 'model_keyword_primary', 'model_keyword_strategy', 'model_keyword_recommended_action', 'model_keyword_provenance', 'model_entity_link_status' ], true ) ) {
            return $this->render_model_metadata_column( is_array($item) ? $item : [], $column_name );
        }
        if ( in_array( $column_name, [ 'sources', 'source' ], true ) ) {
            $source_text = $this->source_label_from_item( is_array($item) ? $item : [], is_string( $value ) ? $value : '' );
            return '' === $source_text ? '&mdash;' : '<code>' . esc_html( $source_text ) . '</code>';
        }
        if ($column_name === 'status') {
            $status = strtolower((string) $value);
            $styles = [
                'approved' => 'display:inline-block;padding:2px 8px;border-radius:999px;background:#dcfce7;color:#166534;font-weight:600;',
                'ignored'  => 'display:inline-block;padding:2px 8px;border-radius:999px;background:#fee2e2;color:#991b1b;font-weight:600;',
                'new'      => 'display:inline-block;padding:2px 8px;border-radius:999px;background:#dbeafe;color:#1e40af;font-weight:600;',
            ];
            $style = $styles[$status] ?? 'display:inline-block;padding:2px 8px;border-radius:999px;background:#f3f4f6;color:#374151;font-weight:600;';
            return '<span style="' . esc_attr($style) . '">' . esc_html((string) $value) . '</span>';
        }

        return esc_html((string) $value);
    }

    /** @param array<string, mixed> $item */
    private function render_model_metadata_column(array $item, string $column_name): string {
        $metadata = $this->model_keyword_metadata_from_item($item);
        if ('model_entity_link_status' === $column_name) {
            $status = (string) ($metadata['entity_link_status'] ?? '');
            if ('unresolved' === $status) {
                return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#fef3c7;color:#92400e;font-weight:600;">' . esc_html__('Unlinked model keyword', 'tmwseo') . '</span>';
            }
            if ('ambiguous' === $status) {
                return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#fee2e2;color:#991b1b;font-weight:600;">' . esc_html__('Ambiguous', 'tmwseo') . '</span>';
            }
            if ('linked' === $status) {
                return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:#dcfce7;color:#166534;font-weight:600;">' . esc_html__('linked', 'tmwseo') . '</span>';
            }
            return '&mdash;';
        }

        $map = [
            'model_keyword_owner' => 'model_keyword_owner',
            'model_keyword_usage_scope' => 'model_keyword_usage_scope',
            'model_keyword_primary' => 'model_keyword_primary_candidate',
            'model_keyword_strategy' => 'model_keyword_strategy',
            'model_keyword_recommended_action' => 'model_keyword_recommended_action',
            'model_keyword_provenance' => 'provenance',
        ];
        $key = $map[$column_name] ?? '';
        $value = $key !== '' ? (string) ($metadata[$key] ?? '') : '';
        return '' === $value ? '&mdash;' : '<code>' . esc_html($value) . '</code>';
    }

    /** @param array<string, mixed> $item @return array<string,string> */
    private function model_keyword_metadata_from_item(array $item): array {
        $metadata = [];
        foreach ([ 'sources', 'notes' ] as $field) {
            $payload = $this->decode_json_field($item[$field] ?? null);
            $metadata = array_merge($metadata, $this->find_model_keyword_metadata($payload));
        }
        if ('' === (string) ($metadata['model_keyword_strategy'] ?? '')) {
            $metadata['model_keyword_strategy'] = $this->model_keyword_strategy_from_item($item);
        }
        $intent = strtolower((string) ($item['intent_type'] ?? $item['intent'] ?? ''));
        $status = strtolower((string) ($item['status'] ?? ''));
        $entity_type = strtolower((string) ($item['entity_type'] ?? ''));
        $entity_id = (int) ($item['entity_id'] ?? 0);
        $match_type = (string) ($metadata['model_entity_match_type'] ?? '');
        if ('ambiguous' === $match_type) {
            $metadata['entity_link_status'] = 'ambiguous';
        } elseif ('model' === $intent && 'model' === $entity_type && $entity_id > 0) {
            $metadata['entity_link_status'] = 'linked';
        } elseif ('model' === $intent && 'approved' === $status && 'model' === $entity_type && 0 === $entity_id) {
            $metadata['entity_link_status'] = 'unresolved';
        } elseif ('model' === $intent && 0 === $entity_id && '' !== (string) ($metadata['model_keyword_owner'] ?? '')) {
            $metadata['entity_link_status'] = 'unresolved';
        }
        return $metadata;
    }

    /** @param array<string, mixed> $payload @return array<string,string> */
    private function find_model_keyword_metadata(array $payload): array {
        $metadata = [];
        foreach ([ 'model_keyword_owner', 'model_keyword_usage_scope', 'model_keyword_primary_candidate', 'model_keyword_strategy', 'model_keyword_recommended_action', 'model_entity_match_type' ] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && '' !== (string) $payload[$key]) {
                $metadata[$key] = (string) $payload[$key];
            }
        }
        if (!empty($payload['personal_model_keyword_csv'])) {
            $metadata['provenance'] = 'personal_model_keyword_csv';
        } elseif (isset($payload['upload_source']) && is_scalar($payload['upload_source']) && '' !== (string) $payload['upload_source']) {
            $metadata['provenance'] = (string) $payload['upload_source'];
        }
        if (isset($payload['model_entity_resolution']) && is_array($payload['model_entity_resolution']) && isset($payload['model_entity_resolution']['match_type'])) {
            $metadata['model_entity_match_type'] = (string) $payload['model_entity_resolution']['match_type'];
        }
        foreach ([ 'keyword_pools_import', 'keyword_pools_import_history' ] as $key) {
            $nested = $payload[$key] ?? null;
            if (!is_array($nested)) { continue; }
            if ($this->is_list_array($nested)) {
                foreach ($nested as $entry) {
                    if (is_array($entry)) { $metadata = array_merge($this->find_model_keyword_metadata($entry), $metadata); }
                }
            } else {
                $metadata = array_merge($this->find_model_keyword_metadata($nested), $metadata);
            }
        }
        return $metadata;
    }

    /** @param array<string, mixed> $item */
    private function source_label_from_item(array $item, string $fallback): string {
        $metadata = $this->model_keyword_metadata_from_item($item);
        if ('' !== (string) ($metadata['provenance'] ?? '')) {
            return (string) $metadata['provenance'];
        }
        foreach ([ 'sources', 'notes' ] as $field) {
            $payload = $this->decode_json_field($item[$field] ?? null);
            foreach ([ 'upload_source', 'parser_source_label', 'source' ] as $key) {
                if (isset($payload[$key]) && is_scalar($payload[$key]) && '' !== (string) $payload[$key]) {
                    return (string) $payload[$key];
                }
            }
        }
        if (strlen($fallback) > 80) { return substr($fallback, 0, 77) . '...'; }
        return $fallback;
    }

    private function escaped_like_contains($wpdb, string $literal): string {
        return '%' . $wpdb->esc_like($literal) . '%';
    }

    /** @param array<string, mixed> $item */
    private function model_keyword_strategy_from_item(array $item): string {
        $intent = strtolower((string) ($item['intent_type'] ?? $item['intent'] ?? ''));
        if ('model' !== $intent) {
            return '';
        }

        foreach ([ 'sources', 'notes' ] as $field) {
            $payload = $this->decode_json_field($item[$field] ?? null);
            $strategy = $this->find_model_keyword_strategy($payload);
            if ('' !== $strategy) {
                return $strategy;
            }
        }

        return '';
    }

    /** @param mixed $value @return array<string, mixed> */
    private function decode_json_field($value): array {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || '' === trim($value)) {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<mixed> $value */
    private function is_list_array(array $value): bool {
        return array_keys($value) === range(0, count($value) - 1);
    }

    /** @param array<string, mixed> $payload */
    private function find_model_keyword_strategy(array $payload): string {
        $strategy = (string) ($payload['model_keyword_strategy'] ?? '');
        if ('' !== $strategy) {
            return $strategy;
        }
        foreach ([ 'keyword_pools_import', 'keyword_pools_import_history' ] as $key) {
            $nested = $payload[$key] ?? null;
            if (!is_array($nested)) {
                continue;
            }
            if ($this->is_list_array($nested)) {
                foreach ($nested as $entry) {
                    if (is_array($entry)) {
                        $strategy = $this->find_model_keyword_strategy($entry);
                        if ('' !== $strategy) { return $strategy; }
                    }
                }
            } else {
                $strategy = $this->find_model_keyword_strategy($nested);
                if ('' !== $strategy) { return $strategy; }
            }
        }
        return '';
    }

    public function prepare_items(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        $columns_info = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
        $column_names = array_map( static fn( $c ) => (string) ( $c['Field'] ?? '' ), (array) $columns_info );
        $has_created_at         = in_array( 'created_at', $column_names, true );
        $has_updated_at         = in_array( 'updated_at', $column_names, true );
        $this->has_page_type     = in_array( 'page_type', $column_names, true );
        $this->has_intent_type   = in_array( 'intent_type', $column_names, true );
        $this->has_entity_type   = in_array( 'entity_type', $column_names, true );
        $this->has_entity_id     = in_array( 'entity_id', $column_names, true );
        $this->has_opportunity   = in_array( 'opportunity', $column_names, true );
        $this->has_seo_score     = in_array( 'seo_score', $column_names, true );
        $this->has_traffic_value = in_array( 'traffic_value', $column_names, true );
        $this->has_cpc           = in_array( 'cpc', $column_names, true );
        $this->has_competition   = in_array( 'competition', $column_names, true );
        $this->has_source        = in_array( 'source', $column_names, true );
        $this->has_sources       = in_array( 'sources', $column_names, true );
        $this->has_notes         = in_array( 'notes', $column_names, true );
        $this->has_updated_at    = $has_updated_at;

        $date_column = 'id';
        if ( $has_created_at ) {
            $date_column = 'created_at';
        } elseif ( $has_updated_at ) {
            $date_column = 'updated_at';
        }

        $this->process_bulk_action();

        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [ $columns, $hidden, $sortable ];

        $allowed_orderby = [ 'keyword', 'volume', 'difficulty', 'kd', 'intent', 'status', 'created_at', 'updated_at' ];
        if ( $this->has_cpc ) { $allowed_orderby[] = 'cpc'; }
        if ( $this->has_competition ) { $allowed_orderby[] = 'competition'; }
        if ( $this->has_seo_score ) { $allowed_orderby[] = 'seo_score'; }
        if ( $this->has_opportunity ) { $allowed_orderby[] = 'opportunity'; }
        if ( $this->has_traffic_value ) { $allowed_orderby[] = 'traffic_value'; }
        $orderby = isset( $_GET['orderby'] ) ? sanitize_key( (string) $_GET['orderby'] ) : 'created_at';
        if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
            $orderby = 'created_at';
        }
        if ( $orderby === 'kd' ) { $orderby = 'difficulty'; }

        $order = isset( $_GET['order'] ) ? strtoupper( sanitize_key( (string) $_GET['order'] ) ) : 'DESC';
        if ( ! in_array( $order, [ 'ASC', 'DESC' ], true ) ) {
            $order = 'DESC';
        }

        $search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : '';
        $numeric_filters = [ 'min_volume', 'max_volume', 'min_kd', 'max_kd', 'min_cpc', 'max_cpc', 'max_competition', 'min_opportunity', 'min_seo_score' ];
        foreach ( $numeric_filters as $key ) {
            if ( isset( $_GET[ $key ] ) && $_GET[ $key ] !== '' ) {
                $this->active_filters[ $key ] = is_numeric( $_GET[ $key ] ) ? (float) $_GET[ $key ] : '';
            }
        }
        foreach ( [ 'status', 'intent', 'intent_type', 'orderby', 'order', 's', 'model_keyword_filter' ] as $key ) {
            if ( isset( $_GET[ $key ] ) && $_GET[ $key ] !== '' ) { $this->active_filters[ $key ] = sanitize_text_field( (string) $_GET[ $key ] ); }
        }
        foreach ( [ 'hide_zero_volume', 'has_volume', 'has_kd', 'has_cpc' ] as $key ) {
            if ( isset( $_GET[ $key ] ) && (string) $_GET[ $key ] === '1' ) { $this->active_filters[ $key ] = 1; }
        }
        if ( $this->has_page_type && isset( $_GET['page_type'] ) && $_GET['page_type'] !== '' ) {
            $this->active_filters['page_type'] = sanitize_text_field( (string) $_GET['page_type'] );
        }

        // Build WHERE conditions
        $conditions = [];
        $where_args = [];

        if ( $this->status_filter !== null ) {
            $conditions[] = 'status = %s';
            $where_args[] = $this->status_filter;
        }

        if ( $search !== '' ) {
            $conditions[] = 'keyword LIKE %s';
            $where_args[] = '%' . $wpdb->esc_like( $search ) . '%';
        }
        if ( isset( $this->active_filters['status'] ) && $this->status_filter === null ) {
            $conditions[] = 'status = %s';
            $where_args[] = (string) $this->active_filters['status'];
        }
        if ( $this->has_intent_type && isset( $this->active_filters['intent_type'] ) ) {
            $conditions[] = 'intent_type = %s';
            $where_args[] = (string) $this->active_filters['intent_type'];
        } elseif ( isset( $this->active_filters['intent'] ) ) {
            $conditions[] = 'intent = %s';
            $where_args[] = (string) $this->active_filters['intent'];
        }
        if ( $this->has_page_type && isset( $this->active_filters['page_type'] ) ) {
            $conditions[] = 'page_type = %s';
            $where_args[] = (string) $this->active_filters['page_type'];
        }
        if ( isset( $this->active_filters['min_volume'] ) && (float) $this->active_filters['min_volume'] >= 1 ) { $conditions[] = 'CAST(volume AS UNSIGNED) > 0'; }
        if ( isset( $this->active_filters['max_volume'] ) ) { $conditions[] = 'CAST(volume AS UNSIGNED) <= %d'; $where_args[] = (int) $this->active_filters['max_volume']; }
        if ( isset( $this->active_filters['hide_zero_volume'] ) || isset( $this->active_filters['has_volume'] ) ) { $conditions[] = 'CAST(volume AS UNSIGNED) > 0'; }
        if ( isset( $this->active_filters['min_kd'] ) ) { $conditions[] = 'CAST(difficulty AS DECIMAL(10,2)) >= %f'; $where_args[] = (float) $this->active_filters['min_kd']; }
        if ( isset( $this->active_filters['max_kd'] ) ) { $conditions[] = '(difficulty IS NULL OR difficulty = "" OR CAST(difficulty AS DECIMAL(10,2)) <= %f)'; $where_args[] = (float) $this->active_filters['max_kd']; }
        if ( isset( $this->active_filters['has_kd'] ) ) { $conditions[] = 'difficulty IS NOT NULL AND difficulty <> "" AND CAST(difficulty AS DECIMAL(10,2)) > 0'; }
        if ( $this->has_cpc && isset( $this->active_filters['min_cpc'] ) ) { $conditions[] = 'CAST(cpc AS DECIMAL(10,4)) >= %f'; $where_args[] = (float) $this->active_filters['min_cpc']; }
        if ( $this->has_cpc && isset( $this->active_filters['max_cpc'] ) ) { $conditions[] = 'CAST(cpc AS DECIMAL(10,4)) <= %f'; $where_args[] = (float) $this->active_filters['max_cpc']; }
        if ( $this->has_cpc && isset( $this->active_filters['has_cpc'] ) ) { $conditions[] = 'cpc IS NOT NULL AND cpc <> "" AND CAST(cpc AS DECIMAL(10,4)) > 0'; }
        if ( $this->has_competition && isset( $this->active_filters['max_competition'] ) ) { $conditions[] = 'CAST(competition AS DECIMAL(10,4)) <= %f'; $where_args[] = (float) $this->active_filters['max_competition']; }
        if ( $this->has_opportunity && isset( $this->active_filters['min_opportunity'] ) ) { $conditions[] = 'CAST(opportunity AS DECIMAL(10,4)) >= %f'; $where_args[] = (float) $this->active_filters['min_opportunity']; }
        if ( $this->has_seo_score && isset( $this->active_filters['min_seo_score'] ) ) { $conditions[] = 'CAST(seo_score AS DECIMAL(10,2)) >= %f'; $where_args[] = (float) $this->active_filters['min_seo_score']; }
        if ( isset( $this->active_filters['model_keyword_filter'] ) ) {
            $filter = (string) $this->active_filters['model_keyword_filter'];
            if ( 'personal_model_csv' === $filter && ( $this->has_sources || $this->has_notes ) ) {
                $personal_csv_like = $this->escaped_like_contains($wpdb, 'personal_model_keyword_csv');
                $likes = [];
                if ( $this->has_sources ) { $likes[] = 'sources LIKE %s'; $where_args[] = $personal_csv_like; }
                if ( $this->has_notes ) { $likes[] = 'notes LIKE %s'; $where_args[] = $personal_csv_like; }
                if ( $likes !== [] ) { $conditions[] = '(' . implode( ' OR ', $likes ) . ')'; }
            } elseif ( 'primary_model_bio' === $filter && ( $this->has_sources || $this->has_notes ) ) {
                $primary_candidate_like = $this->escaped_like_contains($wpdb, '"model_keyword_primary_candidate":"yes"');
                $scope_like = $this->escaped_like_contains($wpdb, '"model_keyword_usage_scope":"model_bio_only"');
                $likes = [];
                if ( $this->has_sources ) { $likes[] = '(sources LIKE %s AND sources LIKE %s)'; $where_args[] = $primary_candidate_like; $where_args[] = $scope_like; }
                if ( $this->has_notes ) { $likes[] = '(notes LIKE %s AND notes LIKE %s)'; $where_args[] = $primary_candidate_like; $where_args[] = $scope_like; }
                if ( $likes !== [] ) { $conditions[] = '(' . implode( ' OR ', $likes ) . ')'; }
            } elseif ( 'unlinked_model' === $filter && $this->has_intent_type && $this->has_entity_type && $this->has_entity_id ) {
                $conditions[] = 'intent_type = %s AND entity_type = %s AND entity_id = 0';
                $where_args[] = 'model';
                $where_args[] = 'model';
            }
        }

        $where_sql = $conditions !== [] ? ' WHERE ' . implode( ' AND ', $conditions ) : '';

        $total_sql   = "SELECT COUNT(*) FROM {$table}{$where_sql}";
        $total_items = $where_args === []
            ? (int) $wpdb->get_var( $total_sql )
            : (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $where_args ) );

        $per_page     = max( 1, (int) $this->get_items_per_page( 'tmw_keywords_per_page', 50 ) );
        $current_page = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
        $offset       = max( 0, ( $current_page - 1 ) * $per_page );

        $order_sql = match ( $orderby ) {
            'volume'      => 'CAST(volume AS UNSIGNED) ' . $order . ', ' . $date_column . ' DESC',
            'difficulty'  => ( $order === 'ASC' ) ? 'CAST(difficulty AS DECIMAL(10,2)) ASC, CAST(volume AS UNSIGNED) DESC' : 'CAST(difficulty AS DECIMAL(10,2)) DESC',
            'cpc'           => $this->has_cpc ? 'CAST(cpc AS DECIMAL(10,4)) ' . $order : $date_column . ' ' . $order,
            'competition'   => $this->has_competition ? 'CAST(competition AS DECIMAL(10,4)) ' . $order . ', CAST(volume AS UNSIGNED) DESC' : $date_column . ' ' . $order,
            'seo_score'     => $this->has_seo_score ? 'CAST(seo_score AS DECIMAL(10,2)) ' . $order . ', CAST(volume AS UNSIGNED) DESC' : $date_column . ' ' . $order,
            'opportunity'   => $this->has_opportunity ? 'CAST(opportunity AS DECIMAL(10,4)) ' . $order . ', CAST(volume AS UNSIGNED) DESC, CAST(difficulty AS DECIMAL(10,2)) ASC' : 'CAST(volume AS UNSIGNED) DESC, CAST(difficulty AS DECIMAL(10,2)) ASC',
            'traffic_value' => $this->has_traffic_value ? 'CAST(traffic_value AS DECIMAL(14,2)) ' . $order . ', CAST(volume AS UNSIGNED) DESC' : $date_column . ' ' . $order,
            'keyword'     => 'keyword ' . $order,
            'status'      => 'status ' . $order,
            'intent'      => 'intent ' . $order,
            'intent_type' => $this->has_intent_type ? 'intent_type ' . $order : 'intent ' . $order,
            'created_at'  => $date_column . ' ' . $order,
            'updated_at'  => $has_updated_at ? 'updated_at ' . $order : $date_column . ' ' . $order,
            default       => $date_column . ' DESC',
        };

        $select_columns = [ 'id', 'keyword', 'volume', 'difficulty', 'intent', 'status' ];
        foreach ( [ 'intent_type', 'entity_type', 'entity_id', 'cpc', 'competition', 'seo_score', 'opportunity', 'traffic_value', 'source', 'sources', 'notes' ] as $optional_column ) {
            if ( in_array( $optional_column, $column_names, true ) ) {
                $select_columns[] = $optional_column;
            }
        }
        if ( $has_created_at ) {
            $select_columns[] = 'created_at';
        } elseif ( $has_updated_at ) {
            $select_columns[] = 'updated_at AS created_at';
        } else {
            $select_columns[] = 'id AS created_at';
        }
        if ( $has_updated_at ) {
            $select_columns[] = 'updated_at';
        }

        $sql          = 'SELECT ' . implode( ', ', $select_columns ) . " FROM {$table}{$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d";
        $query_args  = $where_args;
        $query_args[] = $per_page;
        $query_args[] = $offset;

        $this->items = (array) $wpdb->get_results( $wpdb->prepare( $sql, $query_args ), ARRAY_A );
        if ( ! empty( $wpdb->last_error ) ) {
            error_log( '[TMW-KW-FILTERS] Keyword table query failed: ' . wp_json_encode( [
                'last_error'     => $wpdb->last_error,
                'orderby'        => $orderby,
                'order'          => $order,
                'date_column'    => $date_column,
                'where_sql'      => $where_sql,
                'active_filters' => $this->active_filters,
            ] ) );
        }
        error_log( 'TMW keywords fetched: ' . count( $this->items ) );

        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( $total_items / $per_page ),
        ] );
    }

    public function get_active_filters(): array { return $this->active_filters; }

    protected function process_bulk_action(): void {
        // Primary bulk handling is via AdminFormHandlers::handle_keyword_candidates_bulk()
        // triggered from the load-tmwseo-engine_page_tmwseo-keywords hook before headers
        // are sent, enabling a clean redirect.  This method is a safety fallback only —
        // it runs only if the dedicated handler was not reached (should not happen in
        // normal operation, but guards against edge cases).
        global $wpdb;
        $table  = $wpdb->prefix . 'tmw_keyword_candidates';
        $action = $this->current_action();

        $action_map = [
            'tmwseo_kw_bulk_approve' => 'approved',
            'tmwseo_kw_bulk_reject'  => 'ignored',
        ];

        if ( ! $action || ( ! isset( $action_map[ $action ] ) && $action !== 'tmwseo_kw_bulk_delete' ) ) {
            return;
        }

        check_admin_referer( 'bulk-keywords' );

        $ids = isset( $_POST['keyword_ids'] ) ? (array) $_POST['keyword_ids'] : [];
        $ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
        if ( $ids === [] ) {
            return;
        }

        foreach ( $ids as $id ) {
            if ( isset( $action_map[ $action ] ) ) {
                $wpdb->update( $table, [ 'status' => $action_map[ $action ] ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
            } elseif ( $action === 'tmwseo_kw_bulk_delete' ) {
                $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
            }
        }
    }
}
