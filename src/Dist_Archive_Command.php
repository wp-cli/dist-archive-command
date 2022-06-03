<?php

use WP_CLI\Utils;

/**
 * Create a distribution archive based on a project's .distignore file.
 */
class Dist_Archive_Command {

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
	 * Use one distribution archive command for many projects, instead of a bash
	 * script in each project.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : Path to the project that includes a .distignore file.
	 *
	 * [<target>]
	 * : Path and optional file name for the distribution archive.
	 * If only a path is provided, the file name defaults to the project directory name plus the version, if discoverable.
	 * Also, if only a path is given, the directory that it points to has to already exist for the command to function correctly.
	 *
	 * [--create-target-dir]
	 * : Automatically create the target directory as needed.
	 *
	 * [--plugin-dirname=<plugin-slug>]
	 * : Set the archive extract directory name. Defaults to project directory name.
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
	public function __invoke( $args, $assoc_args ) {
		list( $path ) = $args;
		if ( isset( $args[1] ) ) {
			$archive_file = $args[1];
			$info         = pathinfo( $archive_file );
			if ( '.' === $info['dirname'] ) {
				$archive_file = getcwd() . '/' . $info['basename'];
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
		$ignored_files       = array();
		$source_base         = basename( $path );
		$archive_base        = isset( $assoc_args['plugin-dirname'] ) ? rtrim( $assoc_args['plugin-dirname'], '/' ) : $source_base;
		foreach ( $maybe_ignored_files as $file ) {
			$file = trim( $file );
			if ( 0 === strpos( $file, '#' ) || empty( $file ) ) {
				continue;
			}
			if ( is_dir( $path . '/' . $file ) ) {
				$file = rtrim( $file, '/' ) . '/*';
			}
			// If a path is tied to the root of the plugin using `/`, match exactly, otherwise match liberally.
			if ( 'zip' === $assoc_args['format'] ) {
				$ignored_files[] = ( 0 === strpos( $file, '/' ) )
					? $archive_base . $file
					: '*/' . $file;
			} elseif ( 'targz' === $assoc_args['format'] ) {
				$ignored_files[] = ( 0 === strpos( $file, '/' ) )
					? '^' . $archive_base . $file
					: $file;
			}
		}

		$version = '';
		foreach ( glob( $path . '/*.php' ) as $php_file ) {
			$contents = file_get_contents( $php_file, false, null, 0, 5000 );
			$version  = $this->get_version_in_code( $contents );
			if ( ! empty( $version ) ) {
				$version = '.' . trim( $version );
				break;
			}
		}

		if ( empty( $version ) && file_exists( $path . '/composer.json' ) ) {
			$composer_obj = json_decode( file_get_contents( $path . '/composer.json' ) );
			if ( ! empty( $composer_obj->version ) ) {
				$version = '.' . trim( $composer_obj->version );
			}
		}

		if ( false !== stripos( $version, '-alpha' ) && is_dir( $path . '/.git' ) ) {
			$response   = WP_CLI::launch( "cd {$path}; git log --pretty=format:'%h' -n 1", false, true );
			$maybe_hash = trim( $response->stdout );
			if ( $maybe_hash && 7 === strlen( $maybe_hash ) ) {
				$version .= '-' . $maybe_hash;
			}
		}

		if ( $archive_base !== $source_base ) {
			$plugin_dirname = rtrim( $assoc_args['plugin-dirname'], '/' );
			$archive_base   = $plugin_dirname;
			$tmp_dir        = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $plugin_dirname . $version . '.' . time();
			$new_path       = $tmp_dir . DIRECTORY_SEPARATOR . $plugin_dirname;
			mkdir( $new_path, 0777, true );
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
			foreach ( $iterator as $item ) {
				if ( $item->isDir() ) {
					mkdir( $new_path . DIRECTORY_SEPARATOR . $iterator->getSubPathName() );
				} else {
					copy( $item, $new_path . DIRECTORY_SEPARATOR . $iterator->getSubPathName() );
				}
			}
			$source_path = $new_path;
		} else {
			$source_path = $path;
		}

		if ( is_null( $archive_file ) ) {
			$archive_file = dirname( $path ) . '/' . $archive_base . $version;
			if ( 'zip' === $assoc_args['format'] ) {
				$archive_file .= '.zip';
			} elseif ( 'targz' === $assoc_args['format'] ) {
				$archive_file .= '.tar.gz';
			}
		}

		chdir( dirname( $source_path ) );

		if ( Utils\get_flag_value( $assoc_args, 'create-target-dir' ) ) {
			$this->maybe_create_directory( $archive_file );
		}

		if ( is_dir( $archive_file ) ) {
			$archive_file = rtrim( $archive_file, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $archive_base . $version;
			if ( 'zip' === $assoc_args['format'] ) {
				$archive_file .= '.zip';
			} elseif ( 'targz' === $assoc_args['format'] ) {
				$archive_file .= '.tar.gz';
			}
		}

		if ( ! is_dir( dirname( $archive_file ) ) ) {
			WP_CLI::error( "Target directory does not exist: {$archive_file}" );
		}

		if ( 'zip' === $assoc_args['format'] ) {
			$excludes = implode( ' --exclude ', $ignored_files );
			if ( ! empty( $excludes ) ) {
				$excludes = ' --exclude ' . $excludes;
			}
			$cmd = "zip -r '{$archive_file}' {$archive_base} {$excludes}";
		} elseif ( 'targz' === $assoc_args['format'] ) {
			$excludes = array_map(
				function( $ignored_file ) {
					if ( '/*' === substr( $ignored_file, -2 ) ) {
						$ignored_file = substr( $ignored_file, 0, ( strlen( $ignored_file ) - 2 ) );
					}
						return "--exclude='{$ignored_file}'";
				},
				$ignored_files
			);
			$excludes = implode( ' ', $excludes );
			$cmd      = "tar {$excludes} -zcvf {$archive_file} {$archive_base}";
		}

		WP_CLI::debug( "Running: {$cmd}", 'dist-archive' );
		$ret = WP_CLI::launch( escapeshellcmd( $cmd ), false, true );
		if ( 0 === $ret->return_code ) {
			$filename = pathinfo( $archive_file, PATHINFO_BASENAME );
			WP_CLI::success( "Created {$filename}" );
		} else {
			$error = $ret->stderr ?: $ret->stdout;
			WP_CLI::error( $error );
		}
	}

	/**
	 * Create the directory for a target file if it does not exist yet.
	 *
	 * @param string $archive_file Path and filename of the target file.
	 * @return void
	 */
	private function maybe_create_directory( $archive_file ) {
		$directory = dirname( $archive_file );
		if ( ! is_dir( $directory ) ) {
			mkdir( $directory, $mode = 0777, $recursive = true );
		}
	}

	/**
	 * Gets the content of a version tag in any doc block in the given source code string.
	 *
	 * The version tag might be specified as "@version x.y.z" or "Version: x.y.z" and it can
	 * be preceded by an asterisk (*).
	 *
	 * @param string $code_str The source code string to look into.
	 * @return null|string The detected version string.
	 */
	private function get_version_in_code( $code_str ) {
		$tokens = array_values(
			array_filter(
				token_get_all( $code_str ),
				function ( $token ) {
					return ! is_array( $token ) || T_WHITESPACE !== $token[0];
				}
			)
		);
		foreach ( $tokens as $token ) {
			if ( T_DOC_COMMENT === $token[0] ) {
				$version = $this->get_version_in_docblock( $token[1] );
				if ( null !== $version ) {
					return $version;
				}
			}
		}
		return null;
	}

	/**
	 * Gets the content of a version tag in a docblock.
	 *
	 * @param string $docblock Docblock to parse.
	 * @return null|string The content of the version tag.
	*/
	private function get_version_in_docblock( $docblock ) {
		$docblocktags = $this->parse_doc_block( $docblock );
		if ( isset( $docblocktags['version'] ) ) {
			return $docblocktags['version'];
		}
		return null;
	}

	/**
	 * Parses a docblock and gets an array of tags with their values.
	 *
	 * The tags might be specified as "@version x.y.z" or "Version: x.y.z" and they can
	 * be preceded by an asterisk (*).
	 *
	 * This code is based on the 'phpactor' package.
	 * @see https://github.com/phpactor/docblock/blob/master/lib/Parser.php
	 *
	 * @param string $docblock Docblock to parse.
	 * @return array Associative array of parsed data.
	*/
	private function parse_doc_block( $docblock ) {
		$tag_documentor = '{@([a-zA-Z0-9-_\\\]+)\s*?(.*)?}';
		$tag_property   = '{\s*\*?\s*(.*?):(.*)}';
		$lines          = explode( PHP_EOL, $docblock );
		$tags           = [];

		foreach ( $lines as $line ) {
			if ( 0 === preg_match( $tag_documentor, $line, $matches ) ) {
				if ( 0 === preg_match( $tag_property, $line, $matches ) ) {
					continue;
				}
			}

			$tag_name = strtolower( $matches[1] );
			$metadata = trim( isset( $matches[2] ) ? $matches[2] : '' );

			$tags[ $tag_name ] = $metadata;
		}
		return $tags;
	}
}
