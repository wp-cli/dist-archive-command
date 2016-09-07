runcommand/dist-archive
=======================

Create a distribution .zip or .tar.gz based on a plugin or theme's .distignore file.

[![CircleCI](https://circleci.com/gh/runcommand/dist-archive/tree/master.svg?style=svg)](https://circleci.com/gh/runcommand/dist-archive/tree/master)

Quick links: [Using](#using) | [Installing](#installing) | [Contributing](#contributing)

## Using

~~~
wp dist-archive <path> [<target>] [--format=<format>]
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

	[<target>]
		Path and file name for the distribution archive. Defaults to project directory name plus version, if discoverable.

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

We appreciate you taking the initiative to contribute to this project.

Think you’ve found a bug? Before you create a new issue, you should [search existing issues](https://github.com/runcommand/dist-archive/issues?q=label%3Abug%20) to see if there’s an existing resolution to it, or if it’s already been fixed in a newer version. Once you’ve done a bit of searching and discovered there isn’t an open or fixed issue for your bug, please [create a new issue](https://github.com/runcommand/dist-archive/issues/new) with description of what you were doing, what you saw, and what you expected to see.

Want to contribute a new feature? Please first [open a new issue](https://github.com/runcommand/dist-archive/issues/new) to discuss whether the feature is a good fit for the project. Once you've decided to work on a pull request, please include [functional tests](https://wp-cli.org/docs/pull-requests/#functional-tests) and follow the [WordPress Coding Standards](http://make.wordpress.org/core/handbook/coding-standards/).

Github issues are meant for tracking bugs and enhancements. For general support, email [support@runcommand.io](mailto:support@runcommand.io).


*This README.md is generated dynamically from the project's codebase using `wp scaffold package-readme` ([doc](https://github.com/wp-cli/scaffold-package-command#wp-scaffold-package-readme)). To suggest changes, please submit a pull request against the corresponding part of the codebase.*
