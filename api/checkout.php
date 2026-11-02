<?php
session_start();
require_once '../config/database.php';
require_once 'notification_helper.php';

header("Content-Type: application/json; charset=UTF-8");

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit();
}

$raw_data = file_get_contents("php://input");
$data = json_decode($raw_data, true);

// -------------------------------------------------------------------------
// ฟังก์ชันสำหรับส่ง LINE Notify แจ้งเตือนแอดมิน
// -------------------------------------------------------------------------
function sendAdminLineNotify($message) {
    // นำ Token ที่ได้จาก LINE Notify มาใส่ตรงนี้
    $lineToken = "YOUR_LINE_NOTIFY_TOKEN_HERE"; 
    
    // ถ้ายังไม่ได้ใส่ Token ให้ข้ามไป
    if ($lineToken === "YOUR_LINE_NOTIFY_TOKEN_HERE" || empty($lineToken)) return;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://notify-api.line.me/api/notify");
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "message=" . $message);
    $headers = array('Content-type: application/x-www-form-urlencoded', 'Authorization: Bearer ' . $lineToken);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_exec($ch);
    curl_close($ch);
}
// -------------------------------------------------------------------------

if (!$data || empty($data['items'])) {
    echo json_encode(['success' => false, 'message' => 'Cart is empty']);
    exit();
}

try {
    $database = new Database();
    $conn = $database->getConnection();

    $userId = $_SESSION['user_id'];
    
    // Auto-migrate columns on orders & payments BEFORE beginTransaction() (DDL statements cause implicit commit in MySQL)
    try { $conn->exec("ALTER TABLE orders ADD COLUMN delivery_date VARCHAR(50) NULL AFTER order_type"); } catch (Exception $e) {}
    try { $conn->exec("ALTER TABLE orders MODIFY COLUMN delivery_date VARCHAR(50) NULL"); } catch (Exception $e) {}
    try { $conn->exec("ALTER TABLE orders MODIFY COLUMN status ENUM('pending_payment', 'processing', 'awaiting_final_payment', 'verifying_final_payment', 'preparing', 'shipped', 'ready_for_pickup', 'completed', 'cancelled') DEFAULT 'pending_payment'"); } catch (Exception $e) {}
    try { $conn->exec("ALTER TABLE orders ADD COLUMN tracking_number VARCHAR(100) NULL AFTER shipping_address"); } catch (Exception $e) {}
    try { $conn->exec("ALTER TABLE orders ADD COLUMN stock_deducted TINYINT(1) DEFAULT 0 AFTER final_total_amount"); } catch (Exception $e) {}
    try { $conn->exec("ALTER TABLE payments MODIFY COLUMN status VARCHAR(50) DEFAULT 'pending'"); } catch (Exception $e) {}
    try { $conn->exec("ALTER TABLE payments ADD COLUMN remaining_amount DECIMAL(10,2) DEFAULT 0.00 AFTER amount_paid"); } catch (Exception $e) {}
    try { $conn->exec("ALTER TABLE payments ADD COLUMN remaining_status VARCHAR(50) DEFAULT 'pending' AFTER remaining_amount"); } catch (Exception $e) {}
    try { $conn->exec("ALTER TABLE payments ADD COLUMN second_slip_image_url VARCHAR(255) NULL AFTER remaining_status"); } catch (Exception $e) {}

    $conn->beginTransaction();

    // Check user cancel penalty
    $stmtPenalty = $conn->prepare("SELECT cancel_penalty FROM users WHERE id = ?");
    $stmtPenalty->execute([$userId]);
    $userPenalty = $stmtPenalty->fetchColumn();
    
    $cartType = isset($data['cart_type']) ? $data['cart_type'] : 'retail';
    $deliveryDate = !empty($data['delivery_date']) ? trim($data['delivery_date']) : null;
    if (!empty($deliveryDate)) {
        if (strpos($deliveryDate, '/') !== false) {
            $parts = explode('/', $deliveryDate);
            if (count($parts) === 3) {
                $p0 = intval($parts[0]);
                $p1 = intval($parts[1]);
                $year = intval($parts[2]);
                $day = $p0;
                $month = $p1;
                if ($p1 > 12 && $p0 <= 12) {
                    $day = $p1;
                    $month = $p0;
                }
                if ($year > 2400) $year -= 543;
                if ($year < 100) $year += 2000;
                $deliveryDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
    }
    if ($cartType === 'wholesale') {
        if (empty($deliveryDate) || $deliveryDate === '0000-00-00' || strpos($deliveryDate, '0000') === 0 || strpos($deliveryDate, '1899') === 0) {
            $deliveryDate = date('Y-m-d', strtotime('+3 days'));
        }
    }
    $deliveryOpt = isset($data['delivery_option']) ? $data['delivery_option'] : '';
    $shippingAddress = !empty($data['shipping_address']) ? $data['shipping_address'] : ($deliveryOpt === 'deliveryOption3' ? 'รับที่หน้าร้าน' : 'ไม่ระบุที่อยู่จัดส่ง');
    $paymentMethod = 'transfer';

    // หมายเหตุ: ไม่นำชื่อผู้รับในที่อยู่จัดส่งไปเขียนทับชื่อ-นามสกุลในข้อมูลส่วนตัวของลูกค้า (users.first_name / last_name)
    // เพื่อยึดตามชื่อที่ลูกค้าใช้สมัครสมาชิก (LINE / Email) หรือที่ลูกค้าแก้ไขเองในหน้าโปรไฟล์เท่านั้น
    $rawAddr = '';
    if (!empty($data['address_detail']) && !empty($data['province'])) {
        $rawAddr = $data['address_detail'] . ' ต.' . $data['subdistrict'] . ' อ.' . $data['district'] . ' จ.' . $data['province'] . ' ' . $data['zipcode'];
    }
    if (!empty($rawAddr)) {
        $stmtUpdateAddr = $conn->prepare("UPDATE users SET shipping_address = ? WHERE id = ?");
        $stmtUpdateAddr->execute([$rawAddr, $userId]);
    }

    
    // Calculate total weight and fetch base prices
    $totalWeight = 0;
    foreach ($data['items'] as $item) {
        $totalWeight += (float) $item['quantity'];
    }

    $tierDiscount = 0;
    if ($cartType === 'wholesale') {
        if ($totalWeight >= 25) $tierDiscount = 0.10;
    }

    $subtotal = 0;
    $stmtPrice = $conn->prepare("SELECT price_retail, price_wholesale FROM products WHERE id = ?");

    // Loop through items to calculate secure subtotal
    foreach ($data['items'] as &$item) {
        $productId = (int) preg_replace('/\D/', '', $item['id']);
        $stmtPrice->execute([$productId]);
        $row = $stmtPrice->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) {
            throw new Exception("Product ID " . $productId . " not found in database.");
        }
        
        if ($cartType === 'wholesale') {
            $basePrice = (float) $row['price_wholesale'];
        } else {
            $basePrice = (float) $row['price_retail'];
        }
        
        $discountedPrice = $basePrice * (1 - $tierDiscount);
        
        // Override price from frontend with secure server-side price
        $item['price'] = $discountedPrice;
        $subtotal += $discountedPrice * (float) $item['quantity'];
    }
    unset($item); // break the reference with the last element

    if ($data['delivery_option'] === 'deliveryOption3') {
        $shipping = 0;
    } else {
        $province = isset($data['province']) ? $data['province'] : '';
        
        $zone1Provinces = ['ตรัง'];
            $zone2Provinces = [
                'กรุงเทพมหานคร', 'นนทบุรี', 'ปทุมธานี', 'สมุทรปราการ', 'สมุทรสาคร', 'นครปฐม',
                'พระนครศรีอยุธยา', 'อ่างทอง', 'ลพบุรี', 'สิงห์บุรี', 'ชัยนาท', 'สระบุรี', 'สมุทรสงคราม',
                'สุพรรณบุรี', 'นครนายก', 'กระบี่', 'ชุมพร', 'นครศรีธรรมราช', 'นราธิวาส', 'ปัตตานี', 'พังงา', 'พัทลุง', 'ภูเก็ต', 'ยะลา', 'ระนอง', 'สงขลา', 'สตูล', 'สุราษฎร์ธานี'
            ];
            
            $zone = 3;
            if (in_array($province, $zone1Provinces)) {
                $zone = 1;
            } elseif (in_array($province, $zone2Provinces)) {
                $zone = 2;
            }
            
            $rates = [
                1 => [90, 100, 110, 130, 150, 190],
                2 => [140, 170, 190, 210, 250, 290],
                3 => [270, 290, 310, 340, 360, 380]
            ];
            $maxWeights = [5, 10, 15, 20, 25, 30];
            
            $totalCost = 0;
            $remainingWeight = $totalWeight;
            
            if ($remainingWeight <= 0) {
                $shipping = 0;
            } else {
                while ($remainingWeight > 0) {
                    $boxWeight = $remainingWeight > 30 ? 30 : $remainingWeight;
                    $remainingWeight -= $boxWeight;
                    
                    $rateIndex = 0;
                    foreach ($maxWeights as $i => $mw) {
                        if ($boxWeight <= $mw) {
                            $rateIndex = $i;
                            break;
                        }
                    }
                    $totalCost += $rates[$zone][$rateIndex];
                }
                $shipping = $totalCost;
            }
        }

    $totalAmount = $subtotal + $shipping;
    $status = 'pending_payment';
    
    // ต้องแนบสลิปการโอนเงินเสมอเมื่อลูกค้ากดสั่งซื้อ
    if (!isset($data['slip_image_url']) || empty(trim($data['slip_image_url']))) {
        throw new Exception("กรุณาแนบสลิปการโอนเงินก่อนยืนยันคำสั่งซื้อ");
    }

    $paymentType = 'full';
    $totalRequired = $totalAmount;
    $remainingAmount = 0;
    $remainingStatus = 'completed';
    if ($cartType === 'wholesale') {
        $paymentType = 'deposit';
        $totalRequired = $totalAmount / 2;
        $remainingAmount = $totalAmount - $totalRequired;
        $remainingStatus = 'pending';
    }
    
    // $deliveryOpt is already set above

    // คำสั่งซื้อปลีก (retail): ตัดสต็อกหน้าร้านทันทีเมื่อลูกค้ากดสั่งซื้อ
    // คำสั่งซื้อส่ง (wholesale): ยังไม่ตัดสต็อกตอนสั่งซื้อ แต่จะตัดสต็อกเมื่อแอดมินกดจัดเตรียมสินค้าเสร็จ
    $isStockDeducted = ($cartType === 'retail') ? 1 : 0;

    // Insert Order
    $stmt = $conn->prepare("INSERT INTO orders (user_id, total_amount, order_type, delivery_date, status, shipping_address, stock_deducted) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $totalAmount, $cartType, $deliveryDate, $status, $shippingAddress, $isStockDeducted]);
    $orderId = $conn->lastInsertId();

    // Process Items
    $stmtItem = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity_kg, price_per_kg) VALUES (?, ?, ?, ?)");
    
    // ลูกค้าปลีก (retail): ตัดสต็อกหน้าร้านทันทีเมื่อกดสั่งและแนบสลิป
    // ลูกค้าส่ง (wholesale): ยังไม่ตัดสต็อกหน้าร้านทันที รอแอดมินตรวจสอบสต็อกและกดยืนยันคำสั่งซื้อก่อน
    $stmtUpdateStockRetail = $conn->prepare("UPDATE products SET stock_kg = stock_kg - ? WHERE id = ? AND stock_kg >= ?");

    foreach ($data['items'] as $item) {
        // Extract numeric ID from 'p1' or 'w1'
        $productId = (int) preg_replace('/\D/', '', $item['id']);
        $qty = (float) $item['quantity'];
        
        $stmtItem->execute([$orderId, $productId, $qty, $item['price']]);

        if ($cartType === 'retail') {
            $stmtUpdateStockRetail->execute([$qty, $productId, $qty]);
            if ($stmtUpdateStockRetail->rowCount() == 0) {
                throw new Exception("สินค้าชิ้นส่วน " . $item['name'] . " สต๊อกหน้าร้านไม่เพียงพอ");
            }
        }
    }

    // Insert Payment Record
    $stmtPayment = $conn->prepare("INSERT INTO payments (order_id, slip_image_url, payment_type, total_required, amount_paid, remaining_amount, remaining_status, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    $slipImage = trim($data['slip_image_url']);
    $paymentStatus = 'completed';
    $amountPaid = $totalRequired;
    
    // Update order status to processing (รอตรวจสอบ/ยืนยันคำสั่งซื้อ)
    $stmtUpdateOrder = $conn->prepare("UPDATE orders SET status = 'processing' WHERE id = ?");
    $stmtUpdateOrder->execute([$orderId]);

    $stmtPayment->execute([$orderId, $slipImage, $paymentType, $totalRequired, $amountPaid, $remainingAmount, $remainingStatus, $paymentStatus]);

    if ($conn->inTransaction()) {
        $conn->commit();
    }

    // ==========================================
    // แจ้งเตือนแอดมินผ่าน LINE Notify
    // ==========================================
    $orderNumber = 'ORD-' . str_pad($orderId, 5, '0', STR_PAD_LEFT);
    $notifyMessage = "\n🛒 มีคำสั่งซื้อใหม่!\n";
    $notifyMessage .= "รหัส: " . $orderNumber . "\n";
    $notifyMessage .= "ยอดรวม: ฿" . number_format($totalAmount, 2) . "\n";
    $notifyMessage .= "สถานะ: " . ($status === 'processing' ? 'รอยืนยันสลิป' : 'รอชำระเงิน') . "\n";
    $notifyMessage .= "กรุณาเข้าสู่ระบบเพื่อตรวจสอบครับ";
    sendAdminLineNotify($notifyMessage);

    // ==========================================
    // แจ้งเตือนลูกค้าผ่าน Email และ LINE (ถ้าผูกไว้)
    // ==========================================
    $finalStatus = ($paymentStatus === 'completed') ? 'processing' : 'pending_payment';
    sendOrderStatusNotification($conn, $orderId, $finalStatus);
    // ==========================================

    echo json_encode(['success' => true, 'order_id' => $orderNumber]);

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
