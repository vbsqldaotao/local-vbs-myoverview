<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Vietnamese language strings for local_vbs_myoverview.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Bổ sung dữ liệu danh sách khóa học VBS';
$string['privacy:metadata'] = 'Plugin bổ sung dữ liệu danh sách khóa học VBS không lưu trữ dữ liệu cá nhân. '
    . 'Plugin chỉ tính toán trạng thái hiển thị (badge, khoảng ngày) trực tiếp từ dữ liệu khóa học, ghi danh '
    . 'và hoàn thành đã có sẵn.';

// Custom field.
$string['customfieldcategory'] = 'VBS';
$string['deliverymode'] = 'Hình thức';

// Delivery mode (hình thức) badge labels.
$string['delivery_online'] = 'Online';
$string['delivery_offline'] = 'Offline';
$string['delivery_blended'] = 'Kết hợp';

// Lifecycle state badge labels.
$string['lifecycle_not_started'] = 'Chưa bắt đầu';
$string['lifecycle_in_progress'] = 'Đang diễn ra';
$string['lifecycle_ended'] = 'Đã kết thúc';
$string['lifecycle_completed'] = 'Hoàn thành';

// Enrollment state badge labels.
$string['enrollment_assigned'] = 'Phân công';
$string['enrollment_pending_approval'] = 'Chờ duyệt';
$string['enrollment_open_for_registration'] = 'Mở đăng ký';

// F02 — Trang tiến độ học tập (VBS-160).
$string['progress:pagetitle'] = 'Tiến độ học tập của tôi';
$string['progress:heading'] = 'Tiến độ học tập của tôi';

// Tiêu đề các section.
$string['progress:section_plan'] = 'Kế hoạch đào tạo {$a}';
$string['progress:section_plan_noyear'] = 'Kế hoạch đào tạo';
$string['progress:section_active'] = 'Khóa đang học';
$string['progress:section_completed'] = 'Khóa đã hoàn thành';
$string['progress:section_certificates'] = 'Chứng chỉ đã cấp';

// Section kế hoạch.
$string['progress:plan_progress'] = '{$a->completed}/{$a->total} mục';
$string['progress:plan_status_completed'] = 'Hoàn thành';
$string['progress:plan_status_in_progress'] = 'Đang học';
$string['progress:plan_status_not_started'] = 'Chưa bắt đầu';
$string['progress:plan_noitems'] = 'Kế hoạch đào tạo chưa có khóa học nào.';

// Section khóa đang học.
$string['progress:deadline'] = 'Hạn: {$a}';
$string['progress:nodeadline'] = 'Hạn: —';
$string['progress:percent'] = '{$a}%';
$string['progress:delivery_elearning'] = 'E-learning';
$string['progress:delivery_classroom'] = 'Classroom';
$string['progress:delivery_blended'] = 'Blended';
$string['progress:progressbar_label'] = 'Hoàn thành {$a}%';

// Section khóa đã hoàn thành.
$string['progress:completedon'] = 'Hoàn thành: {$a}';
$string['progress:viewcertificate'] = 'Xem chứng chỉ';

// Section chứng chỉ.
$string['progress:downloadpdf'] = 'Tải xuống PDF';
$string['progress:downloadpdf_label'] = 'Tải xuống PDF chứng chỉ {$a}';

// Trạng thái rỗng (TC-20).
$string['progress:empty_plan'] = 'Chưa có kế hoạch đào tạo năm {$a}.';
$string['progress:empty_plan_noyear'] = 'Chưa có kế hoạch đào tạo.';
$string['progress:empty_active'] = 'Bạn chưa có khóa học nào đang tiến hành.';
$string['progress:empty_completed'] = 'Bạn chưa hoàn thành khóa học nào.';
$string['progress:empty_certificates'] = 'Chưa có chứng chỉ nào được cấp.';

// Loading / lỗi.
$string['progress:loading'] = 'Đang tải…';
$string['progress:loaderror'] = 'Không tải được section này. Vui lòng thử lại sau.';
