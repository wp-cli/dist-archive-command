<?php

use WP_CLI\Tests\TestCase;
use Inmarelibero\GitIgnoreChecker\GitIgnoreChecker;

class Distignore_Filter_Iterator_Test extends TestCase {

	/**
	 * Temporary directory for test files.
	 *
	 * @var string
	 */
	private $temp_dir;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/distignore-test-' . uniqid();
		mkdir( $this->temp_dir );
	}

	/**
	 * Clean up test environment.
	 */
	public function tearDown(): void {
		if ( is_dir( $this->temp_dir ) ) {
			$this->recursiveDelete( $this->temp_dir );
		}
		parent::tearDown();
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory to delete.
	 */
	private function recursiveDelete( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			is_dir( $path ) ? $this->recursiveDelete( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	/**
	 * Test that the iterator filters out ignored files.
	 */
	public function test_filters_ignored_files() {
		// Create test structure.
		file_put_contents( $this->temp_dir . '/included.txt', 'test' );
		file_put_contents( $this->temp_dir . '/ignored.log', 'test' );
		file_put_contents( $this->temp_dir . '/.distignore', "*.log\n" );

		$checker        = new GitIgnoreChecker( $this->temp_dir, '.distignore' );
		$directory_iter = new RecursiveDirectoryIterator( $this->temp_dir, RecursiveDirectoryIterator::SKIP_DOTS );
		$filter_iter    = new Distignore_Filter_Iterator( $directory_iter, $checker, $this->temp_dir );
		$recursive_iter = new RecursiveIteratorIterator( $filter_iter, RecursiveIteratorIterator::SELF_FIRST );

		$files = [];
		foreach ( $recursive_iter as $item ) {
			$files[] = basename( $item->getPathname() );
		}

		$this->assertContains( 'included.txt', $files );
		$this->assertContains( '.distignore', $files );
		$this->assertNotContains( 'ignored.log', $files, 'Ignored file should not be yielded' );
	}

	/**
	 * Test that ignored directories are tracked but files inside are not yielded.
	 */
	public function test_tracks_ignored_directories() {
		// Create test structure.
		mkdir( $this->temp_dir . '/node_modules' );
		file_put_contents( $this->temp_dir . '/node_modules/package.json', '{}' );
		file_put_contents( $this->temp_dir . '/index.php', '<?php' );
		file_put_contents( $this->temp_dir . '/.distignore', "node_modules\n" );

		$checker        = new GitIgnoreChecker( $this->temp_dir, '.distignore' );
		$directory_iter = new RecursiveDirectoryIterator( $this->temp_dir, RecursiveDirectoryIterator::SKIP_DOTS );
		$filter_iter    = new Distignore_Filter_Iterator( $directory_iter, $checker, $this->temp_dir );
		$recursive_iter = new RecursiveIteratorIterator( $filter_iter, RecursiveIteratorIterator::SELF_FIRST );

		$files = [];
		foreach ( $recursive_iter as $item ) {
			$relative_path = str_replace( $this->temp_dir, '', $item->getPathname() );
			$files[]       = $relative_path;
		}

		$this->assertContains( '/index.php', $files );
		$this->assertContains( '/.distignore', $files );
		$this->assertContains( '/node_modules', $files, 'Ignored directory should be yielded for tracking' );
		$this->assertNotContains( '/node_modules/package.json', $files, 'Files inside ignored directory should not be yielded' );
	}

	/**
	 * Test that getExcludedFiles returns the correct list.
	 */
	public function test_get_excluded_files() {
		mkdir( $this->temp_dir . '/ignored_dir' );
		file_put_contents( $this->temp_dir . '/ignored_dir/file.txt', 'test' );
		file_put_contents( $this->temp_dir . '/included.txt', 'test' );
		file_put_contents( $this->temp_dir . '/.distignore', "ignored_dir\n" );

		$checker        = new GitIgnoreChecker( $this->temp_dir, '.distignore' );
		$directory_iter = new RecursiveDirectoryIterator( $this->temp_dir, RecursiveDirectoryIterator::SKIP_DOTS );
		$filter_iter    = new Distignore_Filter_Iterator( $directory_iter, $checker, $this->temp_dir );
		$recursive_iter = new RecursiveIteratorIterator( $filter_iter, RecursiveIteratorIterator::SELF_FIRST );

		// Iterate to populate excluded files.
		iterator_to_array( $recursive_iter );

		$excluded = $filter_iter->getExcludedFiles();

		$this->assertContains( '/ignored_dir', $excluded );
		$this->assertNotContains( '/included.txt', $excluded );
	}

	/**
	 * Test caching behavior to avoid duplicate checks.
	 */
	public function test_caching_avoids_duplicate_checks() {
		file_put_contents( $this->temp_dir . '/test.txt', 'test' );
		file_put_contents( $this->temp_dir . '/.distignore', "*.log\n" );

		$checker        = new GitIgnoreChecker( $this->temp_dir, '.distignore' );
		$directory_iter = new RecursiveDirectoryIterator( $this->temp_dir, RecursiveDirectoryIterator::SKIP_DOTS );
		$filter_iter    = new Distignore_Filter_Iterator( $directory_iter, $checker, $this->temp_dir );

		// First call should cache the result.
		$result1 = $filter_iter->isPathIgnoredCached( '/test.txt' );
		// Second call should use cache.
		$result2 = $filter_iter->isPathIgnoredCached( '/test.txt' );

		$this->assertSame( $result1, $result2 );
		$this->assertFalse( $result1 ); // test.txt should not be ignored.
	}

	/**
	 * Test that hasChildren prevents descent into ignored directories.
	 */
	public function test_has_children_prevents_descent() {
		mkdir( $this->temp_dir . '/node_modules' );
		file_put_contents( $this->temp_dir . '/node_modules/file1.js', 'test' );
		file_put_contents( $this->temp_dir . '/node_modules/file2.js', 'test' );
		file_put_contents( $this->temp_dir . '/.distignore', "node_modules\n" );

		$checker        = new GitIgnoreChecker( $this->temp_dir, '.distignore' );
		$directory_iter = new RecursiveDirectoryIterator( $this->temp_dir, RecursiveDirectoryIterator::SKIP_DOTS );
		$filter_iter    = new Distignore_Filter_Iterator( $directory_iter, $checker, $this->temp_dir );
		$recursive_iter = new RecursiveIteratorIterator( $filter_iter, RecursiveIteratorIterator::SELF_FIRST );

		$files = [];
		foreach ( $recursive_iter as $item ) {
			$relative_path = str_replace( $this->temp_dir, '', $item->getPathname() );
			$files[]       = $relative_path;
		}

		// The node_modules directory should be yielded but its files should not.
		$this->assertContains( '/node_modules', $files );
		$this->assertNotContains( '/node_modules/file1.js', $files );
		$this->assertNotContains( '/node_modules/file2.js', $files );
	}

	/**
	 * Test handling of negation patterns.
	 */
	public function test_negation_patterns() {
		mkdir( $this->temp_dir . '/frontend' );
		mkdir( $this->temp_dir . '/frontend/build' );
		file_put_contents( $this->temp_dir . '/frontend/source.ts', 'test' );
		file_put_contents( $this->temp_dir . '/frontend/build/output.js', 'test' );
		file_put_contents( $this->temp_dir . '/.distignore', "frontend/*\n!/frontend/build/\n" );

		$checker        = new GitIgnoreChecker( $this->temp_dir, '.distignore' );
		$directory_iter = new RecursiveDirectoryIterator( $this->temp_dir, RecursiveDirectoryIterator::SKIP_DOTS );
		$filter_iter    = new Distignore_Filter_Iterator( $directory_iter, $checker, $this->temp_dir );
		$recursive_iter = new RecursiveIteratorIterator( $filter_iter, RecursiveIteratorIterator::SELF_FIRST );

		$files = [];
		foreach ( $recursive_iter as $item ) {
			$relative_path = str_replace( $this->temp_dir, '', $item->getPathname() );
			$files[]       = $relative_path;
		}

		$this->assertContains( '/frontend', $files );
		$this->assertContains( '/frontend/build', $files );
		$this->assertContains( '/frontend/build/output.js', $files, 'Negated path should be included' );
		$this->assertNotContains( '/frontend/source.ts', $files, 'Ignored file should not be included' );
	}

	/**
	 * Test getErrorForItem returns null when no error.
	 */
	public function test_get_error_for_item_returns_null() {
		file_put_contents( $this->temp_dir . '/test.txt', 'test' );
		file_put_contents( $this->temp_dir . '/.distignore', '' );

		$checker        = new GitIgnoreChecker( $this->temp_dir, '.distignore' );
		$directory_iter = new RecursiveDirectoryIterator( $this->temp_dir, RecursiveDirectoryIterator::SKIP_DOTS );
		$filter_iter    = new Distignore_Filter_Iterator( $directory_iter, $checker, $this->temp_dir );

		$error = $filter_iter->getErrorForItem( '/test.txt' );

		$this->assertNull( $error );
	}

	/**
	 * Test that multiple levels of directories are handled correctly.
	 */
	public function test_nested_directory_filtering() {
		mkdir( $this->temp_dir . '/src' );
		mkdir( $this->temp_dir . '/src/components' );
		file_put_contents( $this->temp_dir . '/src/index.php', '<?php' );
		file_put_contents( $this->temp_dir . '/src/components/widget.php', '<?php' );
		file_put_contents( $this->temp_dir . '/.distignore', '' );

		$checker        = new GitIgnoreChecker( $this->temp_dir, '.distignore' );
		$directory_iter = new RecursiveDirectoryIterator( $this->temp_dir, RecursiveDirectoryIterator::SKIP_DOTS );
		$filter_iter    = new Distignore_Filter_Iterator( $directory_iter, $checker, $this->temp_dir );
		$recursive_iter = new RecursiveIteratorIterator( $filter_iter, RecursiveIteratorIterator::SELF_FIRST );

		$files = [];
		foreach ( $recursive_iter as $item ) {
			$relative_path = str_replace( $this->temp_dir, '', $item->getPathname() );
			$files[]       = $relative_path;
		}

		$this->assertContains( '/src', $files );
		$this->assertContains( '/src/components', $files );
		$this->assertContains( '/src/index.php', $files );
		$this->assertContains( '/src/components/widget.php', $files );
	}

	/**
	 * Test that children share the same cache and excluded files arrays.
	 */
	public function test_children_share_state() {
		mkdir( $this->temp_dir . '/level1' );
		mkdir( $this->temp_dir . '/level1/level2' );
		file_put_contents( $this->temp_dir . '/level1/file1.txt', 'test' );
		file_put_contents( $this->temp_dir . '/level1/level2/file2.log', 'test' );
		file_put_contents( $this->temp_dir . '/.distignore', "*.log\n" );

		$checker        = new GitIgnoreChecker( $this->temp_dir, '.distignore' );
		$directory_iter = new RecursiveDirectoryIterator( $this->temp_dir, RecursiveDirectoryIterator::SKIP_DOTS );
		$filter_iter    = new Distignore_Filter_Iterator( $directory_iter, $checker, $this->temp_dir );
		$recursive_iter = new RecursiveIteratorIterator( $filter_iter, RecursiveIteratorIterator::SELF_FIRST );

		// Iterate to populate excluded files.
		iterator_to_array( $recursive_iter );

		$excluded = $filter_iter->getExcludedFiles();

		// The .log file in level2 should be tracked even though it was found by a child iterator.
		$this->assertContains( '/level1/level2/file2.log', $excluded );
	}
}
