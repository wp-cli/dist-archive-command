<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

/**
 * Create a distribution archive based on a project's .distignore file.
 *
 * ## OPTIONS
 *
 * <path>
 * : Path to the project that includes a .distignore file.
 *
 * [--format=<format>]
 * : Choose the format for the archive.
 * ---
 * default: zip
 * options:
 *   - zip
 *   - targz
 * ---
 *
 * @when before_wp_Load
 */
$dist_archive_command = function( $args, $assoc_args ) {

	list( $path ) = $args;
	$path = rtrim( $path, '/' ) . '/';

	$dist_ignore_path = $path . '.distignore';
	if ( ! file_exists( $dist_ignore_path ) ) {
		WP_CLI::error( 'No .distignore file found.' );
	}

};
WP_CLI::add_command( 'dist-archive', $dist_archive_command );
