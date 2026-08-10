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
 *
 * @phpstan-consistent-constructor
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
	 * Cache for ignored status to avoid duplicate checks.
	 *
	 * @var array<string, bool>
	 */
	private $ignored_cache = [];

	/**
	 * List of excluded file paths (relative).
	 *
	 * @var string[]
	 */
	private $excluded_files = [];

	/**
	 * List of items that had errors during checking.
	 *
	 * @var array<string, \Inmarelibero\GitIgnoreChecker\Exception\InvalidArgumentException>
	 */
	private $error_items = [];

	/**
	 * Set of real paths already visited to prevent infinite symlink loops.
	 *
	 * @var array<string, bool>
	 */
	private $visited_paths = [];

	/**
	 * Negation rules (lines with a leading `!`) from the `.distignore` file, without the `!`.
	 * Null until first read.
	 *
	 * @var string[]|null
	 */
	private $negation_rules;

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
	 * Get the relative file path for the current item.
	 *
	 * @param SplFileInfo $item The file item to get the relative path for.
	 * @return string The relative file path.
	 */
	private function getRelativeFilePath( SplFileInfo $item ) {
		$pathname           = $item->getPathname();
		$source_path_length = strlen( $this->source_dir_path );

		if ( 0 === strpos( $pathname, $this->source_dir_path ) ) {
			return substr( $pathname, $source_path_length );
		}

		return $pathname;
	}

	/**
	 * Check whether the current element of the iterator is acceptable.
	 * Filters out ignored files so they don't appear in the iteration.
	 * For directories, we're more conservative - we only filter them out
	 * if we're certain they and all their contents should be ignored.
	 *
	 * @return bool True if the element should be included, false otherwise.
	 */
	#[\ReturnTypeWillChange]
	public function accept() {
		/** @var SplFileInfo $item */
		$item = $this->current();

		// Get relative path.
		$relative_filepath = $this->getRelativeFilePath( $item );

		try {
			$is_ignored = $this->isPathIgnoredCached( $relative_filepath );

			if ( $is_ignored ) {
				// Track this as excluded.
				$this->excluded_files[] = $relative_filepath;

				// For files, we can safely filter them out.
				if ( ! $item->isDir() ) {
					return false;
				}

				// For directories, only filter out if we're not going to descend
				// (hasChildren will handle that check).
				// We need to yield ignored directories so they can be tracked in exclude lists.
				return true;
			}

			return true;
		} catch ( \Inmarelibero\GitIgnoreChecker\Exception\InvalidArgumentException $exception ) {
			// Store the error and yield the item so get_file_list can handle it.
			$this->error_items[ $relative_filepath ] = $exception;
			return true;
		}
	}

	/**
	 * Check if a path is ignored, with caching to avoid duplicate checks.
	 *
	 * @param string $relative_filepath Relative file path to check.
	 * @return bool True if the path is ignored, false otherwise.
	 * @throws \Inmarelibero\GitIgnoreChecker\Exception\InvalidArgumentException
	 */
	public function isPathIgnoredCached( $relative_filepath ) {
		if ( ! isset( $this->ignored_cache[ $relative_filepath ] ) ) {
			$this->ignored_cache[ $relative_filepath ] = $this->checker->isPathIgnored( $relative_filepath );
		}
		return $this->ignored_cache[ $relative_filepath ];
	}

	/**
	 * Check whether the current element has children that should be recursed into.
	 * We return false for ignored directories to prevent descending into them.
	 *
	 * An ignored directory's contents cannot appear in the archive, so there is no need
	 * to traverse them — except when a negation rule (leading `!`) might re-include a
	 * path inside it, e.g. `frontend/*` with `!/frontend/build/`, where the checker
	 * reports `/frontend` itself as ignored but `/frontend/build` is not.
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

		// Prevent infinite recursion from symlinks.
		// Get the real path to detect cycles.
		$real_path = $item->getRealPath();
		if ( false !== $real_path ) {
			// Check if we've already visited this real path.
			if ( isset( $this->visited_paths[ $real_path ] ) ) {
				return false;
			}

			// Check if this is a symlink pointing to a parent directory or creating a cycle.
			if ( $item->isLink() ) {
				// If the real path is a parent or ancestor of the source directory, skip it.
				if ( 0 === strpos( $this->source_dir_path, $real_path ) ) {
					return false;
				}
			}
		}

		// For directories, check if they should be ignored.
		$relative_filepath = $this->getRelativeFilePath( $item );

		try {
			if ( $this->isPathIgnoredCached( $relative_filepath ) ) {
				if ( $this->mightContainNegatedPath( $relative_filepath ) ) {
					return true;
				}
				WP_CLI::debug( "Skipping descent into ignored directory: {$relative_filepath}", 'dist-archive' );
				return false;
			}

			return true;
		} catch ( \Inmarelibero\GitIgnoreChecker\Exception\InvalidArgumentException $exception ) {
			// If there's an error checking, allow descending (error will be handled in get_file_list).
			WP_CLI::debug( "Error checking is path ignored for {$relative_filepath}: " . $exception->getMessage(), 'dist-archive' );
			return true;
		}
	}

	/**
	 * Check whether a `.distignore` negation rule (leading `!`) might re-include a path
	 * inside the given ignored directory, meaning it must still be descended into.
	 *
	 * Anchored negation patterns without wildcards are compared by path prefix; unanchored
	 * or wildcard patterns could match anywhere, so they conservatively require descent.
	 *
	 * @param string $relative_dirpath Relative path of the ignored directory.
	 */
	private function mightContainNegatedPath( string $relative_dirpath ): bool {
		if ( null === $this->negation_rules ) {
			$this->negation_rules = [];
			$distignore_filepath  = $this->source_dir_path . '/.distignore';
			if ( file_exists( $distignore_filepath ) ) {
				foreach ( explode( "\n", (string) file_get_contents( $distignore_filepath ) ) as $line ) {
					$line = trim( $line );
					if ( '' !== $line && '!' === $line[0] ) {
						$this->negation_rules[] = substr( $line, 1 );
					}
				}
			}
		}

		foreach ( $this->negation_rules as $rule ) {
			$rule = rtrim( $rule, '/' );
			if ( '' === $rule || '/' !== $rule[0] || false !== strpbrk( $rule, '*?[' ) ) {
				return true;
			}
			if ( 0 === strpos( $rule, $relative_dirpath . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the inner iterator's children wrapped in this filter.
	 *
	 * @return static
	 */
	#[\ReturnTypeWillChange]
	public function getChildren() {
		/** @var RecursiveDirectoryIterator $inner */
		$inner = $this->getInnerIterator();

		// Mark the current real path as visited to prevent infinite loops.
		/** @var SplFileInfo $item */
		$item      = $this->current();
		$real_path = $item->getRealPath();
		if ( false !== $real_path ) {
			$this->visited_paths[ $real_path ] = true;
		}

		// Pass the same arrays by reference so they accumulate across all levels.
		$child                 = new static( $inner->getChildren(), $this->checker, $this->source_dir_path );
		$child->excluded_files = &$this->excluded_files;
		$child->ignored_cache  = &$this->ignored_cache;
		$child->error_items    = &$this->error_items;
		$child->visited_paths  = &$this->visited_paths;
		$child->negation_rules = &$this->negation_rules;
		return $child;
	}

	/**
	 * Get the list of excluded files that were filtered out.
	 *
	 * @return string[]
	 */
	public function getExcludedFiles() {
		return $this->excluded_files;
	}

	/**
	 * Check if an item had an error during processing.
	 *
	 * @param string $relative_filepath Relative file path to check.
	 * @return \Inmarelibero\GitIgnoreChecker\Exception\InvalidArgumentException|null
	 */
	public function getErrorForItem( $relative_filepath ) {
		return $this->error_items[ $relative_filepath ] ?? null;
	}
}
