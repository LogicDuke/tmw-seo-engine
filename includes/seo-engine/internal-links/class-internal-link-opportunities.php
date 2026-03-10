<?php
/**
 * Internal Link Opportunities engine.
 *
 * @package TMWSEO\Engine\InternalLinks
 */
namespace TMWSEO\Engine\InternalLinks;

if (!defined('ABSPATH')) { exit; }

use TMWSEO\Engine\Logs;

class InternalLinkOpportunities {
    const TABLE_SUFFIX = 'tmwseo_internal_links';
    const OPTION_AUTO_LINK = 'tmwseo_internal_links_auto_apply';

    /** @var array<int,string> */
    private static $supported_post_types = ['post', 'page', 'model', 'video', 'blog', 'traffic_pages', 'tmw_category_page', 'tmw_video'];

    public static function init(): void {
        add_action('admin_post_tmwseo_internal_links_scan', [__CLASS__, 'handle_scan']);
        add_action('admin_post_tmwseo_internal_links_apply', [__CLASS__, 'handle_apply']);
        add_action('admin_post_tmwseo_internal_links_settings', [__CLASS__, 'handle_settings']);
        add_filter('the_content', [__CLASS__, 'inject_auto_links_runtime'], 20);
    }

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function is_auto_apply_enabled(): bool {
        return (bool) get_option(self::OPTION_AUTO_LINK, 0);
    }

    public static function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'tmwseo'));
        }

        $rows = self::get_suggestions(300);
        $scan_notice = isset($_GET['tmwseo_internal_scan']) ? (int) $_GET['tmwseo_internal_scan'] : null;
        $apply_notice = isset($_GET['tmwseo_internal_apply']) ? (int) $_GET['tmwseo_internal_apply'] : null;
        ?>
        <div class="wrap tmwseo-dashboard">
            <h1><?php esc_html_e('Internal Link Opportunities', 'tmwseo'); ?></h1>
            <p><?php esc_html_e('Scans content for keyword/title overlap and suggests internal links.', 'tmwseo'); ?></p>

            <?php if ($scan_notice !== null) : ?>
                <div class="notice notice-success"><p><?php echo esc_html(sprintf(__('Internal link scan completed. %d opportunities saved.', 'tmwseo'), $scan_notice)); ?></p></div>
            <?php endif; ?>

            <?php if ($apply_notice !== null) : ?>
                <div class="notice notice-success"><p><?php echo esc_html(sprintf(__('Applied %d links to content.', 'tmwseo'), $apply_notice)); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:16px;display:flex;gap:12px;align-items:center;">
                <?php wp_nonce_field('tmwseo_internal_links_scan'); ?>
                <input type="hidden" name="action" value="tmwseo_internal_links_scan" />
                <button class="button button-primary" type="submit"><?php esc_html_e('Run Scan', 'tmwseo'); ?></button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:16px;display:flex;gap:12px;align-items:center;">
                <?php wp_nonce_field('tmwseo_internal_links_settings'); ?>
                <input type="hidden" name="action" value="tmwseo_internal_links_settings" />
                <label>
                    <input type="checkbox" name="auto_apply" value="1" <?php checked(self::is_auto_apply_enabled()); ?> />
                    <?php esc_html_e('Auto insert links into content when scan runs', 'tmwseo'); ?>
                </label>
                <button class="button" type="submit"><?php esc_html_e('Save', 'tmwseo'); ?></button>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Source Page', 'tmwseo'); ?></th>
                        <th><?php esc_html_e('Target Page', 'tmwseo'); ?></th>
                        <th><?php esc_html_e('Anchor Text', 'tmwseo'); ?></th>
                        <th><?php esc_html_e('Status', 'tmwseo'); ?></th>
                        <th><?php esc_html_e('Action', 'tmwseo'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)) : ?>
                    <tr><td colspan="5"><?php esc_html_e('No opportunities found yet. Run a scan.', 'tmwseo'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td><a href="<?php echo esc_url((string) $row['source_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) $row['source_title']); ?></a></td>
                            <td><a href="<?php echo esc_url((string) $row['target_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) $row['target_title']); ?></a></td>
                            <td><?php echo esc_html((string) $row['anchor']); ?></td>
                            <td><?php echo esc_html((string) $row['status']); ?></td>
                            <td>
                                <?php if ((string) $row['status'] !== 'linked') : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('tmwseo_internal_links_apply_' . (int) $row['id']); ?>
                                        <input type="hidden" name="action" value="tmwseo_internal_links_apply" />
                                        <input type="hidden" name="suggestion_id" value="<?php echo esc_attr((string) $row['id']); ?>" />
                                        <button class="button button-small" type="submit"><?php esc_html_e('Add Link', 'tmwseo'); ?></button>
                                    </form>
                                <?php else : ?>
                                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function handle_scan(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'tmwseo'));
        }

        check_admin_referer('tmwseo_internal_links_scan');
        $saved = self::scan_and_store();

        if (self::is_auto_apply_enabled()) {
            self::apply_pending_links();
        }

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-internal-links&tmwseo_internal_scan=' . $saved));
        exit;
    }

    public static function handle_settings(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'tmwseo'));
        }

        check_admin_referer('tmwseo_internal_links_settings');
        $enabled = !empty($_POST['auto_apply']) ? 1 : 0;
        update_option(self::OPTION_AUTO_LINK, $enabled, false);
        wp_safe_redirect(admin_url('admin.php?page=tmwseo-internal-links'));
        exit;
    }

    public static function handle_apply(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'tmwseo'));
        }

        $suggestion_id = isset($_POST['suggestion_id']) ? (int) $_POST['suggestion_id'] : 0;
        if ($suggestion_id <= 0) {
            wp_safe_redirect(admin_url('admin.php?page=tmwseo-internal-links'));
            exit;
        }

        check_admin_referer('tmwseo_internal_links_apply_' . $suggestion_id);
        self::apply_suggestion($suggestion_id);

        wp_safe_redirect(admin_url('admin.php?page=tmwseo-internal-links&tmwseo_internal_apply=1'));
        exit;
    }

    public static function scan_and_store(): int {
        global $wpdb;
        $table = self::table_name();
        $posts = self::load_posts_for_scan();

        if (empty($posts)) {
            return 0;
        }

        $wpdb->query("TRUNCATE TABLE {$table}");
        $saved = 0;

        foreach ($posts as $source) {
            $haystack = self::content_haystack($source);
            if ($haystack === '') {
                continue;
            }

            foreach ($posts as $target) {
                if ((int) $source['ID'] === (int) $target['ID']) {
                    continue;
                }

                $keyword_set = self::extract_target_keywords($target);
                foreach ($keyword_set as $keyword) {
                    if ($keyword === '') {
                        continue;
                    }

                    $matched_anchor = self::find_anchor_in_text($haystack, $keyword);
                    if ($matched_anchor === '') {
                        continue;
                    }

                    $inserted = $wpdb->insert(
                        $table,
                        [
                            'source_post_id' => (int) $source['ID'],
                            'source_url' => get_permalink((int) $source['ID']),
                            'source_title' => $source['post_title'],
                            'target_post_id' => (int) $target['ID'],
                            'target_url' => get_permalink((int) $target['ID']),
                            'target_title' => $target['post_title'],
                            'anchor' => $matched_anchor,
                            'status' => 'suggested',
                            'created_at' => current_time('mysql'),
                            'updated_at' => current_time('mysql'),
                        ],
                        ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
                    );

                    if ($inserted) {
                        $saved++;
                    }

                    break;
                }
            }
        }

        Logs::info('internal_links', '[TMW-IL] Internal link opportunity scan completed', ['saved' => $saved]);
        return $saved;
    }

    public static function get_suggestions(int $limit = 100): array {
        global $wpdb;
        $table = self::table_name();

        return (array) $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max(1, $limit)),
            ARRAY_A
        );
    }

    public static function apply_pending_links(): int {
        global $wpdb;
        $table = self::table_name();
        $rows = (array) $wpdb->get_results("SELECT id FROM {$table} WHERE status = 'suggested' ORDER BY id ASC", ARRAY_A);
        $applied = 0;

        foreach ($rows as $row) {
            if (self::apply_suggestion((int) ($row['id'] ?? 0))) {
                $applied++;
            }
        }

        Logs::info('internal_links', '[TMW-IL] Auto apply completed', ['applied' => $applied]);
        return $applied;
    }

    public static function apply_suggestion(int $suggestion_id): bool {
        global $wpdb;
        $table = self::table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $suggestion_id),
            ARRAY_A
        );

        if (!is_array($row) || empty($row['source_post_id']) || empty($row['target_url']) || empty($row['anchor'])) {
            return false;
        }

        $source_post = get_post((int) $row['source_post_id']);
        if (!$source_post instanceof \WP_Post || $source_post->post_status !== 'publish') {
            return false;
        }

        $updated_content = self::insert_link_once((string) $source_post->post_content, (string) $row['anchor'], (string) $row['target_url']);
        if ($updated_content === (string) $source_post->post_content) {
            return false;
        }

        $updated = wp_update_post([
            'ID' => (int) $source_post->ID,
            'post_content' => $updated_content,
        ], true);

        if (is_wp_error($updated)) {
            Logs::warn('internal_links', '[TMW-IL] Failed to apply suggestion', ['id' => $suggestion_id, 'error' => $updated->get_error_message()]);
            return false;
        }

        $wpdb->update(
            $table,
            ['status' => 'linked', 'updated_at' => current_time('mysql')],
            ['id' => $suggestion_id],
            ['%s', '%s'],
            ['%d']
        );

        return true;
    }

    public static function inject_auto_links_runtime(string $content): string {
        if (is_admin() || !self::is_auto_apply_enabled() || !is_singular()) {
            return $content;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return $content;
        }

        global $wpdb;
        $table = self::table_name();
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare("SELECT anchor, target_url FROM {$table} WHERE source_post_id = %d AND status = 'suggested' ORDER BY id ASC LIMIT 3", (int) $post_id),
            ARRAY_A
        );

        foreach ($rows as $row) {
            $anchor = (string) ($row['anchor'] ?? '');
            $target_url = (string) ($row['target_url'] ?? '');
            if ($anchor === '' || $target_url === '') {
                continue;
            }
            $content = self::insert_link_once($content, $anchor, $target_url);
        }

        return $content;
    }

    private static function load_posts_for_scan(): array {
        $post_types = array_values(array_filter(array_unique(self::$supported_post_types), 'post_type_exists'));
        if (empty($post_types)) {
            $post_types = ['post', 'page'];
        }

        return get_posts([
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => 500,
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'all',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
        ]);
    }

    private static function content_haystack(\WP_Post $post): string {
        return trim(wp_strip_all_tags($post->post_title . ' ' . $post->post_excerpt . ' ' . $post->post_content));
    }

    /**
     * @return array<int,string>
     */
    private static function extract_target_keywords(\WP_Post $post): array {
        $focus_keyword = (string) get_post_meta((int) $post->ID, 'rank_math_focus_keyword', true);
        if ($focus_keyword === '') {
            $focus_keyword = (string) get_post_meta((int) $post->ID, '_yoast_wpseo_focuskw', true);
        }

        $h1 = '';
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', (string) $post->post_content, $match)) {
            $h1 = trim(wp_strip_all_tags((string) ($match[1] ?? '')));
        }

        $keywords = [
            mb_strtolower(trim((string) $post->post_title)),
            mb_strtolower(trim($h1)),
            mb_strtolower(trim($focus_keyword)),
        ];

        return array_values(array_filter(array_unique($keywords), static function($keyword) {
            return $keyword !== '';
        }));
    }

    private static function find_anchor_in_text(string $text, string $keyword): string {
        if ($text === '' || $keyword === '') {
            return '';
        }

        if (!preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $text, $match)) {
            return '';
        }

        return trim((string) ($match[0] ?? ''));
    }

    private static function insert_link_once(string $content, string $anchor, string $target_url): string {
        if ($content === '' || $anchor === '' || $target_url === '') {
            return $content;
        }

        if (stripos($content, 'href="' . $target_url . '"') !== false) {
            return $content;
        }

        $replacement = '<a href="' . esc_url($target_url) . '">' . esc_html($anchor) . '</a>';
        $pattern = '/(?![^<]*<a[^>]*)(\b' . preg_quote($anchor, '/') . '\b)(?![^<]*<\/a>)/i';

        return (string) preg_replace($pattern, $replacement, $content, 1);
    }
}
