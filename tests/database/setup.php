<?php
if ( getenv( 'GML_DATABASE_TESTS' ) !== '1' ) exit( 2 );
define( 'WP_INSTALLING', true );
require getenv( 'GML_TEST_WP_ROOT' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
add_filter( 'pre_wp_mail', '__return_true' );
if ( ! is_blog_installed() ) {
    wp_install( 'GML regression', 'regression-admin', 'admin@example.test', false, '', wp_generate_password( 32 ) );
}
update_option( 'active_plugins', [] );
echo 'WordPress ' . get_bloginfo( 'version' ) . ' / MariaDB ' . $wpdb->get_var( 'SELECT VERSION()' ) . " ready\n";
