<?php
session_start();
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// ตรวจสอบและทำความสะอาดข้อมูลเก่าเฉพาะกรณีพบรายการเครื่องในแบบแยกชิ้นรุ่นเก่า
try {
    $offals = "('กระดูกขา', 'ตับ', 'ม้าม', 'ลิ้นวัว', 'ลำไส้อ่อน')";
    $hasLegacyOffals = $db->query("SELECT COUNT(*) FROM products WHERE name IN $offals")->fetchColumn();
    if ($hasLegacyOffals > 0) {
        $db->exec("DELETE FROM inventory_processing WHERE product_id IN (SELECT id FROM products WHERE name IN $offals)");
        $db->exec("DELETE FROM order_items WHERE product_id IN (SELECT id FROM products WHERE name IN $offals)");
        $db->exec("DELETE FROM products WHERE name IN $offals");
    }
} catch (Exception $e) {
    // Ignore error if table doesn't exist yet
}

try {
    $db->exec("ALTER TABLE orders ADD COLUMN order_type VARCHAR(20) DEFAULT 'retail' AFTER total_amount");
} catch (Exception $e) {
}

try {
    $db->exec("ALTER TABLE orders ADD COLUMN delivery_date VARCHAR(50) NULL AFTER order_type");
} catch (Exception $e) {
}

try {
    $db->exec("ALTER TABLE payments ADD COLUMN payment_type VARCHAR(20) DEFAULT 'full' AFTER order_id");
} catch (Exception $e) {
}

try {
    $db->exec("ALTER TABLE payments ADD COLUMN total_required DECIMAL(10,2) DEFAULT 0 AFTER payment_type");
} catch (Exception $e) {
}

try {
    $db->exec("ALTER TABLE payments ADD COLUMN amount_paid DECIMAL(10,2) DEFAULT 0 AFTER total_required");
} catch (Exception $e) {
}

try {
    $db->exec("ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NULL AFTER email");
} catch (Exception $e) {
}

try {
    $db->exec("ALTER TABLE users ADD COLUMN last_name VARCHAR(100) NULL AFTER first_name");
} catch (Exception $e) {
}

try {
    $db->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER last_name");
} catch (Exception $e) {
}

try {
    $db->exec("ALTER TABLE users ADD COLUMN shipping_address TEXT NULL AFTER phone");
} catch (Exception $e) {
}

try {
    $db->exec("ALTER TABLE products ADD COLUMN is_wholesale TINYINT(1) NOT NULL DEFAULT 1 AFTER price_wholesale");
} catch (Exception $e) {
}

try {
    $db->exec("ALTER TABLE products ADD COLUMN unit VARCHAR(20) NOT NULL DEFAULT 'กก.' AFTER price_wholesale");
} catch (Exception $e) {
}

try {
    $dupCheck = $db->query("SELECT COUNT(*) - COUNT(DISTINCT name) FROM products")->fetchColumn();
    if ($dupCheck > 0) {
        $db->exec("
            UPDATE inventory_processing ip
            JOIN products p ON ip.product_id = p.id
            JOIN (SELECT name, MIN(id) AS min_id FROM products GROUP BY name) keep_p ON p.name = keep_p.name
            SET ip.product_id = keep_p.min_id
            WHERE ip.product_id != keep_p.min_id
        ");
        $db->exec("
            UPDATE order_items oi
            JOIN products p ON oi.product_id = p.id
            JOIN (SELECT name, MIN(id) AS min_id FROM products GROUP BY name) keep_p ON p.name = keep_p.name
            SET oi.product_id = keep_p.min_id
            WHERE oi.product_id != keep_p.min_id
        ");
        $db->exec("
            DELETE FROM products 
            WHERE id NOT IN (
                SELECT min_id FROM (
                    SELECT MIN(id) AS min_id FROM products GROUP BY name
                ) AS keep_table
            )
        ");
        try {
            $db->exec("ALTER TABLE products ADD UNIQUE INDEX idx_unique_product_name (name)");
        } catch (Exception $e) {}
    }
    $stmt = $db->query("SELECT * FROM products GROUP BY name ORDER BY id ASC");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $products = [];
    $db_error = "Error loading products: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สั่งซื้อสินค้า - จ้าวเนื้อ๑</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=26">

    <style>
        /* Green Quantity Controller Pill (ตามภาพตัวอย่าง) */
        .green-qty-pill {
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            background-color: #eafaf1;
            border: 1.5px solid #a3e4d7;
            border-radius: 50px;
            padding: 3px 8px;
            min-width: 110px;
            height: 38px;
            user-select: none;
            box-shadow: 0 1px 4px rgba(40, 167, 69, 0.08);
            transition: all 0.2s ease;
        }

        .green-qty-pill:hover {
            border-color: #28a745;
            background-color: #e3f8ed;
        }

        .green-qty-btn {
            background: transparent;
            border: none;
            color: #198754;
            font-size: 1.05rem;
            width: 28px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border-radius: 50%;
            transition: background 0.15s, transform 0.1s;
            padding: 0;
            line-height: 1;
        }

        .green-qty-btn:hover {
            background-color: rgba(40, 167, 69, 0.15);
            color: #146c43;
        }

        .green-qty-btn:active {
            transform: scale(0.9);
        }

        .green-qty-val {
            color: #198754;
            font-weight: 700;
            font-size: 1.15rem;
            min-width: 28px;
            text-align: center;
            line-height: 1;
        }

        .btn-add-initial {
            background-color: #eafaf1;
            border: 1.5px solid #28a745;
            color: #198754;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.92rem;
            padding: 6px 14px;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .btn-add-initial:hover {
            background-color: #28a745;
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(40, 167, 69, 0.25);
        }

        .premium-card {
            border-radius: 12px;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
            border: 1px solid #eee;
        }

        .premium-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        }

        .premium-card .card-img-top {
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
            object-fit: cover;
        }
    </style>
</head>

<body class="bg-light">

    <!-- Navigation -->
    <nav class="navbar navbar-expand navbar-dark navbar-custom sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="index.html">
                <img src="assets/img/logo.jpg" alt="จ้าวเนื้อ๑ Logo" height="50" class="me-2 rounded-circle"
                    onerror="this.src='https://via.placeholder.com/50/610C10/D4AF37?text=JN';">
                จ้าวเนื้อ๑
            </a>
            <div class="navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto mb-0 align-items-center flex-row gap-2" style="padding-left: 1rem;">
                    <li class="nav-item"><a class="nav-link" href="index.html">หน้าแรก</a></li>
                    <li class="nav-item"><a class="nav-link active" href="shop.php">สั่งซื้อสินค้า</a></li>
                    <li class="nav-item"><a class="nav-link nav-cart-link" href="cart.html"><i class="fas fa-shopping-cart"></i>
                            ตะกร้า <span class="badge nav-cart-badge d-none"
                                id="cart-count" data-count="0" style="display: none !important;"></span></a></li>
                    <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true): ?>
                        <li class="nav-item dropdown ms-2">
                            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                                <i class="fas fa-user-circle fs-5 me-1"></i>
                                <?php echo htmlspecialchars($_SESSION['username']); ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="profile.html">ข้อมูลส่วนตัว</a></li>
                                <li><a class="dropdown-item text-danger" href="api/auth.php?action=logout"
                                        onclick="return confirm('ยืนยันการออกจากระบบหรือไม่?');">ออกจากระบบ</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item ms-2">
                            <a href="login.html" class="btn btn-primary-custom btn-sm shadow-sm"><i
                                    class="fas fa-sign-in-alt"></i> เข้าสู่ระบบ</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    <script>
        (function() {
            try {
                var c = JSON.parse(localStorage.getItem('jaonuea_cart') || '[]');
                var q = 0;
                if (Array.isArray(c)) c.forEach(function(i) { q += parseFloat(i.quantity) || 0; });
                var b = document.querySelectorAll('#cart-count, .nav-cart-badge');
                b.forEach(function(el) {
                    if (q > 0) {
                        el.innerText = (q % 1 === 0) ? q : q.toFixed(2);
                        el.setAttribute('data-count', q);
                        el.classList.remove('d-none');
                        el.style.setProperty('display', 'inline-flex', 'important');
                    } else {
                        el.innerText = '';
                        el.setAttribute('data-count', '0');
                        el.classList.add('d-none');
                        el.style.setProperty('display', 'none', 'important');
                    }
                });
            } catch(e) {}
        })();
    </script>

    <!-- Header -->
    <div class="bg-primary-custom py-5 mb-5 text-center text-white"
        style="background-image: url('https://images.unsplash.com/photo-1544025162-8311ebc5126c?q=80&w=2069&auto=format&fit=crop'); background-size: cover; background-position: center; background-blend-mode: multiply;">
        <div class="container">
            <h1 class="font-heading fw-bold text-secondary-custom text-shadow">รายการเนื้อวัวสด</h1>
            <p class="lead">คัดสรรทุกชิ้นส่วน สดใหม่จากฟาร์มของเรา</p>
        </div>
    </div>

    <div class="container mb-5">

        <!-- Tabs for Retail vs Wholesale -->
        <ul class="nav nav-pills mb-4 justify-content-center" id="shopTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active rounded-pill px-4" id="retail-tab" data-bs-toggle="pill"
                    data-bs-target="#retail" type="button" role="tab">ลูกค้าปลีก</button>
            </li>
            <li class="nav-item ms-2" role="presentation">
                <button class="nav-link rounded-pill px-4" id="wholesale-tab" data-bs-toggle="pill"
                    data-bs-target="#wholesale" type="button" role="tab">ลูกค้าส่ง (เหมา)</button>
            </li>
        </ul>

        <?php if (isset($db_error)): ?>
            <div class="alert alert-danger"><?php echo $db_error; ?></div>
        <?php endif; ?>

        <div class="tab-content" id="shopTabsContent">
            <!-- Retail Store -->
            <div class="tab-pane fade show active" id="retail" role="tabpanel">
                <div class="alert alert-secondary border-0 shadow-sm mb-4">
                    <i class="fas fa-info-circle me-2 text-secondary-custom"></i>
                    สำหรับร้านอาหารหรือผู้ที่ต้องการสั่งจำนวนมาก ซื้อตั้งแต่ 25 กก. ขึ้นไป รับส่วนลด
                    <strong>10%</strong> ทุกรายการ <a href="#" onclick="$('#wholesale-tab').tab('show'); return false;"
                        class="alert-link">คลิกที่นี่เพื่อไปหน้าลูกค้าส่ง (เหมา)</a>
                </div>
                <!-- Filters -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-auto mb-3 mb-md-0">
                                <h6 class="mb-0 fw-bold">ค้นหาสินค้า:</h6>
                            </div>
                            <div class="col-md-4 mb-3 mb-md-0">
                                <div class="input-group">
                                    <input type="text" id="retailSearchInput" class="form-control"
                                        placeholder="ชื่อชิ้นส่วนเนื้อ...">
                                    <button class="btn btn-primary-custom" type="button"><i
                                            class="fas fa-search"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Product Grid -->
                <div class="row g-4">
                    <?php if (empty($products) && !isset($db_error)): ?>
                        <div class="col-12 text-center text-muted py-5">
                            <i class="fas fa-box-open fa-3x mb-3"></i>
                            <p>ยังไม่มีสินค้าในระบบ</p>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($products as $product): 
                        $prodUnit = !empty($product['unit']) ? $product['unit'] : 'กก.';
                    ?>
                        <!-- Item -->
                        <div class="col-md-4 col-lg-3 retail-product-item" data-id="<?php echo $product['id']; ?>"
                            data-name="<?php echo htmlspecialchars($product['name']); ?>"
                            data-price="<?php echo $product['price_retail']; ?>"
                            data-unit="<?php echo htmlspecialchars($prodUnit); ?>"
                            data-image="<?php echo htmlspecialchars($product['image_url']); ?>"
                            data-stock="<?php echo floor($product['stock_kg']); ?>">
                            <div class="card premium-card h-100">
                                <img src="<?php echo htmlspecialchars($product['image_url'] ?: 'assets/img/logo.jpg'); ?>" class="card-img-top"
                                    alt="<?php echo htmlspecialchars($product['name']); ?>" style="height:200px; object-fit:cover;"
                                    onerror="this.src='assets/img/logo.jpg';">
                                <div class="card-body d-flex flex-column">
                                    <h6 class="card-title mb-1 fw-bold"><?php echo htmlspecialchars($product['name']); ?></h6>
                                    <div class="stock-display">
                                        <?php 
                                        $display_stock = max(0, $product['stock_kg']); 
                                        if ($display_stock <= 5 && $display_stock > 0): 
                                        ?>
                                            <p class="small text-danger mb-2">คงเหลือ:
                                                <?php echo number_format($display_stock, 0); ?> <?php echo htmlspecialchars($prodUnit); ?> (ใกล้หมด)
                                            </p>
                                        <?php elseif ($display_stock == 0): ?>
                                            <p class="small text-danger fw-bold mb-2">สินค้าหมด (0 <?php echo htmlspecialchars($prodUnit); ?>)</p>
                                        <?php else: ?>
                                            <p class="small text-muted mb-2">คงเหลือ:
                                                <?php echo number_format($display_stock, 0); ?> <?php echo htmlspecialchars($prodUnit); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>

                                    <div class="d-flex justify-content-between align-items-center mt-auto pt-2">
                                        <div class="d-flex align-items-baseline">
                                            <span class="price-tag fw-bold" style="font-size: 1.25rem; color: #222;">฿<?php echo number_format($product['price_retail'], 0); ?></span><span class="text-muted" style="font-size: 0.85rem; margin-left: 2px;">/ <?php echo htmlspecialchars($prodUnit); ?></span>
                                        </div>
                                        <div class="action-display" id="action-ctrl-<?php echo $product['id']; ?>">
                                            <!-- Green Quantity Controller Pill -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Wholesale Store -->
            <div class="tab-pane fade" id="wholesale" role="tabpanel">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-primary-custom text-white py-3">
                        <h5 class="mb-0 text-secondary-custom"><i class="fas fa-truck-loading me-2"></i>
                            สำหรับสั่งซื้อล่วงหน้าเท่านั้น</h5>
                    </div>
                    <div class="card-body p-4">

                        <!-- Tiered Pricing Info -->
                        <div class="alert alert-warning mb-4 shadow-sm border-warning">
                            <h6 class="fw-bold mb-2"><i class="fas fa-tags me-2"></i>โครงสร้างราคาส่งแบบขั้นบันได
                                (ยิ่งซื้อเยอะ ยิ่งถูกลง)</h6>
                            <ul class="mb-0 small text-dark">
                                <li><strong>25 กก. ขึ้นไป</strong> : ลดราคา <strong>10%</strong> จากราคาปกติทุกชิ้นส่วน
                                </li>
                            </ul>
                        </div>

                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>ชิ้นส่วน</th>
                                        <th>ราคาปกติ/กก.</th>
                                        <th style="width: 150px;">จำนวน (กก.)</th>
                                        <th class="text-end">รวมสุทธิ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($products as $product): 
                                        $canWholesale = !isset($product['is_wholesale']) || intval($product['is_wholesale']) === 1;
                                        if (!$canWholesale) continue;
                                        $prodUnit = !empty($product['unit']) ? $product['unit'] : 'กก.';
                                    ?>
                                        <tr class="wholesale-item" data-id="<?php echo $product['id']; ?>"
                                            data-price="<?php echo $product['price_retail']; ?>"
                                            data-unit="<?php echo htmlspecialchars($prodUnit); ?>"
                                            data-image="<?php echo htmlspecialchars($product['image_url']); ?>">
                                            <td><?php echo htmlspecialchars($product['name']); ?></td>
                                            <td>฿<?php echo number_format($product['price_retail'], 2); ?> / <?php echo htmlspecialchars($prodUnit); ?></td>
                                            <td class="ws-input-container">
                                                <input type="number" class="form-control ws-input"
                                                    id="ws-qty-<?php echo $product['id']; ?>"
                                                    data-id="<?php echo $product['id']; ?>"
                                                    data-price="<?php echo $product['price_retail']; ?>"
                                                    data-unit="<?php echo htmlspecialchars($prodUnit); ?>"
                                                    data-image="<?php echo htmlspecialchars($product['image_url']); ?>"
                                                    value="0" min="0">
                                            </td>
                                            <td class="text-end ws-total">฿0.00</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-light fw-bold">
                                        <td colspan="2" class="text-end">
                                            น้ำหนักรวม: <span id="ws-total-weight">0</span> กก.<br>
                                            <small class="text-success" id="ws-shipping-status"></small>
                                        </td>
                                        <td class="text-end">ส่วนลด: <span id="ws-discount"
                                                class="text-danger">-฿0.00</span></td>
                                        <td class="text-end text-primary-custom fs-5" id="ws-grand-total">฿0.00</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <div class="row mt-3 mb-4 text-start">
                            <div class="col-md-5 ms-auto">
                                <label class="form-label fw-bold text-primary-custom"><i
                                        class="far fa-calendar-alt me-2"></i>วันที่ต้องการรับ/จัดส่งสินค้า
                                    (สั่งล่วงหน้า)</label>
                                <input type="text" class="form-control" id="ws-preorder-date" placeholder="dd/mm/yyyy"
                                    required>
                                <div class="form-text text-danger small">* กรุณาสั่งล่วงหน้าอย่างน้อย 3 วัน (วัน/เดือน/ปี)</div>
                            </div>
                        </div>

                        <div class="text-end">
                            <button id="btn-submit-wholesale" class="btn btn-secondary-custom btn-lg"><i
                                    class="fas fa-file-invoice me-2"></i>
                                สั่งซื้อล่วงหน้า</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- jQuery & Bootstrap JS -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Flatpickr for Date Formatting -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <script src="assets/js/main.js?v=29"></script>
    <script>
        // Wholesale Calculation Logic
        $(document).ready(function () {
            $('.ws-input').on('input', function () {
                let totalWeight = 0;
                $('.ws-input').each(function () {
                    totalWeight += parseFloat($(this).val()) || 0;
                });

                let tierDiscount = 0;
                if (totalWeight >= 25) {
                    tierDiscount = 0.10;
                }

                let subTotal = 0;
                let grandTotal = 0;

                $('.ws-input').each(function () {
                    let weight = parseFloat($(this).val()) || 0;
                    let basePrice = parseFloat($(this).data('price'));
                    let unit = $(this).data('unit') || 'กก.';
                    let discountedPrice = basePrice * (1 - tierDiscount);

                    // Update the price display column
                    $(this).closest('tr').find('td:nth-child(2)').text('฿' + discountedPrice.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' / ' + unit);

                    let rowTotal = weight * discountedPrice;
                    $(this).closest('tr').find('.ws-total').text('฿' + rowTotal.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

                    subTotal += (weight * basePrice);
                    grandTotal += rowTotal;
                });

                $('#ws-total-weight').text(totalWeight);

                let savedAmount = subTotal - grandTotal;
                if (savedAmount > 0) {
                    $('#ws-discount').text('-฿' + savedAmount.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ` (ลด ${tierDiscount * 100}%)`);
                } else {
                    $('#ws-discount').text('-฿0.00');
                }

                if (totalWeight > 0 && totalWeight < 25) {
                    let diff = 25 - totalWeight;
                    $('#ws-shipping-status').html(`<span class="text-warning"><i class="fas fa-info-circle me-1"></i>ซื้อเพิ่มอีก ${diff} กก. เพื่อรับส่วนลด 10%</span>`);
                } else if (totalWeight >= 25) {
                    $('#ws-shipping-status').html(`<span class="text-success"><i class="fas fa-check-circle me-1"></i>คุณได้รับส่วนลด 10% แล้ว!</span>`);
                } else {
                    $('#ws-shipping-status').text('');
                }
                $('#ws-grand-total').text('฿' + grandTotal.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
            });

            // Set minimum preorder date to 3 days from today using Flatpickr
            let minDateObj = new Date();
            minDateObj.setDate(minDateObj.getDate() + 3);

            let fp = flatpickr("#ws-preorder-date", {
                dateFormat: "d/m/Y",
                allowInput: true,
                minDate: minDateObj,
                placeholder: "dd/mm/yyyy",
                onChange: function (selectedDates, dateStr, instance) {
                    if (dateStr) {
                        setDeliveryDate(dateStr);
                    }
                }
            });

            let existingDeliveryDate = getDeliveryDate();
            if (existingDeliveryDate) {
                fp.setDate(existingDeliveryDate);
            }

            // Handle wholesale submit
            $('#btn-submit-wholesale').click(function () {
                let totalWeight = parseFloat($('#ws-total-weight').text()) || 0;

                if (totalWeight <= 0) {
                    alert('กรุณาเลือกสินค้าอย่างน้อย 1 รายการ');
                    return;
                }

                let deliveryDate = $('#ws-preorder-date').val();
                if (!deliveryDate) {
                    alert('กรุณาเลือกวันที่ต้องการรับ/จัดส่งสินค้าล่วงหน้าครับ');
                    $('#ws-preorder-date').focus();
                    return;
                }

                // Parse flatpickr d/m/Y to Date obj (วัน/เดือน/ปี) for validation
                let parts = deliveryDate.split('/');
                if (parts.length === 3) {
                    let day = parseInt(parts[0], 10);
                    let month = parseInt(parts[1], 10);
                    let year = parseInt(parts[2], 10);
                    if (year > 2400) year -= 543;
                    let d = new Date(year, month - 1, day);
                    // Zero out time for exact comparison
                    let compareMin = new Date(minDateObj);
                    compareMin.setHours(0, 0, 0, 0);
                    if (d < compareMin) {
                        alert('กรุณาสั่งล่วงหน้าอย่างน้อย 3 วันครับ');
                        $('#ws-preorder-date').focus();
                        return;
                    }
                } else {
                    alert('รูปแบบวันที่ไม่ถูกต้อง (วัน/เดือน/ปี เช่น dd/mm/yyyy)');
                    return;
                }

                let items = [];
                $('.ws-input').each(function () {
                    let weight = parseFloat($(this).val()) || 0;
                    if (weight > 0) {
                        let row = $(this).closest('tr');
                        let name = row.find('td:first').text().trim();
                        let basePrice = parseFloat($(this).data('price'));
                        let id = $(this).data('id');

                        let totalOrderWeight = parseFloat($('#ws-total-weight').text()) || 0;
                        let tierDiscount = 0;
                        if (totalOrderWeight >= 25) tierDiscount = 0.10;

                        let discountedPrice = basePrice * (1 - tierDiscount);

                        items.push({
                            id: 'w' + id, // Real Product ID but wholesale prefix
                            name: name + ' (ขายส่ง)',
                            price: discountedPrice,
                            basePrice: basePrice, // Pass basePrice for dynamic cart recalculation
                            image: $(this).data('image'),
                            quantity: weight
                        });
                    }
                });

                if (items.length > 0) {
                    setWholesaleCart(items, deliveryDate);
                }
            });

            // Helper functions for Green Quantity Controller (ตามภาพ 5105663f.jpg)
            window.getCartItemQty = function(productId) {
                let cart = getCart();
                let found = cart.find(i => String(i.id) === String(productId));
                return found ? (parseInt(found.quantity) || 0) : 0;
            };

            window.renderProductQtyController = function(pId, stock) {
                let container = $(`#action-ctrl-${pId}`);
                if (container.length === 0) return;

                let currentStock = (stock !== undefined && stock !== null) ? Math.floor(stock) : 999;
                if (currentStock <= 0) {
                    container.html(`<button class="btn btn-secondary btn-sm rounded-pill px-3 py-1" style="height:38px; font-size:0.85rem;" disabled><i class="fas fa-times-circle me-1"></i> สินค้าหมด</button>`);
                    return;
                }

                let qty = getCartItemQty(pId);
                if (qty <= 0) {
                    container.html(`
                        <button type="button" class="btn-add-initial" onclick="changeProductQty('${pId}', 1)">
                            <i class="fas fa-plus"></i> เพิ่ม
                        </button>
                    `);
                } else if (qty === 1) {
                    // แบบในภาพเป๊ะๆ (ถังขยะสีเขียว + เลข 1 + เครื่องหมายบวกสีเขียว)
                    container.html(`
                        <div class="green-qty-pill">
                            <button type="button" class="green-qty-btn" onclick="changeProductQty('${pId}', 0)" title="ลบออกจากตะกร้า">
                                <i class="far fa-trash-alt"></i>
                            </button>
                            <span class="green-qty-val">1</span>
                            <button type="button" class="green-qty-btn" onclick="changeProductQty('${pId}', 2)" title="เพิ่มจำนวน">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    `);
                } else {
                    // เมื่อมีมากกว่า 1 ชิ้น: เครื่องหมายลบ + จำนวน + เครื่องหมายบวก
                    container.html(`
                        <div class="green-qty-pill">
                            <button type="button" class="green-qty-btn" onclick="changeProductQty('${pId}', ${qty - 1})" title="ลดจำนวน">
                                <i class="fas fa-minus"></i>
                            </button>
                            <span class="green-qty-val">${qty}</span>
                            <button type="button" class="green-qty-btn" onclick="changeProductQty('${pId}', ${qty + 1})" title="เพิ่มจำนวน">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    `);
                }
            };

            window.changeProductQty = function(pId, newQty) {
                let card = $(`.retail-product-item[data-id="${pId}"]`);
                let name = card.data('name');
                let price = parseFloat(card.data('price')) || 0;
                let unit = card.data('unit') || 'กก.';
                let image = card.data('image');
                let stockLimit = parseInt(card.attr('data-stock')) || 999;

                if (newQty > stockLimit) {
                    alert('ไม่สามารถเพิ่มได้ สต๊อกคงเหลือมีเพียง ' + stockLimit + ' ' + unit);
                    newQty = stockLimit;
                }

                let cart = getCart();
                let cartType = getCartType();
                if (cart.length > 0 && cartType !== 'retail') {
                    if (!confirm('คุณมีตะกร้าแบบ "ขายส่ง" อยู่ หากต้องการเพิ่มสินค้าปลีก ตะกร้าเดิมจะถูกล้าง ยืนยันหรือไม่?')) {
                        return;
                    }
                    cart = [];
                }

                let existingIndex = cart.findIndex(i => String(i.id) === String(pId));
                if (newQty <= 0) {
                    if (existingIndex !== -1) {
                        cart.splice(existingIndex, 1);
                    }
                } else {
                    if (existingIndex !== -1) {
                        cart[existingIndex].quantity = newQty;
                    } else {
                        cart.push({
                            id: String(pId),
                            name: name,
                            price: price,
                            image: image,
                            quantity: newQty,
                            stock: stockLimit
                        });
                    }
                }

                setCartType('retail');
                saveCart(cart);
                renderProductQtyController(pId, stockLimit);
            };

            // แสดงผลปุ่มเริ่มต้นสำหรับทุกสินค้า
            $('.retail-product-item').each(function () {
                let pId = $(this).data('id');
                let stock = $(this).data('stock');
                renderProductQtyController(pId, stock);
            });

            // Retail product search filtering
            $('#retailSearchInput').on('input', function () {
                const searchTerm = $(this).val().toLowerCase();
                $('.retail-product-item').each(function () {
                    const productName = $(this).data('name').toLowerCase();
                    if (productName.includes(searchTerm)) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            });

            // Real-time Stock Updater
            setInterval(async () => {
                try {
                    const res = await fetch('api/client.php?action=get_stock');
                    const data = await res.json();
                    if (data.success && data.stock) {
                        // Update Retail Items
                        $('.retail-product-item').each(function () {
                            let pId = $(this).data('id');
                            let prodUnit = $(this).data('unit') || 'กก.';
                            let currentStock = data.stock[pId];

                            if (currentStock !== undefined) {
                                $(this).attr('data-stock', Math.floor(currentStock));
                                let stockDisplay = $(this).find('.stock-display');

                                // Update stock text
                                let stockHtml = '';
                                if (currentStock <= 5 && currentStock > 0) {
                                    stockHtml = `<p class="small text-danger mb-2">คงเหลือ: ${Math.floor(currentStock)} ${prodUnit} (ใกล้หมด)</p>`;
                                } else if (currentStock <= 0) {
                                    stockHtml = `<p class="small text-danger fw-bold mb-2">สินค้าหมด (0 ${prodUnit})</p>`;
                                } else {
                                    stockHtml = `<p class="small text-muted mb-2">คงเหลือ: ${Math.floor(currentStock)} ${prodUnit}</p>`;
                                }
                                stockDisplay.html(stockHtml);

                                // อัปเดตตัวควบคุมจำนวนสีเขียว
                                renderProductQtyController(pId, currentStock);
                            }
                        });

                        // Update Wholesale Items (no stock limits needed for pre-orders)
                        // Removed dynamic stock check for wholesale because it's preorder only
                        // Recalculate wholesale totals if any inputs changed
                        let wsTotalWeight = 0;
                        let wsGrandTotal = 0;
                        $('.ws-input').each(function () {
                            let w = parseFloat($(this).val()) || 0;
                            let p = parseFloat($(this).data('price'));
                            wsTotalWeight += w;
                            wsGrandTotal += (w * p);
                        });

                        let tierDiscount = (wsTotalWeight >= 25) ? 0.10 : 0;
                        let discountAmt = wsGrandTotal * tierDiscount;
                        let netTotal = wsGrandTotal - discountAmt;

                        $('#ws-total-weight').text(wsTotalWeight);
                        $('#ws-discount').text('-฿' + discountAmt.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
                        $('#ws-grand-total').text('฿' + netTotal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
                    }
                } catch (e) { }
            }, 3000);
        });
    </script>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4 mb-lg-0">
                    <h5 class="text-white d-flex align-items-center mb-3">
                        <img src="assets/img/logo.jpg" alt="Logo" height="30" class="me-2 rounded-circle"
                            onerror="this.src='https://via.placeholder.com/30/610C10/D4AF37?text=JN';">
                        จ้าวเนื้อ๑
                    </h5>
                    <p class="small">จำหน่ายเนื้อวัว ทั้งปลีกและส่ง </p>
                    <div class="social-icons mt-3">
                        <a href="https://www.facebook.com/" target="_blank"><i class="fab fa-facebook"></i></a>
                        <a href="#" data-bs-toggle="modal" data-bs-target="#lineQrModal"><i class="fab fa-line"></i></a>
                    </div>
                </div>
                <div class="col-lg-2 col-md-6 mb-4 mb-lg-0">
                    <h5>เมนูหลัก</h5>
                    <ul class="list-unstyled small">
                        <li class="mb-2"><a href="index.html">หน้าแรก</a></li>
                        <li class="mb-2"><a href="shop.php">สั่งซื้อสินค้า</a></li>
                        <li class="mb-2"><a href="login.html">สมัครสมาชิก/เข้าสู่ระบบ</a></li>
                    </ul>
                </div>
                <div class="col-lg-2 col-md-6 mb-4 mb-lg-0">
                    <h5>บริการ</h5>
                    <ul class="list-unstyled small">
                        <li class="mb-2"><a href="shop.php">เนื้อวัวปลีก</a></li>
                        <li class="mb-2"><a href="shop.php?tab=wholesale">ราคาส่งสำหรับร้านอาหาร</a></li>
                        <li class="mb-2"><a href="#" data-bs-toggle="modal"
                                data-bs-target="#lineQrModal">ติดต่อรับซื้อโคเนื้อ</a></li>
                    </ul>
                </div>
                <div class="col-lg-4">
                    <h5>ติดต่อเรา</h5>
                    <ul class="list-unstyled small">
                        <li class="mb-2"><i class="fas fa-map-marker-alt me-2"></i> <span class="user-select-all">1 หมู่
                                6 ต.โพรงจระเข้ อ.ย่านตาขาว จ.ตรัง 92140</span></li>
                        <li class="mb-2"><i class="fas fa-phone me-2"></i> <span
                                class="user-select-all">065-708-1497</span></li>
                        <li class="mb-2"><i class="fas fa-envelope me-2"></i> <span
                                class="user-select-all">jaonuea@example.com</span></li>
                    </ul>
                </div>
            </div>
            <hr class="mt-4 mb-3 border-secondary">
            <div class="text-center small">
                &copy; 2026 จ้าวเนื้อ๑ (Jaew Nuer). All Rights Reserved. CS Project.
            </div>
        </div>
    </footer>

</body>

</html>