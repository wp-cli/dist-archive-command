<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

$wpcli_dist_archive_autoloader = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $wpcli_dist_archive_autoloader ) ) {
	require_once $wpcli_dist_archive_autoloader;
}

WP_CLI::add_command( 'dist-archive', 'Dist_Archive_Command' );
