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
    private bool $has_opportunity = false;

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
        return [
            'cb'         => '<input type="checkbox" />',
            'keyword'    => __('Keyword', 'tmwseo'),
            'volume'     => __('Volume', 'tmwseo'),
            'difficulty' => __('KD', 'tmwseo'),
            'intent'     => __('Intent', 'tmwseo'),
            'status'     => __('Status', 'tmwseo'),
            'created_at' => __('Created', 'tmwseo'),
        ];
    }

    public function get_sortable_columns(): array {
        $columns = [
            'keyword'    => ['keyword', true],
            'volume'     => ['volume', false],
            'difficulty' => ['difficulty', false],
            'cpc'        => ['cpc', false],
            'intent'     => ['intent', false],
            'status'     => ['status', false],
            'created_at' => ['created_at', false],
        ];
        if ( $this->has_opportunity ) {
            $columns['opportunity'] = [ 'opportunity', false ];
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
        if ( in_array( $column_name, [ 'difficulty', 'cpc' ], true ) && ( $value === null || $value === '' || (float) $value <= 0.0 ) ) {
            return '&mdash;';
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

    public function prepare_items(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_keyword_candidates';
        $columns_info = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
        $column_names = array_map( static fn( $c ) => (string) ( $c['Field'] ?? '' ), (array) $columns_info );
        $this->has_page_type   = in_array( 'page_type', $column_names, true );
        $this->has_opportunity = in_array( 'opportunity', $column_names, true );

        $this->process_bulk_action();

        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [ $columns, $hidden, $sortable ];

        $allowed_orderby = [ 'keyword', 'volume', 'difficulty', 'kd', 'intent', 'status', 'cpc', 'created_at', 'updated_at' ];
        if ( $this->has_opportunity ) { $allowed_orderby[] = 'opportunity'; }
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
        $numeric_filters = [ 'min_volume', 'max_volume', 'min_kd', 'max_kd', 'min_cpc', 'max_cpc' ];
        foreach ( $numeric_filters as $key ) {
            if ( isset( $_GET[ $key ] ) && $_GET[ $key ] !== '' ) {
                $this->active_filters[ $key ] = is_numeric( $_GET[ $key ] ) ? (float) $_GET[ $key ] : '';
            }
        }
        foreach ( [ 'status', 'intent', 'orderby', 'order', 's' ] as $key ) {
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
        if ( isset( $this->active_filters['intent'] ) ) {
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
        if ( isset( $this->active_filters['min_cpc'] ) ) { $conditions[] = 'CAST(cpc AS DECIMAL(10,4)) >= %f'; $where_args[] = (float) $this->active_filters['min_cpc']; }
        if ( isset( $this->active_filters['max_cpc'] ) ) { $conditions[] = 'CAST(cpc AS DECIMAL(10,4)) <= %f'; $where_args[] = (float) $this->active_filters['max_cpc']; }
        if ( isset( $this->active_filters['has_cpc'] ) ) { $conditions[] = 'cpc IS NOT NULL AND cpc <> "" AND CAST(cpc AS DECIMAL(10,4)) > 0'; }

        $where_sql = $conditions !== [] ? ' WHERE ' . implode( ' AND ', $conditions ) : '';

        $total_sql   = "SELECT COUNT(*) FROM {$table}{$where_sql}";
        $total_items = $where_args === []
            ? (int) $wpdb->get_var( $total_sql )
            : (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $where_args ) );

        $per_page     = max( 1, (int) $this->get_items_per_page( 'tmw_keywords_per_page', 50 ) );
        $current_page = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
        $offset       = max( 0, ( $current_page - 1 ) * $per_page );

        $order_sql = match ( $orderby ) {
            'volume'      => 'CAST(volume AS UNSIGNED) ' . $order . ', created_at DESC',
            'difficulty'  => ( $order === 'ASC' ) ? 'CAST(difficulty AS DECIMAL(10,2)) ASC, CAST(volume AS UNSIGNED) DESC' : 'CAST(difficulty AS DECIMAL(10,2)) DESC',
            'cpc'         => 'CAST(cpc AS DECIMAL(10,4)) ' . $order,
            'opportunity' => $this->has_opportunity ? 'CAST(opportunity AS DECIMAL(10,4)) ' . $order . ', CAST(volume AS UNSIGNED) DESC, CAST(difficulty AS DECIMAL(10,2)) ASC' : 'CAST(volume AS UNSIGNED) DESC, CAST(difficulty AS DECIMAL(10,2)) ASC',
            'keyword'     => 'keyword ' . $order,
            'status'      => 'status ' . $order,
            'intent'      => 'intent ' . $order,
            'created_at'  => 'created_at ' . $order,
            'updated_at'  => 'updated_at ' . $order,
            default       => 'created_at DESC',
        };
        $sql         = "SELECT id, keyword, volume, difficulty, cpc, intent, status, created_at" . ( $this->has_opportunity ? ', opportunity' : '' ) . " FROM {$table}{$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d";
        $query_args  = $where_args;
        $query_args[] = $per_page;
        $query_args[] = $offset;

        $this->items = (array) $wpdb->get_results( $wpdb->prepare( $sql, $query_args ), ARRAY_A );
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
