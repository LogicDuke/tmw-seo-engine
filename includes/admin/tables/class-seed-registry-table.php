<?php
namespace TMWSEO\Engine\Admin\Tables;

if (!defined('ABSPATH')) { exit; }

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class SeedRegistryTable extends \WP_List_Table {
    private $status_filter;

    public function __construct(string $status_filter = '') {
        $this->status_filter = $status_filter;
        parent::__construct([
            'singular' => 'seed_candidate',
            'plural'   => 'seed_candidates',
            'ajax'     => false,
        ]);
    }

    public function get_columns(): array {
        return [
            'cb'              => '<input type="checkbox" />',
            'phrase'          => __('Phrase', 'tmwseo'),
            'source'          => __('Source', 'tmwseo'),
            'generation_rule' => __('Rule', 'tmwseo'),
            'batch_id'        => __('Batch', 'tmwseo'),
            'status'          => __('Status', 'tmwseo'),
            'created_at'      => __('Created', 'tmwseo'),
        ];
    }

    public function get_sortable_columns(): array {
        return [
            'phrase'     => ['phrase', true],
            'created_at' => ['created_at', false],
            'status'     => ['status', false],
        ];
    }

    protected function get_bulk_actions(): array {
        return [
            'approve_candidate' => __('Approve', 'tmwseo'),
            'reject_candidate'  => __('Reject', 'tmwseo'),
        ];
    }

    protected function column_cb($item): string {
        return sprintf('<input type="checkbox" name="candidate_ids[]" value="%d" />', (int) ($item['id'] ?? 0));
    }

    public function column_default($item, $column_name): string {
        return esc_html((string) ($item[$column_name] ?? ''));
    }

    public function prepare_items(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tmw_seed_expansion_candidates';

        $this->process_bulk_action();

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];

        $allowed_orderby = ['phrase', 'created_at', 'status'];
        $orderby = isset($_GET['orderby']) ? sanitize_key((string) $_GET['orderby']) : 'created_at';
        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'created_at';
        }

        $order = isset($_GET['order']) ? strtoupper(sanitize_key((string) $_GET['order'])) : 'DESC';
        if (!in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'DESC';
        }

        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['s'])) : '';

        $where_sql = ' WHERE 1=1';
        $where_args = [];

        if ($this->status_filter !== '') {
            $where_sql .= ' AND status = %s';
            $where_args[] = $this->status_filter;
        } else {
            $where_sql .= " AND status IN ('pending','fast_track')";
        }

        if ($search !== '') {
            $where_sql .= ' AND (phrase LIKE %s OR generation_rule LIKE %s OR source LIKE %s)';
            $term = '%' . $wpdb->esc_like($search) . '%';
            $where_args[] = $term;
            $where_args[] = $term;
            $where_args[] = $term;
        }

        $total_sql = "SELECT COUNT(*) FROM {$table}{$where_sql}";
        $total_items = (int) $wpdb->get_var($wpdb->prepare($total_sql, $where_args));

        $per_page = $this->get_items_per_page('tmw_seed_registry_per_page', 50);
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        $sql = "SELECT id, phrase, source, generation_rule, batch_id, status, created_at FROM {$table}{$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
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
        if (!$action || !in_array($action, ['approve_candidate', 'reject_candidate'], true)) {
            return;
        }

        check_admin_referer('bulk-' . $this->_args['plural']);

        $ids = isset($_POST['candidate_ids']) ? (array) $_POST['candidate_ids'] : [];
        $ids = array_values(array_filter(array_map('absint', $ids)));

        foreach ($ids as $id) {
            if ($action === 'approve_candidate') {
                \TMWSEO\Engine\Keywords\ExpansionCandidateRepository::approve_candidate($id, get_current_user_id());
            } else {
                \TMWSEO\Engine\Keywords\ExpansionCandidateRepository::reject_candidate($id, '', get_current_user_id());
            }
        }
    }
}
