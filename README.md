# BTL_PTPMMNM_Nhom4_CNTT1812

## Giới thiệu

Đây là dự án web quản lý và tra cứu lịch trình vận tải công cộng theo chuẩn GTFS (General Transit Feed Specification). Ứng dụng hỗ trợ:

- Tra cứu tuyến xe, trạm dừng và chuyến đi
- Tìm chuyến kế tiếp theo điểm xuất phát và điểm đến
- Đăng ký / đăng nhập tài khoản người dùng
- Đặt vé trực tuyến và lưu truy vấn mẫu
- Quản trị GTFS: nhập dữ liệu, kiểm tra tính hợp lệ, kích hoạt phiên bản lịch trình mới

Dự án được xây dựng bằng PHP + MySQL, chạy trên môi trường XAMPP/LAMP.

## Yêu cầu môi trường

- XAMPP hoặc Apache + MySQL + PHP
- PHP 8.x
- Trình duyệt web hiện đại
- Mở quyền truy cập vào thư mục `htdocs` (hoặc tương đương)

## Cài đặt và chạy

1. Sao chép project vào thư mục web của XAMPP:
   - Ví dụ: `C:/xampp/htdocs/b2/BTL_PTPMMNM_Nhom4_CNTT1812`
   - Trên Linux: `/opt/lampp/htdocs/b2/BTL_PTPMMNM_Nhom4_CNTT1812`

2. Khởi động Apache và MySQL trong XAMPP Control Panel.

3. Import cơ sở dữ liệu:
   - Mở phpMyAdmin
   - Tạo database `transit_db` nếu chưa có
   - Import file `database/database.sql`

4. Kiểm tra cấu hình kết nối database trong file `config/db.php`:
   - Host: `localhost`
   - Database: `transit_db`
   - User: `root`
   - Password: rỗng theo mặc định XAMPP

5. Truy cập ứng dụng trong trình duyệt:
   - `http://localhost/b2/BTL_PTPMMNM_Nhom4_CNTT1812`

## Tài khoản mặc định

Sau khi import database, hệ thống có các tài khoản mẫu sau:

- Admin:
  - Username: `admin`
  - Password: `admin123`

- Người dùng mẫu:
  - Username: `hanhkhach1`
  - Password: `password123`

## Luồng sử dụng chính

### 1. Người dùng
- Đăng ký / đăng nhập tại `auth/login.php` hoặc `auth/register.php`
- Dùng chức năng tra cứu tuyến và chuyến xe trên trang chủ
- Tìm chuyến kế tiếp và đặt vé
- Xem và lưu truy vấn mẫu đã thực hiện

### 2. Quản trị viên
- Đăng nhập bằng tài khoản admin
- Vào trang quản trị GTFS: `admin/gtfs.php`
- Tải lên file GTFS `.zip` hoặc chọn thư mục GTFS có sẵn trong `gtfs_data/`
- Hệ thống sẽ tự động kiểm tra dữ liệu và lưu phiên bản mới
- Chọn phiên bản hợp lệ để kích hoạt làm lịch trình đang sử dụng

## Cấu trúc thư mục chính

- `admin/` — giao diện quản trị và trang điều khiển admin
- `auth/` — đăng nhập, đăng ký, reset mật khẩu
- `views/` — giao diện người dùng: tra cứu, đặt vé, truy vấn mẫu
- `includes/` — logic nghiệp vụ và helper xử lý GTFS
- `config/` — cấu hình database
- `database/` — script khởi tạo cơ sở dữ liệu
- `gtfs_data/` — dữ liệu GTFS mẫu để import hoặc kiểm thử

## Lưu ý quan trọng

- Nếu MySQL chưa chạy, hệ thống sẽ báo lỗi kết nối database.
- Đảm bảo import file `database/database.sql` trước khi truy cập trang web để tránh lỗi schema.
- Dữ liệu GTFS có thể được nhập từ file ZIP hoặc từ thư mục chứa các file `.txt` chuẩn GTFS.

## Mục tiêu dự án

Dự án nhằm mô phỏng hệ thống tra cứu và quản lý lịch trình giao thông công cộng theo tiêu chuẩn GTFS, phù hợp cho bài tập lớn về phát triển phần mềm, quản trị dữ liệu và phát triển web ứng dụng PHP.
