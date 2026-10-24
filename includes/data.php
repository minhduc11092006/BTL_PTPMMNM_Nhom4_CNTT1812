<?php
// includes/data.php - Khởi tạo và quản lý Mock Data danh sách chuyến xe
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Khởi tạo Mock Data vào Session nếu chưa tồn tại (giúp đồng bộ khi đặt vé thành công)
if (!isset($_SESSION['trips'])) {
    $_SESSION['trips'] = [
        1 => [
            'id' => 1,
            'name' => 'Tuyến Bus 01: Bến Thành - Bến xe Chợ Lớn',
            'type' => 'Bus',
            'from' => 'Bến Thành',
            'to' => 'Chợ Lớn',
            'departure_time' => '07:30',
            'total_seats' => 40,
            'booked_seats' => 25,
            'price' => 7000
        ],
        2 => [
            'id' => 2,
            'name' => 'Tuyến Metro 01: Bến Thành - Suối Tiên',
            'type' => 'Tàu Metro',
            'from' => 'Bến Thành',
            'to' => 'Suối Tiên',
            'departure_time' => '08:15',
            'total_seats' => 120,
            'booked_seats' => 110,
            'price' => 15000
        ],
        3 => [
            'id' => 3,
            'name' => 'Tuyến Bus 150: Chợ Lớn - Ngã 3 Tân Vạn',
            'type' => 'Bus',
            'from' => 'Chợ Lớn',
            'to' => 'Tân Vạn',
            'departure_time' => '09:00',
            'total_seats' => 45,
            'booked_seats' => 45,
            'price' => 8000
        ],
        4 => [
            'id' => 4,
            'name' => 'Tàu Hỏa SE1: Sài Gòn - Nha Trang',
            'type' => 'Tàu Hỏa',
            'from' => 'Sài Gòn',
            'to' => 'Nha Trang',
            'departure_time' => '11:45',
            'total_seats' => 80,
            'booked_seats' => 60,
            'price' => 250000
        ],
        5 => [
            'id' => 5,
            'name' => 'Tuyến Bus 109: Công viên 23/9 - Sân bay Tân Sơn Nhất',
            'type' => 'Bus',
            'from' => 'Công viên 23/9',
            'to' => 'Sân bay Tân Sơn Nhất',
            'departure_time' => '14:20',
            'total_seats' => 30,
            'booked_seats' => 10,
            'price' => 20000
        ]
    ];
}

function get_all_trips() {
    return $_SESSION['trips'] ?? [];
}

function get_trip_by_id($id) {
    $trips = get_all_trips();
    return $trips[$id] ?? null;
}
