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
?>