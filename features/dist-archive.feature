Feature: Generate a distribution archive of a project

  Scenario: Generates a ZIP archive by default
    Given a WP install

    When I run `wp scaffold plugin hello-world`
    Then the wp-content/plugins/hello-world directory should exist
    And the wp-content/plugins/hello-world/hello-world.php file should exist
    And the wp-content/plugins/hello-world/.travis.yml file should exist
    And the wp-content/plugins/hello-world/bin directory should exist

    When I run `wp dist-archive wp-content/plugins/hello-world`
    And the wp-content/plugins/hello-world.0.1.0.zip file should exist

    When I run `wp plugin delete hello-world`
    Then the wp-content/plugins/hello-world directory should not exist

    When I run `wp plugin install wp-content/plugins/hello-world.0.1.0.zip`
    Then the wp-content/plugins/hello-world directory should exist
    And the wp-content/plugins/hello-world/hello-world.php file should exist
    And the wp-content/plugins/hello-world/.travis.yml file should not exist
    And the wp-content/plugins/hello-world/bin directory should not exist
