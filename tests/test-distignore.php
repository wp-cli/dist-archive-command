<?php

use WP_CLI\Tests\TestCase;

/**
 * @coversDefaultClass Dist_Archive_Command
 */
class DistignoreTest extends TestCase {

	/**
	 * @dataProvider distignoreSampleData
	 * @covers ::is_ignored_file
	 */
	public function testIgnoreFilesFunction( $filepath, $distignore_entries, $expected ) {

		$sut = new Dist_Archive_Command();

		$result = $sut->is_ignored_file( $filepath, $distignore_entries );

		$this->assertEquals( $expected, $result );
	}

	/**
	 * Example .distignore entries and files.
	 *
	 * Array of arrays containing [example filepath, distignore array, expected result].
	 *
	 * @return array<array<string,array<string>,bool>>
	 */
	public function distignoreSampleData() {

		return array(
			// Ignore .hidden files in the root dir when `.*` is specified.
			array(
				'.hidden',
				array( '.*' ),
				true,
			),
			// Ignore .hidden files in subdirs when `.*` is specified.
			array(
				'subdir/.hidden',
				array( '.*' ),
				true,
			),
			// Ignore .hidden files in the root dir when `/.*` is specified.
			array(
				'.hidden',
				array( '/.*' ),
				true,
			),
			// Do not ignore .hidden files in subdirs dir when `/.*` is specified.
			array(
				'subdir/.hidden',
				array( '/.*' ),
				false,
			),
			// Ignore all files in subdir when `subdir/*.*` is specified.
			array(
				'subdir/all.files',
				array( 'subdir/*.*' ),
				true,
			),
			// Do not ignore any files in root dir when `subdir/*.*` is specified.
			array(
				'all.files',
				array( 'subdir/*.*' ),
				false,
			),
			// Ignore .zip files in the root dir when `*.zip` is specified.
			array(
				'earlier-release.zip',
				array( '*.zip' ),
				true,
			),
			// Ignore .zip files in subdirs dir when `*.zip` is specified.
			array(
				'subdir/earlier-release.zip',
				array( '*.zip' ),
				true,
			),
			// Ignore .zip files in the root dir when `/*.zip` is specified.
			array(
				'earlier-release.zip',
				array( '/*.zip' ),
				true,
			),
			// Do not ignore .zip files in subdirs dir when `/*.zip` is specified.
			array(
				'subdir/earlier-release.zip',
				array( '/*.zip' ),
				true,
			),
			// Ignore maybe.txt file in the root dir when `/maybe.txt` is specified.
			array(
				'maybe.txt',
				array( '/maybe.txt' ),
				true,
			),
			// Do not ignore maybe.txt file in subdirs when `/maybe.txt` is specified.
			array(
				'subdir/maybe.txt',
				array( '/maybe.txt' ),
				false,
			),
		);

	}

}
