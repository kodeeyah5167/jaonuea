<?php
// นำเข้า namespaces ของ PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// เรียกใช้ไฟล์คลาสของ PHPMailer (แบบไม่ใช้ Composer / เรียกผ่านโฟลเดอร์โดยตรง)
require_once __DIR__ . '/../PHPMailer/Exception.php';
require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/SMTP.php';

/**
 * ฟังก์ชันสำหรับส่งอีเมลแจ้งเตือน (Email Notification) ด้วย PHPMailer
 * 
 * @param string $recipient_email อีเมลของผู้รับ (ลูกค้า)
 * @param string $customer_name ชื่อของลูกค้า
 * @param string $message_content เนื้อหาหรือรายละเอียดที่ต้องการแจ้งเตือน (สามารถเป็น HTML หรือ Text)
 * @param string $subject หัวข้ออีเมล (ถ้าไม่ระบุ จะใช้ค่าเริ่มต้น)
 * @return bool คืนค่า true หากส่งสำเร็จ หรือ false หากส่งไม่สำเร็จ
 */
function sendEmailNotification($recipient_email, $customer_name, $message_content, $subject = 'อัปเดตคำสั่งซื้อจากร้าน จ้าวเนื้อ๑')
{
    // สร้างออบเจ็กต์ PHPMailer ใหม่ และตั้งค่าให้โยน Exception หากเกิดข้อผิดพลาด
    $mail = new PHPMailer(true);

    try {
        // --- 1. การตั้งค่าเซิร์ฟเวอร์ SMTP ของ Gmail ---

        // $mail->SMTPDebug = SMTP::DEBUG_SERVER; // เปิดบรรทัดนี้ถ้าต้องการดู Log การส่งอีเมลเพื่อแก้ปัญหา
        $mail->isSMTP();                                            // กำหนดให้ใช้โพรโทคอล SMTP
        $mail->Host = 'smtp.gmail.com';                       // เซิร์ฟเวอร์ SMTP ของ Gmail
        $mail->SMTPAuth = true;                                   // เปิดใช้งานการยืนยันตัวตน SMTP

        // TODO: ใส่อีเมลร้านค้าและ App Password 16 หลัก ที่ได้จากการตั้งค่าบัญชี Google
        $mail->Username = 'kodeeyah5167@gmail.com';               // อีเมลของร้านค้า
        $mail->Password = 'mwlhjzpbiopnoxwr';                       // App Password 16 หลัก (ไม่ใช่รหัสผ่านอีเมลปกติ)

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;         // เปิดใช้งานการเข้ารหัสแบบ STARTTLS
        $mail->Port = 587;                                    // พอร์ตสำหรับ STARTTLS ของ Gmail (587)
        $mail->CharSet = 'UTF-8';                                // กำหนดให้รองรับภาษาไทยอย่างสมบูรณ์
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];

        // --- 2. การตั้งค่าผู้ส่งและผู้รับ ---
        $mail->setFrom($mail->Username, 'จ้าวเนื้อ๑'); // อีเมลและชื่อผู้ส่ง
        $mail->addAddress($recipient_email, $customer_name);        // อีเมลและชื่อผู้รับ

        // --- 3. การตั้งค่าเนื้อหาอีเมล (HTML Email Template) ---
        $mail->isHTML(true);                                        // กำหนดรูปแบบอีเมลเป็น HTML
        $mail->Subject = $subject;

        // ออกแบบหน้าตาอีเมลให้สวยงาม สะอาดตา เหมาะกับร้านค้าออนไลน์ E-commerce
        $html_template = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #f9f9f9; padding: 20px; border-radius: 8px; border: 1px solid #ddd;'>
            
            <!-- ส่วนหัวอีเมล (Header) -->
            <div style='text-align: center; padding-bottom: 20px; border-bottom: 2px solid #610C10;'>
                <h2 style='color: #610C10; margin: 0;'>จ้าวเนื้อ๑ (Jaew Nuer)</h2>
                <p style='color: #555; font-size: 14px; margin-top: 5px;'>จำหน่ายเนื้อวัวคุณภาพ ทั้งปลีกและส่ง</p>
            </div>
            
            <!-- ส่วนเนื้อหาหลัก (Body) -->
            <div style='padding: 20px 0; color: #333; line-height: 1.6; font-size: 15px;'>
                <p>เรียนคุณ <strong>{$customer_name}</strong>,</p>
                
                <!-- กล่องข้อความแจ้งเตือน -->
                <div style='background-color: #ffffff; padding: 15px; border-radius: 5px; border-left: 4px solid #610C10; margin: 20px 0; box-shadow: 0 1px 3px rgba(0,0,0,0.1);'>
                    {$message_content}
                </div>
                
                <p>หากมีข้อสงสัยหรือต้องการสอบถามข้อมูลเพิ่มเติม สามารถติดต่อเราได้ทันทีครับ</p>
            </div>
            
            <!-- ส่วนท้ายอีเมล (Footer) -->
            <div style='text-align: center; padding-top: 20px; border-top: 1px solid #ddd; color: #888; font-size: 12px;'>
                <p>ขอขอบคุณที่ไว้วางใจใช้บริการ <strong>จ้าวเนื้อ๑</strong></p>
                <p style='color: #aaa;'>อีเมลฉบับนี้เป็นการส่งอัตโนมัติจากระบบ กรุณาอย่าตอบกลับ</p>
                <p>1 หมู่ 6 ต.โพรงจระเข้ อ.ย่านตาขาว จ.ตรัง 92140 | โทร. 065-708-1497</p>
            </div>
            
        </div>
        ";

        $mail->Body = $html_template;

        // กำหนด Plain Text สำรอง กรณีที่แอปพลิเคชันอ่านอีเมลของผู้รับไม่รองรับ HTML
        $plain_text = "เรียนคุณ {$customer_name},\n\n";
        $plain_text .= strip_tags(str_replace(['<br>', '</div>', '</p>'], "\n", $message_content)) . "\n\n";
        $plain_text .= "ขอขอบคุณที่ใช้บริการ จ้าวเนื้อ๑\n(กรุณาอย่าตอบกลับอีเมลฉบับนี้อัตโนมัติ)";
        $mail->AltBody = $plain_text;

        // --- 4. ส่งอีเมล ---
        $mail->send();
        return true; // ส่งสำเร็จ คืนค่าเป็น true

    } catch (Exception $e) {
        // ดักจับ Error และบันทึกลง Log
        error_log("อีเมลส่งไม่สำเร็จ ข้อผิดพลาดจาก PHPMailer: {$mail->ErrorInfo}");
        return false; // ส่งไม่สำเร็จ คืนค่าเป็น false
    }
}

/**
 * ตัวอย่างการนำไปใช้งานจริงร่วมกับฐานข้อมูล (Database Logic & Integration)
 * นำฟังก์ชันนี้ไปเรียกใช้ในไฟล์ทำรายการ เช่น api/checkout.php หรือหน้าอัปเดตสถานะออเดอร์ของแอดมิน
 */
function notifyCustomerViaEmail($db_connection, $order_id)
{
    try {
        // 1. ดึงข้อมูลอีเมลและชื่อของลูกค้า จากตาราง users โดยเชื่อมผ่านคำสั่งซื้อ (orders)
        $query = "
            SELECT u.first_name, u.email 
            FROM orders o
            JOIN users u ON o.user_id = u.id
            WHERE o.id = :order_id
            LIMIT 1
        ";

        $stmt = $db_connection->prepare($query);
        $stmt->bindParam(':order_id', $order_id, PDO::PARAM_INT);
        $stmt->execute();

        $customer = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($customer) {
            $customer_email = $customer['email'];
            $customer_name = $customer['first_name'];

            // 2. ตรวจสอบว่าลูกค้ามีอีเมลในระบบหรือไม่
            if (!empty($customer_email)) {

                // 3. เตรียมเนื้อหาอีเมล (สามารถใส่ HTML Tag พื้นฐานได้)
                $order_ref = "ORD-" . str_pad($order_id, 5, '0', STR_PAD_LEFT);
                $message_content = "
                    <h3 style='margin-top:0; color:#333;'><i class='fas fa-check-circle'></i> ยืนยันคำสั่งซื้อ #{$order_ref}</h3>
                    <p>คำสั่งซื้อของคุณได้รับการเข้าสู่ระบบเรียบร้อยแล้ว ขณะนี้ทางร้านกำลังดำเนินการตรวจสอบและจัดเตรียมสินค้า</p>
                    <p style='margin-bottom:0;'>คุณสามารถตรวจสอบสถานะคำสั่งซื้อหรือดูรายละเอียดเพิ่มเติมได้ที่หน้าเว็บไซต์ในเมนู <strong>'โปรไฟล์ส่วนตัว'</strong> ครับ</p>
                ";

                // 4. เรียกใช้งานฟังก์ชันส่งอีเมล
                $send_result = sendEmailNotification($customer_email, $customer_name, $message_content);

                if ($send_result) {
                    return true; // ส่งสำเร็จ
                } else {
                    return false; // ส่งไม่สำเร็จ (ข้อผิดพลาดจะถูกบันทึกใน PHP error_log)
                }
            }
        }
        return false; // ไม่มีข้อมูลลูกค้าหรือไม่มีอีเมล
    } catch (PDOException $e) {
        error_log("Database Error in notifyCustomerViaEmail: " . $e->getMessage());
        return false;
    }
}

/**
 * ฟังก์ชันสำหรับส่งอีเมลรีเซ็ตรหัสผ่าน (Email Routing) พร้อมแนบลิงก์ Token และรหัส Token
 *
 * @param string $recipient_email อีเมลของลูกค้า
 * @param string $customer_name ชื่อของลูกค้า
 * @param string $reset_link ลิงก์สำหรับกดรีเซ็ตรหัสผ่านที่มีค่า Token แนบอยู่
 * @param string $token_code รหัส Token สำหรับกรอกยืนยันบนหน้าเว็บ
 * @return bool
 */
function sendPasswordResetEmail($recipient_email, $customer_name, $reset_link, $token_code)
{
    $safe_name = htmlspecialchars($customer_name ?: 'ลูกค้า');
    $safe_link = htmlspecialchars($reset_link);
    $safe_token = htmlspecialchars($token_code);

    $message_content = "
        <h3 style='margin-top:0; color:#610C10;'>คำขอรีเซ็ตรหัสผ่าน (Reset Password)</h3>
        <p>เราได้รับคำขอรีเซ็ตรหัสผ่านสำหรับบัญชีของคุณในระบบ <strong>จ้าวเนื้อ๑</strong></p>
        <p>คุณสามารถกดปุ่มด้านล่างเพื่อตั้งรหัสผ่านใหม่ หรือคัดลอก <strong>รหัส Token</strong> ไปกรอกในหน้าตั้งรหัสผ่านใหม่ได้ทันที:</p>
        
        <div style='background-color:#fdf6f6; border:2px dashed #610C10; border-radius:8px; padding:16px; text-align:center; margin:20px 0;'>
            <div style='font-size:13px; color:#666; margin-bottom:6px;'>รหัส Token สำหรับรีเซ็ตรหัสผ่านของคุณ</div>
            <div style='font-size:26px; font-weight:bold; letter-spacing:4px; color:#610C10; font-family:monospace;'>{$safe_token}</div>
            <div style='font-size:12px; color:#888; margin-top:6px;'>(รหัส Token และลิงก์นี้จะหมดอายุภายใน 15 นาที)</div>
        </div>

        <div style='text-align:center; margin:25px 0;'>
            <a href='{$safe_link}' style='background-color:#610C10; color:#ffffff; text-decoration:none; padding:12px 28px; border-radius:6px; font-weight:bold; font-size:15px; display:inline-block;'>
                คลิกที่นี่เพื่อตั้งรหัสผ่านใหม่
            </a>
        </div>

        <p style='font-size:13px; color:#666; word-break:break-all;'>
            หากปุ่มด้านบนไม่ทำงาน สามารถคัดลอกลิงก์นี้ไปวางในเบราว์เซอร์ของคุณ:<br>
            <a href='{$safe_link}' style='color:#610C10;'>{$safe_link}</a>
        </p>
        <p style='font-size:12px; color:#999; margin-bottom:0;'>หากคุณไม่ได้เป็นผู้ส่งคำขอนี้ กรุณาเพิกเฉยต่ออีเมลฉบับนี้ รหัสผ่านของคุณจะยังคงปลอดภัยเหมือนเดิม</p>
    ";

    return sendEmailNotification($recipient_email, $safe_name, $message_content, 'รหัส Token และลิงก์สำหรับรีเซ็ตรหัสผ่าน - จ้าวเนื้อ๑');
}
?>