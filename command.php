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
 * @when before_wp_load
 */
$dist_archive_command = function( $args, $assoc_args ) {

	list( $path ) = $args;
	$path = rtrim( realpath( $path ), '/' );
	if ( ! is_dir( $path ) ) {
		WP_CLI::error( 'Provided path is not a directory.' );
	}

	$dist_ignore_path = $path . '/.distignore';
	if ( ! file_exists( $dist_ignore_path ) ) {
		WP_CLI::error( 'No .distignore file found.' );
	}

	$maybe_ignored_files = explode( PHP_EOL, file_get_contents( $dist_ignore_path ) );
	$ignored_files = array();
	$archive_base = basename( $path );
	foreach( $maybe_ignored_files as $file ) {
		$file = trim( $file );
		if ( 0 === strpos( $file, '#' ) || empty( $file ) ) {
			continue;
		}
		if ( is_dir( $path . '/' . $file ) ) {
			$file .= '/*';
		}
		$ignored_files[] = $archive_base . '/' . $file;
	}

	if ( 'zip' === $assoc_args['format'] ) {
		$archive_file = $archive_base . '.zip';
		$excludes = implode( ' --exclude ', $ignored_files );
		if ( ! empty( $excludes ) ) {
			$excludes = ' --exclude ' . $excludes;
		}
		chdir( dirname( $path ) );
		$cmd = "zip -r {$archive_file} {$archive_base} {$excludes}";
		WP_CLI::debug( "Running: {$cmd}", 'dist-archive' );
		$ret = WP_CLI::launch( escapeshellcmd( $cmd ), false, true );
		if ( 0 === $ret->return_code ) {
			WP_CLI::success( "Created {$archive_file}" );
		} else {
			WP_CLI::error( $ret->stderr );
		}
	}

};
WP_CLI::add_command( 'dist-archive', $dist_archive_command );
