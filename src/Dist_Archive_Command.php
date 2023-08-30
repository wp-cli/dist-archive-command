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
	 * [--filename-format=<filename-format>]
	 * : Use a custom format for archive filename. Defaults to '{name}.{version}'.
	 * This is ignored if a custom filename is provided or version does not exist.
	 *
	 * @when before_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		list( $path ) = $args;
		$path         = rtrim( realpath( $path ), '/' );
		if ( ! is_dir( $path ) ) {
			WP_CLI::error( 'Provided input path is not a directory.' );
		}

		if ( isset( $args[1] ) ) {
			// If the end of the string is a filename (file.ext), use it for the output archive filename.
			if ( 1 === preg_match( '/^[a-zA-Z0-9](?:[a-zA-Z0-9._-]*[a-zA-Z0-9])?\.[a-zA-Z0-9_-]+$/', basename( $args[1] ) ) ) {
				$archive_filename = basename( $args[1] );

				// If only the filename was supplied, use the plugin's parent directory for output.
				if ( basename( $args[1] ) === $args[1] ) {
					$archive_path = dirname( $path );
				} else {
					// Otherwise use the supplied directory.
					$archive_path = dirname( $args[1] );
				}
			} else {
				$archive_path     = $args[1];
				$archive_filename = null;
			}
		} else {
			if ( 0 !== strpos( $path, '/' ) ) {
				$archive_path = dirname( getcwd() . '/' . $path );
			} else {
				$archive_path = dirname( $path );
			}
			$archive_filename = null;
		}

		// If the  path is not absolute, it is relative.
		if ( 0 !== strpos( $archive_path, '/' ) ) {
			$archive_path = rtrim( getcwd() . '/' . ltrim( $archive_path, '/' ), '/' );
		}

		$dist_ignore_path = $path . '/.distignore';
		if ( file_exists( $dist_ignore_path ) ) {
			$maybe_ignored_files = explode( PHP_EOL, file_get_contents( $dist_ignore_path ) );
		} else {
			WP_CLI::warning( 'No .distignore file found. All files in directory included in archive.' );
			$maybe_ignored_files = array();
		}

		$ignored_files = array();
		$source_base   = basename( $path );
		$archive_base  = isset( $assoc_args['plugin-dirname'] ) ? rtrim( $assoc_args['plugin-dirname'], '/' ) : $source_base;

		// When zipping directories, we need to exclude both the contents of and the directory itself from the zip file.
		foreach ( array_filter( $maybe_ignored_files ) as $file ) {
			if ( is_dir( $path . '/' . $file ) ) {
				$maybe_ignored_files[] = rtrim( $file, '/' ) . '/*';
				$maybe_ignored_files[] = rtrim( $file, '/' ) . '/';
			}
		}

		foreach ( $maybe_ignored_files as $file ) {
			$file = trim( $file );
			if ( 0 === strpos( $file, '#' ) || empty( $file ) ) {
				continue;
			}
			// If a path is tied to the root of the plugin using `/`, match exactly, otherwise match liberally.
			if ( 'zip' === $assoc_args['format'] ) {
				$ignored_files[] = ( 0 === strpos( $file, '/' ) )
					? $archive_base . $file
					: '*/' . $file;
			} elseif ( 'targz' === $assoc_args['format'] ) {
				if ( php_uname( 's' ) === 'Linux' ) {
					$ignored_files[] = ( 0 === strpos( $file, '/' ) )
						? $archive_base . $file
						: '*/' . $file;
				} else {
					$ignored_files[] = ( 0 === strpos( $file, '/' ) )
						? '^' . $archive_base . $file
						: $file;
				}
			}
		}

		$version = '';

		/**
		 * If the path is a theme (meaning it contains a style.css file)
		 * parse the theme's version from the headers using a regex pattern.
		 * The pattern used is extracted from the get_file_data() function in core.
		 *
		 * @link https://developer.wordpress.org/reference/functions/get_file_data/
		 */
		if ( file_exists( $path . '/style.css' ) ) {
			$contents = file_get_contents( $path . '/style.css', false, null, 0, 5000 );
			$contents = str_replace( "\r", "\n", $contents );
			$pattern  = '/^' . preg_quote( 'Version', ',' ) . ':(.*)$/mi';
			if ( preg_match( $pattern, $contents, $match ) && $match[1] ) {
				$version = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
			}
		}

		if ( empty( $version ) ) {
			foreach ( glob( $path . '/*.php' ) as $php_file ) {
				$contents = file_get_contents( $php_file, false, null, 0, 5000 );
				$version  = $this->get_version_in_code( $contents );
				if ( ! empty( $version ) ) {
					$version = trim( $version );
					break;
				}
			}
		}

		if ( empty( $version ) && file_exists( $path . '/composer.json' ) ) {
			$composer_obj = json_decode( file_get_contents( $path . '/composer.json' ) );
			if ( ! empty( $composer_obj->version ) ) {
				$version = trim( $composer_obj->version );
			}
		}

		if ( ! empty( $version ) && false !== stripos( $version, '-alpha' ) && is_dir( $path . '/.git' ) ) {
			$response   = WP_CLI::launch( "cd {$path}; git log --pretty=format:'%h' -n 1", false, true );
			$maybe_hash = trim( $response->stdout );
			if ( $maybe_hash && 7 === strlen( $maybe_hash ) ) {
				$version .= '-' . $maybe_hash;
			}
		}

		if ( $archive_base !== $source_base || $this->is_path_contains_symlink( $path ) ) {
			$tmp_dir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $archive_base . '.' . $version . '.' . time();
			$new_path = $tmp_dir . DIRECTORY_SEPARATOR . $archive_base;
			mkdir( $new_path, 0777, true );
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
			foreach ( $iterator as $item ) {
				if ( $this->is_ignored_file( $iterator->getSubPathName(), $maybe_ignored_files ) ) {
					continue;
				}
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

		if ( is_null( $archive_filename ) ) {

			if ( ! empty( $version ) ) {
				if ( ! empty( $assoc_args['filename-format'] ) ) {
					$archive_filename = str_replace( [ '{name}', '{version}' ], [ $archive_base, $version ], $assoc_args['filename-format'] );
				} else {
					$archive_filename = $archive_base . '.' . $version;
				}
			} else {
				$archive_filename = $archive_base;
			}

			if ( 'zip' === $assoc_args['format'] ) {
				$archive_filename .= '.zip';
			} elseif ( 'targz' === $assoc_args['format'] ) {
				$archive_filename .= '.tar.gz';
			}
		}
		$archive_filepath = $archive_path . '/' . $archive_filename;

		chdir( dirname( $source_path ) );

		if ( Utils\get_flag_value( $assoc_args, 'create-target-dir' ) ) {
			$this->maybe_create_directory( $archive_filepath );
		}

		if ( ! is_dir( dirname( $archive_path ) ) ) {
			WP_CLI::error( "Target directory does not exist: {$archive_path}" );
		}

		if ( 'zip' === $assoc_args['format'] ) {
			$excludes = implode( ' --exclude ', $ignored_files );
			if ( ! empty( $excludes ) ) {
				$excludes = ' --exclude ' . $excludes;
			}
			$cmd = "zip -r '{$archive_filepath}' {$archive_base} {$excludes}";
		} elseif ( 'targz' === $assoc_args['format'] ) {
			$excludes = array_map(
				function ( $ignored_file ) {
					if ( '/*' === substr( $ignored_file, -2 ) ) {
						$ignored_file = substr( $ignored_file, 0, ( strlen( $ignored_file ) - 2 ) );
					}
					return "--exclude='{$ignored_file}'";
				},
				$ignored_files
			);
			$excludes = implode( ' ', $excludes );
			$cmd      = 'tar ' . ( ( php_uname( 's' ) === 'Linux' ) ? '--anchored ' : '' ) . "{$excludes} -zcvf {$archive_filepath} {$archive_base}";
		}

		$escape_whitelist = 'targz' === $assoc_args['format'] ? array( '^', '*' ) : array();
		WP_CLI::debug( "Running: {$cmd}", 'dist-archive' );
		$escaped_shell_command = $this->escapeshellcmd( $cmd, $escape_whitelist );
		$ret                   = WP_CLI::launch( $escaped_shell_command, false, true );
		if ( 0 === $ret->return_code ) {
			$filename = pathinfo( $archive_filepath, PATHINFO_BASENAME );
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

	/**
	 * Run PHP's escapeshellcmd() then undo escaping known intentional characters.
	 *
	 * Escaped by default: &#;`|*?~<>^()[]{}$\, \x0A and \xFF. ' and " are escaped when not paired.
	 *
	 * @see escapeshellcmd()
	 *
	 * @param string $cmd The shell command to escape.
	 * @param string[] $whitelist Array of exceptions to allow in the escaped command.
	 *
	 * @return string
	 */
	protected function escapeshellcmd( $cmd, $whitelist ) {

		$escaped_command = escapeshellcmd( $cmd );

		foreach ( $whitelist as $undo_escape ) {
			$escaped_command = str_replace( '\\' . $undo_escape, $undo_escape, $escaped_command );
		}

		return $escaped_command;
	}


	/**
	 * Given the path to a directory, check are any of the directories inside it symlinks.
	 *
	 * If the plugin contains a symlink, we will first copy it to a temp directory, potentially omitting any
	 * symlinks that are excluded via the `.distignore` file, avoiding recursive loops as described in #57.
	 *
	 * @param string $path The filepath to the directory to check.
	 *
	 * @return bool
	 */
	protected function is_path_contains_symlink( $path ) {

		if ( ! is_dir( $path ) ) {
			throw new Exception( 'Path `' . $path . '` is not a directory' );
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		/**
		 * @var RecursiveIteratorIterator $iterator
		 * @var SplFileInfo $item
		 */
		foreach ( $iterator as $item ) {
			if ( is_link( $item->getPathname() ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check a file from the plugin against the list of rules in the `.distignore` file.
	 *
	 * @param string $relative_filepath Path to the file from the plugin root.
	 * @param string[] $distignore_entries List of ignore rules.
	 *
	 * @return bool True when the file matches a rule in the `.distignore` file.
	 */
	public function is_ignored_file( $relative_filepath, array $distignore_entries ) {

		foreach ( array_filter( $distignore_entries ) as $entry ) {

			// We don't want to quote `*` in regex pattern, later we'll replace it with `.*`.
			$pattern = str_replace( '*', '&ast;', $entry );

			$pattern = '/' . preg_quote( $pattern, '/' ) . '$/';

			$pattern = str_replace( '&ast;', '.*', $pattern );

			// If the entry is tied to the beginning of the path, add the `^` regex symbol.
			if ( 0 === strpos( $entry, '/' ) ) {
				$pattern = '/^' . substr( $pattern, 3 );
			}

			// If the entry begins with `.` (hidden files), tie it to the beginning of directories.
			if ( 0 === strpos( $entry, '.' ) ) {
				$pattern = '/(^|\/)' . substr( $pattern, 1 );
			}

			if ( 1 === preg_match( $pattern, $relative_filepath ) ) {
				return true;
			}
		}

		return false;
	}
}
