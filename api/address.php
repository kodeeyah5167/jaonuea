<?php
// api/address.php - API สำหรับจัดการที่อยู่จัดส่งหลายรายการ (Multi-Address Management)
session_start();
require_once '../config/database.php';

header("Content-Type: application/json; charset=UTF-8");

$database = new Database();
$db = $database->getConnection();

// ตรวจสอบและสร้างตาราง user_addresses หากยังไม่มี
try {
    $db->exec("CREATE TABLE IF NOT EXISTS user_addresses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        recipient_name VARCHAR(150) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        province VARCHAR(100) NOT NULL,
        district VARCHAR(100) NOT NULL,
        subdistrict VARCHAR(100) NOT NULL,
        zipcode VARCHAR(10) NOT NULL,
        address_detail TEXT NOT NULL,
        label ENUM('home', 'work', 'other') DEFAULT 'home',
        is_default TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // ลบข้อมูลที่อยู่ตัวอย่าง (Mock Data เดิม) ที่เคยถูกสร้างอัตโนมัติออกจากระบบทั้งหมดอย่างเด็ดขาด
    $db->exec("
        DELETE FROM user_addresses
        WHERE recipient_name LIKE '%วรายุทธ์%'
           OR recipient_name LIKE '%กัลย์สุดา%'
           OR recipient_name LIKE '%ร้านค้าป้าลอ%'
           OR recipient_name LIKE '%แม่ตุ๊ก%'
           OR (recipient_name LIKE '%คอดีเยะห์%' AND (address_detail LIKE '%โพรงจระเข้%' OR address_detail LIKE '%1 หมู่%' OR address_detail LIKE '%1 ม.%'))
           OR phone LIKE '%982282260%'
           OR phone LIKE '%958860516%'
           OR phone LIKE '%931503897%'
           OR (phone LIKE '%657081497%' AND (address_detail LIKE '%โพรงจระเข้%' OR address_detail LIKE '%ย่านตาขาว%'))
    ");
} catch (Exception $e) {
    // Continue
}

// ตรวจสอบผู้ใช้งานจาก Session
$userId = $_SESSION['user_id'] ?? null;
$isLoggedIn = !empty($userId) && (!empty($_SESSION['logged_in']));

// รับข้อมูลจาก Request
$raw_data = file_get_contents("php://input");
$data = json_decode($raw_data, true);
if (!$data && isset($_POST['action'])) {
    $data = $_POST;
}
if (!$data && isset($_GET['action'])) {
    $data = $_GET;
}

$action = $data['action'] ?? ($_GET['action'] ?? 'list');

// หากยังไม่เข้าสู่ระบบ ให้ส่งสถานะว่ายังไม่ได้ล็อกอิน และคืนข้อมูลที่อยู่ว่าง
if (!$isLoggedIn) {
    if ($action === 'list') {
        echo json_encode([
            'success' => true,
            'logged_in' => false,
            'addresses' => [],
            'default_address' => null,
            'message' => 'ยังไม่ได้เข้าสู่ระบบ'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    } else {
        echo json_encode([
            'success' => false,
            'logged_in' => false,
            'message' => 'กรุณาเข้าสู่ระบบก่อนดำเนินการ'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

// ฟังก์ชันแปลงเบอร์โทรเป็นรูปแบบ (+66) XX XXX XXXX
function formatPhone($phone) {
    $clean = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($clean) === 10 && substr($clean, 0, 1) === '0') {
        return '(+66) ' . substr($clean, 1, 2) . ' ' . substr($clean, 3, 3) . ' ' . substr($clean, 6);
    }
    return $phone;
}

// ฟังก์ชันจัดรูปแบบที่อยู่ฉบับเต็ม
function formatFullAddress($item) {
    $lines = [];
    if (!empty($item['address_detail'])) {
        $lines[] = trim($item['address_detail']);
    }
    $sub = !empty($item['subdistrict']) ? ((strpos($item['province'], 'กรุงเทพ') !== false ? 'แขวง' : 'ตำบล') . $item['subdistrict']) : '';
    $dis = !empty($item['district']) ? ((strpos($item['province'], 'กรุงเทพ') !== false ? 'เขต' : 'อำเภอ') . $item['district']) : '';
    $prov = !empty($item['province']) ? ('จังหวัด' . $item['province']) : '';
    $zip = $item['zipcode'] ?? '';

    $geo = array_filter([$sub, $dis, $prov, $zip]);
    if (!empty($geo)) {
        $lines[] = implode(', ', $geo);
    }
    return implode("\n", $lines);
}

try {
    switch ($action) {
        case 'list':
            // ลบข้อมูลที่อยู่ตัวอย่าง (Mock Data เดิม) ที่เคยถูกสร้างอัตโนมัติออกจากระบบอย่างเด็ดขาด
            try {
                $cleanupStmt = $db->prepare("
                    DELETE FROM user_addresses
                    WHERE recipient_name LIKE '%วรายุทธ์%'
                       OR recipient_name LIKE '%กัลย์สุดา%'
                       OR recipient_name LIKE '%ร้านค้าป้าลอ%'
                       OR recipient_name LIKE '%แม่ตุ๊ก%'
                       OR (recipient_name LIKE '%คอดีเยะห์%' AND (address_detail LIKE '%โพรงจระเข้%' OR address_detail LIKE '%1 หมู่%' OR address_detail LIKE '%1 ม.%'))
                       OR phone LIKE '%982282260%'
                       OR phone LIKE '%958860516%'
                       OR phone LIKE '%931503897%'
                       OR (phone LIKE '%657081497%' AND (address_detail LIKE '%โพรงจระเข้%' OR address_detail LIKE '%ย่านตาขาว%'))
                ");
                $cleanupStmt->execute();
            } catch (Exception $e) {}

            // ดึงรายการที่อยู่ทั้งหมดของผู้ใช้ (แสดงเฉพาะที่อยู่ที่ผู้ใช้กรอกเองเท่านั้น)
            $stmt = $db->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, id DESC");
            $stmt->execute([$userId]);
            $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // จัดรูปแบบข้อมูลสำหรับการแสดงผล UI
            foreach ($addresses as &$addr) {
                $addr['phone_formatted'] = formatPhone($addr['phone']);
                $addr['full_address'] = formatFullAddress($addr);
                $addr['is_default'] = (int)$addr['is_default'];
            }

            // หา Default Address หรือรายการแรกสุด
            $defaultAddress = null;
            foreach ($addresses as $a) {
                if ($a['is_default'] === 1) {
                    $defaultAddress = $a;
                    break;
                }
            }
            if (!$defaultAddress && !empty($addresses)) {
                $defaultAddress = $addresses[0];
            }

            echo json_encode([
                'success' => true,
                'addresses' => $addresses,
                'default_address' => $defaultAddress
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'get':
            $addressId = (int)($data['id'] ?? ($_GET['id'] ?? 0));
            $stmt = $db->prepare("SELECT * FROM user_addresses WHERE id = ? AND user_id = ?");
            $stmt->execute([$addressId, $userId]);
            $addr = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($addr) {
                $addr['phone_formatted'] = formatPhone($addr['phone']);
                $addr['full_address'] = formatFullAddress($addr);
                $addr['is_default'] = (int)$addr['is_default'];
                echo json_encode(['success' => true, 'address' => $addr], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลที่อยู่นี้']);
            }
            break;

        case 'save':
            $id = !empty($data['id']) ? (int)$data['id'] : null;
            $recipientName = trim($data['recipient_name'] ?? '');
            $phone = trim($data['phone'] ?? '');
            $province = trim($data['province'] ?? '');
            $district = trim($data['district'] ?? '');
            $subdistrict = trim($data['subdistrict'] ?? '');
            $zipcode = trim($data['zipcode'] ?? '');
            $addressDetail = trim($data['address_detail'] ?? '');
            $label = in_array($data['label'] ?? '', ['home', 'work']) ? $data['label'] : 'home';
            $isDefault = !empty($data['is_default']) ? 1 : 0;

            if (empty($recipientName) || empty($phone) || empty($addressDetail) || empty($province)) {
                echo json_encode(['success' => false, 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
                exit();
            }

            // ถ้าไม่มีที่อยู่อื่นเลย ให้รายการนี้เป็นค่าเริ่มต้นโดยอัตโนมัติ
            $countStmt = $db->prepare("SELECT COUNT(*) FROM user_addresses WHERE user_id = ?");
            $countStmt->execute([$userId]);
            $totalCount = (int)$countStmt->fetchColumn();
            if ($totalCount === 0) {
                $isDefault = 1;
            }

            // หากเลือกเป็นค่าเริ่มต้น ให้ยกเลิกค่าเริ่มต้นของรายการอื่น
            if ($isDefault === 1) {
                $clearStmt = $db->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?");
                $clearStmt->execute([$userId]);
            }

            if ($id) {
                // แก้ไขที่อยู่เดิม
                $updateStmt = $db->prepare("UPDATE user_addresses SET 
                    recipient_name = ?, 
                    phone = ?, 
                    province = ?, 
                    district = ?, 
                    subdistrict = ?, 
                    zipcode = ?, 
                    address_detail = ?, 
                    label = ?, 
                    is_default = ? 
                    WHERE id = ? AND user_id = ?");
                $updateStmt->execute([
                    $recipientName, $phone, $province, $district, $subdistrict, $zipcode,
                    $addressDetail, $label, $isDefault, $id, $userId
                ]);
                $savedId = $id;
            } else {
                // เพิ่มที่อยู่ใหม่
                $insertStmt = $db->prepare("INSERT INTO user_addresses 
                    (user_id, recipient_name, phone, province, district, subdistrict, zipcode, address_detail, label, is_default) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insertStmt->execute([
                    $userId, $recipientName, $phone, $province, $district, $subdistrict, $zipcode,
                    $addressDetail, $label, $isDefault
                ]);
                $savedId = (int)$db->lastInsertId();
            }

            echo json_encode([
                'success' => true,
                'message' => $id ? 'บันทึกการแก้ไขที่อยู่เรียบร้อยแล้ว' : 'เพิ่มที่อยู่ใหม่เรียบร้อยแล้ว',
                'id' => $savedId
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'set_default':
            $addressId = (int)($data['id'] ?? 0);
            if (!$addressId) {
                echo json_encode(['success' => false, 'message' => 'Invalid address ID']);
                exit();
            }

            // เคลียร์อันเดิม
            $db->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?")->execute([$userId]);
            // ตั้งค่าอันใหม่
            $db->prepare("UPDATE user_addresses SET is_default = 1 WHERE id = ? AND user_id = ?")->execute([$addressId, $userId]);

            echo json_encode(['success' => true, 'message' => 'ตั้งเป็นที่อยู่เริ่มต้นเรียบร้อยแล้ว']);
            break;

        case 'delete':
            $addressId = (int)($data['id'] ?? 0);
            if (!$addressId) {
                echo json_encode(['success' => false, 'message' => 'Invalid address ID']);
                exit();
            }

            // ตรวจสอบว่าที่อยู่ที่จะลบเป็น Default หรือไม่
            $chkStmt = $db->prepare("SELECT is_default FROM user_addresses WHERE id = ? AND user_id = ?");
            $chkStmt->execute([$addressId, $userId]);
            $isDef = (int)$chkStmt->fetchColumn();

            $delStmt = $db->prepare("DELETE FROM user_addresses WHERE id = ? AND user_id = ?");
            $delStmt->execute([$addressId, $userId]);

            // ถ้าลบตัว default ให้ตั้งตัวล่าสุดที่เหลืออยู่เป็น default
            if ($isDef === 1) {
                $remStmt = $db->prepare("SELECT id FROM user_addresses WHERE user_id = ? ORDER BY id DESC LIMIT 1");
                $remStmt->execute([$userId]);
                $remId = $remStmt->fetchColumn();
                if ($remId) {
                    $db->prepare("UPDATE user_addresses SET is_default = 1 WHERE id = ?")->execute([$remId]);
                }
            }

            echo json_encode(['success' => true, 'message' => 'ลบที่อยู่เรียบร้อยแล้ว']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

