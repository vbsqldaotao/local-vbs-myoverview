@local_vbs_myoverview @javascript
Feature: Course registration via VBS enrol UI
  As a learner
  I want to see appropriate messages when a course is full
  So that I understand I cannot register

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                | lang |
      | learner1 | Nguyen    | Van A    | learner1@example.com | vi   |
    And the following "courses" exist:
      | fullname        | shortname | visible |
      | Khóa học A      | KHA       | 1       |
      | Khóa học B Full | KHB       | 1       |
    And the following config values are set as admin:
      | lang | vi |
    And all visible courses are configured for VBS registration
    And I am logged in as "learner1"

  # TC-07-03: Error message is shown when course is full
  #
  # Uses the specific step `the register button for :coursename should be disabled`
  # (inherited from behat_local_vbs_enrol via the class hierarchy).
  # Do NOT add a general `the :label button for :coursename should be disabled` step
  # to behat_local_vbs_myoverview.php — it would create an Ambiguous match error
  # because the inherited specific step also matches that pattern.
  Scenario: Error message is shown when course is full
    Given the course "KHB" has no available slots
    When I navigate to "/local/vbs_enrol/index.php"
    Then I should see "Hết chỗ" in the "Khóa học B Full" course card
    And the register button for "Khóa học B Full" should be disabled
