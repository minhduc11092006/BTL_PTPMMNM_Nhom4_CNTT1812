<?php
// config/fare.php - Cấu hình GIÁ VÉ ĐỒNG HẠNG áp dụng cho mọi tuyến.
//
// Chỉ cần sửa số bên dưới, lưu file và tải lại trang là giá hiển thị đổi theo.
// LƯU Ý: bộ GTFS Hà Nội không kèm dữ liệu giá vé, nên con số mặc định dưới đây chỉ là
// GIÁ GIẢ ĐỊNH để minh họa. Hãy thay bằng mức giá bạn muốn dùng.
return [
    'flat_fare' => 7000,   // Giá vé lượt, đơn vị: đồng (VNĐ). Đặt 0 để hiển thị "Miễn phí".
];
