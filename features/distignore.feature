# The "Examples" dataprovider is needed due to differences in zip and tar.

Feature: Generate a distribution archive of a project

  Scenario: Ignores backup files with a period and tilde
    Given an empty directory
    And a foo/.distignore file:
      """
      .*~
      """
    And a foo/test.php file:
      """
      <?php
      echo 'Hello world';
      """
    And a foo/.testwithperiod.php file:
      """
      <?php
      echo 'Hello world';
      """
    And a foo/.testwithtilde.php~after file:
      """
      <?php
      echo 'Hello world';
      """
    And a foo/.test.php~ file:
      """
      <?php
      echo 'Hello world'; bak
      """

    When I run `wp dist-archive foo`
    Then STDOUT should be:
      """
      Success: Created foo.zip
      """

    When I run `rm -rf foo`
    Then the foo directory should not exist

    When I try `unzip foo.zip`
    Then the foo directory should exist
    And the foo/test.php file should exist
    And the foo/.testwithperiod.php file should exist
    And the foo/.testwithtilde.php~after file should exist
    And the foo/.test.php~ file should not exist

  Scenario Outline: Ignores hidden files in subdirectories
    Given an empty directory
    And a foo/.distignore file:
      """
      .DS_Store
      """
    And a foo/test.php file:
      """
      <?php
      echo 'Hello world;';
      """
    And a foo/test-dir/test.php file:
      """
      <?php
      echo 'Hello world;';
      """
    And a foo/test-dir/.DS_Store file:
      """
      Bad!
      """

    When I run `wp dist-archive foo --format=<format>`
    Then STDOUT should be:
      """
      Success: Created foo.<extension>
      """
    And the foo.<extension> file should exist

    When I run `rm -rf foo`
    Then the foo directory should not exist

    When I try `<extract> foo.<extension>`
    Then the foo directory should exist
    And the foo/test.php file should exist
    And the foo/test-dir/test.php file should exist
    And the foo/test-dir/.DS_Store file should not exist

    Examples:
      | format | extension | extract   |
      | zip    | zip       | unzip     |
      | targz  | tar.gz    | tar -zxvf |

  Scenario Outline: Ignores .git folder
    Given an empty directory
    And a foo/.distignore file:
      """
      .git
      """
    And a foo/.git/version.control file:
      """
      history
      """
    And a foo/.git/subfolder/version.control file:
      """
      history
      """
    And a foo/plugin.php file:
      """
      <?php
      echo 'Hello world';
      """

    Then the foo/.git directory should exist

    Then the foo/.git/subfolder/version.control file should exist

    When I run `wp dist-archive foo --format=<format>`
    Then STDOUT should be:
      """
      Success: Created foo.<extension>
      """
    And the foo.<extension> file should exist

    When I run `rm -rf foo`
    Then the foo directory should not exist

    When I try `<extract> foo.<extension>`
    Then the foo directory should exist
    And the foo/plugin.php file should exist
    And the foo/.git directory should not exist
    And the foo/.git/subfolder directory should not exist
    And the foo/.git/version.control file should not exist
    And the foo/.git/subfolder/version.control file should not exist

    Examples:
      | format | extension | extract   |
      | zip    | zip       | unzip     |
      | targz  | tar.gz    | tar -zxvf |

  Scenario Outline: Ignores files specified with absolute path and not similarly named files
    Given an empty directory
    And a foo/.distignore file:
      """
      /maybe-ignore-me.txt
      """
    And a foo/test.php file:
      """
      <?php
      echo 'Hello world;';
      """
    And a foo/test-dir/test.php file:
      """
      <?php
      echo 'Hello world;';
      """
    And a foo/maybe-ignore-me.txt file:
      """
      Ignore
      """
    And a foo/test-dir/maybe-ignore-me.txt file:
      """
      Do not ignore
      """
    And a foo/test-dir/foo/maybe-ignore-me.txt file:
      """
      Do not ignore
      """

    When I run `wp dist-archive foo --format=<format> --plugin-dirname=<plugin-dirname>`
    Then STDOUT should be:
      """
      Success: Created <plugin-dirname>.<extension>
      """
    And the <plugin-dirname>.<extension> file should exist

    When I run `rm -rf foo`
    Then the foo directory should not exist

    When I run `rm -rf <plugin-dirname>`
    Then the <plugin-dirname> directory should not exist

    When I try `<extract> <plugin-dirname>.<extension>`
    Then the <plugin-dirname> directory should exist
    And the <plugin-dirname>/test.php file should exist
    And the <plugin-dirname>/test-dir/test.php file should exist
    And the <plugin-dirname>/maybe-ignore-me.txt file should not exist
    And the <plugin-dirname>/test-dir/maybe-ignore-me.txt file should exist
    And the <plugin-dirname>/test-dir/foo/maybe-ignore-me.txt file should exist

    Examples:
      | format | extension | extract   | plugin-dirname |
      | zip    | zip       | unzip     | foo            |
      | targz  | tar.gz    | tar -zxvf | foo            |
      | zip    | zip       | unzip     | bar            |
      | targz  | tar.gz    | tar -zxvf | bar2           |

  Scenario Outline: Correctly ignores hidden files when specified in distignore
    Given an empty directory
    And a foo/.distignore file:
      """
      .*
      """
    And a foo/.hidden file:
      """
      Ignore
      """
    And a foo/test-dir/.hidden file:
      """
      Ignore
      """
    And a foo/not.hidden file:
      """
      Do not ignore
      """
    And a foo/test-dir/not.hidden file:
      """
      Do not ignore
      """

    When I run `wp dist-archive foo --format=<format> --plugin-dirname=<plugin-dirname>`
    Then STDOUT should be:
      """
      Success: Created <plugin-dirname>.<extension>
      """
    And the <plugin-dirname>.<extension> file should exist

    When I run `rm -rf foo`
    Then the foo directory should not exist

    When I run `rm -rf <plugin-dirname>`
    Then the <plugin-dirname> directory should not exist

    When I try `<extract> <plugin-dirname>.<extension>`
    Then the <plugin-dirname> directory should exist
    And the <plugin-dirname>/.hidden file should not exist
    And the <plugin-dirname>/not.hidden file should exist
    And the <plugin-dirname>/test-dir/.hidden file should not exist
    And the <plugin-dirname>/test-dir/not.hidden file should exist

    Examples:
      | format | extension | extract   | plugin-dirname |
      | zip    | zip       | unzip     | foo            |
      | targz  | tar.gz    | tar -zxvf | foo            |
      | zip    | zip       | unzip     | bar3           |
      | targz  | tar.gz    | tar -zxvf | bar4           |

  Scenario Outline: Ignores files with exact match and not similarly named files
    Given an empty directory
    And a foo/.distignore file:
      """
      ignore-me.js
      """
    And a foo/test.php file:
      """
      <?php
      echo 'Hello world;';
      """
    And a foo/ignore-me.json file:
      """
      Do not ignore
      """
    And a foo/ignore-me.js file:
      """
      Ignore
      """

    When I run `wp dist-archive foo --format=<format> --plugin-dirname=<plugin-dirname>`
    Then STDOUT should be:
      """
      Success: Created <plugin-dirname>.<extension>
      """
    And the <plugin-dirname>.<extension> file should exist

    When I run `rm -rf foo`
    Then the foo directory should not exist

    When I run `rm -rf <plugin-dirname>`
    Then the <plugin-dirname> directory should not exist

    When I try `<extract> <plugin-dirname>.<extension>`
    Then the <plugin-dirname> directory should exist
    And the <plugin-dirname>/test.php file should exist
    And the <plugin-dirname>/ignore-me.json file should exist
    And the <plugin-dirname>/ignore-me.js file should not exist

    Examples:
      | format | extension | extract   | plugin-dirname |
      | zip    | zip       | unzip     | foo            |
      | targz  | tar.gz    | tar -zxvf | foo            |
      | zip    | zip       | unzip     | bar            |
      | targz  | tar.gz    | tar -zxvf | bar2           |

  Scenario Outline: Ignores files in ignored directory except subdirectory excluded from exclusion: `!/frontend/build/`
    # @see https://github.com/wp-cli/dist-archive-command/issues/44#issue-917541953
    Given an empty directory
    And a foo/test.php file:
      """
      <?php
      echo 'Hello world;';
      """
    And a foo/.distignore file:
      """
      frontend/*
      !/frontend/build/
      """
    And a foo/frontend/test.ts file:
      """
      excludeme
      """
    And a foo/frontend/build/test.js file:
      """
      includeme
      """

    When I run `wp dist-archive foo --format=<format> --plugin-dirname=<plugin-dirname>`
    Then STDOUT should be:
      """
      Success: Created <plugin-dirname>.<extension>
      """
    And the <plugin-dirname>.<extension> file should exist

#    When I run `rm -rf foo`
    When I run `mv foo sourcefoo`
    Then the foo directory should not exist

    When I run `rm -rf <plugin-dirname>`
    Then the <plugin-dirname> directory should not exist

    When I try `<extract> <plugin-dirname>.<extension>`
    Then the <plugin-dirname> directory should exist
    And the <plugin-dirname>/test.php file should exist
    And the <plugin-dirname>/frontend/test.ts file should not exist
    And the <plugin-dirname>/frontend/build/test.js file should exist

    Examples:
      | format | extension | extract   | plugin-dirname |
      | zip    | zip       | unzip     | foo            |
      | targz  | tar.gz    | tar -zxvf | foo            |
      | zip    | zip       | unzip     | bar5           |
      | targz  | tar.gz    | tar -zxvf | bar6           |

  Scenario Outline: Ignores files matching pattern in all subdirectories of explicit directory: `blocks/src/block/**/*.js`
    # @see https://github.com/wp-cli/dist-archive-command/issues/44#issuecomment-1677135516
    Given an empty directory
    And a foo/.distignore file:
      """
      blocks/src/block/**/*.ts
      """
    And a foo/blocks/src/block/level1/test.ts file:
      """
      excludeme
      """
    And a foo/blocks/src/block/level1/test.js file:
      """
      includeme
      """
    And a foo/blocks/src/block/level1/level2/test.ts file:
      """
      excludeme
      """
    And a foo/blocks/src/block/level1/level2/test.js file:
      """
      includeme
      """

    When I run `wp dist-archive foo --format=<format> --plugin-dirname=<plugin-dirname>`
    Then STDOUT should be:
      """
      Success: Created <plugin-dirname>.<extension>
      """
    And the <plugin-dirname>.<extension> file should exist

    When I run `mv foo sourcefoo`
    Then the foo directory should not exist

    When I run `rm -rf <plugin-dirname>`
    Then the <plugin-dirname> directory should not exist

    When I try `<extract> <plugin-dirname>.<extension>`
    Then the <plugin-dirname> directory should exist
    And the <plugin-dirname>/blocks/src/block/level1/test.ts file should not exist
    And the <plugin-dirname>/blocks/src/block/level1/test.js file should exist
    And the <plugin-dirname>/blocks/src/block/level1/level2/test.ts file should not exist
    And the <plugin-dirname>/blocks/src/block/level1/level2/test.js file should exist

    Examples:
      | format | extension | extract   | plugin-dirname |
      | zip    | zip       | unzip     | foo            |
      | targz  | tar.gz    | tar -zxvf | foo            |
      | zip    | zip       | unzip     | bar7           |
      | targz  | tar.gz    | tar -zxvf | bar8           |
