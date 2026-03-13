<?php
namespace TMWSEO\Engine\Admin\Tables;

use TMWSEO\Engine\Opportunities\OpportunityDatabase;

if (!defined('ABSPATH')) { exit; }

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class OpportunitiesTable extends \WP_List_Table {
    private $db;
    private $ui;

    public function __construct($ui) {
        $this->db = new OpportunityDatabase();
        $this->ui = $ui;
        parent::__construct([
            'singular' => 'opportunity',
            'plural'   => 'opportunities',
            'ajax'     => false,
        ]);
    }

    public function get_columns(): array {
        return [
            'cb'                => '<input type="checkbox" />',
            'keyword'           => __('Keyword', 'tmwseo'),
            'type'              => __('Type', 'tmwseo'),
            'source'            => __('Source', 'tmwseo'),
            'opportunity_score' => __('Score', 'tmwseo'),
            'status'            => __('Status', 'tmwseo'),
            'created_at'        => __('Created', 'tmwseo'),
        ];
    }

    public function get_sortable_columns(): array {
        return [
            'keyword'           => ['keyword', true],
            'created_at'        => ['created_at', false],
            'opportunity_score' => ['opportunity_score', false],
        ];
    }

    protected function get_bulk_actions(): array {
        return [
            'approve' => __('Approve', 'tmwseo'),
            'reject'  => __('Reject', 'tmwseo'),
        ];
    }

    protected function column_cb($item): string {
        return sprintf('<input type="checkbox" name="opportunity_ids[]" value="%d" />', (int) ($item['id'] ?? 0));
    }

    public function column_default($item, $column_name): string {
        return esc_html((string) ($item[$column_name] ?? ''));
    }

    public function prepare_items(): void {
        global $wpdb;
        $table = OpportunityDatabase::table_name();

        $this->process_bulk_action();

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];

        $allowed_orderby = ['keyword', 'opportunity_score', 'created_at'];
        $orderby = isset($_GET['orderby']) ? sanitize_key((string) $_GET['orderby']) : 'opportunity_score';
        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'opportunity_score';
        }

        $order = isset($_GET['order']) ? strtoupper(sanitize_key((string) $_GET['order'])) : 'DESC';
        if (!in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'DESC';
        }

        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['s'])) : '';

        $where_sql = '';
        $where_args = [];
        if ($search !== '') {
            $where_sql = ' WHERE keyword LIKE %s OR source LIKE %s';
            $term = '%' . $wpdb->esc_like($search) . '%';
            $where_args = [$term, $term];
        }

        $total_sql = "SELECT COUNT(*) FROM {$table}{$where_sql}";
        $total_items = $where_args === [] ? (int) $wpdb->get_var($total_sql) : (int) $wpdb->get_var($wpdb->prepare($total_sql, $where_args));

        $per_page = $this->get_items_per_page('tmw_opportunities_per_page', 50);
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        $sql = "SELECT id, keyword, type, source, opportunity_score, status, created_at FROM {$table}{$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $query_args = $where_args;
        $query_args[] = $per_page;
        $query_args[] = $offset;

        $this->items = (array) $wpdb->get_results($wpdb->prepare($sql, $query_args), ARRAY_A);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($total_items / $per_page),
        ]);
    }

    protected function process_bulk_action(): void {
        $action = $this->current_action();
        if (!$action || !in_array($action, ['approve', 'reject'], true)) {
            return;
        }

        check_admin_referer('bulk-' . $this->_args['plural']);

        $ids = isset($_POST['opportunity_ids']) ? (array) $_POST['opportunity_ids'] : [];
        $ids = array_values(array_filter(array_map('absint', $ids)));

        foreach ($ids as $id) {
            if ($action === 'approve') {
                $this->ui->run_bulk_generate_draft($id);
            } elseif ($action === 'reject') {
                $this->db->update_status($id, 'ignored');
            }
        }
    }
}
