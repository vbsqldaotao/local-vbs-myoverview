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
