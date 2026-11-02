<?php
session_start();
require_once '../config/database.php';
require_once 'notification_helper.php';

header("Content-Type: application/json; charset=UTF-8");

$raw_data = file_get_contents("php://input");
$data = json_decode($raw_data, true);

if (!$data && isset($_POST['action'])) {
    $data = $_POST;
}
if (!$data && isset($_GET['action'])) {
    $data = $_GET;
}

if (!isset($data['action'])) {
    echo json_encode(['status' => 'error', 'message' => 'No action specified']);
    exit();
}

$action = $data['action'];

try {
    $database = new Database();
    $db = $database->getConnection();

    switch ($action) {
        case 'get_products':
            $query = "SELECT * FROM products ORDER BY id ASC";
            $stmt = $db->query($query);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'data' => $products]);
            break;

        case 'get_stock':
            $query = "SELECT id, stock_kg FROM products";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $stock = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $stock[$row['id']] = (float)$row['stock_kg'];
            }
            echo json_encode(['success' => true, 'stock' => $stock]);
            break;

        case 'get_profile_data':
            if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
                echo json_encode(['status' => 'error', 'message' => 'Please login first']);
                exit();
            }
            require_once 'auto_cancel.php';
            auto_cancel_expired_orders($db);
            $user_id = $_SESSION['user_id'];
            
            // Auto-migrate: Ensure columns exist
            try { $db->exec("ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NULL AFTER email"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE users ADD COLUMN last_name VARCHAR(100) NULL AFTER first_name"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE users ADD COLUMN custom_name_set TINYINT(1) DEFAULT 0 AFTER last_name"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE users ADD COLUMN line_name VARCHAR(150) NULL AFTER provider_id"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER last_name"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE users ADD COLUMN shipping_address TEXT NULL AFTER phone"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) NULL AFTER shipping_address"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE orders MODIFY COLUMN status ENUM('pending_payment', 'processing', 'awaiting_final_payment', 'verifying_final_payment', 'preparing', 'shipped', 'ready_for_pickup', 'completed', 'cancelled') DEFAULT 'pending_payment'"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE orders ADD COLUMN tracking_number VARCHAR(100) NULL AFTER shipping_address"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE orders ADD COLUMN pickup_code VARCHAR(50) NULL AFTER tracking_number"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE orders ADD COLUMN final_total_amount DECIMAL(10,2) NULL AFTER total_amount"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE orders ADD COLUMN stock_deducted TINYINT(1) DEFAULT 0 AFTER final_total_amount"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE order_items ADD COLUMN actual_quantity_kg DECIMAL(8,2) NULL AFTER quantity_kg"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE payments MODIFY COLUMN status VARCHAR(50) DEFAULT 'pending'"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE payments ADD COLUMN remaining_amount DECIMAL(10,2) DEFAULT 0.00 AFTER amount_paid"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE payments ADD COLUMN remaining_status VARCHAR(50) DEFAULT 'pending' AFTER remaining_amount"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE payments ADD COLUMN second_slip_image_url VARCHAR(255) NULL AFTER remaining_status"); } catch (Exception $e) {}
            
            $stmtUser = $db->prepare("SELECT id, username, email, first_name, last_name, phone, shipping_address, profile_image, role, auth_provider, provider_id, line_name, custom_name_set FROM users WHERE id = ?");
            $stmtUser->execute([$user_id]);
            $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                echo json_encode(['status' => 'error', 'message' => 'User not found']);
                exit();
            }

            $customSet = (int)($user['custom_name_set'] ?? 0);
            $authProvider = strtolower(trim($user['auth_provider'] ?? 'local'));
            $email = trim($user['email'] ?? '');
            $isLineLocal = (strpos($email, '@line.local') !== false);

            $regName = '';
            if ($authProvider === 'line') {
                $regName = !empty($user['line_name']) ? trim($user['line_name']) : (!empty($_SESSION['line_name']) ? trim($_SESSION['line_name']) : '');
            } else {
                $regName = (!$isLineLocal && !empty($email)) ? $email : trim($user['username'] ?? '');
            }

            $currentFullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

            // ตรวจสอบว่าชื่อใน first_name โดนชื่อผู้รับจากที่อยู่จัดส่งทับหรือไม่
            $isTainted = false;
            if (!empty($user['first_name'])) {
                try {
                    $chkAddr = $db->prepare("SELECT COUNT(*) FROM user_addresses WHERE user_id = ? AND recipient_name = ?");
                    $chkAddr->execute([$user_id, $user['first_name']]);
                    if ($chkAddr->fetchColumn() > 0) {
                        $isTainted = true;
                    }
                } catch (Exception $e) {}
            }

            // หากลูกค้าไม่กดเปลี่ยน หรือชื่อเคยโดนที่อยู่จัดส่งทับ ให้ยึดตามชื่อที่ลูกค้าสมัครสมาชิก (LINE หรือ Email)
            if ($customSet === 0 || $isTainted || empty($currentFullName)) {
                if (!empty($regName)) {
                    $user['first_name'] = $regName;
                    $user['last_name'] = '';
                    $user['custom_name_set'] = 0;
                    try {
                        $db->prepare("UPDATE users SET first_name = ?, last_name = '', custom_name_set = 0 WHERE id = ?")->execute([$regName, $user_id]);
                    } catch (Exception $e) {}
                }
            }
            
            if (empty($user['shipping_address'])) {
                $stmtAddr = $db->prepare("SELECT shipping_address FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
                $stmtAddr->execute([$user_id]);
                $address = $stmtAddr->fetchColumn();
                $user['shipping_address'] = $address ? $address : '';
            }
            
            $stmtOrders = $db->prepare("
                SELECT o.id, o.created_at, o.total_amount, o.order_type, o.delivery_date, o.status, o.shipping_address, o.pickup_code, o.tracking_number, o.final_total_amount, o.cancel_reason, p.slip_image_url, p.amount_paid, p.remaining_amount, p.remaining_status, p.second_slip_image_url 
                FROM orders o
                LEFT JOIN payments p ON o.id = p.order_id
                WHERE o.user_id = ? 
                ORDER BY o.created_at ASC, o.id ASC
            ");
            $stmtOrders->execute([$user_id]);
            $orders = $stmtOrders->fetchAll(PDO::FETCH_ASSOC);
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
            
            echo json_encode(['status' => 'success', 'user' => $user, 'orders' => $orders]);
            break;

        case 'get_user_notifications':
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
                exit;
            }
            $user_id = $_SESSION['user_id'];
            $stmt = $db->prepare("SELECT cancel_penalty FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stmtNotif = $db->prepare("SELECT id, message, created_at FROM notifications WHERE user_id = ? AND is_read = FALSE ORDER BY created_at DESC");
            $stmtNotif->execute([$user_id]);
            $notifications = $stmtNotif->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($notifications) > 0) {
                $update = $db->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ?");
                $update->execute([$user_id]);
            }
            
            echo json_encode([
                'status' => 'success',
                'cancel_penalty' => $user['cancel_penalty'] ?? 0,
                'notifications' => $notifications
            ]);
            break;

        case 'check_updates':
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
                exit;
            }
            $user_id = $_SESSION['user_id'];
            
            // Unread notifications
            $stmtNotif = $db->prepare("SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND is_read = FALSE");
            $stmtNotif->execute([$user_id]);
            $unread_count = $stmtNotif->fetchColumn();
            
            // Orders requiring action (e.g. awaiting_final_payment)
            $stmtAction = $db->prepare("SELECT COUNT(*) as action_count FROM orders WHERE user_id = ? AND status = 'awaiting_final_payment'");
            $stmtAction->execute([$user_id]);
            $action_count = $stmtAction->fetchColumn();
            
            // Active orders
            $stmtActive = $db->prepare("SELECT COUNT(*) as active_count FROM orders WHERE user_id = ? AND status NOT IN ('completed', 'cancelled')");
            $stmtActive->execute([$user_id]);
            $active_count = $stmtActive->fetchColumn();

            echo json_encode([
                'status' => 'success',
                'unread_notifications' => (int)$unread_count,
                'action_required' => (int)$action_count,
                'active_orders' => (int)$active_count
            ]);
            break;

        case 'update_profile':
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['success' => false, 'message' => 'Please login first']);
                exit();
            }
            $userId = $_SESSION['user_id'];
            
            try { $db->exec("ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NULL AFTER email"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE users ADD COLUMN last_name VARCHAR(100) NULL AFTER first_name"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE users ADD COLUMN custom_name_set TINYINT(1) DEFAULT 0 AFTER last_name"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE users ADD COLUMN line_name VARCHAR(150) NULL AFTER provider_id"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER last_name"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE users ADD COLUMN shipping_address TEXT NULL AFTER phone"); } catch (Exception $e) {}
            try { $db->exec("ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) NULL AFTER shipping_address"); } catch (Exception $e) {}
            
            $stmtCur = $db->prepare("SELECT username, email, auth_provider, line_name, first_name, last_name, phone, shipping_address, profile_image, custom_name_set FROM users WHERE id = ?");
            $stmtCur->execute([$userId]);
            $currentUser = $stmtCur->fetch(PDO::FETCH_ASSOC) ?: [];

            $customNameSet = 1;
            $firstName = $currentUser['first_name'] ?? '';
            $lastName = $currentUser['last_name'] ?? '';

            if (isset($data['fullname'])) {
                $fullName = trim($data['fullname']);
                if ($fullName !== '') {
                    $nameParts = preg_split('/\s+/', $fullName, 2);
                    $firstName = $nameParts[0];
                    $lastName = isset($nameParts[1]) ? $nameParts[1] : '';
                    $customNameSet = 1;
                } else {
                    // หากผู้ใช้ล้างชื่อออก ให้คืนค่ากลับไปเป็นชื่อที่สมัครสมาชิก (LINE หรือ Email)
                    $authProvider = strtolower(trim($currentUser['auth_provider'] ?? 'local'));
                    $email = trim($currentUser['email'] ?? '');
                    $isLineLocal = (strpos($email, '@line.local') !== false);
                    if ($authProvider === 'line') {
                        $regName = !empty($currentUser['line_name']) ? trim($currentUser['line_name']) : (!empty($_SESSION['line_name']) ? trim($_SESSION['line_name']) : ($currentUser['username'] ?? ''));
                    } else {
                        $regName = (!$isLineLocal && !empty($email)) ? $email : trim($currentUser['username'] ?? '');
                    }
                    $nameParts = preg_split('/\s+/', $regName, 2);
                    $firstName = $nameParts[0];
                    $lastName = isset($nameParts[1]) ? $nameParts[1] : '';
                    $customNameSet = 0;
                }
            } else {
                $customNameSet = (int)($currentUser['custom_name_set'] ?? 0);
            }
            
            $phone = isset($data['phone']) ? trim($data['phone']) : ($currentUser['phone'] ?? '');
            $address = isset($data['address']) ? trim($data['address']) : ($currentUser['shipping_address'] ?? '');
            $profileImage = $currentUser['profile_image'] ?? '';

            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../uploads/profiles/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $ext = 'jpg';
                }
                $fileName = 'profile_' . $userId . '_' . time() . '.' . $ext;
                $targetFile = $uploadDir . $fileName;
                if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $targetFile)) {
                    $profileImage = 'uploads/profiles/' . $fileName;
                }
            }
            
            $stmt = $db->prepare("UPDATE users SET first_name = ?, last_name = ?, phone = ?, shipping_address = ?, profile_image = ?, custom_name_set = ? WHERE id = ?");
            $stmt->execute([$firstName, $lastName, $phone, $address, $profileImage, $customNameSet, $userId]);
            
            if (!empty($firstName)) {
                $_SESSION['username'] = $firstName;
            }
            $_SESSION['profile_image'] = $profileImage;

            $displayName = trim($firstName . ' ' . $lastName);
            if ($displayName === '') {
                $displayName = $currentUser['username'] ?? 'สมาชิก';
            }

            echo json_encode([
                'success' => true,
                'fullname' => $displayName,
                'phone' => $phone,
                'profile_image' => $profileImage,
                'message' => 'Profile updated successfully'
            ]);
            break;

        case 'get_order_items':
            if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
                echo json_encode(['status' => 'error', 'message' => 'Please login first']);
                exit();
            }
            $user_id = $_SESSION['user_id'];
            $order_id = $data['order_id'] ?? '';
            if (empty($order_id)) throw new Exception("Missing order_id");
            
            $stmtVerify = $db->prepare("SELECT id FROM orders WHERE id = ? AND user_id = ?");
            $stmtVerify->execute([$order_id, $user_id]);
            if (!$stmtVerify->fetch()) throw new Exception("Unauthorized or order not found");
            
            $stmt = $db->prepare("
                SELECT oi.product_id as id, oi.quantity_kg as quantity, oi.price_per_kg as price, p.name, p.image_url as image
                FROM order_items oi 
                JOIN products p ON oi.product_id = p.id 
                WHERE oi.order_id = ?
            ");
            $stmt->execute([$order_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'items' => $items]);
            break;

        case 'cancel_order':
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบ']);
                exit;
            }
            $order_id = $data['order_id'] ?? '';
            $user_id = $_SESSION['user_id'];
            if (empty($order_id)) {
                echo json_encode(['status' => 'error', 'message' => 'Missing order ID']);
                exit;
            }
            $db->beginTransaction();
            $stmt = $db->prepare("SELECT id, status, order_type FROM orders WHERE id = ? AND user_id = ? FOR UPDATE");
            $stmt->execute([$order_id, $user_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                $db->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'ไม่พบคำสั่งซื้อ หรือคุณไม่มีสิทธิ์ยกเลิก']);
                exit;
            }
            if (!in_array($order['status'], ['pending_payment', 'processing'])) {
                $db->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'ร้านกำลังเตรียมสินค้า กรุณาติดต่อร้านโดยตรงเพื่อยกเลิก']);
                exit;
            }
            if ($order['order_type'] === 'retail') {
                $stmtItems = $db->prepare("SELECT product_id, quantity_kg FROM order_items WHERE order_id = ?");
                $stmtItems->execute([$order_id]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
                
                $stmtRestore = $db->prepare("UPDATE products SET stock_kg = stock_kg + ? WHERE id = ?");
                foreach ($items as $item) {
                    $stmtRestore->execute([$item['quantity_kg'], $item['product_id']]);
                }
            }
            $stmtUpdate = $db->prepare("UPDATE orders SET status = 'cancelled', cancel_reason = 'ลูกค้ายกเลิกเอง' WHERE id = ?");
            $stmtUpdate->execute([$order_id]);
            $db->commit();
            
            // แจ้งเตือนลูกค้ายกเลิกคำสั่งซื้อ
            sendOrderStatusNotification($db, $order_id, 'cancelled', ['cancel_reason' => 'ลูกค้ายกเลิกเอง']);
            
            echo json_encode(['status' => 'success', 'message' => 'ยกเลิกคำสั่งซื้อเรียบร้อยแล้ว']);
            break;

        case 'pay_remaining':
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['status' => 'error', 'message' => 'Please login first']);
                exit();
            }
            $order_id = $_POST['order_id'] ?? '';
            if (empty($order_id)) {
                echo json_encode(['status' => 'error', 'message' => 'Missing order ID']);
                exit();
            }
            $stmt = $db->prepare("SELECT id, status FROM orders WHERE id = ? AND user_id = ?");
            $stmt->execute([$order_id, $_SESSION['user_id']]);
            $order = $stmt->fetch();
            
            if (!$order) throw new Exception("Order not found or unauthorized");
            if ($order['status'] !== 'awaiting_final_payment') throw new Exception("Order is not awaiting final payment");

            $slipUrl = '';
            if (!empty($_POST['verified_slip_url'])) {
                $slipUrl = trim($_POST['verified_slip_url']);
            } else if (isset($_FILES['slipUpload']) && $_FILES['slipUpload']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../assets/uploads/slips/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $fileName = time() . '_slip2_' . preg_replace("/[^a-zA-Z0-9\._-]/", "", basename($_FILES['slipUpload']['name']));
                $targetFile = $uploadDir . $fileName;
                
                if (!move_uploaded_file($_FILES['slipUpload']['tmp_name'], $targetFile)) {
                    throw new Exception("ไม่สามารถบันทึกไฟล์สลิปได้");
                }
                $slipUrl = 'assets/uploads/slips/' . $fileName;
            } else {
                throw new Exception("สลิปไม่ถูกต้อง: กรุณาแนบรูปภาพสลิปการโอนเงิน");
            }
            
            $stmtUpdate = $db->prepare("UPDATE payments SET second_slip_image_url = ?, remaining_status = 'verifying' WHERE order_id = ?");
            $stmtUpdate->execute([$slipUrl, $order_id]);
            
            $stmtUpdateOrder = $db->prepare("UPDATE orders SET status = 'verifying_final_payment' WHERE id = ?");
            $stmtUpdateOrder->execute([$order_id]);
            
            // แจ้งเตือนลูกค้าส่งสลิปส่วนที่เหลือ
            sendOrderStatusNotification($db, $order_id, 'verifying_final_payment');
            
            echo json_encode(['status' => 'success', 'message' => 'Slip uploaded successfully']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
