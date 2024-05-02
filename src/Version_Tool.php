<?php

class Version_Tool {

	public function get_version( string $path ): ?string {

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
				$headers = $this->get_file_data(
					$php_file,
					array(
						'name'    => 'Plugin Name',
						'version' => 'Version',
					)
				);
				if ( empty( $headers['name'] ) ) {
					continue;
				}
				if ( ! empty( $headers['version'] ) ) {
					$version = $headers['version'];
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

		return $version;
	}

	/**
	 * Retrieves metadata from a file.
	 *
	 * Modified slightly from WordPress 6.5.2 wp-includes/functions.php:6830
	 * @see get_file_data()
	 * @see https://github.com/WordPress/WordPress/blob/ddc3f387b5df4687f5b829119d0c0f797be674bf/wp-includes/functions.php#L6830-L6888
	 *
	 * Searches for metadata in the first 8 KB of a file, such as a plugin or theme.
	 * Each piece of metadata must be on its own line. Fields can not span multiple
	 * lines, the value will get cut at the end of the first line.
	 *
	 * @link https://codex.wordpress.org/File_Header
	 *
	 * @param string $file        Absolute path to the file.
	 * @param array  $all_headers List of headers, in the format `array( 'HeaderKey' => 'Header Name' )`.
	 * @return string[] Array of file header values keyed by header name.
	 */
	private function get_file_data( string $file, array $all_headers ): array {

		/**
		 * @see wp_initial_constants()
		 * `define( 'KB_IN_BYTES', 1024 );`
		 */
		$kb_in_bytes = 1024;

		// Pull only the first 8 KB of the file in.
		$file_data = file_get_contents( $file, false, null, 0, 8 * $kb_in_bytes );

		if ( false === $file_data ) {
			$file_data = '';
		}

		// Make sure we catch CR-only line endings.
		$file_data = str_replace( "\r", "\n", $file_data );

		/**
		 * Strips close comment and close php tags from file headers used by WP.
		 *
		 * functions.php:6763
		 *
		 * @param string $str Header comment to clean up.
		 * @return string
		 */
		$_cleanup_header_comment = function ( $str ) {
			return trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $str ) );
		};

		foreach ( $all_headers as $field => $regex ) {
			if ( preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $regex, '/' ) . ':(.*)$/mi', $file_data, $match ) && $match[1] ) {
				$all_headers[ $field ] = $_cleanup_header_comment( $match[1] );
			} else {
				$all_headers[ $field ] = '';
			}
		}

		return $all_headers;
	}
}
