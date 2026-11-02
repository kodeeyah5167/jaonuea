document.addEventListener('DOMContentLoaded', function() {
    // Inject Toast HTML into the body
    const toastHTML = `
    <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1100">
        <div id="newOrderToast" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="toast-header bg-success text-white">
                <i class="fas fa-bell me-2"></i>
                <strong class="me-auto">แจ้งเตือนคำสั่งซื้อใหม่</strong>
                <small>เมื่อสักครู่</small>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body bg-white">
                มีคำสั่งซื้อใหม่เข้ามาในระบบ กรุณาตรวจสอบ!
                <div class="mt-2 pt-2 border-top">
                    <a href="admin-orders.html" class="btn btn-sm btn-success">ดูคำสั่งซื้อ</a>
                </div>
            </div>
        </div>
    </div>
    `;
    document.body.insertAdjacentHTML('beforeend', toastHTML);

    let lastKnownOrderId = localStorage.getItem('adminLastOrderId') || 0;

    function checkNewOrders() {
        fetch('api/admin.php?action=get_new_orders_count')
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    // Update badge
                    const badges = document.querySelectorAll('#menu-orders-badge, .sidebar a[href="admin-orders.html"] .badge');
                    badges.forEach(badge => {
                        if (data.count > 0) {
                            badge.textContent = data.count;
                            badge.style.display = 'inline-block';
                            badge.style.opacity = '1';
                            badge.style.visibility = 'visible';
                        } else {
                            badge.style.display = 'none';
                        }
                    });

                    // Check for new order notification
                    if (lastKnownOrderId == 0) {
                        lastKnownOrderId = data.latest_order_id;
                        localStorage.setItem('adminLastOrderId', lastKnownOrderId);
                    } else if (data.latest_order_id > lastKnownOrderId) {
                        // Show toast
                        const toastEl = document.getElementById('newOrderToast');
                        if (toastEl) {
                            const toast = new bootstrap.Toast(toastEl, { delay: 10000 });
                            toast.show();
                            
                            // Optional: play sound
                            try {
                                const audio = new Audio('assets/sound/notification.mp3'); // We'll try to play if exists
                                audio.play().catch(e => {});
                            } catch(e) {}
                        }
                        
                        lastKnownOrderId = data.latest_order_id;
                        localStorage.setItem('adminLastOrderId', lastKnownOrderId);
                        
                        // If we are on admin-orders.html, refresh the table
                        if (typeof fetchOrders === 'function') {
                            fetchOrders();
                        }
                    }
                }
            })
            .catch(err => console.error('Error checking new orders:', err));
    }

    // Check immediately and then every 10 seconds
    checkNewOrders();
    setInterval(checkNewOrders, 10000);
});
