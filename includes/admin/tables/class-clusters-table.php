<?php
namespace TMWSEO\Engine\Admin\Tables;

if (!defined('ABSPATH')) { exit; }

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ClustersTable extends \WP_List_Table {
    private $cluster_service;
    private $scoring_engine;

    public function __construct($cluster_service, $scoring_engine) {
        $this->cluster_service = $cluster_service;
        $this->scoring_engine = $scoring_engine;

        parent::__construct([
            'singular' => 'cluster',
            'plural'   => 'clusters',
            'ajax'     => false,
        ]);
    }

    public function get_columns(): array {
        return [
            'cb'            => '<input type="checkbox" />',
            'name'          => __('Name', 'tmwseo'),
            'score'         => __('Score', 'tmwseo'),
            'grade'         => __('Grade', 'tmwseo'),
            'keywords'      => __('Keywords', 'tmwseo'),
            'opportunity'   => __('Opportunity', 'tmwseo'),
            'created_at'    => __('Created', 'tmwseo'),
        ];
    }

    public function get_sortable_columns(): array {
        return [
            'name'       => ['name', true],
            'created_at' => ['created_at', false],
        ];
    }

    protected function get_bulk_actions(): array {
        return [
            'merge'  => __('Merge', 'tmwseo'),
            'delete' => __('Delete', 'tmwseo'),
        ];
    }

    protected function column_cb($item): string {
        return sprintf('<input type="checkbox" name="cluster_ids[]" value="%d" />', (int) ($item['id'] ?? 0));
    }

    public function column_name($item): string {
        $id = (int) ($item['id'] ?? 0);
        $name = (string) ($item['name'] ?? '');
        $url = add_query_arg(['page' => 'tmw-seo-clusters', 'cluster_id' => $id], admin_url('admin.php'));
        return '<a href="' . esc_url($url) . '">' . esc_html($name) . '</a>';
    }

    public function column_default($item, $column_name): string {
        return esc_html((string) ($item[$column_name] ?? ''));
    }

    public function prepare_items(): void {
        $this->process_bulk_action();

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        $allowed_orderby = ['name', 'created_at'];
        $orderby = isset($_GET['orderby']) ? sanitize_key((string) $_GET['orderby']) : 'created_at';
        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'created_at';
        }

        $order = isset($_GET['order']) ? strtoupper(sanitize_key((string) $_GET['order'])) : 'DESC';
        if (!in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'DESC';
        }

        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['s'])) : '';

        $all_items = $this->cluster_service->list_clusters([
            'limit' => 5000,
            'offset' => 0,
            'orderby' => $orderby,
            'order' => $order,
        ]);

        if ($search !== '') {
            $all_items = array_values(array_filter((array) $all_items, static function ($item) use ($search) {
                $name = strtolower((string) ($item['name'] ?? ''));
                return strpos($name, strtolower($search)) !== false;
            }));
        }

        $total_items = count($all_items);
        $per_page = $this->get_items_per_page('tmw_clusters_per_page', 50);
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;
        $paged_items = array_slice($all_items, $offset, $per_page);

        $advisor = \TMW_Main_Class::get_cluster_advisor();
        $this->items = [];
        foreach ($paged_items as $cluster) {
            $cluster_id = (int) ($cluster['id'] ?? 0);
            if ($cluster_id <= 0) {
                continue;
            }
            $score_data = $this->scoring_engine->score_cluster($cluster_id);
            $keywords = $this->cluster_service->get_cluster_keywords($cluster_id);
            $opportunity = $advisor ? $advisor->get_cluster_opportunity_score($cluster_id) : null;

            $this->items[] = [
                'id' => $cluster_id,
                'name' => (string) ($cluster['name'] ?? ''),
                'score' => (string) ((int) ($score_data['score'] ?? 0)),
                'grade' => (string) ($score_data['grade'] ?? 'F'),
                'keywords' => (string) (is_array($keywords) ? count($keywords) : 0),
                'opportunity' => isset($opportunity['score']) ? (string) ((int) $opportunity['score']) : '—',
                'created_at' => (string) ($cluster['created_at'] ?? ''),
            ];
        }

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($total_items / $per_page),
        ]);
    }

    protected function process_bulk_action(): void {
        $action = $this->current_action();
        if (!$action || !in_array($action, ['merge', 'delete'], true)) {
            return;
        }

        check_admin_referer('bulk-' . $this->_args['plural']);

        $ids = isset($_POST['cluster_ids']) ? (array) $_POST['cluster_ids'] : [];
        $ids = array_values(array_filter(array_map('absint', $ids)));
        if ($ids === []) {
            return;
        }

        if ($action === 'delete') {
            foreach ($ids as $id) {
                $this->cluster_service->delete_cluster($id);
            }
        }
    }
}
