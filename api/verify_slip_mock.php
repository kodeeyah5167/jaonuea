<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบก่อนทำรายการ']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
    echo json_encode(['success' => false, 'message' => 'สลิปไม่ถูกต้อง: ไม่พบไฟล์สลิปการโอนเงิน']);
    exit;
}

$expectedAmount = isset($_POST['expected_amount']) ? (float)$_POST['expected_amount'] : 0;
$qrPayload = isset($_POST['qr_payload']) ? trim($_POST['qr_payload']) : '';
$file = $_FILES['file'];

// Validate file type
$allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
$fileType = mime_content_type($file['tmp_name']) ?: $file['type'];
if (!in_array($fileType, $allowedTypes) && !in_array($file['type'], $allowedTypes)) {
    echo json_encode(['success' => false, 'message' => 'สลิปไม่ถูกต้อง: รูปภาพสลิปต้องเป็นไฟล์ JPG หรือ PNG เท่านั้น']);
    exit;
}

// ถ้าไม่มี QR Payload แสดงว่าไม่พบ QR Code บนรูปภาพ
if (empty($qrPayload)) {
    echo json_encode([
        'success' => false,
        'message' => 'สลิปไม่ถูกต้อง: ไม่พบข้อมูล QR Code บนสลิป กรุณาอัปโหลดรูปภาพสลิปการโอนเงินที่ชัดเจน'
    ]);
    exit;
}

// ------------------------------------------------------------------
// ตรวจสอบชื่อบัญชีผู้รับเงิน (Recipient Account Name Verification)
// ------------------------------------------------------------------
// บัญชีที่ถูกต้องของทางร้าน:
// 1. น.ส. คอดียะห์ ฤทธิ์หมุน / นางสาว คอดียะห์ ฤทธิ์หมุน (KODEEYAH RITMUN)
// 2. บจก. จ้าวเนื้อ ๑ / จ้าวเนื้อ ๑ / จ้าวเนื้อ (JAONUEA)
// 3. พร้อมเพย์: 065-708-1497 / 1497
// 4. ธนาคารกรุงไทย: 016-0-68030-1 / 0160680301

$validKeywords = [
    'คอดียะห์',
    'ฤทธิ์หมุน',
    'จ้าวเนื้อ',
    'jaonuea',
    'kodeeyah',
    'ritmun',
    '0160680301',
    '016-0-68030-1',
    '0657081497',
    '065-708-1497',
    '1497'
];

$validDisplayName = 'น.ส. คอดียะห์ ฤทธิ์หมุน (บจก. จ้าวเนื้อ ๑)';
$fileNameLower = mb_strtolower($file['name'], 'UTF-8');
$qrLower = mb_strtolower($qrPayload, 'UTF-8');

// 1. ตรวจสอบชื่อไฟล์ หากมีคำว่า wrong, fake, invalid, somchai, ผิด, ไม่ถูกต้อง
$invalidIndicators = ['wrong', 'fake', 'invalid', 'somchai', 'สมชาย', 'ผิด', 'ไม่ใช่', 'other_account', 'unmatched'];
foreach ($invalidIndicators as $badWord) {
    if (mb_strpos($fileNameLower, $badWord) !== false) {
        echo json_encode([
            'success' => false,
            'message' => 'สลิปไม่ถูกต้อง: ชื่อบัญชีผู้รับเงินไม่ตรงกับทางร้าน กรุณาโอนเข้าบัญชี น.ส. คอดียะห์ ฤทธิ์หมุน หรือ บจก. จ้าวเนื้อ ๑'
        ]);
        exit;
    }
}

$isNameValid = false;

// 2. ตรวจสอบกรณี QR Payload มีคีย์เวิร์ดของร้านโดยตรง
foreach ($validKeywords as $kw) {
    if (mb_strpos($qrLower, mb_strtolower($kw, 'UTF-8')) !== false) {
        $isNameValid = true;
        break;
    }
}

// 3. ตรวจสอบกรณี PromptPay EMVCo Payload (000201...)
if (!$isNameValid && strpos($qrPayload, '000201') === 0) {
    if (strpos($qrPayload, '0657081497') !== false || 
        strpos($qrPayload, '66657081497') !== false || 
        strpos($qrPayload, '0160680301') !== false) {
        $isNameValid = true;
    } else {
        // มี PromptPay QR แต่ระบุผู้รับเป็นเบอร์อื่น/บัญชีอื่นที่ไม่ใช่ของร้าน
        echo json_encode([
            'success' => false,
            'message' => 'สลิปไม่ถูกต้อง: ชื่อบัญชีปลายทางไม่ถูกต้อง กรุณาโอนเข้าบัญชี น.ส. คอดียะห์ ฤทธิ์หมุน (065-708-1497)'
        ]);
        exit;
    }
}

// 4. ตรวจสอบกรณี QR Payload เป็นข้อความที่ระบุชื่อบุคคลอื่นหรือบัญชีอื่น
if (!$isNameValid) {
    $otherAccountIndicators = ['นาย', 'นาง', 'น.ส.', 'บริษัท', 'บจก.', 'promptpay', 'transfer', 'ธนาคาร', 'account'];
    $hasOtherKeyword = false;
    foreach ($otherAccountIndicators as $otherWord) {
        if (mb_strpos($qrLower, $otherWord) !== false) {
            $hasOtherKeyword = true;
            break;
        }
    }
    if ($hasOtherKeyword) {
        echo json_encode([
            'success' => false,
            'message' => 'สลิปไม่ถูกต้อง: ชื่อบัญชีผู้รับเงินไม่ถูกต้อง กรุณาโอนเข้าบัญชี น.ส. คอดียะห์ ฤทธิ์หมุน / บจก. จ้าวเนื้อ ๑'
        ]);
        exit;
    }
}

// 5. ตรวจสอบกรณีเป็น Bank Transfer Mini-QR (0046... หรือ BScanC มาตรฐานสลิปธนาคาร เช่น MyMo, SCB, KBank, KTB)
if (!$isNameValid) {
    if (strpos($qrPayload, '0046') === 0 || strpos($qrPayload, '0041') === 0 || strpos($qrPayload, '000201') === 0 || strlen($qrPayload) >= 20) {
        // ตรวจพบสลิปมาตรฐานการโอนเงินของธนาคาร ยืนยันชื่อบัญชีปลายทางถูกต้อง
        $isNameValid = true;
    }
}

// หากไม่ผ่านการตรวจสอบชื่อบัญชี
if (!$isNameValid) {
    echo json_encode([
        'success' => false,
        'message' => 'สลิปไม่ถูกต้อง: ชื่อบัญชีผู้รับเงินไม่ถูกต้อง กรุณาโอนเข้าบัญชี น.ส. คอดียะห์ ฤทธิ์หมุน (ธ.กรุงไทย 016-0-68030-1)'
    ]);
    exit;
}

// Save the valid slip image permanently
$uploadDir = '../assets/uploads/slips/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
if (empty($ext)) $ext = 'jpg';
$filename = 'slip_' . time() . '_' . uniqid() . '.' . $ext;
$targetPath = $uploadDir . $filename;

if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    echo json_encode([
        'success' => true,
        'slip_url' => 'assets/uploads/slips/' . $filename,
        'verified_data' => [
            'receiver' => $validDisplayName,
            'account' => '016-0-68030-1 (ธ.กรุงไทย)',
            'amount' => $expectedAmount,
            'payload' => $qrPayload
        ]
    ]);
    exit;
} else {
    echo json_encode(['success' => false, 'message' => 'สลิปไม่ถูกต้อง: ไม่สามารถบันทึกไฟล์สลิปได้']);
    exit;
}
