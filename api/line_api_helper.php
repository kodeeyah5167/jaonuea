<?php
/**
 * ฟังก์ชันสำหรับส่งข้อความแจ้งเตือนผ่าน LINE Messaging API (Push Message)
 * 
 * @param string $line_user_id ไอดีของผู้ใช้ LINE (LINE User ID ที่ได้จาก Webhook หรือ LINE Login)
 * @param string $message_text ข้อความที่ต้องการส่งไปแจ้งเตือน
 * @return bool|string คืนค่า true หากสำเร็จ หรือคืนค่า error message หากล้มเหลว
 */
function sendLineNotification($line_user_id, $message_text)
{
    // กำหนด Channel Access Token (Long-lived) ของ LINE Official Account
    $access_token = 'kgZ1EOTP0SHIPlDTmk0onMPwwHG0hGYELCCzSHCq8CNn1hAlaWfrP27j/G1hZwoyWxXBkvacg0u8btUktnnzmLZ7YuMLUVV0D4TCLVz4cURiOTuyGLu7qB7v33LBIp5qYvWWedn1SlS8vHot5nhzZAdB04t89/1O/w1cDnyilFU=';

    // Endpoint สำหรับส่ง Push Message
    $url = 'https://api.line.me/v2/bot/message/push';

    // โครงสร้างข้อมูล JSON ที่จะส่งไปยัง LINE API
    $data = [
        'to' => $line_user_id,
        'messages' => [
            [
                'type' => 'text',
                'text' => $message_text
            ]
        ]
    ];
    $post_data = json_encode($data);

    // กำหนด Headers
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $access_token
    ];

    // เริ่มต้นใช้งาน cURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);

    // สำคัญ: สำหรับ XAMPP/Localhost อาจพบปัญหา SSL Certificate
    // หากนำไปใช้งานจริงบน Server ที่มี SSL ที่ถูกต้องแล้ว สามารถตั้งเป็น true ได้
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    // ทำการส่ง HTTP POST และรับผลลัพธ์
    $result = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // ตรวจสอบผลลัพธ์
    if ($error) {
        return "cURL Error: " . $error;
    }

    if ($http_status == 200) {
        return true; // ส่งข้อความสำเร็จ
    } else {
        return "HTTP Error: " . $http_status . " Response: " . $result;
    }
}

/**
 * ตัวอย่างการนำไปใช้งานจริงร่วมกับฐานข้อมูล (Database Logic & Integration)
 * สามารถนำโค้ดส่วนนี้ไปแทรกประยุกต์ใช้ในไฟล์ที่จัดการคำสั่งซื้อ (เช่น api/checkout.php หรือหน้าจัดการสถานะออเดอร์ของแอดมิน)
 */
function notifyCustomerOrder($db_connection, $order_id)
{
    try {
        // 1. ดึงข้อมูล LINE User ID และชื่อของลูกค้า จากตาราง users โดยหาผ่านคำสั่งซื้อ (orders)
        // (หมายเหตุ: ในระบบจ้าวเนื้อตาราง users จะมีคอลัมน์ provider_id ที่เก็บข้อมูล LINE UID เอาไว้เมื่อล็อกอินผ่าน LINE)
        $query = "
            SELECT u.first_name, u.provider_id AS line_user_id 
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
            $customer_line_id = $customer['line_user_id'];
            $customer_name = $customer['first_name'];

            // 2. ตรวจสอบว่าลูกค้าได้ผูกบัญชี LINE ไว้หรือไม่
            if (!empty($customer_line_id)) {

                // 3. เตรียมข้อความแจ้งเตือน (Push Message)
                $order_ref = "ORD-" . str_pad($order_id, 5, '0', STR_PAD_LEFT);
                $message = "สวัสดีคุณ {$customer_name}\n\n";
                $message .= "🛒 คำสั่งซื้อ #{$order_ref} ของคุณได้รับการยืนยันแล้ว\n";
                $message .= "ทางร้านกำลังดำเนินการจัดเตรียมสินค้า\n\n";
                $message .= "ขอบคุณที่ใช้บริการ จ้าวเนื้อฟาร์ม ครับ 🥩";

                // 4. เรียกใช้ฟังก์ชันส่งข้อความ
                $send_result = sendLineNotification($customer_line_id, $message);

                if ($send_result === true) {
                    // ส่งข้อความสำเร็จ
                    return true;
                } else {
                    // ส่งข้อความไม่สำเร็จ (สามารถบันทึก error ลง log เพื่อใช้ในการตรวจสอบภายหลัง)
                    error_log("LINE Push Failed for Order {$order_id}: " . $send_result);
                    return false;
                }
            } else {
                // ลูกค้าไม่ได้ล็อกอินหรือผูกบัญชี LINE (สามารถทำระบบแจ้งเตือนผ่าน SMS/Email ต่อไปได้)
                return false;
            }
        }
    } catch (PDOException $e) {
        error_log("Database Error in notifyCustomerOrder: " . $e->getMessage());
        return false;
    }
}

/**
 * ฟังก์ชันสำหรับส่งข้อความพร้อมปุ่มลิงก์และรหัส Token รีเซ็ตรหัสผ่านผ่าน LINE Messaging API (Push Message)
 * ใช้ LINE User ID (UID) จากคอลัมน์ provider_id ของลูกค้าที่สมัครด้วย LINE
 *
 * @param string $line_user_id รหัส LINE User ID (UID) ของลูกค้า
 * @param string $customer_name ชื่อของลูกค้า
 * @param string $reset_link ลิงก์สำหรับรีเซ็ตรหัสผ่านที่มี Token แนบอยู่
 * @param string $token_code รหัส Token สำหรับกรอกรีเซ็ตรหัสผ่าน
 * @return bool|string คืนค่า true หากส่งสำเร็จ หรือข้อความ error หากล้มเหลว
 */
function sendLineResetPasswordNotification($line_user_id, $customer_name, $reset_link, $token_code)
{
    if (empty($line_user_id)) {
        return "Missing LINE User ID (UID)";
    }

    $access_token = 'kgZ1EOTP0SHIPlDTmk0onMPwwHG0hGYELCCzSHCq8CNn1hAlaWfrP27j/G1hZwoyWxXBkvacg0u8btUktnnzmLZ7YuMLUVV0D4TCLVz4cURiOTuyGLu7qB7v33LBIp5qYvWWedn1SlS8vHot5nhzZAdB04t89/1O/w1cDnyilFU=';
    $url = 'https://api.line.me/v2/bot/message/push';

    $display_name = !empty($customer_name) ? $customer_name : 'ลูกค้า';

    // ข้อความแจ้งรหัส Token และลิงก์รีเซ็ตรหัสผ่าน
    $text_msg = "🔐 คำขอรีเซ็ตรหัสผ่าน (จ้าวเนื้อ๑)\n"
              . "สวัสดีคุณ {$display_name}\n\n"
              . "รหัส Token สำหรับรีเซ็ตรหัสผ่านของคุณคือ:\n"
              . "👉 {$token_code}\n\n"
              . "หรือคลิกลิงก์ด้านล่างเพื่อตั้งรหัสผ่านใหม่:\n"
              . "{$reset_link}\n\n"
              . "(รหัส Token และลิงก์นี้มีอายุการใช้งาน 15 นาที หากคุณไม่ได้เป็นผู้ทำรายการ กรุณาเพิกเฉยต่อข้อความนี้)";

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $access_token
    ];

    // 1. พยายามส่งแบบ Flex Message (มีปุ่มกดลิงก์รีเซ็ตรหัสผ่าน + แสดงรหัส Token ชัดเจน)
    $flex_payload = [
        'to' => $line_user_id,
        'messages' => [
            [
                'type' => 'flex',
                'altText' => "รหัส Token รีเซ็ตรหัสผ่านของคุณคือ {$token_code} - จ้าวเนื้อ๑",
                'contents' => [
                    'type' => 'bubble',
                    'header' => [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'backgroundColor' => '#610C10',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => 'จ้าวเนื้อ๑ - รีเซ็ตรหัสผ่าน',
                                'color' => '#FFFFFF',
                                'weight' => 'bold',
                                'size' => 'md'
                            ]
                        ]
                    ],
                    'body' => [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'spacing' => 'md',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => "สวัสดีคุณ {$display_name}",
                                'weight' => 'bold',
                                'size' => 'sm',
                                'wrap' => true
                            ],
                            [
                                'type' => 'text',
                                'text' => 'รหัส Token สำหรับตั้งรหัสผ่านใหม่ของคุณคือ:',
                                'size' => 'xs',
                                'color' => '#666666',
                                'wrap' => true
                            ],
                            [
                                'type' => 'box',
                                'layout' => 'vertical',
                                'backgroundColor' => '#FDF6F6',
                                'cornerRadius' => 'md',
                                'paddingAll' => '12px',
                                'contents' => [
                                    [
                                        'type' => 'text',
                                        'text' => (string)$token_code,
                                        'size' => 'xl',
                                        'weight' => 'bold',
                                        'color' => '#610C10',
                                        'align' => 'center'
                                    ]
                                ]
                            ],
                            [
                                'type' => 'text',
                                'text' => 'คุณสามารถกรอกรหัส Token นี้บนหน้าเว็บไซต์ หรือกดปุ่มด้านล่างเพื่อตั้งรหัสผ่านใหม่ (หมดอายุใน 15 นาที)',
                                'size' => 'xxs',
                                'color' => '#888888',
                                'wrap' => true
                            ]
                        ]
                    ],
                    'footer' => [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'contents' => [
                            [
                                'type' => 'button',
                                'style' => 'primary',
                                'color' => '#610C10',
                                'action' => [
                                    'type' => 'uri',
                                    'label' => 'ตั้งรหัสผ่านใหม่',
                                    'uri' => $reset_link
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($flex_payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $result = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_status == 200) {
        return true;
    }

    // 2. Fallback: กรณีทดสอบบน localhost ที่ LINE Flex Button URI อาจไม่รองรับโดเมน localhost
    // ให้ส่งเป็นข้อความ Push Message ปกติพร้อมรหัส Token และลิงก์ทันที เพื่อให้ลูกค้าได้รับผ่านแชท LINE เสมอ
    return sendLineNotification($line_user_id, $text_msg);
}
?>