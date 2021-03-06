@javascript
Feature: Sort locales
  In order to sort locales in the catalog
  As a user
  I need to be able to sort locales by several columns in the catalog

  Background:
    Given the following locales:
      | code  | fallback | activated |
      | de_DE |          | no        |
      | en_US |          | yes       |
      | fr_FR |          | yes       |
    And I am logged in as "admin"

  Scenario: Successfully display the sortable columns
    Given I am on the locales page
    Then the rows should be sortable by code and activated
    And the rows should be sorted ascending by code
    And I should see sorted locales de_DE, en_US and fr_FR

  Scenario: Successfully sort locales by code
    Given I am on the locales page
    When I sort by "code" value ascending
    Then I should see sorted locales de_DE, en_US and fr_FR
    When I sort by "code" value descending
    Then I should see sorted locales fr_FR, en_US and de_DE

  Scenario: Successfully sort locales by activated
    Given I am on the locales page
    When I sort by "Activated" value ascending
    Then I should see sorted locales de_DE, en_US and fr_FR
    When I sort by "Activated" value descending
    Then I should see sorted locales en_US, fr_FR and de_DE
