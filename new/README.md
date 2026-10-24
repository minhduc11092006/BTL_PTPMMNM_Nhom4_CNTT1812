# Hệ Thống Đặt Vé Giao Thông Công Cộng (TransitHub)

Dự án ứng dụng web PHP thuần (Core PHP & MySQL) với giao diện Bootstrap 5, được tổ chức theo cấu trúc phân tầng (Clean Layered Architecture) chuẩn mực, dễ bảo trì và mở rộng.

---

## 📁 Cấu Trúc Thư Mục Dự Án

```text
/b2/new/
├── assets/                     # Tài nguyên tĩnh của hệ thống
│   ├── css/
│   │   └── style.css           # Bảng màu, typography, card hover, responsive
│   └── js/
│       └── main.js             # Script tương tác, tự động đóng thông báo, xác nhận
│
├── components/                 # Các khối giao diện dùng chung (Partials/Components)
│   ├── header.php              # Thẻ <head>, meta, CDN Bootstrap & Google Fonts
│   ├── navbar.php              # Thanh điều hướng, hiển thị User/Role, Đăng nhập/Đăng xuất
│   └── footer.php              # Chân trang bản quyền và nạp JS
│
├── config/                     # Cấu hình hệ thống & Kết nối CSDL
│   └── db.php                  # Kết nối MySQL bằng PDO (bắt lỗi Exception rõ ràng)
│
├── database/                   # Cơ sở dữ liệu và kịch bản khởi tạo
│   └── database.sql            # Script tạo CSDL transit_db, bảng users và tài khoản mẫu
│
├── includes/                   # Các hàm xử lý nghiệp vụ, Middleware bảo mật
│   ├── auth.php                # Quản lý phiên (Session), phân quyền (is_admin, require_login, require_admin)
│   └── data.php                # Mock Data các chuyến xe, hàm get_all_trips(), get_trip_by_id()
│
├── admin_dashboard.php         # Bảng điều khiển Quản trị viên (Thống kê chuyến, ghế, doanh thu)
├── book_ticket.php             # Giao diện đặt vé hành khách (Hỗ trợ lưu nháp Session & Cookie 24h)
├── index.php                   # Trang chủ: Tra cứu lịch trình & tìm kiếm chuyến xe (GET)
├── login.php                   # Đăng nhập & phân luồng điều hướng theo Role (Admin / User)
├── logout.php                  # Đăng xuất tài khoản an toàn (Hủy Session & Cookie)
├── process_booking.php         # Xử lý logic đặt vé, kiểm tra dữ liệu (Validation), in hóa đơn vé
└── register_account.php        # Đăng ký tài khoản hành khách mới (Mã hóa Bcrypt)
```

---

## 🚀 Hướng Dẫn Cài Đặt & Chạy Trên XAMPP

1. **Khởi động dịch vụ:**
   - Mở **XAMPP Control Panel**, nhấn **Start** cho cả **Apache** và **MySQL**.

2. **Import Cơ sở dữ liệu:**
   - Truy cập: [http://localhost/phpmyadmin/](http://localhost/phpmyadmin/)
   - Chọn thẻ **Import** (Nhập) -> Chọn file `database/database.sql` và nhấn **Import**.
   - Hoặc chạy lệnh SQL trong file `database/database.sql`.

3. **Truy cập ứng dụng:**
   - Mở trình duyệt và truy cập: [http://localhost/b2/new/](http://localhost/b2/new/)

---

## 🔑 Tài Khoản Thử Nghiệm

| Vai trò | Tên đăng nhập | Mật khẩu | Trang truy cập chính |
| :--- | :--- | :--- | :--- |
| **Quản trị viên (Admin)** | `admin` | `admin123` | `admin_dashboard.php` |
| **Hành khách (User)** | `hanhkhach1` | `password123` | `index.php`, `book_ticket.php` |
