@local_vbs_myoverview @theme_vbs @f02 @javascript
Feature: Learner views personal learning progress page — F02 (/local/vbs_myoverview/progress.php)
  Covers AC-01 (active courses + progress bar), AC-03 (completed courses + certificate link),
  AC-04 (training plan for the current year) and AC-08 P0 (access-control — learner sees
  only their own data).

  The page is AMD-driven: the progress AMD module fetches
  local_vbs_myoverview_get_learning_progress (or bundled mock data with ?vbsmock=1) and
  hydrates each of the four sections independently. Rendering scenarios use ?vbsmock=1 so
  the harness does not require local_vbs_plan or mod_customcert. AC-08 uses the real WS
  to verify the server-side security gate enforced in execute().

  Background:
    # Lock site language to English so localised strings in the DOM are deterministic.
    Given the following config values are set as admin:
      | lang | en |
    And the following "users" exist:
      | username | firstname | lastname | email           |
      | sv001    | Học       | Viên     | sv001@vbs.test  |
      | sv002    | Học viên  | Hai      | sv002@vbs.test  |

  # ─────────────────────────────────────────────────────────────────
  # AC-01: Active courses with progress bar
  # ─────────────────────────────────────────────────────────────────

  Scenario: AC-01 – Progress page renders the four section headings
    Given I log in as "sv001"
    When I am on the learning progress page in mock mode
    Then I should see "Courses in progress"
    And I should see "Completed courses"
    And I should see "Issued certificates"

  Scenario: AC-01 – Active courses section shows course name and progress bar
    Given I log in as "sv001"
    When I am on the learning progress page in mock mode
    And the learning progress section "active" is loaded
    Then I should see "Kế toán cơ bản" in the "active" learning progress section
    And the "active" learning progress section contains a progress bar

  # ─────────────────────────────────────────────────────────────────
  # AC-03: Completed courses + certificate link
  # ─────────────────────────────────────────────────────────────────

  Scenario: AC-03 – Completed courses section shows a course with a certificate link
    Given I log in as "sv001"
    When I am on the learning progress page in mock mode
    And the learning progress section "completed" is loaded
    Then I should see "An toàn lao động" in the "completed" learning progress section
    And the "completed" learning progress section contains a "View certificate" link

  Scenario: AC-03 – Completed course without a certificate shows no certificate link
    Given I log in as "sv001"
    When I am on the learning progress page in mock mode
    And the learning progress section "completed" is loaded
    Then I should see "Định hướng hội nhập" in the "completed" learning progress section
    And the "completed" learning progress section does not contain a "View certificate" link for "Định hướng hội nhập"

  # ─────────────────────────────────────────────────────────────────
  # AC-04: Training plan for the current year
  # ─────────────────────────────────────────────────────────────────

  Scenario: AC-04 – Training plan section shows plan items with status badges
    Given I log in as "sv001"
    When I am on the learning progress page in mock mode
    And the learning progress section "plan" is loaded
    Then the "plan" learning progress section contains plan items
    And I should see "In progress" in the "plan" learning progress section
    And I should see "Completed" in the "plan" learning progress section

  # ─────────────────────────────────────────────────────────────────
  # AC-08 (P0): Learner sees only their own data
  # Uses the real WS — the security gate is in execute(), not the page shell.
  # ─────────────────────────────────────────────────────────────────

  Scenario: AC-08 – Learner cannot load another learner's progress data
    Given I log in as "sv001"
    When I am on the learning progress page for user "sv002"
    Then all learning progress sections remain in skeleton state
