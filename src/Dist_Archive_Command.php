<?php

use Inmarelibero\GitIgnoreChecker\GitIgnoreChecker;
use WP_CLI\Utils;

/**
 * Create a distribution archive based on a project's .distignore file.
 */
class Dist_Archive_Command {
	/**
	 * @var GitIgnoreChecker
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
	 * [--force]
	 * : Forces overwriting of the archive file if it already exists.
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
	 * : Use a custom format for archive filename. Available substitutions: {name}, {version}.
	 * This is ignored if the <target> parameter is provided or the version cannot be determined.
	 * ---
	 * default: "{name}.{version}"
	 * ---
	 *
	 * @when before_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {

		list( $source_dir_path, $destination_dir_path, $archive_file_name, $archive_output_dir_name ) = $this->get_file_paths_and_names( $args, $assoc_args );

		$this->checker        = new GitIgnoreChecker( $source_dir_path, '.distignore' );
		$dist_ignore_filepath = $source_dir_path . '/.distignore';
		if ( file_exists( $dist_ignore_filepath ) ) {
			$file_ignore_rules = explode( PHP_EOL, (string) file_get_contents( $dist_ignore_filepath ) );
		} else {
			WP_CLI::warning( 'No .distignore file found. All files in directory included in archive.' );
			$file_ignore_rules = [];
		}

		if ( basename( $source_dir_path ) !== $archive_output_dir_name || $this->is_path_contains_symlink( $source_dir_path ) ) {
			$tmp_dir  = sys_get_temp_dir() . '/' . uniqid( $archive_file_name );
			$new_path = "{$tmp_dir}/{$archive_output_dir_name}";
			mkdir( $new_path, 0777, true );
			foreach ( $this->get_file_list( $source_dir_path ) as $relative_filepath ) {
				$source_item = $source_dir_path . $relative_filepath;
				if ( is_dir( $source_item ) ) {
					mkdir( "{$new_path}/{$relative_filepath}", 0777, true );
				} else {
					copy( $source_item, $new_path . $relative_filepath );
				}
			}
			$source_path = $new_path;
		} else {
			$source_path = $source_dir_path;
		}

		$archive_absolute_filepath = "{$destination_dir_path}/{$archive_file_name}";

		if ( file_exists( $archive_absolute_filepath ) ) {
			$should_overwrite = Utils\get_flag_value( $assoc_args, 'force' );
			if ( ! $should_overwrite ) {
				WP_CLI::warning( 'Archive file already exists' );
				WP_CLI::log( $archive_absolute_filepath );
				$answer      = \cli\prompt(
					'Do you want to skip or replace it with a new archive?',
					$default = false,
					$marker  = ' [s/r]: '
				);
				$should_overwrite = 'r' === strtolower( $answer );
			}
			if ( ! $should_overwrite ) {
				WP_CLI::log( 'Skipping' . PHP_EOL );
				WP_CLI::log( 'Archive generation skipped.' );
				exit( 0 );
			}
			WP_CLI::log( "Replacing $archive_absolute_filepath" . PHP_EOL );
		}

		chdir( dirname( $source_path ) );

		$cmd = "zip -r '{$archive_absolute_filepath}' {$archive_output_dir_name}";

		// If the files are being zipped in place, we need the exclusion rules.
		// whereas if they were copied for any reasons above, the rules have already been applied.
		if ( $source_path !== $source_dir_path || empty( $file_ignore_rules ) ) {
			if ( 'zip' === $assoc_args['format'] ) {
				$cmd = "zip -r '{$archive_absolute_filepath}' {$archive_output_dir_name}";
			} elseif ( 'targz' === $assoc_args['format'] ) {
				$cmd = "tar -zcvf {$archive_absolute_filepath} {$archive_output_dir_name}";
			}
		} else {
			$tmp_dir = sys_get_temp_dir() . '/' . uniqid( $archive_file_name );
			mkdir( $tmp_dir, 0777, true );
			if ( 'zip' === $assoc_args['format'] ) {
				$include_list_filepath = $tmp_dir . '/include-file-list.txt';
				file_put_contents(
					$include_list_filepath,
					trim(
						implode(
							"\n",
							array_map(
								function ( $relative_path ) use ( $source_path ) {
									return basename( $source_path ) . $relative_path;
								},
								$this->get_file_list( $source_path )
							)
						)
					)
				);
				$cmd = "zip --filesync -r '{$archive_absolute_filepath}' {$archive_output_dir_name} -i@{$include_list_filepath}";
			} elseif ( 'targz' === $assoc_args['format'] ) {
				$exclude_list_filepath = "{$tmp_dir}/exclude-file-list.txt";
				$excludes              = array_filter(
					array_map(
						function ( $ignored_file ) use ( $source_path ) {
							$regex = preg_quote( basename( $source_path ) . $ignored_file, '\\' );
							return ( php_uname( 's' ) === 'Linux' ) ? $regex : "^{$regex}$";
						},
						$this->get_file_list( $source_path, true )
					)
				);
				file_put_contents(
					$exclude_list_filepath,
					trim( implode( "\n", $excludes ) )
				);
				$anchored_flag = ( php_uname( 's' ) === 'Linux' ) ? '--anchored ' : '';
				$cmd           = "tar {$anchored_flag} --exclude-from={$exclude_list_filepath} -zcvf {$archive_absolute_filepath} {$archive_output_dir_name}";
			}
		}

		$escape_whitelist = 'targz' === $assoc_args['format'] ? array( '^', '*' ) : array();
		WP_CLI::debug( "Running: {$cmd}", 'dist-archive' );
		$escaped_shell_command = $this->escapeshellcmd( $cmd, $escape_whitelist );

		/**
		 * @var WP_CLI\ProcessRun $ret
		 */
		$ret = WP_CLI::launch( $escaped_shell_command, false, true );
		if ( 0 === $ret->return_code ) {
			$filename  = pathinfo( $archive_absolute_filepath, PATHINFO_BASENAME );
			$file_size = $this->get_size_format( (int) filesize( $archive_absolute_filepath ), 2 );

			WP_CLI::success( "Created {$filename} (Size: {$file_size})" );
		} else {
			$error = $ret->stderr ?: $ret->stdout;
			WP_CLI::error( $error );
		}
	}

	/**
	 * Determine the full paths and names to use from the CLI input.
	 *
	 * I.e. the source directory, the output directory, the output filename, and the directory name the archive will
	 * extract to.
	 *
	 * @param non-empty-array<string> $args Source path (required), target (path or name, optional).
	 * @param array{format:string,filename-format:string,plugin-dirname?:string,create-target-dir?:bool} $assoc_args
	 *
	 * @return string[] $source_dir_path, $destination_dir_path, $destination_archive_name, $archive_output_dir_name
	 */
	private function get_file_paths_and_names( $args, $assoc_args ) {

		$source_dir_path = realpath( $args[0] );
		if ( ! $source_dir_path || ! is_dir( $source_dir_path ) ) {
			WP_CLI::error( 'Provided input path is not a directory.' );
		}

		if ( isset( $args[1] ) ) {
			$destination_input = $args[1];
			// If the end of the string is a filename (file.ext), use it for the output archive filename.
			if ( 1 === preg_match( '/(zip$|tar$|tar.gz$)/', $destination_input ) ) {
				$archive_file_name = basename( $destination_input );

				// If only the filename was supplied, use the plugin's parent directory for output, otherwise use
				// the supplied directory.
				$destination_dir_path = basename( $destination_input ) === $destination_input
					? dirname( $source_dir_path )
					: dirname( $destination_input );

			} else {
				// Only a path was supplied, not a filename.
				$destination_dir_path = $destination_input;
				$archive_file_name    = null;
			}
		} else {
			// Use the plugin's parent directory for output.
			$destination_dir_path = dirname( $source_dir_path );
			$archive_file_name    = null;
		}

		// Convert relative path to absolute path (check does it begin with e.g. "c:" or "/").
		if ( 1 !== preg_match( '/(^[a-zA-Z]+:|^\/)/', $destination_dir_path ) ) {
			$destination_dir_path = getcwd() . '/' . $destination_dir_path;
		}

		if ( Utils\get_flag_value( $assoc_args, 'create-target-dir' ) ) {
			$this->maybe_create_directory( $destination_dir_path );
		}

		$destination_dir_path = realpath( $destination_dir_path );

		if ( ! $destination_dir_path || ! is_dir( $destination_dir_path ) ) {
			WP_CLI::error( "Target directory does not exist: {$destination_dir_path}" );
		}

		// Use the optionally supplied plugin-dirname, or use the name of the directory containing the source files.
		$archive_output_dir_name = isset( $assoc_args['plugin-dirname'] )
			? rtrim( $assoc_args['plugin-dirname'], '/' )
			: basename( $source_dir_path );

		if ( is_null( $archive_file_name ) ) {
			$version = $this->get_version( $source_dir_path );

			// If the version number has been found, substitute it into the filename-format template, or just use the name.
			$archive_file_stem = ! empty( $version )
				? str_replace( [ '{name}', '{version}' ], [ $archive_output_dir_name, $version ], $assoc_args['filename-format'] )
				: $archive_output_dir_name;

			$archive_file_name = 'zip' === $assoc_args['format']
				? $archive_file_stem . '.zip'
				: $archive_file_stem . '.tar.gz';
		}

		return [ $source_dir_path, $destination_dir_path, $archive_file_name, $archive_output_dir_name ];
	}

	/**
	 * Determine the plugin version from style.css, the main plugin .php file, or composer.json.
	 *
	 * Append the commit hash to `-alpha` versions.
	 *
	 * @param string $source_dir_path
	 *
	 * @return string
	 */
	private function get_version( $source_dir_path ) {

		$version = '';

		/**
		 * If the path is a theme (meaning it contains a style.css file)
		 * parse the theme's version from the headers using a regex pattern.
		 * The pattern used is extracted from the get_file_data() function in core.
		 *
		 * @link https://developer.wordpress.org/reference/functions/get_file_data/
		 */
		if ( file_exists( $source_dir_path . '/style.css' ) ) {
			$contents = (string) file_get_contents( $source_dir_path . '/style.css', false, null, 0, 5000 );
			$contents = str_replace( "\r", "\n", $contents );
			$pattern  = '/^' . preg_quote( 'Version', ',' ) . ':(.*)$/mi';
			if ( preg_match( $pattern, $contents, $match ) && $match[1] ) {
				$version = trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
			}
		}

		if ( empty( $version ) ) {
			foreach ( (array) glob( $source_dir_path . '/*.php' ) as $php_file ) {
				if ( ! $php_file ) {
					continue;
				}
				$contents = (string) file_get_contents( $php_file, false, null, 0, 5000 );
				$ver      = $this->get_version_in_code( $contents );
				if ( ! empty( $ver ) ) {
					$version = trim( $ver );
					break;
				}
			}
		}

		if ( empty( $version ) && file_exists( $source_dir_path . '/composer.json' ) ) {
			/**
			 * @var null|object{version?: string} $composer_obj
			 */
			$composer_obj = json_decode( (string) file_get_contents( $source_dir_path . '/composer.json' ) );
			if ( $composer_obj && ! empty( $composer_obj->version ) ) {
				$version = trim( $composer_obj->version );
			}
		}

		if ( ! empty( $version ) && false !== stripos( $version, '-alpha' ) && is_dir( $source_dir_path . '/.git' ) ) {
			/**
			 * @var WP_CLI\ProcessRun $response
			 */
			$response   = WP_CLI::launch( "cd {$source_dir_path}; git log --pretty=format:'%h' -n 1", false, true );
			$maybe_hash = trim( $response->stdout );
			if ( $maybe_hash && 7 === strlen( $maybe_hash ) ) {
				$version .= '-' . $maybe_hash;
			}
		}

		return $version;
	}

	/**
	 * Create the directory for a target file if it does not exist yet.
	 *
	 * @param string $destination_dir_path Directory path for the target file.
	 * @return void
	 */
	private function maybe_create_directory( $destination_dir_path ) {
		if ( ! is_dir( $destination_dir_path ) ) {
			mkdir( $destination_dir_path, $mode = 0777, $recursive = true );
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
	 * @return array<string, string> Associative array of parsed data.
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

			$tag_name = trim( isset( $matches[1] ) ? strtolower( $matches[1] ) : '' );
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
	 * @param string $source_dir_path The path to the directory to check.
	 *
	 * @return bool
	 */
	protected function is_path_contains_symlink( $source_dir_path ) {

		if ( ! is_dir( $source_dir_path ) ) {
			throw new Exception( 'Path `' . $source_dir_path . '` is not a directory' );
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir_path, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		/**
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
	 * Filter all files in a path to either: a list of files to include in, or a list of files to exclude from, the archive.
	 *
	 * Exclude list should contain directory names when no files in that directory exist in the include list.
	 *
	 * @param string $source_dir_path Path to process.
	 * @param bool $excluded Whether to return the list of files to exclude. Default (false) returns the list of files to include.
	 * @return string[] Filtered list of files to include or exclude (depending on $excluded flag).
	 */
	private function get_file_list( $source_dir_path, $excluded = false ) {

		$included_files = [];

		$directory_iterator = new RecursiveDirectoryIterator( $source_dir_path, RecursiveDirectoryIterator::SKIP_DOTS );
		$filter_iterator    = new Distignore_Filter_Iterator( $directory_iterator, $this->checker, $source_dir_path );
		$iterator           = new RecursiveIteratorIterator(
			$filter_iterator,
			RecursiveIteratorIterator::SELF_FIRST
		);

		/**
		 * @var SplFileInfo $item
		 */
		foreach ( $iterator as $item ) {
			$pathname = $item->getPathname();
			if ( 0 === strpos( $pathname, $source_dir_path ) ) {
				$relative_filepath = substr( $pathname, strlen( $source_dir_path ) );
			} else {
				$relative_filepath = $pathname;
			}

			// Check if this item had an error during filtering.
			$error = $filter_iterator->getErrorForItem( $relative_filepath );
			if ( $error ) {
				if ( $item->isLink() && ! file_exists( (string) readlink( $item->getPathname() ) ) ) {
					WP_CLI::error( "Broken symlink at {$relative_filepath}. Target missing at {$item->getLinkTarget()}." );
				} else {
					WP_CLI::error( $error->getMessage() );
				}
			}

			// Check if this item is ignored (directories may still be yielded even if ignored).
			if ( ! $filter_iterator->isPathIgnoredCached( $relative_filepath ) ) {
				$included_files[] = $relative_filepath;
			}
		}

		if ( $excluded ) {
			// Get excluded files from the filter iterator.
			$excluded_files = $filter_iterator->getExcludedFiles();

			// Check all excluded directories and remove them from the excluded list if they contain included files.
			foreach ( $excluded_files as $excluded_file_index => $excluded_relative_path ) {
				if ( ! is_dir( $source_dir_path . $excluded_relative_path ) ) {
					continue;
				}
				foreach ( $included_files as $included_relative_path ) {
					if ( 0 === strpos( $included_relative_path, $excluded_relative_path ) ) {
						unset( $excluded_files[ $excluded_file_index ] );
					}
				}
			}

			return $excluded_files;
		}

		return $included_files;
	}

	/**
	 * Converts a number of bytes to the largest unit the bytes will fit into.
	 *
	 * @param int $bytes    Number of bytes.
	 * @param int $decimals Precision of number of decimal places.
	 * @return string Number string.
	 */
	private function get_size_format( $bytes, $decimals = 0 ) {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Backfilling WP native constants.
		if ( ! defined( 'KB_IN_BYTES' ) ) {
			define( 'KB_IN_BYTES', 1024 );
		}
		if ( ! defined( 'MB_IN_BYTES' ) ) {
			define( 'MB_IN_BYTES', 1024 * KB_IN_BYTES );
		}
		if ( ! defined( 'GB_IN_BYTES' ) ) {
			define( 'GB_IN_BYTES', 1024 * MB_IN_BYTES );
		}
		if ( ! defined( 'TB_IN_BYTES' ) ) {
			define( 'TB_IN_BYTES', 1024 * GB_IN_BYTES );
		}
		// phpcs:enable

		$size_key = floor( log( $bytes ) / log( 1000 ) );
		$sizes    = [ 'B', 'KB', 'MB', 'GB', 'TB' ];

		if ( is_infinite( $size_key ) ) {
			$size_key = 0;
		}

		$size_key = (int) $size_key;

		$size_format = isset( $sizes[ $size_key ] ) ? $sizes[ $size_key ] : $sizes[0];

		// Display the size as a number.
		switch ( $size_format ) {
			case 'TB':
				$divisor = pow( 1000, 4 );
				break;

			case 'GB':
				$divisor = pow( 1000, 3 );
				break;

			case 'MB':
				$divisor = pow( 1000, 2 );
				break;

			case 'KB':
				$divisor = 1000;
				break;

			case 'tb':
			case 'TiB':
				$divisor = TB_IN_BYTES;
				break;

			case 'gb':
			case 'GiB':
				$divisor = GB_IN_BYTES;
				break;

			case 'mb':
			case 'MiB':
				$divisor = MB_IN_BYTES;
				break;

			case 'kb':
			case 'KiB':
				$divisor = KB_IN_BYTES;
				break;

			case 'b':
			case 'B':
			default:
				$divisor = 1;
				break;
		}

		$size_format_display = preg_replace( '/IB$/u', 'iB', strtoupper( $size_format ) );

		return round( (int) $bytes / $divisor, $decimals ) . ' ' . $size_format_display;
	}
}
