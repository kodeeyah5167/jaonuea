<?php
// checkout.php - หน้าทำการสั่งซื้อ (Checkout Page ตามภาพตัวอย่าง 111.jpg, 222.jpg, 333.jpg)
session_start();
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// ตรวจสอบสินค้าในร้านเพื่อดึงข้อมูลประกอบการแสดงผล
$products = [];
try {
    $stmt = $db->query("SELECT * FROM products ORDER BY id ASC LIMIT 5");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ทำการสั่งซื้อ - จ้าวเนื้อ๑</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts Kanit -->
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Multi-Address Manager CSS (Shopee Style) -->
    <link rel="stylesheet" href="assets/css/address-manager.css?v=2">
    <style>
        body {
            font-family: 'Kanit', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f5f5f5;
            color: #222222;
            padding-bottom: 90px;
        }

        .checkout-mobile-wrapper {
            max-width: 500px;
            margin: 0 auto;
            background: #f5f5f5;
            min-height: 100vh;
        }

        /* Top Mobile App Bar (111.jpg) */
        .checkout-top-nav {
            background: #ffffff;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 50;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .checkout-top-nav .nav-back-btn {
            background: none;
            border: none;
            color: #ee4d2d;
            font-size: 1.25rem;
            padding: 4px;
            cursor: pointer;
            margin-right: 12px;
            text-decoration: none;
            display: flex;
            align-items: center;
        }

        .checkout-top-nav .nav-title {
            font-size: 1.15rem;
            font-weight: 600;
            color: #222222;
            margin: 0 auto;
            transform: translateX(-15px);
        }

        /* Section Card */
        .shopee-card {
            background: #ffffff;
            border-radius: 8px;
            margin: 10px 12px;
            padding: 14px 16px;
            border: 1px solid #eaeaea;
        }

        .store-header {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 12px;
            color: #222;
        }

        .product-item-row {
            display: flex;
            gap: 12px;
            padding-bottom: 12px;
        }

        .product-item-row img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid #f0f0f0;
        }

        .product-item-details {
            flex-grow: 1;
        }

        .product-item-title {
            font-size: 0.92rem;
            line-height: 1.35;
            color: #333;
            margin-bottom: 4px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .product-item-variant {
            font-size: 0.8rem;
            color: #888;
            margin-bottom: 6px;
        }

        .product-price-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .current-price {
            color: #ee4d2d;
            font-weight: 600;
            font-size: 1rem;
        }

        .original-price {
            color: #999;
            text-decoration: line-through;
            font-size: 0.82rem;
            margin-left: 6px;
        }

        .item-qty {
            color: #777;
            font-size: 0.85rem;
        }

        /* Action Rows */
        .checkout-row-action {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-top: 1px solid #f5f5f5;
            font-size: 0.9rem;
            cursor: pointer;
        }

        .checkout-row-action .action-label {
            color: #222;
            font-weight: 500;
        }

        .checkout-row-action .action-value {
            color: #888;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Delivery Option Box (111.jpg) */
        .delivery-option-box {
            background-color: #f6fbf9;
            border: 1px solid #d4ede3;
            border-radius: 6px;
            padding: 12px;
            margin-top: 10px;
            position: relative;
        }

        .delivery-truck-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 4px;
        }

        .delivery-date {
            color: #009688;
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .delivery-cost {
            font-weight: 600;
            color: #222;
        }

        .delivery-cost-old {
            text-decoration: line-through;
            color: #999;
            font-size: 0.8rem;
            margin-right: 4px;
            font-weight: normal;
        }

        .delivery-desc {
            font-size: 0.82rem;
            color: #555;
        }

        .delivery-badge-note {
            font-size: 0.78rem;
            color: #009688;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* Flash Sale Discount Banner */
        .discount-banner {
            background: #fff8f5;
            border-radius: 8px;
            margin: 10px 12px;
            padding: 10px 14px;
            border: 1px solid #fce3db;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.88rem;
        }

        .discount-label {
            color: #ee4d2d;
            font-weight: 600;
        }

        .countdown-badge {
            background: #222;
            color: #fff;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 0.8rem;
            font-family: monospace;
        }

        /* Bottom Sticky Bar (111.jpg) */
        .checkout-bottom-bar {
            position: fixed;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 100%;
            max-width: 500px;
            background: #ffffff;
            border-top: 1px solid #e5e5e5;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 14px;
            z-index: 100;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.05);
        }

        .summary-price-box {
            text-align: right;
            line-height: 1.25;
        }

        .summary-total-label {
            font-size: 0.95rem;
            color: #333;
        }

        .summary-total-val {
            color: #ee4d2d;
            font-weight: 700;
            font-size: 1.2rem;
        }

        .summary-savings {
            font-size: 0.75rem;
            color: #888;
        }

        .btn-shopee-checkout {
            background-color: #ee4d2d;
            color: #ffffff;
            border: none;
            padding: 12px 28px;
            font-size: 1.05rem;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.2s;
            white-space: nowrap;
        }

        .btn-shopee-checkout:hover {
            background-color: #d63d1e;
        }
    </style>
</head>
<body>

    <div class="checkout-mobile-wrapper">
        <!-- Top App Bar (111.jpg) -->
        <div class="checkout-top-nav">
            <a href="shop.php" class="nav-back-btn">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h1 class="nav-title">ทำการสั่งซื้อ</h1>
        </div>

        <!-- 1. Top Shipping Address Card (อ้างอิงภาพ 111.jpg) -->
        <div style="margin: 12px 12px 0 12px;">
            <div class="checkout-address-card" id="checkoutTopAddressCard">
                <!-- โหลดอัตโนมัติจาก AddressManager -->
                <div class="d-flex align-items-center justify-content-center py-3 text-muted">
                    <i class="fas fa-spinner fa-spin me-2"></i> กำลังโหลดที่อยู่จัดส่ง...
                </div>
            </div>
        </div>

        <!-- 2. Store & Products Card -->
        <div class="shopee-card">
            <div class="store-header">
                <i class="fas fa-store text-danger"></i>
                <span>จ้าวเนื้อ ๑ (jaonuea store)</span>
            </div>

            <div id="checkout-items-list">
                <!-- Items list -->
                <div class="product-item-row">
                    <img src="assets/img/chuck.jpg" alt="เนื้อสันคอ" onerror="this.src='https://via.placeholder.com/80/610C10/fff?text=Beef';">
                    <div class="product-item-details">
                        <div class="product-item-title">เนื้อสันคอโคขุนพรีเมียม สดใหม่ คัดเกรดพิเศษ</div>
                        <div class="product-item-variant">ตัดสเต็ก, ชิ้นละ 1 กก.</div>
                        <div class="product-price-row">
                            <div>
                                <span class="current-price">฿250</span>
                                <span class="original-price">฿290</span>
                            </div>
                            <span class="item-qty">x1</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Shop Voucher -->
            <div class="checkout-row-action">
                <span class="action-label">โค้ดส่วนลดร้านค้า</span>
                <span class="action-value">กดใช้โค้ด <i class="fas fa-chevron-right small"></i></span>
            </div>

            <!-- Notes -->
            <div class="checkout-row-action">
                <span class="action-label">หมายเหตุ</span>
                <span class="action-value text-muted">ฝากข้อความถึงร้านค้า <i class="fas fa-chevron-right small"></i></span>
            </div>
        </div>

        <!-- 3. Delivery Option Card (111.jpg) -->
        <div class="shopee-card">
            <div class="d-flex justify-content-between align-items-center">
                <span class="fw-bold" style="font-size: 0.95rem;">ตัวเลือกการจัดส่ง</span>
                <span class="text-muted small" style="cursor: pointer;">ดูทั้งหมด <i class="fas fa-chevron-right small"></i></span>
            </div>

            <div class="delivery-option-box">
                <div class="delivery-truck-header">
                    <div class="delivery-date">
                        <i class="fas fa-truck-moving"></i> วันนี้ - 2 วันถัดไป
                    </div>
                    <div>
                        <span class="delivery-cost-old">฿77</span>
                        <span class="delivery-cost text-success">฿39</span>
                    </div>
                </div>
                <div class="delivery-desc">
                    Standard Delivery - รถควบคุมอุณหภูมิความเย็น (Cold Chain)
                </div>
            </div>

            <div class="delivery-badge-note">
                <i class="far fa-check-circle"></i> รับประกันความสดใหม่ ส่งตรงถึงหน้าบ้านคุณ
            </div>

            <div class="d-flex justify-content-between align-items-center pt-3 mt-2 border-top">
                <span style="font-size: 0.92rem; color: #555;">สินค้ารวม 1 ชิ้น</span>
                <span class="fw-bold fs-6">฿289</span>
            </div>
        </div>

        <!-- 4. Flash Sale / Discount Banner (111.jpg) -->
        <div class="discount-banner">
            <div>
                <span class="discount-label">ส่วนลดพิเศษ</span>
                <span class="text-muted ms-1">ประหยัด ฿86</span>
            </div>
            <div>
                <span class="countdown-badge">00</span> :
                <span class="countdown-badge">59</span> :
                <span class="countdown-badge">26</span>
            </div>
        </div>

        <!-- 5. Payment Method Card -->
        <div class="shopee-card">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-bold" style="font-size: 0.95rem;">วิธีการชำระเงิน</span>
                <span class="text-danger small fw-bold">โอนเงิน / สแกน QR Code</span>
            </div>
            <div class="p-3 bg-light rounded text-center border">
                <img src="assets/img/qrcode.jpg" alt="QR Code" class="img-fluid rounded mb-2" style="max-width: 180px;">
                <div class="small fw-bold text-dark">ธนาคารกรุงไทย (Krungthai)</div>
                <div class="fw-bold text-primary">016-0-68030-1 (บจก. จ้าวเนื้อ ๑)</div>
            </div>
        </div>

        <!-- 6. Bottom Sticky Checkout Bar (111.jpg) -->
        <div class="checkout-bottom-bar">
            <div class="summary-price-box">
                <div>
                    <span class="summary-total-label">รวมยอดสั่งซื้อ</span>
                    <span class="summary-total-val" id="bottomTotalAmount">฿289</span>
                </div>
                <div class="summary-savings">ประหยัดไป ฿86</div>
            </div>
            <button type="button" class="btn-shopee-checkout" id="btnPlaceOrder">
                สั่งสินค้า
            </button>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Multi-Address Manager JS (Shopee Flow) -->
    <script src="assets/js/address-manager.js?v=4"></script>

    <script>
        $(document).ready(function () {
            // โหลดสินค้าจาก cart ใน LocalStorage ถ้ามี
            const cartJson = localStorage.getItem('jaonuea_cart');
            let cart = [];
            if (cartJson) {
                try {
                    cart = JSON.parse(cartJson);
                } catch(e) {}
            }

            if (cart && cart.length > 0) {
                let itemsHtml = '';
                let totalSubtotal = 0;
                let totalKg = 0;

                cart.forEach(item => {
                    let price = parseFloat(item.price) || 250;
                    let qty = parseFloat(item.quantity) || 1;
                    let itemTotal = price * qty;
                    totalSubtotal += itemTotal;
                    totalKg += qty;

                    itemsHtml += `
                    <div class="product-item-row">
                        <img src="${item.image || 'assets/img/chuck.jpg'}" alt="${item.name}" onerror="this.src='assets/img/chuck.jpg';">
                        <div class="product-item-details">
                            <div class="product-item-title">${item.name}</div>
                            <div class="product-item-variant">จำนวน: ${qty} กก.</div>
                            <div class="product-price-row">
                                <div>
                                    <span class="current-price">฿${price.toFixed(2)}</span>
                                </div>
                                <span class="item-qty">x${qty}</span>
                            </div>
                        </div>
                    </div>`;
                });

                $('#checkout-items-list').html(itemsHtml);
                let finalTotal = totalSubtotal + 39; // ค่าจัดส่ง 39 บาท
                $('#bottomTotalAmount').text('฿' + finalTotal.toFixed(2));
            }

            // เริ่มต้นระบบ AddressManager เพื่อรองรับ 111.jpg, 222.jpg, 333.jpg
            AddressManager.init({
                container: '#checkoutTopAddressCard',
                onChange: function (addr) {
                    console.log('Selected shipping address:', addr);
                }
            });

            // ปุ่ม "สั่งสินค้า"
            $('#btnPlaceOrder').on('click', function () {
                const selectedAddr = AddressManager.getSelectedAddress();
                if (!selectedAddr) {
                    alert('กรุณาเข้าสู่ระบบและระบุที่อยู่จัดส่งก่อนสั่งสินค้า');
                    window.location.href = 'login.html?redirect=checkout.php';
                    return;
                }

                alert('ทำการสั่งซื้อสำเร็จ!\nจัดส่งไปยัง: ' + selectedAddr.recipient_name + ' (' + selectedAddr.phone + ')\nที่อยู่: ' + selectedAddr.full_address.replace(/\n/g, ' '));
                window.location.href = 'shop.php';
            });
        });
    </script>
</body>
</html>

