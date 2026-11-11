<?php
// api/auth.php

session_start();
require_once '../config/database.php';
require_once __DIR__ . '/email_helper.php';
require_once __DIR__ . '/line_api_helper.php';

header("Content-Type: application/json; charset=UTF-8");

// ฟังก์ชันป้องกัน XSS สแกนข้อความ
function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// รับข้อมูล JSON หรือ POST payload
$raw_data = file_get_contents("php://input");
$data = json_decode($raw_data, true);

if (!$data && isset($_GET['action'])) {
    $data = $_GET;
}

if (!isset($data['action'])) {
    echo json_encode(['status' => 'error', 'message' => 'No action specified']);
    exit();
}

$action = $data['action'];

$database = new Database();
$db = $database->getConnection();
try { $db->exec("ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NULL AFTER email"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN last_name VARCHAR(100) NULL AFTER first_name"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20) NULL AFTER last_name"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN shipping_address TEXT NULL AFTER phone"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN auth_provider VARCHAR(50) DEFAULT 'local'"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN provider_id VARCHAR(255) NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN cancel_penalty INT DEFAULT 0"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN reset_token VARCHAR(128) NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN reset_token_code VARCHAR(20) NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN reset_token_expires_at DATETIME NULL"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN custom_name_set TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN line_name VARCHAR(150) NULL"); } catch (Exception $e) {}

if ($action === 'login') {
    $email_or_username = sanitizeInput($data['email'] ?? '');
    $password = trim($data['password'] ?? '');

    if (empty($email_or_username) || empty($password)) {
        echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
        exit();
    }

    try {
        // ใช้ Prepared Statement เพื่อป้องกัน SQL Injection
        $query = "SELECT id, username, email, password_hash, role FROM users WHERE email = :email OR username = :username LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':email', $email_or_username);
        $stmt->bindParam(':username', $email_or_username);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // ตรวจสอบรหัสผ่าน (รองรับทั้งแบบ hash และ plain text สำหรับทดสอบ)
            $is_valid_pwd = password_verify($password, $user['password_hash']) || $password === $user['password_hash'];

            // อัปเดตรหัสผ่านและรับรอง admin1497 สำหรับบัญชี admin@example.com
            if (!$is_valid_pwd && ($user['email'] === 'admin@example.com' || $user['username'] === 'admin') && $password === 'admin1497') {
                $is_valid_pwd = true;
                try {
                    $new_hash = password_hash('admin1497', PASSWORD_DEFAULT);
                    $up_pwd = $db->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
                    $up_pwd->execute([':hash' => $new_hash, ':id' => $user['id']]);
                } catch (Exception $e) {}
            }

            if ($is_valid_pwd) {
                // ป้องกัน Session Fixation
                session_regenerate_id(true);
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['logged_in'] = true;

                // ตรวจสอบ Role เพื่อกำหนดหน้าแรกที่ควรส่งไป
                $redirect = 'index.html'; // default
                if ($user['role'] === 'admin') {
                    $redirect = 'admin-dashboard.html';
                } else {
                    $redirect = 'shop.php';
                }

                echo json_encode([
                    'status' => 'success',
                    'message' => 'เข้าสู่ระบบสำเร็จ',
                    'role' => $user['role'],
                    'redirect' => $redirect
                ]);
            } else {
                echo json_encode([
                    'status' => 'error',
                    'code' => 'WRONG_PASSWORD',
                    'message' => 'รหัสผ่านไม่ถูกต้อง'
                ]);
            }
        } else {
            // ถ้าระบบเช็คว่าอีเมลนี้ยังไม่มีในฐานข้อมูล
            echo json_encode([
                'status' => 'error',
                'code' => 'USER_NOT_FOUND',
                'message' => 'ไม่พบบัญชีนี้ กรุณาสมัครสมาชิก'
            ]);
        }
    } catch(PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
} elseif ($action === 'register') {
    // การสมัครสมาชิก
    $email = sanitizeInput($data['email'] ?? '');
    $password = trim($data['password'] ?? '');
    $username = 'user_' . substr(md5(uniqid(rand(), true)), 0, 8);
    
    if (empty($email) || empty($password)) {
        echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
        exit();
    }

    try {
        // เช็คว่ามีผู้ใช้นี้อยู่แล้วหรือไม่
        $check_query = "SELECT id FROM users WHERE email = :email LIMIT 1";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':email', $email);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() > 0) {
            echo json_encode([
                'status' => 'error',
                'code' => 'ACCOUNT_EXISTS',
                'message' => 'มีบัญชีนี้ในระบบแล้ว'
            ]);
            exit();
        }

        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        $query = "INSERT INTO users (username, email, password_hash, first_name, auth_provider, custom_name_set, role) VALUES (:username, :email, :password_hash, :first_name, 'local', 0, 'retail')";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':password_hash', $password_hash);
        $stmt->bindParam(':first_name', $email);
        
        if ($stmt->execute()) {
            $new_user_id = $db->lastInsertId();
            session_regenerate_id(true);
            $_SESSION['user_id'] = $new_user_id;
            $_SESSION['username'] = $username;
            $_SESSION['role'] = 'retail';
            $_SESSION['logged_in'] = true;

            echo json_encode([
                'status' => 'success',
                'is_new_user' => true,
                'user_id' => $new_user_id,
                'message' => 'สมัครสมาชิกสำเร็จ'
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถสมัครสมาชิกได้']);
        }
    } catch(PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error']);
    }
} elseif ($action === 'request_password_reset') {
    $identifier = sanitizeInput($data['email'] ?? $data['identifier'] ?? '');

    if (empty($identifier)) {
        echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกอีเมลของคุณ']);
        exit();
    }

    try {
        // 1. ค้นหาบัญชีผู้ใช้โดยแยกชื่อ Parameter ไม่ให้ซ้ำกัน (ป้องกัน SQLSTATE[HY093]: Invalid parameter number เมื่อปิด Emulate Prepares)
        $check_query = "
            SELECT id, username, email, first_name, last_name, auth_provider, provider_id 
            FROM users 
            WHERE email = :ident_email 
               OR username = :ident_username 
               OR phone = :ident_phone 
            LIMIT 1
        ";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute([
            ':ident_email'    => $identifier,
            ':ident_username' => $identifier,
            ':ident_phone'    => $identifier
        ]);

        if ($check_stmt->rowCount() === 0) {
            echo json_encode([
                'status'  => 'error',
                'code'    => 'USER_NOT_FOUND',
                'message' => 'ไม่พบบัญชีนี้ในระบบ กรุณาตรวจสอบอีเมลหรือสมัครสมาชิก'
            ]);
            exit();
        }

        $user_row = $check_stmt->fetch(PDO::FETCH_ASSOC);
        $auth_provider = strtolower(trim($user_row['auth_provider'] ?? 'local'));

        // 2. ป้องกันบัญชีโซเชียล Google / LINE จากการขอรีเซ็ตรหัสผ่าน (เนื่องจากไม่ได้ใช้รหัสผ่านในการเข้าสู่ระบบ)
        if ($auth_provider === 'google') {
            echo json_encode([
                'status'  => 'error',
                'message' => 'บัญชีนี้สมัครสมาชิกผ่าน Google กรุณาเข้าสู่ระบบด้วยปุ่ม Google โดยไม่ต้องใช้รหัสผ่าน'
            ]);
            exit();
        }
        if ($auth_provider === 'line') {
            echo json_encode([
                'status'  => 'error',
                'message' => 'บัญชีนี้สมัครสมาชิกผ่าน LINE กรุณาเข้าสู่ระบบด้วยปุ่ม LINE โดยไม่ต้องใช้รหัสผ่าน'
            ]);
            exit();
        }

        $customer_name = trim(($user_row['first_name'] ?? '') . ' ' . ($user_row['last_name'] ?? ''));
        if ($customer_name === '') {
            $customer_name = !empty($user_row['username']) ? $user_row['username'] : 'ลูกค้า';
        }

        // 3. สร้างรหัส Token แบบปลอดภัย (Cryptographically Secure) และรหัสยืนยัน 6 หลัก กำหนดอายุ 15 นาที
        $reset_token = bin2hex(random_bytes(32));
        $token_code  = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        $save_stmt = $db->prepare("
            UPDATE users 
            SET reset_token = :token, 
                reset_token_code = :code, 
                reset_token_expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE) 
            WHERE id = :id
        ");
        $save_stmt->execute([
            ':token' => $reset_token,
            ':code'  => $token_code,
            ':id'    => $user_row['id']
        ]);

        // สร้างลิงก์รีเซ็ตรหัสผ่านแบบไดนามิกตามโดเมนและโฟลเดอร์โปรเจกต์
        $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host       = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath   = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
        $reset_link = "{$protocol}://{$host}{$basePath}/login.html?reset_token=" . urlencode($reset_token);

        // 4. ส่งรหัส Token และลิงก์รีเซ็ตรหัสผ่านทางอีเมลด้วย PHPMailer
        $mail_res = sendPasswordResetEmail($user_row['email'], $customer_name, $reset_link, $token_code);
        if ($mail_res) {
            echo json_encode([
                'status'  => 'success',
                'channel' => 'email',
                'email'   => $user_row['email'],
                'message' => 'ระบบได้ส่งรหัส Token และลิงก์สำหรับรีเซ็ตรหัสผ่านไปยังอีเมล ' . $user_row['email'] . ' เรียบร้อยแล้ว'
            ]);
        } else {
            echo json_encode([
                'status'  => 'error',
                'message' => 'ไม่สามารถส่งอีเมลรีเซ็ตรหัสผ่านได้ในขณะนี้ กรุณาตรวจสอบการตั้งค่าอีเมลหรือลองใหม่อีกครั้ง'
            ]);
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
    }
} elseif ($action === 'verify_reset_token') {
    $token = trim($data['token'] ?? '');
    if (empty($token)) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบรหัส Token']);
        exit();
    }

    try {
        $stmt = $db->prepare("
            SELECT id, email, username, auth_provider 
            FROM users 
            WHERE (reset_token = :token OR reset_token_code = :code) 
              AND reset_token IS NOT NULL 
              AND reset_token_expires_at > NOW() 
            LIMIT 1
        ");
        $stmt->execute([
            ':token' => $token,
            ':code'  => $token
        ]);

        if ($stmt->rowCount() === 0) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'รหัส Token ไม่ถูกต้องหรือหมดอายุแล้ว กรุณาขอรีเซ็ตรหัสผ่านใหม่อีกครั้ง'
            ]);
            exit();
        }

        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'status'        => 'success',
            'email'         => $u['email'],
            'auth_provider' => $u['auth_provider']
        ]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการตรวจสอบรหัส Token']);
    }
} elseif ($action === 'reset_password') {
    $token        = trim($data['token'] ?? '');
    $email        = sanitizeInput($data['email'] ?? '');
    $new_password = trim($data['new_password'] ?? '');

    if (empty($token) || empty($new_password)) {
        echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกรหัส Token และรหัสผ่านใหม่ให้ครบถ้วน']);
        exit();
    }

    if (mb_strlen($new_password) < 8) {
        echo json_encode(['status' => 'error', 'message' => 'รหัสผ่านใหม่ต้องมีความยาวอย่างน้อย 8 ตัวอักษร']);
        exit();
    }

    try {
        // ตรวจสอบความถูกต้องของ Token และอีเมล โดยแยกชื่อ Parameter ไม่ให้ซ้ำกัน (ป้องกัน SQLSTATE[HY093])
        if (!empty($email)) {
            $check_query = "
                SELECT id, email, username 
                FROM users 
                WHERE (email = :ident_email OR username = :ident_username OR phone = :ident_phone)
                  AND (reset_token = :token OR reset_token_code = :code) 
                  AND reset_token IS NOT NULL 
                  AND reset_token_expires_at > NOW() 
                LIMIT 1
            ";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([
                ':ident_email'    => $email,
                ':ident_username' => $email,
                ':ident_phone'    => $email,
                ':token'          => $token,
                ':code'           => $token
            ]);
        } else {
            $check_query = "
                SELECT id, email, username 
                FROM users 
                WHERE (reset_token = :token OR reset_token_code = :code) 
                  AND reset_token IS NOT NULL 
                  AND reset_token_expires_at > NOW() 
                LIMIT 1
            ";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([
                ':token' => $token,
                ':code'  => $token
            ]);
        }

        if ($check_stmt->rowCount() === 0) {
            echo json_encode([
                'status'  => 'error',
                'code'    => 'INVALID_TOKEN',
                'message' => 'รหัส Token ไม่ถูกต้องหรือหมดอายุแล้ว กรุณาขอรหัส Token ใหม่อีกครั้ง'
            ]);
            exit();
        }

        $user_row = $check_stmt->fetch(PDO::FETCH_ASSOC);
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);

        // อัปเดตรหัสผ่านใหม่ และล้างค่า Token ทิ้งทันทีเพื่อป้องกันการนำกลับมาใช้ซ้ำ (One-Time Use)
        $update_stmt = $db->prepare("
            UPDATE users 
            SET password_hash = :password_hash, 
                reset_token = NULL, 
                reset_token_code = NULL, 
                reset_token_expires_at = NULL 
            WHERE id = :id
        ");
        $update_stmt->execute([
            ':password_hash' => $password_hash,
            ':id'            => $user_row['id']
        ]);

        echo json_encode([
            'status'  => 'success',
            'email'   => $user_row['email'],
            'message' => 'ตั้งรหัสผ่านใหม่สำเร็จ กรุณาเข้าสู่ระบบด้วยรหัสผ่านใหม่'
        ]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
    }
} elseif ($action === 'social_login') {
    $provider = sanitizeInput($data['provider'] ?? '');
    $provider_id = sanitizeInput($data['provider_id'] ?? '');
    $email = sanitizeInput($data['email'] ?? '');
    $mode = sanitizeInput($data['mode'] ?? 'login'); // 'login' หรือ 'register'
    
    if (empty($provider) || empty($email)) {
        echo json_encode(['status' => 'error', 'message' => 'ข้อมูลไม่ครบถ้วน']);
        exit();
    }

    try {
        // เช็คว่ามีผู้ใช้นี้อยู่แล้วหรือไม่โดยอิงจากอีเมล หรือ provider_id
        $query = "SELECT id, username, email, role, auth_provider, provider_id FROM users WHERE email = :email OR (auth_provider = :provider AND provider_id = :provider_id AND provider_id != '') LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':email' => $email,
            ':provider' => $provider,
            ':provider_id' => $provider_id
        ]);

        if ($stmt->rowCount() > 0) {
            // พบบัญชีในระบบแล้ว
            if ($mode === 'register') {
                echo json_encode([
                    'status' => 'error',
                    'code' => 'ACCOUNT_EXISTS',
                    'email' => $email,
                    'provider' => $provider,
                    'message' => 'มีบัญชีนี้ในระบบแล้ว'
                ]);
                exit();
            }

            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // อัปเดต provider_id เฉพาะกรณีที่ไม่ได้สมัครผ่าน LINE (เพื่อรักษาช่องทางการแจ้งเตือนเดิมของลูกค้า)
            if (($user['auth_provider'] ?? '') !== 'line' && empty($user['provider_id'])) {
                $update_query = "UPDATE users SET provider_id = :provider_id WHERE id = :id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':provider_id', $provider_id);
                $update_stmt->bindParam(':id', $user['id']);
                $update_stmt->execute();
            }

        } else {
            // ยังไม่พบบัญชีในระบบ
            if ($mode !== 'register') {
                // หากกดจากหน้าเข้าสู่ระบบ ไม่อนุญาตให้เข้าสู่ระบบอัตโนมัติ ต้องสมัครสมาชิกก่อน
                echo json_encode([
                    'status' => 'error',
                    'code' => 'USER_NOT_FOUND',
                    'email' => $email,
                    'message' => 'ไม่พบบัญชีนี้ กรุณาสมัครสมาชิก'
                ]);
                exit();
            }

            // สร้างผู้ใช้ใหม่ (เฉพาะเมื่อกดจากหน้าสมัครสมาชิก mode === 'register')
            $username = 'user_' . substr(md5(uniqid(rand(), true)), 0, 8);
            $social_name = sanitizeInput($data['name'] ?? '');
            $social_pic = sanitizeInput($data['picture'] ?? '');
            
            $insert_query = "INSERT INTO users (username, email, first_name, profile_image, auth_provider, provider_id, line_name, custom_name_set, role) VALUES (:username, :email, :first_name, :profile_image, :provider, :provider_id, :line_name, 0, 'retail')";
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->bindParam(':username', $username);
            $insert_stmt->bindParam(':email', $email);
            $insert_stmt->bindParam(':first_name', $social_name);
            $insert_stmt->bindParam(':profile_image', $social_pic);
            $insert_stmt->bindParam(':provider', $provider);
            $insert_stmt->bindParam(':provider_id', $provider_id);
            $insert_stmt->bindParam(':line_name', $social_name);
            $insert_stmt->execute();
            
            $user_id = $db->lastInsertId();
            $user = [
                'id' => $user_id,
                'username' => $username,
                'role' => 'retail'
            ];
            $is_new_user = true;
        }

        // ล็อกอินและสร้าง Session
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['logged_in'] = true;

        $redirect = 'index.html';
        if ($user['role'] === 'admin') {
            $redirect = 'admin-dashboard.html';
        } else {
            $redirect = 'shop.php';
        }

        echo json_encode([
            'status' => 'success',
            'is_new_user' => !empty($is_new_user),
            'message' => ($mode === 'register' ? 'สมัครสมาชิกด้วย ' : 'เข้าสู่ระบบด้วย ') . ucfirst($provider) . ' สำเร็จ',
            'role' => $user['role'],
            'redirect' => $redirect
        ]);
        
    } catch(PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
} elseif ($action === 'check_session') {
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        try {
            $query = "SELECT first_name, last_name, phone, shipping_address, profile_image, email, username, auth_provider, provider_id, line_name, custom_name_set FROM users WHERE id = :id LIMIT 1";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $_SESSION['user_id']);
            $stmt->execute();
            $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

            $customSet = (int)($user_data['custom_name_set'] ?? 0);
            $authProvider = strtolower(trim($user_data['auth_provider'] ?? 'local'));
            $email = trim($user_data['email'] ?? '');
            $isLineLocal = (strpos($email, '@line.local') !== false);

            $regName = '';
            if ($authProvider === 'line') {
                $regName = !empty($user_data['line_name']) ? trim($user_data['line_name']) : (!empty($_SESSION['line_name']) ? trim($_SESSION['line_name']) : '');
            } else {
                $regName = (!$isLineLocal && !empty($email)) ? $email : trim($user_data['username'] ?? '');
            }

            $currentFullName = trim(($user_data['first_name'] ?? '') . ' ' . ($user_data['last_name'] ?? ''));

            // ตรวจสอบว่าชื่อใน first_name โดนชื่อผู้รับจากที่อยู่จัดส่งทับหรือไม่
            $isTainted = false;
            if (!empty($user_data['first_name'])) {
                try {
                    $chkAddr = $db->prepare("SELECT COUNT(*) FROM user_addresses WHERE user_id = ? AND recipient_name = ?");
                    $chkAddr->execute([$_SESSION['user_id'], $user_data['first_name']]);
                    if ($chkAddr->fetchColumn() > 0) {
                        $isTainted = true;
                    }
                } catch (Exception $e) {}
            }

            // หากผู้ใช้ไม่ได้แก้ไขชื่อเอง หรือชื่อเคยโดนที่อยู่จัดส่งทับ ให้คืนค่าชื่อโปรไฟล์เป็นชื่อสมัครสมาชิก (LINE หรือ Email)
            if ($customSet === 0 || $isTainted || empty($currentFullName)) {
                if (!empty($regName)) {
                    $currentFullName = $regName;
                }
            }

            echo json_encode([
                'status' => 'success', 
                'logged_in' => true, 
                'user_id' => $_SESSION['user_id'],
                'first_name' => $currentFullName,
                'last_name' => ($customSet === 1 && !$isTainted) ? ($user_data['last_name'] ?? '') : '',
                'fullname' => $currentFullName,
                'email' => $email,
                'username' => $user_data['username'] ?? '',
                'auth_provider' => $authProvider,
                'phone' => $user_data['phone'] ?? '',
                'shipping_address' => $user_data['shipping_address'] ?? '',
                'profile_image' => $user_data['profile_image'] ?? ''
            ]);
        } catch(PDOException $e) {
            echo json_encode(['status' => 'success', 'logged_in' => true, 'user_id' => $_SESSION['user_id']]);
        }
    } else {
        echo json_encode(['status' => 'success', 'logged_in' => false]);
    }
} elseif ($action === 'logout') {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_unset();
    session_destroy();
    header("Location: ../index.html");
    exit();
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
}
?>
