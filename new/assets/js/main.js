// assets/js/main.js - Xử lý Javascript tương tác toàn hệ thống
document.addEventListener('DOMContentLoaded', function () {
    // Tự động đóng thông báo flash sau 6 giây nếu có
    const autoCloseAlerts = document.querySelectorAll('.alert-dismissible');
    autoCloseAlerts.forEach(function (alert) {
        setTimeout(function () {
            try {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                bsAlert.close();
            } catch (e) {
                // Ignore if bootstrap alert not available
            }
        }, 6000);
    });

    // Xác nhận khi hủy hoặc đăng xuất
    const confirmButtons = document.querySelectorAll('[data-confirm]');
    confirmButtons.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            const message = btn.getAttribute('data-confirm') || 'Bạn có chắc chắn muốn thực hiện hành động này?';
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });
});
