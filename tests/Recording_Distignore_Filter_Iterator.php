<?php

/**
 * Records every path checked against the ignore rules, including by the child iterators
 * that getChildren() creates via `new static`, since the property is static.
 */
class Recording_Distignore_Filter_Iterator extends Distignore_Filter_Iterator {

	/**
	 * Relative paths passed to isPathIgnoredCached, in order.
	 *
	 * @var string[]
	 */
	public static $checked_paths = [];

	/**
	 * @param string $relative_filepath Relative file path to check.
	 * @return bool True if the path is ignored, false otherwise.
	 */
	public function isPathIgnoredCached( $relative_filepath ): bool {
		self::$checked_paths[] = $relative_filepath;
		return parent::isPathIgnoredCached( $relative_filepath );
	}
}
