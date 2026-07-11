@local_vbs_myoverview @javascript
Feature: Learner views the course list — core block_myoverview (F01)
  Covers FR-F01-001 to FR-F01-005 (VBS-127) for the block_myoverview layer.
  VBS-specific badge/date-range tests (delta D6/D7) live in vbs-theme repo
  because they require theme_vbs active and local_vbs_myoverview installed together.

  Background:
    # B1 fix: lock site language to English so core UI labels are deterministic.
    Given the following config values are set as admin:
      | lang | en |
    And the following "users" exist:
      | username | firstname | lastname | email          |
      | sv001    | Học       | Viên     | sv001@vbs.test |
      | svnew    | Học viên  | Mới      | svnew@vbs.test |
    And the following "courses" exist:
      | fullname               | shortname    | startdate      | enddate        |
      | An toàn lao động       | ATLD-TEST    | ##2026-06-01## | ##2026-08-31## |
      | Kỹ năng mềm            | KNM-TEST     | ##2027-01-01## | ##2027-03-31## |
      | Nghiệp vụ đã kết thúc  | NVKT-TEST    | ##2026-01-01## | ##2026-03-31## |
      | Khóa học thứ tư        | COURSE4-TEST | ##2026-06-01## | ##2026-08-31## |
    And the following "course enrolments" exist:
      | user  | course       | role    |
      | sv001 | ATLD-TEST    | student |
      | sv001 | KNM-TEST     | student |
      | sv001 | NVKT-TEST    | student |
      | sv001 | COURSE4-TEST | student |

  # ─────────────────────────────────────────────────────────────────
  # FR-F01-001: Hiển thị danh sách khóa học được phân công
  # ─────────────────────────────────────────────────────────────────

  Scenario: FR-F01-001 – Học viên thấy tất cả khóa học được phân công
    Given I log in as "sv001"
    When I am on the VBS course list page
    Then I should see "An toàn lao động"
    And I should see "Kỹ năng mềm"
    And I should see "Nghiệp vụ đã kết thúc"
    And I should see "Khóa học thứ tư"

  Scenario: FR-F01-001 – Học viên không có khóa học thấy trạng thái rỗng
    Given I log in as "svnew"
    When I am on the VBS course list page
    Then I should see "not enrolled"

  # ─────────────────────────────────────────────────────────────────
  # FR-F01-002: Tìm kiếm theo từ khóa
  # ─────────────────────────────────────────────────────────────────

  Scenario: FR-F01-002 – Tìm kiếm theo từ khóa – happy path
    Given I log in as "sv001"
    And I am on the VBS course list page
    When I search courses for "An toàn"
    Then I should see "An toàn lao động"
    And I should not see "Kỹ năng mềm"
    And I should not see "Nghiệp vụ đã kết thúc"

  Scenario: FR-F01-002 – Tìm kiếm không có kết quả – hiển thị empty state
    Given I log in as "sv001"
    And I am on the VBS course list page
    When I search courses for "xyz_khong_ton_tai_99999"
    Then I should see the course search no-result message

  Scenario: FR-F01-002 – Xóa từ khóa – khôi phục danh sách đầy đủ
    Given I log in as "sv001"
    And I am on the VBS course list page
    When I search courses for "An toàn"
    And I clear the course search
    Then I should see "An toàn lao động"
    And I should see "Kỹ năng mềm"

  Scenario: FR-F01-002 + FR-F01-003 – Tìm kiếm kết hợp bộ lọc trạng thái (AND logic)
    Given I log in as "sv001"
    And I am on the VBS course list page
    And I filter courses by "inprogress"
    When I search courses for "An toàn"
    Then I should see "An toàn lao động"
    And I should not see "Kỹ năng mềm"
    And I should not see "Nghiệp vụ đã kết thúc"

  # ─────────────────────────────────────────────────────────────────
  # FR-F01-003: Lọc theo trạng thái
  # ─────────────────────────────────────────────────────────────────

  Scenario: FR-F01-003 – Lọc "In progress" chỉ hiển thị khóa đang diễn ra
    Given I log in as "sv001"
    And I am on the VBS course list page
    When I filter courses by "inprogress"
    Then I should see "An toàn lao động"
    And I should see "Khóa học thứ tư"
    And I should not see "Kỹ năng mềm"
    And I should not see "Nghiệp vụ đã kết thúc"

  Scenario: FR-F01-003 – Lọc "Future" chỉ hiển thị khóa chưa bắt đầu
    Given I log in as "sv001"
    And I am on the VBS course list page
    When I filter courses by "future"
    Then I should see "Kỹ năng mềm"
    And I should not see "An toàn lao động"
    And I should not see "Nghiệp vụ đã kết thúc"

  Scenario: FR-F01-003 – Lọc "Past" chỉ hiển thị khóa đã kết thúc
    Given I log in as "sv001"
    And I am on the VBS course list page
    When I filter courses by "past"
    Then I should see "Nghiệp vụ đã kết thúc"
    And I should not see "An toàn lao động"
    And I should not see "Kỹ năng mềm"

  Scenario: FR-F01-003 – Xóa bộ lọc "All" trả về danh sách đầy đủ
    Given I log in as "sv001"
    And I am on the VBS course list page
    And I filter courses by "inprogress"
    When I filter courses by "all"
    Then I should see "An toàn lao động"
    And I should see "Kỹ năng mềm"
    And I should see "Nghiệp vụ đã kết thúc"

  # ─────────────────────────────────────────────────────────────────
  # FR-F01-005: Phân trang — bộ lọc được giữ sau khi tải thêm
  # B3 fix: valid step syntax + sufficient courses to trigger pagination
  # ─────────────────────────────────────────────────────────────────

  @vbs_pagination
  Scenario: FR-F01-005 – Bộ lọc được giữ sau khi tải thêm khóa học
    # Moodle 4.4 stores page size as a user preference (min valid = 12, default = 12).
    # Background provides 2 in-progress courses; add 11 more to reach 13 total,
    # which exceeds the default page size of 12 and triggers the "next page" control.
    Given the following "courses" exist:
      | fullname    | shortname | startdate      | enddate        |
      | Khóa học 5  | C05-TEST  | ##2026-06-01## | ##2026-08-31## |
      | Khóa học 6  | C06-TEST  | ##2026-06-01## | ##2026-08-31## |
      | Khóa học 7  | C07-TEST  | ##2026-06-01## | ##2026-08-31## |
      | Khóa học 8  | C08-TEST  | ##2026-06-01## | ##2026-08-31## |
      | Khóa học 9  | C09-TEST  | ##2026-06-01## | ##2026-08-31## |
      | Khóa học 10 | C10-TEST  | ##2026-06-01## | ##2026-08-31## |
      | Khóa học 11 | C11-TEST  | ##2026-06-01## | ##2026-08-31## |
      | Khóa học 12 | C12-TEST  | ##2026-06-01## | ##2026-08-31## |
      | Khóa học 13 | C13-TEST  | ##2026-06-01## | ##2026-08-31## |
      | Khóa học 14 | C14-TEST  | ##2026-06-01## | ##2026-08-31## |
      | Khóa học 15 | C15-TEST  | ##2026-06-01## | ##2026-08-31## |
    And the following "course enrolments" exist:
      | user  | course   | role    |
      | sv001 | C05-TEST | student |
      | sv001 | C06-TEST | student |
      | sv001 | C07-TEST | student |
      | sv001 | C08-TEST | student |
      | sv001 | C09-TEST | student |
      | sv001 | C10-TEST | student |
      | sv001 | C11-TEST | student |
      | sv001 | C12-TEST | student |
      | sv001 | C13-TEST | student |
      | sv001 | C14-TEST | student |
      | sv001 | C15-TEST | student |
    And I log in as "sv001"
    And I am on the VBS course list page
    And I filter courses by "inprogress"
    When I follow the next page in the course list
    Then the active filter is still "inprogress"
