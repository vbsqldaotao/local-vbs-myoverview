@local_vbs_myoverview @javascript
Feature: Learner views the course list (F01)
  Kiểm tra chức năng "Học viên xem danh sách khóa học" (VBS-131).
  Acceptance criteria: FR-F01-001 đến FR-F01-005 (VBS-127) +
  VBS presentation contract: badge 2 chiều + date range (VBS-132).

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | sv001    | Học       | Viên     | sv001@vbs.test     |
      | svnew    | Học viên  | Mới      | svnew@vbs.test     |
    And the following "courses" exist:
      | fullname                     | shortname  | startdate      | enddate        |
      | An toàn lao động             | ATLD-TEST  | ##2026-06-01## | ##2026-08-31## |
      | Kỹ năng mềm                  | KNM-TEST   | ##2027-01-01## | ##2027-03-31## |
      | Nghiệp vụ đã kết thúc        | NVKT-TEST  | ##2026-01-01## | ##2026-03-31## |
      | Khóa học không hình thức     | NOMODE-TEST| ##2026-06-01## | ##2026-08-31## |
    And the following "course enrolments" exist:
      | user  | course      | role    |
      | sv001 | ATLD-TEST   | student |
      | sv001 | KNM-TEST    | student |
      | sv001 | NVKT-TEST   | student |
      | sv001 | NOMODE-TEST | student |
    And the course "ATLD-TEST" has delivery mode "online"
    And the course "KNM-TEST" has delivery mode "offline"
    And the course "NVKT-TEST" has delivery mode "blended"

  # ─────────────────────────────────────────────────────────────────
  # FR-F01-001: Hiển thị danh sách khóa học được phân công
  # ─────────────────────────────────────────────────────────────────

  Scenario: FR-F01-001 – Học viên thấy tất cả khóa học được phân công
    Given I log in as "sv001"
    When I am on the VBS course list page
    Then I should see "An toàn lao động"
    And I should see "Kỹ năng mềm"
    And I should see "Nghiệp vụ đã kết thúc"
    And I should see "Khóa học không hình thức"

  Scenario: FR-F01-001 – Học viên không có khóa học thấy trạng thái rỗng
    Given I log in as "svnew"
    When I am on the VBS course list page
    Then I should see "No courses"

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
    Then I should see "No courses"

  Scenario: FR-F01-002 – Xóa từ khóa – khôi phục danh sách đầy đủ
    Given I log in as "sv001"
    And I am on the VBS course list page
    When I search courses for "An toàn"
    And I clear the course search
    Then I should see "An toàn lao động"
    And I should see "Kỹ năng mềm"

  Scenario: FR-F01-002 + FR-F01-003 – Tìm kiếm kết hợp lọc trạng thái (AND logic)
    Given I log in as "sv001"
    And I am on the VBS course list page
    And I filter courses by "inprogress"
    When I search courses for "An toàn"
    Then I should see "An toàn lao động"
    And I should not see "Kỹ năng mềm"

  # ─────────────────────────────────────────────────────────────────
  # FR-F01-003: Lọc theo trạng thái
  # ─────────────────────────────────────────────────────────────────

  Scenario: FR-F01-003 – Lọc "In progress" chỉ hiển thị khóa đang diễn ra
    Given I log in as "sv001"
    And I am on the VBS course list page
    When I filter courses by "inprogress"
    Then I should see "An toàn lao động"
    And I should see "Khóa học không hình thức"
    And I should not see "Kỹ năng mềm"
    And I should not see "Nghiệp vụ đã kết thúc"

  Scenario: FR-F01-003 – Lọc "Future" chỉ hiển thị khóa chưa bắt đầu
    Given I log in as "sv001"
    And I am on the VBS course list page
    When I filter courses by "future"
    Then I should see "Kỹ năng mềm"
    And I should not see "An toàn lao động"

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
    When I filter courses by "allincludinghidden"
    Then I should see "An toàn lao động"
    And I should see "Kỹ năng mềm"
    And I should see "Nghiệp vụ đã kết thúc"

  # ─────────────────────────────────────────────────────────────────
  # FR-F01-004 / VBS delta D7: Course card hiển thị date range
  # ─────────────────────────────────────────────────────────────────

  Scenario: FR-F01-004 – Card khóa học hiển thị khoảng thời gian (date range)
    Given I log in as "sv001"
    When I am on the VBS course list page
    Then the course card for "An toàn lao động" shows a date range

  Scenario: FR-F01-004 – Card không hiển thị date range khi khóa học không có ngày
    Given the following "courses" exist:
      | fullname       | shortname | startdate | enddate |
      | Khóa không ngày| NONDATE  | 0         | 0       |
    And the following "course enrolments" exist:
      | user  | course   | role    |
      | sv001 | NONDATE  | student |
    And I log in as "sv001"
    When I am on the VBS course list page
    Then the course card for "Khóa không ngày" does not show a date range

  # ─────────────────────────────────────────────────────────────────
  # VBS delta D6: Badge 2 chiều (delivery → lifecycle → enrollment)
  # ─────────────────────────────────────────────────────────────────

  Scenario: VBS-D6 – Badge delivery hiển thị khi có delivery_mode
    Given I log in as "sv001"
    And I am on the VBS course list page
    Then the course card for "An toàn lao động" shows the vbs badge "Online"
    And the course card for "Kỹ năng mềm" shows the vbs badge "Offline"
    And the course card for "Nghiệp vụ đã kết thúc" shows the vbs badge "Blended"

  Scenario: VBS-D6 – Degrade khi thiếu delivery_mode – không có delivery chip
    Given I log in as "sv001"
    And I am on the VBS course list page
    Then the course card for "Khóa học không hình thức" does not show a delivery badge

  Scenario: VBS-D6 – Badge lifecycle hiển thị đúng trạng thái
    Given I log in as "sv001"
    And I am on the VBS course list page
    Then the course card for "An toàn lao động" shows the vbs badge "In progress"
    And the course card for "Kỹ năng mềm" shows the vbs badge "Not started"
    And the course card for "Nghiệp vụ đã kết thúc" shows the vbs badge "Ended"

  Scenario: VBS-D6 – Badge enrollment "Assigned" hiển thị cho khóa được phân công
    Given I log in as "sv001"
    And I am on the VBS course list page
    Then the course card for "An toàn lao động" shows the vbs badge "Assigned"

  Scenario: VBS-D6 – Badge order: delivery → lifecycle → enrollment
    Given I log in as "sv001"
    And I am on the VBS course list page
    Then the course card for "An toàn lao động" has badge order "Online, In progress, Assigned"

  # ─────────────────────────────────────────────────────────────────
  # FR-F01-005: Phân trang / pagination
  # ─────────────────────────────────────────────────────────────────

  @vbs_pagination
  Scenario: FR-F01-005 – Bộ lọc được giữ khi phân trang
    Given the following "courses" exist with batch size 25 per page:
      | fullname       | shortname  | startdate      | enddate        |
      | Pagination Test| PGTEST-001 | ##2026-06-01## | ##2026-08-31## |
    And the following "course enrolments" exist:
      | user  | course     | role    |
      | sv001 | PGTEST-001 | student |
    And the course "PGTEST-001" has delivery mode "online"
    And I log in as "sv001"
    And I am on the VBS course list page
    And I filter courses by "inprogress"
    When I follow the next page in the course list
    Then the active filter is still "inprogress"
