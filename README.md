runcommand/dist-archive
=======================

Create a distribution .zip or .tar.gz based on a plugin or theme's .distignore file.

[![Build Status](https://travis-ci.org/runcommand/dist-archive.svg?branch=master)](https://travis-ci.org/runcommand/dist-archive)

Quick links: [Using](#using) | [Installing](#installing) | [Contributing](#contributing)

## Using


~~~
wp dist-archive <path> [--format=<format>]
~~~

For a plugin in a directory 'wp-content/plugins/hello-world', this command
creates a distribution archive 'wp-content/plugins/hello-world.zip'.

You can specify files or directories you'd like to exclude from the archive
with a .distignore file in your project repository:

```
.distignore
.editorconfig
.git
.gitignore
.travis.yml
circle.yml
```

Use one distibution archive command for many projects, instead of a bash
script in each project.

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
