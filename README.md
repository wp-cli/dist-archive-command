wp-cli/dist-archive-command
===========================

Create a distribution .zip or .tar.gz based on a plugin or theme's .distignore file.

[![Testing](https://github.com/wp-cli/dist-archive-command/actions/workflows/testing.yml/badge.svg)](https://github.com/wp-cli/dist-archive-command/actions/workflows/testing.yml)

Quick links: [Using](#using) | [Installing](#installing) | [Contributing](#contributing) | [Support](#support)

## Using

~~~
wp dist-archive <path> [<target>] [--create-target-dir] [--force] [--plugin-dirname=<plugin-slug>] [--format=<format>] [--filename-format=<filename-format>]
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

Use one distribution archive command for many projects, instead of a bash
script in each project.

**OPTIONS**

	<path>
		Path to the project that includes a .distignore file.

	[<target>]
		Path and optional file name for the distribution archive.
		If only a path is provided, the file name defaults to the project directory name plus the version, if discoverable.
		Also, if only a path is given, the directory that it points to has to already exist for the command to function correctly.

	[--create-target-dir]
		Automatically create the target directory as needed.

	[--force]
		Forces overwriting of the archive file if it already exists.

	[--plugin-dirname=<plugin-slug>]
		Set the archive extract directory name. Defaults to project directory name.

	[--format=<format>]
		Choose the format for the archive.
		---
		default: zip
		options:
		  - zip
		  - targz
		---

	[--filename-format=<filename-format>]
		Use a custom format for archive filename. Available substitutions: {name}, {version}.
		This is ignored if the <target> parameter is provided or the version cannot be determined.
		---
		default: "{name}.{version}"
		---

## Installing

Installing this package requires WP-CLI v2 or greater. Update to the latest stable release with `wp cli update`.

Once you've done so, you can install the latest stable version of this package with:

```bash
wp package install wp-cli/dist-archive-command:@stable
```

To install the latest development version of this package, use the following command instead:

```bash
wp package install wp-cli/dist-archive-command:dev-main
```

## Contributing

We appreciate you taking the initiative to contribute to this project.

Contributing isn’t limited to just code. We encourage you to contribute in the way that best fits your abilities, by writing tutorials, giving a demo at your local meetup, helping other users with their support questions, or revising our documentation.

For a more thorough introduction, [check out WP-CLI's guide to contributing](https://make.wordpress.org/cli/handbook/contributing/). This package follows those policy and guidelines.

### Reporting a bug

Think you’ve found a bug? We’d love for you to help us get it fixed.

Before you create a new issue, you should [search existing issues](https://github.com/wp-cli/dist-archive-command/issues?q=label%3Abug%20) to see if there’s an existing resolution to it, or if it’s already been fixed in a newer version.

Once you’ve done a bit of searching and discovered there isn’t an open or fixed issue for your bug, please [create a new issue](https://github.com/wp-cli/dist-archive-command/issues/new). Include as much detail as you can, and clear steps to reproduce if possible. For more guidance, [review our bug report documentation](https://make.wordpress.org/cli/handbook/bug-reports/).

### Creating a pull request

Want to contribute a new feature? Please first [open a new issue](https://github.com/wp-cli/dist-archive-command/issues/new) to discuss whether the feature is a good fit for the project.

Once you've decided to commit the time to seeing your pull request through, [please follow our guidelines for creating a pull request](https://make.wordpress.org/cli/handbook/pull-requests/) to make sure it's a pleasant experience. See "[Setting up](https://make.wordpress.org/cli/handbook/pull-requests/#setting-up)" for details specific to working on this package locally.

## Support

GitHub issues aren't for general support questions, but there are other venues you can try: https://wp-cli.org/#support


*This README.md is generated dynamically from the project's codebase using `wp scaffold package-readme` ([doc](https://github.com/wp-cli/scaffold-package-command#wp-scaffold-package-readme)). To suggest changes, please submit a pull request against the corresponding part of the codebase.*
