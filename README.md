runcommand/dist-archive
=======================

Create a distribution archive based on a project's .distignore

[![Build Status](https://travis-ci.org/runcommand/dist-archive.svg?branch=master)](https://travis-ci.org/runcommand/dist-archive)

Quick links: [Using](#using) | [Installing](#installing) | [Contributing](#contributing)

## Using


~~~
wp dist-archive <path> [--format=<format>]
~~~

**OPTIONS**

	<path>
		Path to the project that includes a .distignore file.

	[--format=<format>]
		Choose the format for the archive.
		---
		default: zip
		options:
		  - zip
		  - targz
		---



## Installing

Installing this package requires WP-CLI v0.23.0 or greater. Update to the latest stable release with `wp cli update`.

Once you've done so, you can install this package with `wp package install runcommand/dist-archive`.

## Contributing

Code and ideas are more than welcome.

Please [open an issue](https://github.com/runcommand/dist-archive/issues) with questions, feedback, and violent dissent. Pull requests are expected to include test coverage.
