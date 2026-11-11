<?php
session_start();
require_once '../config/database.php';
require_once 'notification_helper.php';

header('Content-Type: application/json');

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // Auto-migrate users table columns just in case the admin hasn't visited profile.php
    try { $db->exec("ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NULL AFTER email"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN last_name VARCHAR(100) NULL AFTER first_name"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER last_name"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN shipping_address TEXT NULL AFTER phone"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE orders ADD COLUMN delivery_date VARCHAR(50) NULL AFTER order_type"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE orders MODIFY COLUMN delivery_date VARCHAR(50) NULL"); } catch (Exception $e) {}
    try {
        $db->exec("
            UPDATE orders 
            SET delivery_date = DATE_FORMAT(DATE_ADD(created_at, INTERVAL 3 DAY), '%Y-%m-%d')
            WHERE order_type = 'wholesale' 
              AND (delivery_date IS NULL 
                   OR delivery_date = '' 
                   OR delivery_date = '0000-00-00' 
                   OR delivery_date LIKE '0000%' 
                   OR delivery_date LIKE '%1899%'
                   OR delivery_date LIKE '%2442%'
                   OR delivery_date = '30/11/1899')
        ");
    } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE orders MODIFY COLUMN status ENUM('pending_payment', 'processing', 'awaiting_final_payment', 'verifying_final_payment', 'preparing', 'shipped', 'ready_for_pickup', 'completed', 'cancelled') DEFAULT 'pending_payment'"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE orders ADD COLUMN tracking_number VARCHAR(100) NULL AFTER shipping_address"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE orders ADD COLUMN stock_deducted TINYINT(1) DEFAULT 0 AFTER final_total_amount"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE order_items ADD COLUMN actual_quantity_kg DECIMAL(8,2) NULL AFTER quantity_kg"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE payments MODIFY COLUMN status VARCHAR(50) DEFAULT 'pending'"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE payments ADD COLUMN remaining_amount DECIMAL(10,2) DEFAULT 0.00 AFTER amount_paid"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE payments ADD COLUMN remaining_status VARCHAR(50) DEFAULT 'pending' AFTER remaining_amount"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE payments ADD COLUMN second_slip_image_url VARCHAR(255) NULL AFTER remaining_status"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE cattles ADD COLUMN seller_name VARCHAR(150) NULL AFTER tag_number"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE cattles ADD COLUMN seller_phone VARCHAR(30) NULL AFTER seller_name"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE cattles ADD COLUMN gender VARCHAR(20) DEFAULT 'ผู้' AFTER breed"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE cattles ADD COLUMN initial_weight_kg DECIMAL(8,2) NULL AFTER weight_kg"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE cattles ADD COLUMN image_url VARCHAR(255) NULL AFTER purchased_at"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE cattles ADD COLUMN notes TEXT NULL AFTER image_url"); } catch (Exception $e) {}
    try {
        $db->exec("UPDATE cattles SET initial_weight_kg = weight_kg WHERE initial_weight_kg IS NULL");
    } catch (Exception $e) {}

    // Auto-clean duplicate products if any exist in the database
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
    } catch (Exception $e) {}

    switch ($action) {
        
        // ==========================================
        // 1. Dashboard Stats
        // ==========================================
        case 'get_stats':
            // ยอดขายเดือนนี้ (นับคำสั่งซื้อทั้งหมดที่ไม่ถูกยกเลิก เพื่อให้แสดงผลแบบเรียลไทม์ทันที)
            $stmt = $db->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM orders WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status != 'cancelled'");
            $today_sales = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

            // ยอดขายสะสมทั้งหมด
            $stmtTotalSales = $db->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM orders WHERE status != 'cancelled'");
            $total_sales_all = $stmtTotalSales->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            
            // คำสั่งซื้อที่กำลังดำเนินการ (รออนุมัติ / กำลังเตรียม / รอรับ)
            $stmt = $db->query("SELECT COUNT(*) as count FROM orders WHERE status NOT IN ('completed', 'cancelled')");
            $new_orders = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

            // คำสั่งซื้อที่เสร็จสมบูรณ์แล้ว
            $stmtComp = $db->query("SELECT COUNT(*) as count FROM orders WHERE status = 'completed'");
            $completed_orders = $stmtComp->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            
            // สินค้าใกล้หมด / หมดสต๊อก (<= 10 kg รวม <= 0 kg สินค้าหมด)
            $stmt = $db->query("
                SELECT MIN(id) as id, name, MAX(stock_kg) as stock_kg 
                FROM products 
                GROUP BY name 
                HAVING MAX(stock_kg) <= 10 
                ORDER BY MAX(stock_kg) ASC, name ASC
            ");
            $low_stock_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $low_stock = count($low_stock_list);

            // สต๊อกเนื้อคงเหลือรวมทั้งหมด (กก.)
            $stmtTotalStock = $db->query("
                SELECT COALESCE(SUM(max_stock), 0) as total_kg
                FROM (SELECT MAX(stock_kg) as max_stock FROM products GROUP BY name) t
            ");
            $total_stock_kg = $stmtTotalStock->fetch(PDO::FETCH_ASSOC)['total_kg'] ?? 0;
            
            // โคเนื้อในฟาร์ม (ยังมีชีวิต) และโคที่ชำแหละแล้ว
            $stmt = $db->query("SELECT COUNT(*) as count FROM cattles WHERE status = 'alive'");
            $farm_cattles = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

            $stmtProc = $db->query("SELECT COUNT(*) as count FROM cattles WHERE status = 'processed'");
            $processed_cattles = $stmtProc->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
            
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'today_sales' => (float)$today_sales,
                    'total_sales_all' => (float)$total_sales_all,
                    'new_orders' => (int)$new_orders,
                    'completed_orders' => (int)$completed_orders,
                    'low_stock' => (int)$low_stock,
                    'low_stock_list' => $low_stock_list,
                    'total_stock_kg' => (float)$total_stock_kg,
                    'farm_cattles' => (int)$farm_cattles,
                    'processed_cattles' => (int)$processed_cattles,
                    'server_time' => date('H:i:s')
                ]
            ]);
            break;

        case 'get_revenue_chart':
            // ดึงข้อมูลรายได้รายเดือนทั้งหมด (จากคำสั่งซื้อที่ไม่ถูกยกเลิก)
            $stmtRev = $db->query("
                SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COALESCE(SUM(total_amount), 0) AS total
                FROM orders
                WHERE status != 'cancelled' AND created_at IS NOT NULL
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ");
            $revByMonth = [];
            foreach ($stmtRev->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!empty($r['ym'])) {
                    $revByMonth[$r['ym']] = (float)$r['total'];
                }
            }

            // ดึงข้อมูลต้นทุนการรับซื้อโครายเดือนทั้งหมด
            $stmtCost = $db->query("
                SELECT DATE_FORMAT(purchased_at, '%Y-%m') AS ym, COALESCE(SUM(cost_price), 0) AS total
                FROM cattles
                WHERE purchased_at IS NOT NULL
                GROUP BY DATE_FORMAT(purchased_at, '%Y-%m')
            ");
            $costByMonth = [];
            foreach ($stmtCost->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if (!empty($c['ym'])) {
                    $costByMonth[$c['ym']] = (float)$c['total'];
                }
            }

            // กำหนดให้เดือน กันยายน 2026 (2026-09) เป็นเดือนแรกเสมอ และเรียงต่อเนื่องจนถึงเดือนปัจจุบัน/เดือนล่าสุดที่มีข้อมูล
            $allDataMonths = array_unique(array_merge(array_keys($revByMonth), array_keys($costByMonth)));
            sort($allDataMonths);

            $currentYm = date('Y-m');
            $minYm = '2026-09';
            $maxYm = !empty($allDataMonths) ? max(end($allDataMonths), $currentYm, $minYm) : max($currentYm, $minYm);

            $thaiMonths = [
                '01' => 'ม.ค.', '02' => 'ก.พ.', '03' => 'มี.ค.', '04' => 'เม.ย.',
                '05' => 'พ.ค.', '06' => 'มิ.ย.', '07' => 'ก.ค.', '08' => 'ส.ค.',
                '09' => 'กันยายน', '10' => 'ตุลาคม', '11' => 'พฤศจิกายน', '12' => 'ธันวาคม'
            ];

            $labels = [];
            $months_ym = [];
            $revenue_data = [];
            $cost_data = [];
            $profit_data = [];

            $cursor = new DateTime($minYm . '-01');
            $endDate = new DateTime($maxYm . '-01');

            while ($cursor <= $endDate) {
                $ym = $cursor->format('Y-m');
                $mNum = $cursor->format('m');
                $yearNum = $cursor->format('Y');
                $label = ($thaiMonths[$mNum] ?? $mNum) . ' ' . $yearNum;

                $rev = $revByMonth[$ym] ?? 0.0;
                $cost = $costByMonth[$ym] ?? 0.0;

                $months_ym[] = $ym;
                $labels[] = $label;
                $revenue_data[] = (float)$rev;
                $cost_data[] = (float)$cost;
                $profit_data[] = (float)($rev - $cost);

                $cursor->modify('+1 month');
            }

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'months' => $months_ym,
                    'labels' => $labels,
                    'revenue' => $revenue_data,
                    'cost' => $cost_data,
                    'profit' => $profit_data
                ]
            ]);
            break;

        case 'get_profit_summary':
            // ดึงข้อมูลโคที่ชำแหละแล้วทั้งหมด พร้อมวันที่ชำแหละและน้ำหนักเนื้อที่ได้รวม เรียงตามลำดับวัน เดือน ปี
            $stmt = $db->query("
                SELECT c.id, c.tag_number, c.breed, c.purchased_at, c.cost_price, c.status,
                       COALESCE(DATE(MAX(ip.processed_at)), c.purchased_at) as processed_date
                FROM cattles c
                LEFT JOIN inventory_processing ip ON c.id = ip.cattle_id
                WHERE c.status = 'processed'
                GROUP BY c.id, c.tag_number, c.breed, c.purchased_at, c.cost_price, c.status
                ORDER BY processed_date ASC, c.id ASC
            ");
            $cattles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $results = [];
            foreach ($cattles as $cattle) {
                $revenue = 0;
                $total_yield = 0;
                if ($cattle['status'] == 'processed') {
                    $stmt2 = $db->prepare("
                        SELECT ip.yield_kg, p.price_retail
                        FROM inventory_processing ip
                        JOIN products p ON ip.product_id = p.id
                        WHERE ip.cattle_id = :cid
                    ");
                    $stmt2->execute(['cid' => $cattle['id']]);
                    $yields = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($yields as $y) {
                        $yKg = (float)$y['yield_kg'];
                        $total_yield += $yKg;
                        $revenue += ($yKg * (float)$y['price_retail']);
                    }
                }
                $profit = $revenue - (float)$cattle['cost_price'];
                $results[] = [
                    'id' => (int)$cattle['id'],
                    'tag_number' => $cattle['tag_number'],
                    'breed' => $cattle['breed'] ?? '',
                    'purchased_at' => $cattle['processed_date'] ?: $cattle['purchased_at'],
                    'raw_purchased_at' => $cattle['purchased_at'],
                    'total_yield_kg' => (float)$total_yield,
                    'cost_price' => (float)$cattle['cost_price'],
                    'revenue' => (float)$revenue,
                    'profit' => (float)$profit,
                    'status' => $cattle['status']
                ];
            }
            
            echo json_encode(['status' => 'success', 'data' => $results]);
            break;

        case 'get_daily_monthly_summary':
            // สรุปยอดรายวันแบบเรียลไทม์ (ดึงเฉพาะวันที่มีรายการขายหรือรับซื้อโคจริง เรียงตามลำดับวัน เดือน ปี)
            $stmtDaily = $db->query("
                SELECT all_dates.d AS date,
                       COALESCE(sales.revenue, 0) AS revenue,
                       COALESCE(sales.order_count, 0) AS order_count,
                       COALESCE(purchases.cost, 0) AS cost,
                       COALESCE(purchases.cattle_count, 0) AS cattle_count,
                       (COALESCE(sales.revenue, 0) - COALESCE(purchases.cost, 0)) AS profit
                FROM (
                    SELECT DATE(created_at) AS d FROM orders WHERE status != 'cancelled' AND created_at IS NOT NULL
                    UNION
                    SELECT DATE(purchased_at) AS d FROM cattles WHERE purchased_at IS NOT NULL
                ) all_dates
                LEFT JOIN (
                    SELECT DATE(created_at) as d, SUM(total_amount) as revenue, COUNT(*) as order_count
                    FROM orders
                    WHERE status != 'cancelled'
                    GROUP BY DATE(created_at)
                ) sales ON all_dates.d = sales.d
                LEFT JOIN (
                    SELECT DATE(purchased_at) as d, SUM(cost_price) as cost, COUNT(*) as cattle_count
                    FROM cattles
                    GROUP BY DATE(purchased_at)
                ) purchases ON all_dates.d = purchases.d
                WHERE all_dates.d IS NOT NULL
                  AND (COALESCE(sales.revenue, 0) > 0 OR COALESCE(purchases.cost, 0) > 0)
                ORDER BY all_dates.d ASC
            ");
            $daily = $stmtDaily->fetchAll(PDO::FETCH_ASSOC);
            
            // สรุปยอดรายเดือนแบบเรียลไทม์ (ดึงเฉพาะเดือนที่มีรายการขายหรือรับซื้อโคจริง เรียงตามลำดับเดือน ปี)
            $stmtMonthly = $db->query("
                SELECT all_months.m AS month,
                       COALESCE(sales.revenue, 0) AS revenue,
                       COALESCE(sales.order_count, 0) AS order_count,
                       COALESCE(purchases.cost, 0) AS cost,
                       COALESCE(purchases.cattle_count, 0) AS cattle_count,
                       (COALESCE(sales.revenue, 0) - COALESCE(purchases.cost, 0)) AS profit
                FROM (
                    SELECT DATE_FORMAT(created_at, '%Y-%m') AS m FROM orders WHERE status != 'cancelled' AND created_at IS NOT NULL
                    UNION
                    SELECT DATE_FORMAT(purchased_at, '%Y-%m') AS m FROM cattles WHERE purchased_at IS NOT NULL
                ) all_months
                LEFT JOIN (
                    SELECT DATE_FORMAT(created_at, '%Y-%m') as m, SUM(total_amount) as revenue, COUNT(*) as order_count
                    FROM orders
                    WHERE status != 'cancelled'
                    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                ) sales ON all_months.m = sales.m
                LEFT JOIN (
                    SELECT DATE_FORMAT(purchased_at, '%Y-%m') as m, SUM(cost_price) as cost, COUNT(*) as cattle_count
                    FROM cattles
                    GROUP BY DATE_FORMAT(purchased_at, '%Y-%m')
                ) purchases ON all_months.m = purchases.m
                WHERE all_months.m IS NOT NULL
                  AND (COALESCE(sales.revenue, 0) > 0 OR COALESCE(purchases.cost, 0) > 0)
                ORDER BY all_months.m ASC
            ");
            $monthly = $stmtMonthly->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'daily' => $daily,
                'monthly' => $monthly
            ]);
            break;

        // ==========================================
        // 2. Orders Management
        // ==========================================
        case 'get_orders':
            $stmt = $db->query("
                SELECT o.*, u.username, u.first_name, u.last_name, u.phone
                FROM orders o 
                JOIN users u ON o.user_id = u.id 
                ORDER BY o.id DESC, o.created_at DESC
            ");
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($orders as &$ord) {
                if ($ord['order_type'] === 'wholesale') {
                    $dVal = trim($ord['delivery_date'] ?? '');
                    if (empty($dVal) || $dVal === '0000-00-00' || strpos($dVal, '0000') === 0 || strpos($dVal, '1899') !== false || strpos($dVal, '2442') !== false || $dVal === '30/11/1899') {
                        $createdTs = !empty($ord['created_at']) ? strtotime($ord['created_at']) : time();
                        $fixedDate = date('Y-m-d', strtotime('+3 days', $createdTs));
                        $ord['delivery_date'] = $fixedDate;
                        try {
                            $db->prepare("UPDATE orders SET delivery_date = ? WHERE id = ?")->execute([$fixedDate, $ord['id']]);
                        } catch (Exception $e) {}
                    }
                }
            }
            unset($ord);
            echo json_encode(['status' => 'success', 'data' => $orders]);
            break;

        case 'get_order_details':
            $order_id = $_GET['id'] ?? '';
            if (!$order_id) throw new Exception("Order ID required");
            
            // Get order and user details
            $stmt = $db->prepare("
                SELECT o.*, u.username, u.first_name, u.last_name, u.phone 
                FROM orders o 
                JOIN users u ON o.user_id = u.id 
                WHERE o.id = ?
            ");
            $stmt->execute([$order_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) throw new Exception("Order not found");

            if ($order && $order['order_type'] === 'wholesale') {
                $dVal = trim($order['delivery_date'] ?? '');
                if (empty($dVal) || $dVal === '0000-00-00' || strpos($dVal, '0000') === 0 || strpos($dVal, '1899') !== false || strpos($dVal, '2442') !== false || $dVal === '30/11/1899') {
                    $createdTs = !empty($order['created_at']) ? strtotime($order['created_at']) : time();
                    $fixedDate = date('Y-m-d', strtotime('+3 days', $createdTs));
                    $order['delivery_date'] = $fixedDate;
                    try {
                        $db->prepare("UPDATE orders SET delivery_date = ? WHERE id = ?")->execute([$fixedDate, $order['id']]);
                    } catch (Exception $e) {}
                }
            }
            
            // Get order items
            $stmt2 = $db->prepare("
                SELECT oi.*, p.name as product_name, p.stock_kg
                FROM order_items oi
                JOIN products p ON oi.product_id = p.id
                WHERE oi.order_id = ?
            ");
            $stmt2->execute([$order_id]);
            $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            
            // Get payment details
            $stmt3 = $db->prepare("SELECT * FROM payments WHERE order_id = ? LIMIT 1");
            $stmt3->execute([$order_id]);
            $payment = $stmt3->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'status' => 'success',
                'order' => $order,
                'items' => $items,
                'payment' => $payment
            ]);
            break;

        case 'update_order':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Invalid request method");
            $order_id = $_POST['order_id'] ?? '';
            $status = $_POST['status'] ?? '';
            
            if (empty($order_id) || empty($status)) {
                throw new Exception("Missing parameters");
            }
            
            $db->beginTransaction();
            
            // Get current order status, type, and stock deduction flag
            $stmtOrder = $db->prepare("SELECT user_id, status, order_type, total_amount, stock_deducted FROM orders WHERE id = :id FOR UPDATE");
            $stmtOrder->execute([':id' => $order_id]);
            $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                $db->rollBack();
                throw new Exception("Order not found");
            }
            
            // กรณีออเดอร์ขายส่ง:
            // 1. จากรอตรวจสอบมัดจำ -> ยืนยันมัดจำเข้าสู่สถานะ 'preparing' (จัดเตรียมสินค้า): ยังไม่ตัดสต็อก
            // 2. หากข้ามไปสถานะ 'shipped' หรือ 'completed' โดยที่ยังไม่เคยตัดสต็อกมาก่อน (stock_deducted == 0) ให้ทำการตัดสต็อกหน้าร้าน
            if ($order['order_type'] === 'wholesale' 
                && (int)($order['stock_deducted'] ?? 0) === 0 
                && in_array($status, ['shipped', 'ready_for_pickup', 'completed'])) {
                
                $stmtCheckItems = $db->prepare("
                    SELECT oi.id as item_id, oi.product_id, oi.quantity_kg, p.name as product_name, p.stock_kg
                    FROM order_items oi
                    JOIN products p ON oi.product_id = p.id
                    WHERE oi.order_id = :id
                    FOR UPDATE
                ");
                $stmtCheckItems->execute([':id' => $order_id]);
                $wsItems = $stmtCheckItems->fetchAll(PDO::FETCH_ASSOC);
                
                $insufficient = [];
                foreach ($wsItems as $wItem) {
                    $avail = (float)$wItem['stock_kg'];
                    $req = (float)$wItem['quantity_kg'];
                    if ($avail < $req) {
                        $missing = number_format($req - $avail, 2);
                        $insufficient[] = "- {$wItem['product_name']}: สั่ง {$req} กก. (มีสต็อกหน้าร้าน " . number_format(max(0, $avail), 2) . " กก. ขาดอีก {$missing} กก.)";
                    }
                }
                
                if (!empty($insufficient)) {
                    $db->rollBack();
                    throw new Exception("ไม่สามารถเปลี่ยนสถานะได้ เนื่องจากสต็อกหน้าร้านไม่เพียงพอ:\n" . implode("\n", $insufficient) . "\nกรุณาชำแหละวัวเพิ่มก่อน");
                }
                
                $stmtDeductWs = $db->prepare("UPDATE products SET stock_kg = stock_kg - :qty WHERE id = :pid");
                $stmtSetActual = $db->prepare("UPDATE order_items SET actual_quantity_kg = :qty WHERE id = :item_id");
                foreach ($wsItems as $wItem) {
                    $stmtDeductWs->execute([':qty' => $wItem['quantity_kg'], ':pid' => $wItem['product_id']]);
                    $stmtSetActual->execute([':qty' => $wItem['quantity_kg'], ':item_id' => $wItem['item_id']]);
                }
                $db->prepare("UPDATE orders SET stock_deducted = 1 WHERE id = :id")->execute([':id' => $order_id]);
            }
            
            // If changing to cancelled, and it's not already cancelled
            if ($status === 'cancelled' && $order['status'] !== 'cancelled') {
                // คืนสต็อกเฉพาะกรณีที่เคยถูกตัดสต็อกไปแล้ว (คำสั่งซื้อปลีก หรือ คำสั่งซื้อส่งที่ตัดสต็อกแล้ว)
                $wasDeducted = ((int)($order['stock_deducted'] ?? 0) === 1) || ($order['order_type'] === 'retail');
                
                if ($wasDeducted) {
                    $stmtItems = $db->prepare("SELECT product_id, quantity_kg, actual_quantity_kg FROM order_items WHERE order_id = :id");
                    $stmtItems->execute([':id' => $order_id]);
                    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
                    
                    $stmtRestore = $db->prepare("UPDATE products SET stock_kg = stock_kg + :qty WHERE id = :pid");
                    foreach ($items as $item) {
                        $qty_to_restore = $item['actual_quantity_kg'] ?? $item['quantity_kg'];
                        if ($qty_to_restore > 0) {
                            $stmtRestore->execute([
                                ':qty' => $qty_to_restore,
                                ':pid' => $item['product_id']
                            ]);
                        }
                    }
                    $db->prepare("UPDATE orders SET stock_deducted = 0 WHERE id = :id")->execute([':id' => $order_id]);
                }
                
                // If the order was already being prepared, penalize the user
                if ($order['status'] === 'preparing') {
                    $stmtPenalty = $db->prepare("UPDATE users SET cancel_penalty = cancel_penalty + 1 WHERE id = :uid");
                    $stmtPenalty->execute([':uid' => $order['user_id']]);
                    
                    $stmtNotify = $db->prepare("INSERT INTO notifications (user_id, message) VALUES (:uid, :msg)");
                    $stmtNotify->execute([
                        ':uid' => $order['user_id'],
                        ':msg' => "คำสั่งซื้อ #ORD-" . str_pad($order_id, 4, '0', STR_PAD_LEFT) . " ถูกยกเลิกโดยร้านค้าในขณะที่กำลังเตรียมสินค้า"
                    ]);
                }
            }
            
            if ($status === 'cancelled') {
                $reason = trim($_POST['cancel_reason'] ?? '');
                if (empty($reason)) {
                    $reason = 'แอดมินเป็นผู้ยกเลิก';
                }
                $stmt = $db->prepare("UPDATE orders SET status = :status, cancel_reason = :reason WHERE id = :id");
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':reason', $reason);
                $stmt->bindParam(':id', $order_id);
                $stmt->execute();
            } else {
                $tracking_number = $_POST['tracking_number'] ?? null;
                if ($tracking_number) {
                    $stmt = $db->prepare("UPDATE orders SET status = :status, tracking_number = :tracking WHERE id = :id");
                    $stmt->bindParam(':status', $status);
                    $stmt->bindParam(':tracking', $tracking_number);
                    $stmt->bindParam(':id', $order_id);
                    $stmt->execute();
                } else {
                    $stmt = $db->prepare("UPDATE orders SET status = :status WHERE id = :id");
                    $stmt->bindParam(':status', $status);
                    $stmt->bindParam(':id', $order_id);
                    $stmt->execute();
                }
                
                // หากอนุมัติยอดคงเหลือเข้าสู่ขั้นตอนกำลังจัดเตรียม (preparing) ให้อัปเดตสถานะการชำระส่วนที่เหลือด้วย
                if ($status === 'preparing') {
                    $stmtPayUpd = $db->prepare("UPDATE payments SET remaining_status = 'completed' WHERE order_id = :id");
                    $stmtPayUpd->execute([':id' => $order_id]);
                    
                    $stmtNotifyPrep = $db->prepare("INSERT INTO notifications (user_id, message) VALUES (:uid, :msg)");
                    $stmtNotifyPrep->execute([
                        ':uid' => $order['user_id'],
                        ':msg' => "คำสั่งซื้อ #ORD-" . str_pad($order_id, 4, '0', STR_PAD_LEFT) . " ได้รับการยืนยันการชำระเงินแล้ว ขณะนี้แอดมินกำลังจัดเตรียมสินค้าให้คุณครับ"
                    ]);
                }
            }
            
            if ($db->inTransaction()) {
                $db->commit();
            }
            
            // แจ้งเตือนลูกค้าหลังเปลี่ยนสถานะสำเร็จ
            $extraData = [];
            if (isset($tracking_number) && $tracking_number) $extraData['tracking_number'] = $tracking_number;
            if (isset($reason) && $reason) $extraData['cancel_reason'] = $reason;
            if (!($order['order_type'] === 'wholesale' && $order['status'] === 'verifying_final_payment' && $status === 'preparing')) {
                sendOrderStatusNotification($db, $order_id, $status, $extraData);
            }

            echo json_encode(['status' => 'success']);
            break;

        case 'finish_wholesale_preparation':
        case 'confirm_wholesale_order':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Invalid request method");
            
            $order_id = $_POST['order_id'] ?? '';
            if (empty($order_id)) {
                throw new Exception("Missing order ID");
            }
            
            $db->beginTransaction();
            
            $stmtOrder = $db->prepare("SELECT id, user_id, total_amount, status, order_type, stock_deducted FROM orders WHERE id = :id FOR UPDATE");
            $stmtOrder->execute([':id' => $order_id]);
            $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                $db->rollBack();
                throw new Exception("ไม่พบคำสั่งซื้อในระบบ");
            }
            
            if ((int)($order['stock_deducted'] ?? 0) === 1) {
                $db->rollBack();
                throw new Exception("คำสั่งซื้อนี้ได้รับการตัดสต็อกหน้าร้านไปแล้ว");
            }
            
            // ตรวจสอบสต็อกหน้าร้านของทุกชิ้นส่วนในคำสั่งซื้อ
            $stmtItems = $db->prepare("
                SELECT oi.id as item_id, oi.product_id, oi.quantity_kg, oi.price_per_kg, p.name as product_name, p.stock_kg
                FROM order_items oi
                JOIN products p ON oi.product_id = p.id
                WHERE oi.order_id = :order_id
                FOR UPDATE
            ");
            $stmtItems->execute([':order_id' => $order_id]);
            $orderItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($orderItems)) {
                $db->rollBack();
                throw new Exception("ไม่พบรายการสินค้าในคำสั่งซื้อนี้");
            }
            
            $insufficientList = [];
            foreach ($orderItems as $oi) {
                $availableStock = (float)$oi['stock_kg'];
                $requiredKg = (float)$oi['quantity_kg'];
                if ($availableStock < $requiredKg) {
                    $missingKg = number_format($requiredKg - max(0, $availableStock), 2);
                    $insufficientList[] = "- {$oi['product_name']}: ลูกค้าสั่ง " . number_format($requiredKg, 2) . " กก. (มีสต็อกหน้าร้าน " . number_format(max(0, $availableStock), 2) . " กก. ขาดอีก {$missingKg} กก.)";
                }
            }
            
            if (!empty($insufficientList)) {
                $db->rollBack();
                throw new Exception("ไม่สามารถตัดสต็อกได้ เนื่องจากสต็อกหน้าร้านไม่เพียงพอ:\n" . implode("\n", $insufficientList) . "\n\nกรุณาไปที่หน้าคลังสินค้าเพื่อชำแหละวัวเพิ่มก่อนกดยืนยันจัดเตรียมเสร็จสิ้น");
            }
            
            // สต็อกหน้าร้านเพียงพอ -> ตัดสต็อกหน้าร้านทันทีเมื่อแอดมินกดจัดเตรียมสินค้าเสร็จสิ้น
            $stmtDeductStock = $db->prepare("UPDATE products SET stock_kg = stock_kg - :qty WHERE id = :product_id");
            $stmtUpdateItem = $db->prepare("UPDATE order_items SET actual_quantity_kg = :qty WHERE id = :item_id");
            
            foreach ($orderItems as $oi) {
                $stmtDeductStock->execute([
                    ':qty' => $oi['quantity_kg'],
                    ':product_id' => $oi['product_id']
                ]);
                $stmtUpdateItem->execute([
                    ':qty' => $oi['quantity_kg'],
                    ':item_id' => $oi['item_id']
                ]);
            }
            
            // คำนวณยอดคงเหลือ (อีก 50% ที่เหลือ)
            $stmtPayment = $db->prepare("SELECT amount_paid FROM payments WHERE order_id = :id LIMIT 1");
            $stmtPayment->execute([':id' => $order_id]);
            $paidVal = $stmtPayment->fetchColumn();
            $amount_paid = ($paidVal !== false && (float)$paidVal > 0) ? (float)$paidVal : ((float)$order['total_amount'] / 2);
            
            $total_amount = (float)$order['total_amount'];
            $remaining_amount = max(0, $total_amount - $amount_paid);
            $new_status = 'awaiting_final_payment';
            
            // อัปเดตสถานะออเดอร์เป็น awaiting_final_payment และบันทึก stock_deducted = 1
            $stmtUpdateOrder = $db->prepare("UPDATE orders SET status = :status, stock_deducted = 1 WHERE id = :id");
            $stmtUpdateOrder->execute([
                ':status' => $new_status,
                ':id' => $order_id
            ]);
            
            // อัปเดตยอดคงเหลือในตาราง payments
            $stmtUpdatePayment = $db->prepare("UPDATE payments SET total_required = :new_req, remaining_amount = :rem, remaining_status = :rstatus WHERE order_id = :id");
            $stmtUpdatePayment->execute([
                ':new_req' => $total_amount,
                ':rem' => $remaining_amount,
                ':rstatus' => 'pending',
                ':id' => $order_id
            ]);
            
            // เพิ่มข้อความแจ้งให้ลูกค้าชำระเงินส่วนที่เหลืออีก 50% เพื่อที่แอดมินจะได้จัดส่งสินค้า
            $msg = "คำสั่งซื้อ #ORD-" . str_pad($order_id, 4, '0', STR_PAD_LEFT) . " จัดเตรียมสินค้าเสร็จเรียบร้อยแล้ว กรุณาชำระเงินส่วนที่เหลืออีก 50% จำนวน ฿" . number_format($remaining_amount, 2) . " เพื่อให้ร้านค้าดำเนินการจัดส่งสินค้า";
            
            $stmtNotify = $db->prepare("INSERT INTO notifications (user_id, message) VALUES (:uid, :msg)");
            $stmtNotify->execute([
                ':uid' => $order['user_id'],
                ':msg' => $msg
            ]);
            
            if ($db->inTransaction()) {
                $db->commit();
            }
            
            require_once 'notification_helper.php';
            sendOrderStatusNotification($db, $order_id, 'awaiting_final_payment');
            
            echo json_encode([
                'status' => 'success',
                'remaining_amount' => $remaining_amount,
                'message' => 'จัดเตรียมสินค้าเสร็จสิ้นและตัดสต็อกหน้าร้านทันทีเรียบร้อยแล้ว! ระบบได้ส่งข้อความแจ้งให้ลูกค้าชำระเงินส่วนที่เหลืออีก 50% แล้ว'
            ]);
            break;

        case 'save_actual_weights':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Invalid request method");
            
            $order_id = $_POST['order_id'] ?? '';
            $items_json = $_POST['items'] ?? '[]';
            $items = json_decode($items_json, true);
            
            if (empty($order_id) || empty($items)) {
                throw new Exception("Missing parameters");
            }
            
            $db->beginTransaction();
            
            $stmtOrder = $db->prepare("SELECT user_id, total_amount, status FROM orders WHERE id = :id FOR UPDATE");
            $stmtOrder->execute([':id' => $order_id]);
            $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                $db->rollBack();
                throw new Exception("Order not found");
            }
            
            // Calculate new total amount based on actual weights & verify stock first
            $new_subtotal = 0;
            $stmtUpdateItem = $db->prepare("UPDATE order_items SET actual_quantity_kg = :actual_kg WHERE id = :item_id AND order_id = :order_id");
            $stmtGetPrice = $db->prepare("
                SELECT oi.product_id, oi.price_per_kg, p.name as product_name, p.stock_kg
                FROM order_items oi
                JOIN products p ON oi.product_id = p.id
                WHERE oi.id = :item_id AND oi.order_id = :order_id
                FOR UPDATE
            ");
            $stmtDeductStock = $db->prepare("UPDATE products SET stock_kg = stock_kg - :actual_kg WHERE id = :product_id");
            
            $insufficientList = [];
            $resolvedItems = [];
            foreach ($items as $item) {
                $stmtGetPrice->execute([':item_id' => $item['id'], ':order_id' => $order_id]);
                $price_row = $stmtGetPrice->fetch(PDO::FETCH_ASSOC);
                if ($price_row) {
                    $avail = (float)$price_row['stock_kg'];
                    $req = (float)$item['actual_kg'];
                    if ($avail < $req) {
                        $missing = number_format($req - max(0, $avail), 2);
                        $insufficientList[] = "- {$price_row['product_name']}: ต้องการ {$req} กก. (มีสต็อกหน้าร้าน " . number_format(max(0, $avail), 2) . " กก. ขาดอีก {$missing} กก.)";
                    }
                    $resolvedItems[] = [
                        'id' => $item['id'],
                        'actual_kg' => $req,
                        'product_id' => $price_row['product_id'],
                        'price_per_kg' => (float)$price_row['price_per_kg']
                    ];
                }
            }
            
            if (!empty($insufficientList)) {
                $db->rollBack();
                throw new Exception("ไม่สามารถยืนยันคำสั่งซื้อได้ เนื่องจากสต็อกหน้าร้านไม่เพียงพอ:\n" . implode("\n", $insufficientList) . "\nกรุณาชำแหละวัวเพิ่มก่อนยืนยันคำสั่งซื้อ");
            }
            
            foreach ($resolvedItems as $rItem) {
                $new_subtotal += ($rItem['actual_kg'] * $rItem['price_per_kg']);
                
                $stmtUpdateItem->execute([
                    ':actual_kg' => $rItem['actual_kg'],
                    ':item_id' => $rItem['id'],
                    ':order_id' => $order_id
                ]);
                
                // ตัดสต็อกหน้าร้านทันทีเมื่อแอดมินยืนยันคำสั่งซื้อ
                $stmtDeductStock->execute([
                    ':actual_kg' => $rItem['actual_kg'],
                    ':product_id' => $rItem['product_id']
                ]);
            }
            
            $stmtOldSubtotal = $db->prepare("SELECT SUM(quantity_kg * price_per_kg) as old_sub FROM order_items WHERE order_id = :id");
            $stmtOldSubtotal->execute([':id' => $order_id]);
            $old_subtotal = $stmtOldSubtotal->fetchColumn();
            
            $shipping_fee = $order['total_amount'] - $old_subtotal;
            $new_total_amount = $new_subtotal + $shipping_fee;
            
            // Get amount paid so far
            $stmtPayment = $db->prepare("SELECT amount_paid FROM payments WHERE order_id = :id LIMIT 1");
            $stmtPayment->execute([':id' => $order_id]);
            $amount_paid = (float) $stmtPayment->fetchColumn();

            $new_status = 'awaiting_final_payment';
            $remaining_amount = 0;
            
            if ($amount_paid >= $new_total_amount) {
                $new_status = 'preparing';
            } else {
                $remaining_amount = $new_total_amount - $amount_paid;
            }
            
            // Update order total and status
            $stmtUpdateOrder = $db->prepare("UPDATE orders SET total_amount = :new_total, status = :status WHERE id = :id");
            $stmtUpdateOrder->execute([
                ':new_total' => $new_total_amount,
                ':status' => $new_status,
                ':id' => $order_id
            ]);

            // Update payment requirement and remaining amount
            $stmtUpdatePayment = $db->prepare("UPDATE payments SET total_required = :new_req, remaining_amount = :rem WHERE order_id = :id");
            $stmtUpdatePayment->execute([
                ':new_req' => $new_total_amount,
                ':rem' => $remaining_amount,
                ':id' => $order_id
            ]);
            
            // Notify user
            $msg = $new_status === 'preparing' 
                ? "คำสั่งซื้อ #ORD-" . str_pad($order_id, 4, '0', STR_PAD_LEFT) . " แอดมินยืนยันคำสั่งซื้อแล้ว (ชำระครบถ้วน) ทางร้านกำลังจัดเตรียมสินค้า" 
                : "คำสั่งซื้อ #ORD-" . str_pad($order_id, 4, '0', STR_PAD_LEFT) . " แอดมินยืนยันคำสั่งซื้อแล้ว กรุณาชำระเงินส่วนที่เหลือ จำนวน ฿" . number_format($remaining_amount, 2) . " เพื่อที่แอดมินจะได้จัดเตรียมสินค้า";
            
            $stmtNotify = $db->prepare("INSERT INTO notifications (user_id, message) VALUES (:uid, :msg)");
            $stmtNotify->execute([
                ':uid' => $order['user_id'],
                ':msg' => $msg
            ]);
            
            // ส่งแจ้งเตือนผ่าน Email/LINE
            if ($db->inTransaction()) {
                $db->commit();
            }
            
            require_once 'notification_helper.php';
            sendOrderStatusNotification($db, $order_id, $new_status);
            
            echo json_encode(['status' => 'success', 'remaining_amount' => $remaining_amount]);
            break;

        // ==========================================
        // 3. Cattle Purchase & List
        // ==========================================
        case 'purchase_cattle':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Invalid request method");
            
            $tag_number = $_POST['tag_number'] ?? '';
            $seller_name = trim($_POST['seller_name'] ?? '');
            $seller_phone = trim($_POST['seller_phone'] ?? '');
            $breed = $_POST['breed'] ?? '';
            $gender = $_POST['gender'] ?? 'ผู้';
            $weight_kg = $_POST['weight_kg'] ?? 0;
            $cost_price = $_POST['cost_price'] ?? 0;
            $purchased_at = $_POST['purchased_at'] ?? '';
            $notes = trim($_POST['notes'] ?? '');
            
            if (empty($tag_number) || empty($breed) || empty($purchased_at)) {
                throw new Exception("Missing required fields");
            }
            
            $image_url = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../uploads/cattles/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                
                $fileName = time() . '_' . preg_replace("/[^a-zA-Z0-9\._-]/", "", basename($_FILES['image']['name']));
                $targetFile = $uploadDir . $fileName;
                
                if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
                    $image_url = 'uploads/cattles/' . $fileName;
                }
            }
            
            $stmt = $db->prepare("INSERT INTO cattles (tag_number, seller_name, seller_phone, breed, gender, weight_kg, initial_weight_kg, cost_price, purchased_at, image_url, notes) VALUES (:tag, :seller_name, :seller_phone, :breed, :gender, :weight, :initial_weight, :cost, :pdate, :img, :notes)");
            $stmt->bindParam(':tag', $tag_number);
            $stmt->bindParam(':seller_name', $seller_name);
            $stmt->bindParam(':seller_phone', $seller_phone);
            $stmt->bindParam(':breed', $breed);
            $stmt->bindParam(':gender', $gender);
            $stmt->bindParam(':weight', $weight_kg);
            $stmt->bindParam(':initial_weight', $weight_kg);
            $stmt->bindParam(':cost', $cost_price);
            $stmt->bindParam(':pdate', $purchased_at);
            $stmt->bindParam(':img', $image_url);
            $stmt->bindParam(':notes', $notes);
            $stmt->execute();
            
            echo json_encode(['status' => 'success', 'cattle_id' => $db->lastInsertId()]);
            break;

        case 'get_cattles':
            $stmt = $db->query("
                SELECT c.*, 
                       c.initial_weight_kg as base_weight_kg,
                       COALESCE((SELECT weight_kg FROM cattle_growth cg WHERE cg.cattle_id = c.id ORDER BY measured_at DESC LIMIT 1), c.weight_kg) as weight_kg 
                FROM cattles c 
                ORDER BY c.purchased_at ASC, c.id ASC
            ");
            $cattles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'data' => $cattles]);
            break;

        case 'get_cattle_processing':
            $cattle_id = $_GET['id'] ?? '';
            if (empty($cattle_id)) throw new Exception("Missing cattle ID");
            $stmt = $db->prepare("
                SELECT ip.*, p.name as product_name 
                FROM inventory_processing ip 
                JOIN products p ON ip.product_id = p.id 
                WHERE ip.cattle_id = ?
            ");
            $stmt->execute([$cattle_id]);
            $processing = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'data' => $processing]);
            break;

        case 'update_cattle':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Invalid request method");
            $id = $_POST['id'] ?? '';
            $breed = $_POST['breed'] ?? '';
            $gender = $_POST['gender'] ?? 'ผู้';
            $weight_kg = $_POST['weight_kg'] ?? 0;
            $cost_price = $_POST['cost_price'] ?? 0;
            $purchased_at = $_POST['purchased_at'] ?? '';
            
            if(empty($id) || empty($breed) || empty($purchased_at)) throw new Exception("Missing data");
            
            $image_url = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../uploads/cattles/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                
                $fileName = time() . '_' . preg_replace("/[^a-zA-Z0-9\._-]/", "", basename($_FILES['image']['name']));
                $targetFile = $uploadDir . $fileName;
                
                if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
                    $image_url = 'uploads/cattles/' . $fileName;
                }
            }
            
            if ($image_url) {
                $stmt = $db->prepare("UPDATE cattles SET breed=?, gender=?, weight_kg=?, cost_price=?, purchased_at=?, image_url=? WHERE id=?");
                $stmt->execute([$breed, $gender, $weight_kg, $cost_price, $purchased_at, $image_url, $id]);
            } else {
                $stmt = $db->prepare("UPDATE cattles SET breed=?, gender=?, weight_kg=?, cost_price=?, purchased_at=? WHERE id=?");
                $stmt->execute([$breed, $gender, $weight_kg, $cost_price, $purchased_at, $id]);
            }
            
            echo json_encode(['status' => 'success']);
            break;

        case 'save_cattle_seller_info':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Invalid request method");
            $id = $_POST['id'] ?? '';
            $seller_name = trim($_POST['seller_name'] ?? '');
            $seller_phone = trim($_POST['seller_phone'] ?? '');
            if (empty($id)) throw new Exception("Missing cattle ID");

            $stmt = $db->prepare("UPDATE cattles SET seller_name = :sname, seller_phone = :sphone WHERE id = :id");
            $stmt->execute([
                ':sname' => $seller_name,
                ':sphone' => $seller_phone,
                ':id' => $id
            ]);
            echo json_encode(['status' => 'success']);
            break;

        case 'delete_cattle':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Invalid request method");
            $id = $_POST['id'] ?? '';
            if(empty($id)) throw new Exception("Missing cattle ID");
            
            $db->beginTransaction();
            // หากโคตัวนี้เคยชำแหละและเพิ่มสต๊อกไปแล้ว ให้หักสต๊อกที่มาจากโคตัวนี้ออกก่อนลบข้อมูลการชำแหละ
            $stmtProc = $db->prepare("SELECT product_id, yield_kg FROM inventory_processing WHERE cattle_id = ?");
            $stmtProc->execute([$id]);
            $procRows = $stmtProc->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($procRows)) {
                $stmtRevert = $db->prepare("UPDATE products SET stock_kg = GREATEST(0, stock_kg - ?) WHERE id = ?");
                foreach ($procRows as $pr) {
                    $stmtRevert->execute([$pr['yield_kg'], $pr['product_id']]);
                }
                $stmtDelProc = $db->prepare("DELETE FROM inventory_processing WHERE cattle_id = ?");
                $stmtDelProc->execute([$id]);
            }

            $db->prepare("DELETE FROM cattle_growth WHERE cattle_id = ?")->execute([$id]);
            $db->prepare("DELETE FROM cattle_health WHERE cattle_id = ?")->execute([$id]);

            $stmt = $db->prepare("DELETE FROM cattles WHERE id=?");
            $stmt->execute([$id]);
            $db->commit();
            echo json_encode(['status' => 'success']);
            break;

        // ==========================================
        // 4. Inventory Processing
        // ==========================================
        case 'process_inventory':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Invalid request method");
            
            $cattle_id = $_POST['cattle_id'] ?? '';
            $yields = json_decode($_POST['yields'] ?? '{}', true);
            
            if (empty($cattle_id) || empty($yields)) {
                throw new Exception("Missing parameters");
            }
            
            $db->beginTransaction();
            
            // Check if cattle exists
            $stmtCheck = $db->prepare("SELECT status FROM cattles WHERE id = :id");
            $stmtCheck->execute([':id' => $cattle_id]);
            $cattle = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if (!$cattle) {
                throw new Exception("Cattle not found");
            }
            
            // If already processed, we need to revert old stock first
            if ($cattle['status'] === 'processed') {
                $stmtOld = $db->prepare("SELECT product_id, yield_kg FROM inventory_processing WHERE cattle_id = ?");
                $stmtOld->execute([$cattle_id]);
                $old_yields = $stmtOld->fetchAll(PDO::FETCH_ASSOC);
                
                $revert_stock = $db->prepare("UPDATE products SET stock_kg = stock_kg - ? WHERE id = ?");
                foreach ($old_yields as $oy) {
                    $revert_stock->execute([$oy['yield_kg'], $oy['product_id']]);
                }
                
                // Delete old records
                $stmtDel = $db->prepare("DELETE FROM inventory_processing WHERE cattle_id = ?");
                $stmtDel->execute([$cattle_id]);
            } else {
                $stmtUpdateCattle = $db->prepare("UPDATE cattles SET status = 'processed' WHERE id = :id");
                $stmtUpdateCattle->execute([':id' => $cattle_id]);
            }
            
            // Process each part
            $insert_proc = $db->prepare("INSERT INTO inventory_processing (cattle_id, product_id, yield_kg) VALUES (:cid, :pid, :yield)");
            $update_stock = $db->prepare("UPDATE products SET stock_kg = stock_kg + :yield WHERE id = :pid");
            
            foreach ($yields as $part_name => $yield_str) {
                $yield = floatval($yield_str);
                if ($yield > 0) {
                    $prod_stmt = $db->prepare("SELECT id FROM products WHERE name = :name LIMIT 1");
                    $prod_stmt->bindParam(':name', $part_name);
                    $prod_stmt->execute();
                    $prod = $prod_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($prod) {
                        $pid = $prod['id'];
                        // Record processing
                        $insert_proc->execute([':cid' => $cattle_id, ':pid' => $pid, ':yield' => $yield]);
                        // Add stock
                        $update_stock->execute([':yield' => $yield, ':pid' => $pid]);
                    }
                }
            }
            
            $db->commit();
            echo json_encode(['status' => 'success']);
            break;

        // ==========================================
        // 5. Inventory Stock Management
        // ==========================================
        case 'get_inventory_stock':
            // Auto-clean duplicate products if any exist in the database
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
            } catch (Exception $e) {}

            // Ensure is_wholesale and unit columns exist
            try {
                $db->exec("ALTER TABLE products ADD COLUMN is_wholesale TINYINT(1) NOT NULL DEFAULT 1 AFTER price_wholesale");
            } catch (Exception $e) {}
            try {
                $db->exec("ALTER TABLE products ADD COLUMN unit VARCHAR(20) NOT NULL DEFAULT 'กก.' AFTER price_wholesale");
            } catch (Exception $e) {}

            $stmt = $db->query("
                SELECT MIN(id) as id, name, MAX(description) as description, MAX(stock_kg) as stock_kg, MAX(price_retail) as price_per_kg, MAX(price_wholesale) as price_wholesale, MAX(unit) as unit, MAX(is_wholesale) as is_wholesale, MAX(image_url) as image_url
                FROM products 
                GROUP BY name
                ORDER BY MIN(id) ASC
            ");
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'data' => $products]);
            break;

        case 'add_product':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Invalid request method");

            try {
                $db->exec("ALTER TABLE products ADD COLUMN is_wholesale TINYINT(1) NOT NULL DEFAULT 1 AFTER price_wholesale");
            } catch (Exception $e) {}
            try {
                $db->exec("ALTER TABLE products ADD COLUMN unit VARCHAR(20) NOT NULL DEFAULT 'กก.' AFTER price_wholesale");
            } catch (Exception $e) {}

            $name = trim($_POST['name'] ?? '');
            $price_retail = floatval($_POST['price_retail'] ?? 0);
            $unitInput = trim($_POST['unit'] ?? 'กก.');
            $unit = ($unitInput === 'ชิ้น') ? 'ชิ้น' : 'กก.';
            $is_wholesale = isset($_POST['is_wholesale']) ? intval($_POST['is_wholesale']) : 1;
            if ($is_wholesale === 1) {
                // ราคาขายส่งเมื่อสั่งซื้อ 25 กก. ขึ้นไป นำราคาขายปลีกลบ 10% เสมอ
                $price_wholesale = round($price_retail * 0.90, 2);
            } else {
                $price_wholesale = 0;
            }
            $stock_kg = 0; // สต๊อกเริ่มต้นเป็น 0 เสมอ ให้แอดมินจัดการผ่านการแปลงสภาพโคหรือปรับสต๊อก
            $description = trim($_POST['description'] ?? '');

            if ($name === '' || $price_retail <= 0) {
                throw new Exception("กรุณาระบุชื่อชิ้นส่วนเนื้อและราคาขายปลีกให้ถูกต้อง");
            }

            // Check duplicate name
            $chkStmt = $db->prepare("SELECT COUNT(*) FROM products WHERE name = :name");
            $chkStmt->execute([':name' => $name]);
            if ($chkStmt->fetchColumn() > 0) {
                throw new Exception("มีชิ้นส่วนเนื้อ \"" . $name . "\" อยู่ในระบบแล้ว");
            }

            $image_url = 'assets/img/logo.jpg';
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../uploads/products/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $fileExtension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                if (!in_array($fileExtension, $allowedExts)) {
                    throw new Exception("รองรับเฉพาะไฟล์รูปภาพ (JPG, PNG, WEBP, GIF)");
                }
                $fileName = 'part_' . time() . '_' . uniqid() . '.' . $fileExtension;
                $targetPath = $uploadDir . $fileName;
                if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
                    $image_url = 'uploads/products/' . $fileName;
                }
            }

            $stmt = $db->prepare("
                INSERT INTO products (name, description, price_retail, price_wholesale, unit, is_wholesale, stock_kg, image_url)
                VALUES (:name, :desc, :retail, :wholesale, :unit, :is_ws, :stock, :img)
            ");
            $stmt->execute([
                ':name' => $name,
                ':desc' => $description,
                ':retail' => $price_retail,
                ':wholesale' => $price_wholesale,
                ':unit' => $unit,
                ':is_ws' => $is_wholesale,
                ':stock' => $stock_kg,
                ':img' => $image_url
            ]);

            echo json_encode(['status' => 'success', 'id' => $db->lastInsertId(), 'name' => $name, 'unit' => $unit]);
            break;

        case 'delete_product':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Invalid request method");

            $product_id = intval($_POST['product_id'] ?? 0);
            if ($product_id <= 0) {
                throw new Exception("ไม่พบรหัสชิ้นส่วนเนื้อที่ต้องการลบ");
            }

            $db->beginTransaction();
            try {
                $findStmt = $db->prepare("SELECT name, image_url FROM products WHERE id = :id LIMIT 1");
                $findStmt->execute([':id' => $product_id]);
                $prod = $findStmt->fetch(PDO::FETCH_ASSOC);
                if (!$prod) {
                    throw new Exception("ไม่พบข้อมูลชิ้นส่วนเนื้อในระบบ");
                }

                // Get all IDs with this name in case duplicates existed
                $idsStmt = $db->prepare("SELECT id FROM products WHERE name = :name");
                $idsStmt->execute([':name' => $prod['name']]);
                $ids = $idsStmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($ids)) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $delProc = $db->prepare("DELETE FROM inventory_processing WHERE product_id IN ($placeholders)");
                    $delProc->execute($ids);

                    $delOrderItems = $db->prepare("DELETE FROM order_items WHERE product_id IN ($placeholders)");
                    $delOrderItems->execute($ids);

                    $delProd = $db->prepare("DELETE FROM products WHERE id IN ($placeholders)");
                    $delProd->execute($ids);
                }

                // Delete uploaded file if it is in uploads/products/
                if (!empty($prod['image_url']) && strpos($prod['image_url'], 'uploads/products/') === 0) {
                    $filePath = '../' . $prod['image_url'];
                    if (file_exists($filePath)) {
                        @unlink($filePath);
                    }
                }

                $db->commit();
                echo json_encode(['status' => 'success']);
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
            break;

        case 'update_inventory_stock':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Invalid request method");
            
            $product_id = $_POST['product_id'] ?? '';
            $stock_kg = $_POST['stock_kg'] ?? '';
            
            if (empty($product_id) || $stock_kg === '') throw new Exception("Missing parameters");
            
            $stock_kg = floatval($stock_kg);
            if ($stock_kg < 0) throw new Exception("Stock cannot be negative");
            
            $stmt = $db->prepare("UPDATE products SET stock_kg = :stock WHERE id = :id");
            $stmt->execute([':stock' => $stock_kg, ':id' => $product_id]);
            
            echo json_encode(['status' => 'success']);
            break;

        // ==========================================
        // 6. Farm Management (Growth & Health)
        // ==========================================
        case 'add_growth':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Invalid request method");
            $cattle_id = $_POST['cattle_id'] ?? '';
            $weight = $_POST['weight'] ?? 0;
            $measured_at = $_POST['measured_at'] ?? '';
            
            if(empty($cattle_id) || empty($weight) || empty($measured_at)) throw new Exception("Missing data");
            
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("INSERT INTO cattle_growth (cattle_id, weight_kg, measured_at) VALUES (?, ?, ?)");
                $stmt->execute([$cattle_id, $weight, $measured_at]);
                
                // Update the main cattle weight
                $stmtUpdate = $db->prepare("UPDATE cattles SET weight_kg = ? WHERE id = ?");
                $stmtUpdate->execute([$weight, $cattle_id]);
                
                if ($db->inTransaction()) {
                    $db->commit();
                }
                echo json_encode(['status' => 'success']);
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
            break;
            
        case 'get_growth':
            $cattle_id = $_GET['cattle_id'] ?? '';
            if(empty($cattle_id)) throw new Exception("Missing cattle ID");
            
            $stmt = $db->prepare("SELECT * FROM cattle_growth WHERE cattle_id = ? ORDER BY measured_at ASC");
            $stmt->execute([$cattle_id]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'data' => $data]);
            break;

        case 'add_health':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Invalid request method");
            $cattle_id = $_POST['cattle_id'] ?? '';
            $type = $_POST['type'] ?? '';
            $date = $_POST['date'] ?? '';
            $details = $_POST['details'] ?? '';
            
            if(empty($cattle_id) || empty($type) || empty($date)) throw new Exception("Missing data");
            
            $stmt = $db->prepare("INSERT INTO cattle_health (cattle_id, record_type, record_date, details) VALUES (?, ?, ?, ?)");
            $stmt->execute([$cattle_id, $type, $date, $details]);
            echo json_encode(['status' => 'success']);
            break;
            
        case 'get_health':
            $cattle_id = $_GET['cattle_id'] ?? '';
            if(empty($cattle_id)) throw new Exception("Missing cattle ID");
            
            $stmt = $db->prepare("SELECT * FROM cattle_health WHERE cattle_id = ? ORDER BY record_date ASC, id ASC");
            $stmt->execute([$cattle_id]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'data' => $data]);
            break;

        case 'get_new_orders_count':
            // Count orders that require admin attention (not completed or cancelled)
            $stmt = $db->query("SELECT COUNT(*) FROM orders WHERE status NOT IN ('completed', 'cancelled')");
            $count = $stmt->fetchColumn();
            
            // Also get the latest order ID to detect if there is a NEW order
            $stmt2 = $db->query("SELECT id FROM orders ORDER BY id DESC LIMIT 1");
            $latest_id = $stmt2->fetchColumn();
            
            echo json_encode([
                'status' => 'success',
                'count' => (int)$count,
                'latest_order_id' => (int)$latest_id
            ]);
            break;

        default:
            throw new Exception("Unknown action: " . htmlspecialchars($action));
    }

} catch(Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
