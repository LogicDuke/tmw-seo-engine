<?php

namespace TMWSEO\Engine\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ListTablePagination extends \WP_List_Table {

    public function __construct() {
        parent::__construct(
            [
                'singular' => 'row',
                'plural'   => 'rows',
                'ajax'     => false,
            ]
        );
    }

    public function render_bottom( int $total_items, int $per_page, int $current_page, array $extra_query_args = [] ): void {
        $total_items  = max( 0, $total_items );
        $per_page     = max( 1, $per_page );
        $total_pages  = (int) max( 1, (int) ceil( $total_items / $per_page ) );
        $current_page = max( 1, min( $current_page, $total_pages ) );

        $this->set_pagination_args(
            [
                'total_items' => $total_items,
                'per_page'    => $per_page,
                'total_pages' => $total_pages,
            ]
        );

        $base_url = remove_query_arg( 'paged' );
        if ( ! empty( $extra_query_args ) ) {
            $base_url = add_query_arg( $extra_query_args, $base_url );
        }

        $links = paginate_links(
            [
                'base'      => add_query_arg( 'paged', '%#%', $base_url ),
                'format'    => '',
                'current'   => $current_page,
                'total'     => $total_pages,
                'type'      => 'array',
                'prev_text' => '« Prev',
                'next_text' => 'Next »',
            ]
        );

        echo '<div class="tablenav bottom">';
        echo '<div class="tablenav-pages" style="float:right">';

        if ( is_array( $links ) && ! empty( $links ) ) {
            $page_links = [];
            foreach ( $links as $link ) {
                if ( strpos( $link, 'current' ) !== false ) {
                    $num = (int) wp_strip_all_tags( $link );
                    $page_links[] = '<span class="tablenav-pages-navspan">Page ' . esc_html( (string) $num ) . '</span>';
                } elseif ( preg_match( '/>(\d+)</', $link, $matches ) ) {
                    $label = 'Page ' . (int) $matches[1];
                    $page_links[] = preg_replace( '/>(\d+)</', '>' . esc_html( $label ) . '<', $link, 1 );
                } else {
                    $page_links[] = $link;
                }
            }

            echo wp_kses_post( implode( ' | ', $page_links ) );
        }

        echo '</div>';
        echo '<br class="clear" />';
        echo '</div>';
    }
}
