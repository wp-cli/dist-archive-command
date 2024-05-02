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

		return $version;
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
	public function get_version_in_code( $code_str ) {
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
