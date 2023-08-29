<?php

use WP_CLI\Utils;

/**
 * Create a distribution archive based on a project's .distignore file.
 */
class Dist_Archive_Command {
	/**
	 * @var \Inmarelibero\GitIgnoreChecker\GitIgnoreChecker
	 */
	private $checker;

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

		$this->checker = new \Inmarelibero\GitIgnoreChecker\GitIgnoreChecker( $path, '.distignore' );

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

		$dist_ignore_filepath = $path . '/.distignore';
		if ( file_exists( $dist_ignore_filepath ) ) {
			$file_ignore_rules = explode( PHP_EOL, file_get_contents( $dist_ignore_filepath ) );
		} else {
			WP_CLI::warning( 'No .distignore file found. All files in directory included in archive.' );
			$file_ignore_rules = array();
		}

		$source_base  = basename( $path );
		$archive_base = isset( $assoc_args['plugin-dirname'] ) ? rtrim( $assoc_args['plugin-dirname'], '/' ) : $source_base;

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
			$tmp_dir  = sys_get_temp_dir() . uniqid( $archive_base . '.' . $version );
			$new_path = $tmp_dir . DIRECTORY_SEPARATOR . $archive_base;
			mkdir( $new_path, 0777, true );
			foreach ( $this->get_file_list( $path ) as $relative_filepath ) {
				$source_item = $path . $relative_filepath;
				if ( is_dir( $source_item ) ) {
					mkdir( $new_path . '/' . $relative_filepath, 0777, true );
				} else {
					copy( $source_item, $new_path . $relative_filepath );
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

		// If the files are being zipped in place, we need the exclusion rules.
		// whereas if they were copied for any reasons above, the rules have already been applied.
		if ( $source_path !== $path || empty( $file_ignore_rules ) ) {
			if ( 'zip' === $assoc_args['format'] ) {
				$cmd = "zip -r '{$archive_filepath}' {$archive_base}";
			} elseif ( 'targz' === $assoc_args['format'] ) {
				$cmd = "tar -zcvf {$archive_filepath} {$archive_base}";
			}
		} else {
			$tmp_dir = sys_get_temp_dir() . uniqid( $archive_base . '.' . $version );
			mkdir( $tmp_dir, 0777, true );
			if ( 'zip' === $assoc_args['format'] ) {
				$include_list_filepath = $tmp_dir . '/include-file-list.txt';
				file_put_contents(
					$include_list_filepath,
					trim(
						implode(
							"\n",
							array_map(
								function( $relative_path ) use ( $source_path ) {
									return basename( $source_path ) . $relative_path;
								},
								$this->get_file_list( $source_path )
							)
						)
					)
				);
				$cmd = "zip -r '{$archive_filepath}' {$archive_base} -i@{$include_list_filepath}";
			} elseif ( 'targz' === $assoc_args['format'] ) {
				$exclude_list_filepath = $tmp_dir . '/exclude-file-list.txt';
				$excludes              = array_filter(
					array_map(
						function( $ignored_file ) use ( $source_path ) {
							return '^' . preg_quote( basename( $source_path ) . $ignored_file, '\\' ) . '$';
						},
						$this->get_file_list( $source_path, true )
					)
				);
				file_put_contents(
					$exclude_list_filepath,
					trim( implode( "\n", $excludes ) )
				);
				$cmd = "tar --exclude-from={$exclude_list_filepath} -zcvf {$archive_filepath} {$archive_base}";
			}
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
	 * Filter all files in a path to list of file to include or to exclude from the archive.
	 *
	 * Exclude list should contain directory names when no files in that directory exist in the include list.
	 *
	 * @param string $path Path to process
	 * @param bool $excluded Return the list of files to exclude. Default (false) returns the list of files to include.
	 * @return string[]
	 */
	private function get_file_list( $path, $excluded = false ) {

		$included_files = array();
		$excluded_files = array();

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
			$relative_filepath = str_replace( $path, '', $item->getPathname() );
			if ( $this->checker->isPathIgnored( $relative_filepath ) ) {
				$excluded_files[] = $relative_filepath;
			} else {
				$included_files[] = $relative_filepath;
			}
		}

		// Check all excluded directories and remove the from the excluded list if they contain included files.
		foreach ( $excluded_files as $excluded_file_index => $excluded_relative_path ) {
			if ( ! is_dir( $path . $excluded_relative_path ) ) {
				continue;
			}
			foreach ( $included_files as $included_relative_path ) {
				if ( 0 === strpos( $included_relative_path, $excluded_relative_path ) ) {
					unset( $excluded_files[ $excluded_file_index ] );
				}
			}
		}

		return $excluded ? $excluded_files : $included_files;
	}
}
