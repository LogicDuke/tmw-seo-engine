<?php
/**
 * TMW SEO Engine — Keyword Clusters List Table
 *
 * Reads directly from tmw_keyword_clusters (the keyword-clustering dataset).
 * This is NOT the legacy internal-link cluster table (tmw_clusters).
 *
 * Used exclusively by the Keywords admin page (view=clusters).
 *
 * @package TMWSEO\Engine\Admin\Tables
 * @since   5.3.0
 */
namespace TMWSEO\Engine\Admin\Tables;

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class KeywordClustersTable extends \WP_List_Table {

    public function __construct() {
        parent::__construct( [
            'singular' => 'keyword_cluster',
            'plural'   => 'keyword_clusters',
            'ajax'     => false,
        ] );
    }

    public function get_columns(): array {
        return [
            'representative'  => __( 'Cluster Label', 'tmwseo' ),
            'cluster_key'     => __( 'Key', 'tmwseo' ),
            'status'          => __( 'Status', 'tmwseo' ),
            'total_volume'    => __( 'Volume', 'tmwseo' ),
            'avg_difficulty'  => __( 'Avg KD', 'tmwseo' ),
            'opportunity'     => __( 'Opportunity', 'tmwseo' ),
            'page_id'         => __( 'Page', 'tmwseo' ),
            'updated_at'      => __( 'Updated', 'tmwseo' ),
        ];
    }

    public function get_sortable_columns(): array {
        return [
            'representative' => [ 'representative', true ],
            'total_volume'   => [ 'total_volume', false ],
            'avg_difficulty' => [ 'avg_difficulty', false ],
            'opportunity'    => [ 'opportunity', false ],
            'updated_at'     => [ 'updated_at', false ],
        ];
    }

    public function column_default( $item, $column_name ): string {
        $value = $item[ $column_name ] ?? '';

        switch ( $column_name ) {
            case 'representative':
                $key = esc_html( (string) ( $item['cluster_key'] ?? '' ) );
                return '<strong>' . esc_html( (string) $value ) . '</strong>'
                    . ( $key !== '' ? '<br><span style="font-size:11px;color:#9ca3af;">' . $key . '</span>' : '' );

            case 'cluster_key':
                return '<code style="font-size:11px;">' . esc_html( (string) $value ) . '</code>';

            case 'status':
                $status = strtolower( (string) $value );
                $styles = [
                    'new'      => 'background:#dbeafe;color:#1e40af;',
                    'built'    => 'background:#dcfce7;color:#166534;',
                    'archived' => 'background:#f3f4f6;color:#6b7280;',
                ];
                $style = $styles[ $status ] ?? 'background:#f3f4f6;color:#374151;';
                return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-weight:600;font-size:11px;' . esc_attr( $style ) . '">' . esc_html( (string) $value ) . '</span>';

            case 'total_volume':
                return $value !== null && $value !== ''
                    ? esc_html( number_format_i18n( (int) $value ) )
                    : '—';

            case 'avg_difficulty':
                return $value !== null && $value !== ''
                    ? esc_html( number_format( (float) $value, 1 ) )
                    : '—';

            case 'opportunity':
                return $value !== null && $value !== ''
                    ? esc_html( number_format( (float) $value, 2 ) )
                    : '—';

            case 'page_id':
                $page_id = (int) $value;
                if ( $page_id <= 0 ) {
                    return '<span style="color:#9ca3af;">—</span>';
                }
                $edit_url = get_edit_post_link( $page_id );
                $title    = get_the_title( $page_id ) ?: ( 'Post #' . $page_id );
                return $edit_url
                    ? '<a href="' . esc_url( $edit_url ) . '" style="font-size:12px;">' . esc_html( $title ) . '</a>'
                    : esc_html( (string) $page_id );

            case 'updated_at':
                $dt = (string) $value;
                return $dt !== ''
                    ? '<span style="font-size:12px;color:#6b7280;">' . esc_html( substr( $dt, 0, 16 ) ) . '</span>'
                    : '—';

            default:
                return esc_html( (string) $value );
        }
    }

    public function prepare_items(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_keyword_clusters';

        $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];

        // Sorting
        $allowed_orderby = [ 'representative', 'cluster_key', 'status', 'total_volume', 'avg_difficulty', 'opportunity', 'updated_at' ];
        $orderby = isset( $_GET['orderby'] ) ? sanitize_key( (string) $_GET['orderby'] ) : 'opportunity';
        if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
            $orderby = 'opportunity';
        }
        $order = isset( $_GET['order'] ) ? strtoupper( sanitize_key( (string) $_GET['order'] ) ) : 'DESC';
        if ( ! in_array( $order, [ 'ASC', 'DESC' ], true ) ) {
            $order = 'DESC';
        }

        // Search
        $search     = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : '';
        $conditions = [];
        $where_args = [];

        if ( $search !== '' ) {
            $conditions[] = '(representative LIKE %s OR cluster_key LIKE %s)';
            $like         = '%' . $wpdb->esc_like( $search ) . '%';
            $where_args[] = $like;
            $where_args[] = $like;
        }

        $where_sql = $conditions !== [] ? ' WHERE ' . implode( ' AND ', $conditions ) : '';

        // Count
        $count_sql   = "SELECT COUNT(*) FROM {$table}{$where_sql}";
        $total_items = $where_args === []
            ? (int) $wpdb->get_var( $count_sql )
            : (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $where_args ) );

        // Pagination
        $per_page     = max( 1, (int) $this->get_items_per_page( 'tmw_kw_clusters_per_page', 50 ) );
        $current_page = $this->get_pagenum();
        $offset       = ( $current_page - 1 ) * $per_page;

        // Fetch
        $select_sql = "SELECT id, cluster_key, representative, status, total_volume, avg_difficulty, opportunity, page_id, updated_at
                       FROM {$table}{$where_sql}
                       ORDER BY {$orderby} {$order}
                       LIMIT %d OFFSET %d";

        $query_args   = $where_args;
        $query_args[] = $per_page;
        $query_args[] = $offset;

        $this->items = (array) $wpdb->get_results( $wpdb->prepare( $select_sql, $query_args ), ARRAY_A );

        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( $total_items / $per_page ),
        ] );
    }
}
