<?php

use Inmarelibero\GitIgnoreChecker\GitIgnoreChecker;

/**
 * Filter iterator that skips descending into ignored directories to improve performance.
 *
 * This filter prevents RecursiveIteratorIterator from descending into
 * directories that are marked as ignored in .distignore, avoiding unnecessary
 * iteration through thousands of files in directories like node_modules.
 * However, it still yields the ignored directories themselves so they can
 * be properly tracked in exclude lists.
 */
class Distignore_Filter_Iterator extends RecursiveFilterIterator {
	/**
	 * @var GitIgnoreChecker
	 */
	private $checker;

	/**
	 * @var string
	 */
	private $source_dir_path;

	/**
	 * Constructor.
	 *
	 * @param RecursiveIterator<string, SplFileInfo> $iterator Iterator to filter.
	 * @param GitIgnoreChecker $checker GitIgnore checker instance.
	 * @param string $source_dir_path Base directory path.
	 */
	public function __construct( RecursiveIterator $iterator, GitIgnoreChecker $checker, $source_dir_path ) {
		parent::__construct( $iterator );
		$this->checker         = $checker;
		$this->source_dir_path = $source_dir_path;
	}

	/**
	 * Check whether the current element of the iterator is acceptable.
	 * We accept all elements so they can be checked in get_file_list().
	 *
	 * @return bool Always true to accept all elements.
	 */
	#[\ReturnTypeWillChange]
	public function accept() {
		// Accept all elements - filtering happens in get_file_list().
		return true;
	}

	/**
	 * Check whether the current element has children that should be recursed into.
	 * We return false for certain ignored directories to prevent descending into them.
	 *
	 * This optimization only applies to directories that appear to be "leaf" ignore
	 * patterns (simple directory names without wildcards), to safely handle cases
	 * like `node_modules` while still correctly processing complex patterns with
	 * negations like `frontend/*` with `!/frontend/build/`.
	 *
	 * @return bool True if we should descend into this directory, false otherwise.
	 */
	#[\ReturnTypeWillChange]
	public function hasChildren() {
		/** @var SplFileInfo $item */
		$item = $this->current();

		// If it's not a directory, it has no children.
		if ( ! $item->isDir() ) {
			return false;
		}

		// For directories, check if they should be ignored.
		$relative_filepath = str_replace( $this->source_dir_path, '', $item->getPathname() );

		try {
			$is_ignored = $this->checker->isPathIgnored( $relative_filepath );

			if ( ! $is_ignored ) {
				// Not ignored, so descend.
				return true;
			}

			// Directory is ignored. Check if it's safe to skip descent.
			// We only skip for single-level directories (no slashes except leading/trailing)
			// to avoid issues with wildcard patterns and negations.
			$path_parts = explode( '/', trim( $relative_filepath, '/' ) );
			if ( count( $path_parts ) === 1 ) {
				// This is a top-level ignored directory like "/node_modules" or "/.git".
				// It's likely safe to skip descent as these are typically simple patterns.
				// However, we still need to be conservative. Let's check if a child would be ignored.
				$test_child = $relative_filepath . '/test';
				try {
					$child_ignored = $this->checker->isPathIgnored( $test_child );
					if ( $child_ignored ) {
						// Child is also ignored, safe to skip descent.
						return false;
					}
				} catch ( \Inmarelibero\GitIgnoreChecker\Exception\InvalidArgumentException $exception ) {
					// On error, descend to be safe.
					return true;
				}
			}

			// For nested directories or if test shows children might not be ignored, descend.
			return true;
		} catch ( \Inmarelibero\GitIgnoreChecker\Exception\InvalidArgumentException $exception ) {
			// If there's an error checking, allow descending (error will be handled in get_file_list).
			return true;
		}
	}

	/**
	 * Return the inner iterator's children wrapped in this filter.
	 *
	 * @return RecursiveFilterIterator
	 */
	#[\ReturnTypeWillChange]
	public function getChildren() {
		/** @var RecursiveDirectoryIterator $inner */
		$inner = $this->getInnerIterator();
		return new self( $inner->getChildren(), $this->checker, $this->source_dir_path );
	}
}
