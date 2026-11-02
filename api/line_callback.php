<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/line_api_helper.php';

// --- CONFIGURATION ---
// นำ Channel ID และ Channel Secret จาก LINE Developers Console มาใส่ที่นี่
$line_channel_id = '2011186653';
$line_channel_secret = 'a041510e70eaab430698858d9a022c4e';
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script_path = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/jaonuea/api/line_callback.php');
$redirect_uri = "{$protocol}://{$host}{$script_path}";
// ---------------------

if (isset($_GET['error'])) {
    die("LINE Login Error: " . htmlspecialchars($_GET['error_description']));
}

if (!isset($_GET['code'])) {
    die("No authorization code provided.");
}

$code = $_GET['code'];

// 1. นำ Authorization Code ไปแลกเป็น Access Token และ ID Token
$token_url = 'https://api.line.me/oauth2/v2.1/token';
$post_data = http_build_query([
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => $redirect_uri,
    'client_id' => $line_channel_id,
    'client_secret' => $line_channel_secret
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $token_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded'
]);
$response = curl_exec($ch);
curl_close($ch);

$token_data = json_decode($response, true);

if (!isset($token_data['id_token'])) {
    die("Failed to retrieve ID token from LINE.");
}

$id_token = $token_data['id_token'];

// 2. นำ ID Token ไป Verify เพื่อดึงข้อมูลผู้ใช้ (Email, Name, Provider ID)
$verify_url = 'https://api.line.me/oauth2/v2.1/verify';
$verify_post_data = http_build_query([
    'id_token' => $id_token,
    'client_id' => $line_channel_id
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $verify_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $verify_post_data);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$verify_response = curl_exec($ch);
curl_close($ch);

$user_profile = json_decode($verify_response, true);

if (isset($user_profile['error'])) {
    die("Failed to verify ID token: " . $user_profile['error_description']);
}

// ข้อมูลที่ได้จาก LINE
$provider = 'line';
$provider_id = $user_profile['sub'];
$name = isset($user_profile['name']) ? $user_profile['name'] : 'LINE User';
$email = !empty($user_profile['email']) ? $user_profile['email'] : ('line_' . $provider_id . '@line.local');
$picture = isset($user_profile['picture']) ? $user_profile['picture'] : '';

// 3. จัดการข้อมูลในฐานข้อมูล (ระบบล็อกอิน/สมัครสมาชิก/รีเซ็ตรหัสผ่านผ่าน LINE)
$database = new Database();
$db = $database->getConnection();
try { $db->exec("ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) NULL AFTER shipping_address"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN reset_token VARCHAR(128) NULL AFTER cancel_penalty"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN reset_token_code VARCHAR(20) NULL AFTER reset_token"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN reset_token_expires_at DATETIME NULL AFTER reset_token_code"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN custom_name_set TINYINT(1) DEFAULT 0 AFTER last_name"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN line_name VARCHAR(150) NULL AFTER provider_id"); } catch (Exception $e) {}

$raw_state = $_GET['state'] ?? 'login123';
$is_register = (strpos($raw_state, 'register') === 0);
$is_forgot = (strpos($raw_state, 'forgot') === 0);
$custom_redirect = '';
if (strpos($raw_state, '__') !== false) {
    $parts = explode('__', $raw_state, 2);
    $custom_redirect = $parts[1] ?? '';
} elseif (!in_array($raw_state, ['login123', 'register123', 'forgot123'])) {
    $custom_redirect = $raw_state;
}

try {
    // ค้นหาว่ามีอีเมลหรือบัญชี LINE นี้ในระบบแล้วหรือไม่
    $query = "SELECT id, username, first_name, last_name, email, role, auth_provider, provider_id, line_name, custom_name_set FROM users WHERE email = :email OR (auth_provider = 'line' AND provider_id = :provider_id AND provider_id != '') LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':email' => $email,
        ':provider_id' => $provider_id
    ]);

    // กรณีกดขอรีเซ็ตรหัสผ่านผ่าน LINE (LINE Routing)
    if ($is_forgot) {
        if ($stmt->rowCount() === 0) {
            header("Location: ../login.html?auth_error=not_found&mode=register&email=" . urlencode($email));
            exit();
        }
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // บันทึกค่า LINE User ID (UID) ลง provider_id เสมอเพื่อใช้ส่งข้อความผ่าน LINE Messaging API
        $reset_token = bin2hex(random_bytes(32));
        $token_code = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        $upd_stmt = $db->prepare("
            UPDATE users 
            SET provider_id = :provider_id,
                reset_token = :token,
                reset_token_code = :code,
                reset_token_expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
            WHERE id = :id
        ");
        $upd_stmt->execute([
            ':provider_id' => $provider_id,
            ':token' => $reset_token,
            ':code' => $token_code,
            ':id' => $user['id']
        ]);

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
        $reset_link = "{$protocol}://{$host}{$basePath}/login.html?reset_token=" . urlencode($reset_token);

        $customer_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        if ($customer_name === '') {
            $customer_name = !empty($name) ? $name : ($user['username'] ?? 'ลูกค้า');
        }

        sendLineResetPasswordNotification($provider_id, $customer_name, $reset_link, $token_code);

        header("Location: ../login.html?forgot_sent=line&reset_email=" . urlencode($user['email']));
        exit();
    }

    if ($stmt->rowCount() > 0) {
        // กรณีมีบัญชีในระบบอยู่แล้ว แต่ลูกค้ากดจากหน้า "สมัครสมาชิก" (สมัครซ้ำด้วยบัญชีเดิม)
        if ($is_register) {
            $isLineLocal = (strpos($email, '@line.local') !== false);
            $emailParam = $isLineLocal ? '' : urlencode($email);
            $redirUrl = "../login.html?auth_error=account_exists&provider=line&mode=register";
            if (!empty($emailParam)) {
                $redirUrl .= "&email=" . $emailParam;
            }
            header("Location: $redirUrl");
            exit();
        }

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // หากเป็นบัญชีที่สมัครผ่าน LINE อยู่แล้ว ให้อัปเดต provider_id (LINE UID) และ line_name ให้เป็นปัจจุบันเสมอ
        if (($user['auth_provider'] ?? '') === 'line') {
            $customSet = (int)($user['custom_name_set'] ?? 0);
            
            // ตรวจสอบว่าชื่อใน first_name เคยโดนชื่อผู้รับจากที่อยู่จัดส่งทับหรือไม่
            $isTainted = false;
            if (!empty($user['first_name'])) {
                try {
                    $chkAddr = $db->prepare("SELECT COUNT(*) FROM user_addresses WHERE user_id = ? AND recipient_name = ?");
                    $chkAddr->execute([$user['id'], $user['first_name']]);
                    if ($chkAddr->fetchColumn() > 0) {
                        $isTainted = true;
                    }
                } catch (Exception $e) {}
            }

            // หากผู้ใช้ไม่ได้แก้ไขชื่อเอง หรือชื่อเคยโดนที่อยู่จัดส่งทับ ให้คืนค่าชื่อโปรไฟล์เป็นชื่อบัญชี LINE เสมอ
            if ($customSet === 0 || $isTainted || empty($user['first_name'])) {
                $update_query = "UPDATE users SET provider_id = :provider_id, line_name = :line_name, first_name = :first_name, last_name = '', custom_name_set = 0 WHERE id = :id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->execute([
                    ':provider_id' => $provider_id,
                    ':line_name' => $name,
                    ':first_name' => $name,
                    ':id' => $user['id']
                ]);
            } else {
                $update_query = "UPDATE users SET provider_id = :provider_id, line_name = :line_name WHERE id = :id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->execute([
                    ':provider_id' => $provider_id,
                    ':line_name' => $name,
                    ':id' => $user['id']
                ]);
            }
        }
    } else {
        // กรณียังไม่เคยมีบัญชีในระบบ แต่ลูกค้ากดจากหน้า "เข้าสู่ระบบ" (ยังไม่เคยสมัครสมาชิก)
        if (!$is_register) {
            $isLineLocal = (strpos($email, '@line.local') !== false);
            $emailParam = $isLineLocal ? '' : urlencode($email);
            $redirUrl = "../login.html?auth_error=not_found&provider=line&mode=register";
            if (!empty($emailParam)) {
                $redirUrl .= "&email=" . $emailParam;
            }
            header("Location: $redirUrl");
            exit();
        }

        // สร้างผู้ใช้ใหม่เมื่อกดจากหน้า "สมัครสมาชิก" เท่านั้น
        $username = 'line_' . substr(md5($provider_id), 0, 8);
        
        $insert_query = "INSERT INTO users (username, email, first_name, profile_image, auth_provider, provider_id, line_name, custom_name_set, role) VALUES (:username, :email, :first_name, :profile_image, :provider, :provider_id, :line_name, 0, 'retail')";
        $insert_stmt = $db->prepare($insert_query);
        $insert_stmt->bindParam(':username', $username);
        $insert_stmt->bindParam(':email', $email);
        $insert_stmt->bindParam(':first_name', $name);
        $insert_stmt->bindParam(':profile_image', $picture);
        $insert_stmt->bindParam(':provider', $provider);
        $insert_stmt->bindParam(':provider_id', $provider_id);
        $insert_stmt->bindParam(':line_name', $name);
        $insert_stmt->execute();
        
        $user_id = $db->lastInsertId();
        $user = [
            'id' => $user_id,
            'username' => $username,
            'role' => 'retail'
        ];
        $is_new_registration = true;
    }

    // 4. สร้าง Session และ ล็อกอิน
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['profile_image'] = $picture ?? '';
    $_SESSION['line_name'] = $name;
    $_SESSION['logged_in'] = true;

    // หากเพิ่งสมัครสมาชิกใหม่เสร็จ ให้เด้งไปหน้ากรอกข้อมูลโปรไฟล์ (รูป, ชื่อ, เบอร์โทร และกดข้ามได้)
    if (!empty($is_new_registration)) {
        $setupUrl = '../login.html?setup_profile=1';
        if (!empty($custom_redirect)) {
            $setupUrl .= '&redirect=' . urlencode($custom_redirect);
        }
        header("Location: $setupUrl");
        exit();
    }

    // เปลี่ยนหน้าไปตาม Role หรือค่า state (redirect)
    $redirect = '../index.html';
    
    // Default role-based redirect
    if ($user['role'] === 'admin') {
        $redirect = '../admin-dashboard.html';
    } else {
        if (!empty($custom_redirect)) {
            $redirect_path = filter_var($custom_redirect, FILTER_SANITIZE_URL);
            if (strpos($redirect_path, 'http') === false) {
                $redirect = '../' . ltrim($redirect_path, '/');
            } else {
                $redirect = $redirect_path;
            }
        } else {
            $redirect = '../shop.php';
        }
    }
    
    header("Location: $redirect");
    exit();

} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
