<?php

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

/**
 * Create a distribution archive based on a project's .distignore file.
 *
 * For a plugin in a directory 'wp-content/plugins/hello-world', this command
 * creates a distribution archive 'wp-content/plugins/hello-world.zip'.
 *
 * You can specify files or directories you'd like to exclude from the archive
 * with a .distignore file in your project repository:
 *
 * ```
 * .distignore
 * .editorconfig
 * .git
 * .gitignore
 * .travis.yml
 * circle.yml
 * ```
 *
 * Use one distibution archive command for many projects, instead of a bash
 * script in each project.
 *
 * ## OPTIONS
 *
 * <path>
 * : Path to the project that includes a .distignore file.
 *
 * [<target>]
 * : Path and file name for the distribution archive. Defaults to project directory name plus version, if discoverable.
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
	if ( isset( $args[1] ) ) {
		$archive_file = $args[1];
		$info = pathinfo( $archive_file );
		if ( '.' === $info['dirname'] ) {
			$archive_file  = getcwd() . '/' . $info['basename'];
		}
	} else {
		$archive_file = null;
	}
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

	$version = '';
	foreach( glob( $path . '/*.php' ) as $php_file ) {
		$contents = file_get_contents( $php_file, false, null, 0, 5000 );
		if ( preg_match( '#\* Version:(.+)#', $contents, $matches ) ) {
			$version = '.' . trim( $matches[1] );
			break;
		}
	}

	if ( empty( $version ) && file_exists( $path . '/composer.json' ) ) {
		$composer_obj = json_decode( file_get_contents( $path . '/composer.json' ) );
		if ( ! empty( $composer_obj->version ) ) {
			$version = '.' . trim( $composer_obj->version );
		}
	}

	if ( 'zip' === $assoc_args['format'] ) {
		if ( is_null( $archive_file ) ) {
			$archive_file = dirname( $path ) . '/' . $archive_base . $version . '.zip';
		}
		$excludes = implode( ' --exclude ', $ignored_files );
		if ( ! empty( $excludes ) ) {
			$excludes = ' --exclude ' . $excludes;
		}
		chdir( dirname( $path ) );
		$cmd = "zip -r {$archive_file} {$archive_base} {$excludes}";
		WP_CLI::debug( "Running: {$cmd}", 'dist-archive' );
		$ret = WP_CLI::launch( escapeshellcmd( $cmd ), false, true );
		if ( 0 === $ret->return_code ) {
			$filename = pathinfo( $archive_file, PATHINFO_BASENAME );
			WP_CLI::success( "Created {$filename}" );
		} else {
			$error = $ret->stderr ? $ret->stderr : $ret->stdout;
			WP_CLI::error( $error );
		}
	}

};
WP_CLI::add_command( 'dist-archive', $dist_archive_command );
