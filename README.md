# Smart Transit GTFS - Hệ Thống Quản Lý & Tra Cứu Lịch Trình Giao Thông Công Cộng

> **Bài Tập Lớn Môn:** Phát triển phần mềm mã nguồn mở (PTPMMNM)  
> **Nhóm thực hiện:** Nhóm 4 — Lớp CNTT1812  
> **Nền tảng:** PHP 8.x + MySQL + Leaflet GIS + Tailwind CSS + Bootstrap 5  

---

## 📌 Giới thiệu dự án

**Smart Transit GTFS** là ứng dụng web tra cứu và quản lý lịch trình giao thông công cộng (xe buýt & metro) chuẩn hóa theo định dạng dữ liệu quốc tế **GTFS (General Transit Feed Specification)**.

Hệ thống giải quyết bài toán số hóa dữ liệu vận tải công cộng đô thị, cho phép người dùng tra cứu mạng lưới tuyến đường trực quan trên bản đồ số, theo dõi sơ đồ các điểm dừng dạng Metro Timeline, tìm kiếm chuyến xe kế tiếp theo thời gian thực và hỗ trợ nhà quản lý nhập, kiểm tra, kích hoạt các phiên bản lịch trình linh hoạt.

---

## 🚀 Các tính năng nổi bật

### 1. Bản Đồ Tuyến Đường & Tra Cứu GTFS Hợp Nhất (`views/map.php`)
- **Bản đồ GIS OpenStreetMap + Leaflet**: Trực quan hóa hình học toàn bộ các tuyến xe (`shapes.txt`) và trạm đón (`stops.txt`).
- **Sơ đồ tuyến dạng Metro Timeline (Linear Transit Diagram)**:
  - Cột giữa hiển thị trạm dừng dạng sơ đồ ray Metro tuyến tính.
  - Phân cấp rõ nét: *Trạm Đầu*, *Trạm Trung Gian*, *Bến Cuối*.
  - Màu sắc đường ray và nút trạm tự động đồng bộ theo mã màu chuẩn GTFS (`route_color`).
- **Đồng bộ 2 chiều (Bi-directional Sync)**:
  - Bấm chọn **Chiều đi** hoặc **Chiều về** trên Sơ đồ Timeline -> Bản đồ tự động lọc lộ trình, trạm dừng của chiều đó và `fitBounds` camera vào phạm vi tương ứng.
  - Ngược lại, bật/tắt checkbox trên bản đồ -> Switcher ở sơ đồ Timeline tự động nhảy tab.
- **Floating HUD (Bộ điều khiển nổi trên mặt Minimap)**:
  - ☀️/🌙/🛰️ **Chuyển đổi 3 lớp nền bản đồ**: Bản đồ sáng (OSM), Bản đồ tối tương phản cao (CARTO Dark), Ảnh vệ tinh (Esri World Imagery).
  - 🎯 **Định vị GPS (Locate Me)**: Xác định vị trí người dùng tức thì, thả marker định vị kèm sóng radar.
  - ⛶ **Toàn màn hình (Fullscreen)**: Mở rộng khung bản đồ tối đa màn hình.
  - 🚌 **Thẻ thông báo chuyến (Trip Badge)**: Hiển thị tên chuyến đang xem trực tiếp dạng thẻ nổi trên mặt bản đồ.
- **Tra cứu tuyến & trạm**: Danh sách tuyến xe trực quan kèm phân trang 10 trạm/trang cho danh sách điểm dừng.
- **Quản lý Truy vấn đã lưu (Saved Queries)**: Lưu các bộ lọc yêu thích và chạy lại nhanh qua popup modal.

### 2. Tra Cứu Chuyến Kế Tiếp Tại Trạm (`views/next_trip.php`)
- **Thuật toán xử lý lịch trình GTFS chính xác**:
  - Đối chiếu ngày chạy trong tuần (`calendar.txt`).
  - Xử lý các ngày ngoại lệ bổ sung hoặc hủy dịch vụ (`calendar_dates.txt`).
  - Hỗ trợ giờ chạy đêm vượt 24h chuẩn GTFS (`departure_time >= 24:00:00`, ví dụ `24:15` rạng sáng hôm sau).
- **Giao diện thẻ chuyến (Transit Card Grid)**:
  - Hiển thị dải màu nhận diện tuyến, giờ đón chính xác tại trạm.
  - **Phân trang giới hạn 8 chuyến / trang** kèm thanh chuyển trang mượt mà.
  - Nút **"Xem Lộ Trình Trên Bản Đồ"** dẫn thẳng sang `map.php`, kích hoạt bản đồ và hiển thị thẻ tên chuyến ngay trên Minimap.

### 3. So Sánh Phiên Bản Lịch Trình (`views/compare.php`)
- So sánh sự thay đổi giữa 2 phiên bản dữ liệu GTFS: số lượng tuyến mới, trạm thay đổi, chuyến tăng/giảm.

### 4. Quản Trị Hệ Thống GTFS (`admin/gtfs.php`)
- Nhập dữ liệu từ file nén `.zip` hoặc thư mục GTFS nguồn.
- Kiểm tra tính toàn vẹn (Validation) các file bắt buộc (`agency.txt`, `routes.txt`, `stops.txt`, `trips.txt`, `stop_times.txt`, `calendar.txt`).
- Quản lý phiên bản Feed: Kích hoạt (Activate) phiên bản làm lịch trình vận hành chính thức.

---

## 🛠️ Công nghệ sử dụng

| Thành phần | Công nghệ |
|---|---|
| **Backend** | PHP 8.x (Kiến trúc MVC tối giản, PDO Prepared Statements) |
| **Cơ sở dữ liệu** | MySQL 5.7+ / MariaDB 10.4+ |
| **Giao diện (Frontend)** | HTML5, CSS3, JavaScript (ES6+) |
| **CSS Framework** | Tailwind CSS CDN + Bootstrap 5.3 (Modal & Utilities) |
| **Icon System** | Bootstrap Icons 1.11.3 |
| **Bản đồ số (GIS)** | Leaflet 1.9.4 + OpenStreetMap Tile Layer |
| **Lớp bản đồ vệ tinh/tối** | CARTO Basemaps + Esri World Imagery |

---

## 💻 Yêu cầu môi trường

- **Hệ điều hành:** Windows 10/11, macOS, hoặc Linux (Ubuntu/Debian).
- **Phần mềm máy chủ:** [XAMPP](https://www.apachefriends.org/) (khuyên dùng) hoặc WampServer / LAMP / Docker.
- **Phiên bản PHP:** PHP 8.0 trở lên (bật các extension `pdo_mysql`, `mbstring`, `zip`).
- **Cơ sở dữ liệu:** MySQL / MariaDB.
- **Trình duyệt:** Google Chrome, Microsoft Edge, Firefox hoặc Safari phiên bản mới nhất.

---

## ⚙️ Hướng dẫn cài đặt & Cách chạy dự án

### Bước 1: Đặt mã nguồn vào thư mục Web của máy chủ

Sao chép toàn bộ thư mục dự án `BTL_PTPMMNM_Nhom4_CNTT1812` vào thư mục `htdocs` của XAMPP:

- **Windows:** `C:\xampp\htdocs\btl_ptpm\BTL_PTPMMNM_Nhom4_CNTT1812`  
  *(hoặc đặt trực tiếp tại `C:\xampp\htdocs\BTL_PTPMMNM_Nhom4_CNTT1812`)*
- **Linux:** `/opt/lampp/htdocs/BTL_PTPMMNM_Nhom4_CNTT1812`
- **macOS (XAMPP):** `/Applications/XAMPP/xamppfiles/htdocs/BTL_PTPMMNM_Nhom4_CNTT1812`

---

### Bước 2: Khởi động máy chủ dịch vụ

Mở **XAMPP Control Panel** và nhấn **Start** cho 2 dịch vụ:
1. **Apache** (Cổng mặc định `80`, `443`)
2. **MySQL** (Cổng mặc định `3306`)

---

### Bước 3: Khởi tạo Cơ sở dữ liệu (Database)

1. Mở trình duyệt và truy cập trang quản trị phpMyAdmin:  
   👉 `http://localhost/phpmyadmin`
2. Tạo một cơ sở dữ liệu mới:
   - Tên database: `transit_db`
   - Bảng mã (Collation): `utf8mb4_unicode_ci`
3. Nhấp vào tab **Import** (Nhập):
   - Chọn file: `database/database.sql` trong thư mục dự án.
   - Nhấn **Import** (Thực hiện) để tạo cấu trúc bảng và nạp dữ liệu khởi tạo.

---

### Bước 4: Kiểm tra cấu hình kết nối Database

Mở file [`config/db.php`](file:///config/db.php) và kiểm tra thông tin kết nối phù hợp với máy của bạn:

```php
$db_host = 'localhost';
$db_port = '3306';
$db_name = 'transit_db';
$db_user = 'root';
$db_pass = ''; // Mặc định trên XAMPP để trống
```

---

### Bước 5: Truy cập và sử dụng ứng dụng

Mở trình duyệt web và truy cập theo đường dẫn:

- Nếu đặt trong thư mục `btl_ptpm`:
  👉 **`http://localhost/btl_ptpm/BTL_PTPMMNM_Nhom4_CNTT1812/`**
- Nếu đặt trực tiếp tại thư mục `htdocs`:
  👉 **`http://localhost/BTL_PTPMMNM_Nhom4_CNTT1812/`**

---

## 🔑 Tài khoản đăng nhập mặc định

Hệ thống đã chuẩn bị sẵn tài khoản trong cơ sở dữ liệu mẫu:

| Loại tài khoản | Tên đăng nhập (Username) | Mật khẩu (Password) | Vai trò (Role) |
|---|---|---|---|
| **Quản trị viên (Admin)** | `admin` | `admin123` | Quản trị dữ liệu GTFS, kích hoạt phiên bản |
| **Người dùng mẫu 1** | `hanhkhach1` | `password123` | Tra cứu lịch trình, lưu truy vấn mẫu |
| **Người dùng mẫu 2** | `user1` | `password123` | Tra cứu lịch trình, trải nghiệm tính năng |

---

## 📁 Cấu trúc thư mục dự án

```text
BTL_PTPMMNM_Nhom4_CNTT1812/
├── admin/                      # Quản trị hệ thống
│   └── gtfs.php                # Upload, kiểm tra & kích hoạt phiên bản GTFS
├── auth/                       # Phân hệ tài khoản & xác thực
│   ├── login.php               # Đăng nhập
│   ├── register.php            # Đăng ký tài khoản
│   └── logout.php              # Đăng xuất
├── config/                     # Cấu hình hệ thống
│   └── db.php                  # Kết nối CSDL MySQL (PDO)
├── database/                   # Tệp tin CSDL mẫu
│   └── database.sql            # Script khởi tạo bảng & dữ liệu ban đầu
├── gtfs_data/                  # Thư mục lưu trữ các bộ dữ liệu GTFS feed mẫu
├── includes/                   # Thư viện logic nghiệp vụ dùng chung
│   ├── auth.php                # Helper quản lý phiên người dùng
│   ├── gtfs_functions.php      # Bộ hàm đọc, phân tích & tính toán GTFS
│   └── ...
├── views/                      # Giao diện người dùng chính
│   ├── map.php                 # Bản đồ GIS + Sơ đồ Metro Timeline + Tra cứu
│   ├── next_trip.php           # Tra cứu chuyến xe kế tiếp tại trạm
│   └── compare.php             # So sánh các phiên bản GTFS
├── index.php                   # Trang chủ hệ thống
├── README.md                   # Tài liệu hướng dẫn dự án
└── AGENTS.md                   # Nguyên tắc phát triển dự án
```

---

## ⚠️ Xử lý sự cố thường gặp (Troubleshooting)

1. **Lỗi "Không thể kết nối đến cơ sở dữ liệu (MySQL)"**:
   - Kiểm tra MySQL trong XAMPP Control Panel đã ở trạng thái *Running* (màu xanh).
   - Đảm bảo tên database trong phpMyAdmin trùng khớp là `transit_db`.
2. **Lỗi bản đồ không hiện đường tuyến**:
   - Tài khoản Admin cần vào menu **Quản Trị GTFS** (`admin/gtfs.php`) và nhấn **Kích hoạt** một phiên bản dữ liệu GTFS.
3. **Lỗi định vị GPS không chạy**:
   - Tính năng Định vị GPS (`Locate Me`) yêu cầu trình duyệt cấp quyền vị trí và hoạt động tốt nhất trên môi trường `localhost` hoặc giao thức bảo mật `HTTPS`.

---

## 👥 Thành viên nhóm 4 - CNTT1812

- Dự án được xây dựng phục vụ học phần **Phát triển phần mềm mã nguồn mở**.
- Mọi đóng góp và mã nguồn được chia sẻ theo giấy phép mã nguồn mở phục vụ mục đích học tập và nghiên cứu.
