<?php
/**
 * Shared WP_Post test stub.
 *
 * Keep this shape as a superset of fields used by isolated tests so suite order
 * does not decide which class definition wins.
 */
if ( ! class_exists( 'WP_Post' ) ) {
    class WP_Post {
        public $ID = 0;
        public $post_title = '';
        public $post_name = '';
        public $post_status = 'publish';
        public $post_type = 'post';
        public $post_excerpt = '';
        public $post_content = '';
        public $post_parent = 0;

        public function __construct( array $props = [] ) {
            foreach ( $props as $key => $value ) {
                $this->$key = $value;
            }
        }
    }
}
