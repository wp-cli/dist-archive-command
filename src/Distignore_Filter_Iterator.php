<?php

use Inmarelibero\GitIgnoreChecker\GitIgnoreChecker;

/**
 * Filter iterator that skips ignored directories to improve performance.
 *
 * This filter prevents RecursiveIteratorIterator from descending into
 * directories that are marked as ignored in .distignore, avoiding unnecessary
 * iteration through thousands of files in directories like node_modules.
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
	 *
	 * @return bool True if the current element is acceptable, false otherwise.
	 */
	#[\ReturnTypeWillChange]
	public function accept() {
		/** @var SplFileInfo $item */
		$item = $this->current();

		// If it's not a directory, accept it (filtering will happen later in get_file_list).
		if ( ! $item->isDir() ) {
			return true;
		}

		// For directories, check if they should be ignored to prevent descending into them.
		$relative_filepath = str_replace( $this->source_dir_path, '', $item->getPathname() );

		try {
			// If the directory is ignored, reject it to prevent descending.
			return ! $this->checker->isPathIgnored( $relative_filepath );
		} catch ( \Inmarelibero\GitIgnoreChecker\Exception\InvalidArgumentException $exception ) {
			// If there's an error checking, allow it through (error will be handled in get_file_list).
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
