<?php
require_once __DIR__ . '/email_helper.php';
require_once __DIR__ . '/line_api_helper.php';

/**
 * ฟังก์ชันหลักสำหรับส่งการแจ้งเตือนไปยังลูกค้า ทั้งผ่านอีเมลและ LINE (ถ้าผูกบัญชีไว้)
 * รองรับสถานะคำสั่งซื้อทุกรูปแบบ ทั้งปลีกและส่ง
 * 
 * @param PDO $db_connection การเชื่อมต่อฐานข้อมูล
 * @param int $order_id รหัสคำสั่งซื้อ
 * @param string $status_code รหัสสถานะ (เช่น 'processing', 'preparing', 'awaiting_final_payment', 'shipped', 'completed', 'cancelled')
 * @param array $extra_data ข้อมูลเพิ่มเติม เช่น เลขพัสดุ (tracking_number) หรือ เหตุผลที่ยกเลิก (cancel_reason)
 */
function sendOrderStatusNotification($db_connection, $order_id, $status_code, $extra_data = [])
{
    try {
        // 1. ดึงข้อมูลออเดอร์และลูกค้าจากฐานข้อมูล (รวมถึงช่องทางที่ลูกค้าใช้สมัครสมาชิก auth_provider)
        $query = "
            SELECT o.total_amount, o.order_type, o.status, o.shipping_address, o.tracking_number, o.cancel_reason,
                   u.username, u.first_name, u.last_name, u.email, u.auth_provider, u.provider_id AS line_user_id, 
                   p.amount_paid, p.remaining_amount
            FROM orders o
            JOIN users u ON o.user_id = u.id
            LEFT JOIN payments p ON o.id = p.order_id
            WHERE o.id = :order_id
            LIMIT 1
        ";
        $stmt = $db_connection->prepare($query);
        $stmt->bindParam(':order_id', $order_id, PDO::PARAM_INT);
        $stmt->execute();
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order)
            return false;

        $customer_name = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));
        if ($customer_name === '') {
            $customer_name = !empty($order['username']) ? $order['username'] : 'ลูกค้า';
        }
        $customer_email = $order['email'] ?? '';
        $customer_line_id = $order['line_user_id'] ?? '';
        $auth_provider = strtolower(trim($order['auth_provider'] ?? 'local'));
        $order_ref = "ORD-" . str_pad($order_id, 5, '0', STR_PAD_LEFT);
        $is_wholesale = (isset($order['order_type']) && $order['order_type'] === 'wholesale');
        $is_pickup = (!empty($order['shipping_address']) && (mb_strpos($order['shipping_address'], 'รับที่หน้าร้าน') !== false || !empty($order['pickup_code'])));

        $total_amount = (float) ($order['total_amount'] ?? 0);
        $amount_paid = isset($order['amount_paid']) && (float) $order['amount_paid'] > 0
            ? (float) $order['amount_paid']
            : ($is_wholesale ? ($total_amount / 2) : $total_amount);
        $remain = isset($order['remaining_amount']) && (float) $order['remaining_amount'] > 0
            ? (float) $order['remaining_amount']
            : ($total_amount / 2);

        $formatted_total = number_format($total_amount, 2);
        $formatted_paid = number_format($total_amount, 2);
        $formatted_deposit = number_format($amount_paid, 2);
        $formatted_remain = number_format($remain, 2);

        $tracking_number = $order['tracking_number'] ?? '';
        if (isset($extra_data['tracking_number'])) {
            $tracking_number = $extra_data['tracking_number'];
        }
        $display_tracking = !empty(trim((string) $tracking_number)) ? trim((string) $tracking_number) : '-';
        $cancel_reason = $extra_data['cancel_reason'] ?? $order['cancel_reason'] ?? '';

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/jaonuea/api/index.php'))), '/');
        $payment_link = "{$protocol}://{$host}{$basePath}/profile.html";

        $title = "";
        $message = "";
        $html_message = "";

        // 2. กำหนดข้อความแจ้งเตือนตามแต่ละสถานะ (แยกตามคำสั่งซื้อปลีก / ขายส่ง / รับหน้าร้าน / จัดส่ง)
        switch ($status_code) {
            case 'pending_payment':
            case 'new_order':
            case 'processing':
                if ($is_wholesale) {
                    $title = "📦 ได้รับคำสั่งซื้อของคุณแล้ว! ({$order_ref})";
                    $message = "📦 ได้รับคำสั่งซื้อของคุณแล้ว!\n"
                        . "สวัสดีคุณ {$customer_name} ขอบคุณที่ไว้วางใจจ้าวเนื้อ๑ ออเดอร์หมายเลข: {$order_ref} ของคุณเข้าสู่ระบบแล้ว\n"
                        . "💰 ยอดรวมทั้งสิ้น: {$formatted_total} บาท";
                } else {
                    $title = "🛒 ได้รับคำสั่งซื้อของคุณแล้ว! ({$order_ref})";
                    $message = "🛒 ได้รับคำสั่งซื้อของคุณแล้ว!\n"
                        . "สวัสดีคุณ {$customer_name} จ้าวเนื้อ๑ได้รับคำสั่งซื้อหมายเลข: {$order_ref} เรียบร้อยแล้ว";
                }
                $html_message = "<div style='font-size:15px; line-height:1.7; color:#333;'>" . nl2br(htmlspecialchars($message)) . "</div>";
                break;

            case 'deposit_confirmed':
            case 'preparing':
                if ($is_wholesale) {
                    $title = "✅ ได้รับยอดมัดจำ 50% แล้ว ({$order_ref})";
                    if ($is_pickup) {
                        $message = "✅ ได้รับยอดมัดจำ 50% แล้ว\n"
                            . "ทางเราได้รับยอดชำระ {$formatted_deposit} บาท สำหรับออเดอร์ {$order_ref} เรียบร้อยแล้ว\n"
                            . "ขณะนี้ทางฟาร์มกำลังเร่งดำเนินการเตรียมสินค้าตามออเดอร์ของคุณ เมื่อสินค้าพร้อมรับที่หน้าร้าน เราจะแจ้งให้ทราบเพื่อชำระยอดที่เหลือค่ะ";
                    } else {
                        $message = "✅ ได้รับยอดมัดจำ 50% แล้ว\n"
                            . "ทางเราได้รับยอดชำระ {$formatted_deposit} บาท สำหรับออเดอร์ {$order_ref} เรียบร้อยแล้ว\n"
                            . "ขณะนี้ทางฟาร์มกำลังเร่งดำเนินการเตรียมสินค้าตามออเดอร์ของคุณ เมื่อสินค้าพร้อมจัดส่ง เราจะแจ้งให้ทราบเพื่อชำระยอดที่เหลือค่ะ";
                    }
                } else {
                    $title = "✅ ยืนยันการชำระเงินสำเร็จ ({$order_ref})";
                    if ($is_pickup) {
                        $message = "✅ ยืนยันการชำระเงินสำเร็จ\n"
                            . "ขอบคุณค่ะคุณ {$customer_name} ทางเราได้รับยอดชำระเงิน {$formatted_paid} บาท สำหรับออเดอร์ {$order_ref} เรียบร้อยแล้ว\n"
                            . "ขณะนี้ร้านกำลังจัดเตรียมสินค้าที่สดใหม่ให้คุณ จะแจ้งให้ทราบอีกครั้งเมื่อสินค้าพร้อมรับที่หน้าร้านค่ะ";
                    } else {
                        $message = "✅ ยืนยันการชำระเงินสำเร็จ\n"
                            . "ขอบคุณค่ะคุณ {$customer_name} ทางเราได้รับยอดชำระเงิน {$formatted_paid} บาท สำหรับออเดอร์ {$order_ref} เรียบร้อยแล้ว\n"
                            . "ขณะนี้ร้านกำลังจัดเตรียมสินค้าที่สดใหม่ให้คุณ จะแจ้งให้ทราบอีกครั้งเมื่อสินค้าเริ่มจัดส่งค่ะ";
                    }
                }
                $html_message = "<div style='font-size:15px; line-height:1.7; color:#333;'>" . nl2br(htmlspecialchars($message)) . "</div>";
                break;

            case 'awaiting_final_payment':
                if ($is_pickup) {
                    $title = "📢 สินค้าเตรียมเสร็จเรียบร้อย พร้อมรับที่หน้าร้าน! ({$order_ref})";
                    $message = "📢 สินค้าเตรียมเสร็จเรียบร้อย พร้อมรับที่หน้าร้าน!\n"
                        . "สวัสดีคุณ {$customer_name} ออเดอร์หมายเลข: {$order_ref} ของคุณเตรียมเสร็จเรียบร้อย พร้อมรับที่หน้าร้านแล้วค่ะ\n"
                        . "❗ เพื่อให้พร้อมรับสินค้าที่หน้าร้าน กรุณาชำระยอดค้างชำระอีก 50% ที่เหลือ เป็นจำนวนเงิน: {$formatted_remain} บาท\n"
                        . "💳 แจ้งชำระยอดคงเหลือได้ที่: {$payment_link}";
                    $html_message = "
                        <div style='font-size:15px; line-height:1.7; color:#333;'>
                            <strong>📢 สินค้าเตรียมเสร็จเรียบร้อย พร้อมรับที่หน้าร้าน!</strong><br>
                            สวัสดีคุณ " . htmlspecialchars($customer_name) . " ออเดอร์หมายเลข: <strong>{$order_ref}</strong> ของคุณเตรียมเสร็จเรียบร้อย พร้อมรับที่หน้าร้านแล้วค่ะ<br>
                            ❗ เพื่อให้พร้อมรับสินค้าที่หน้าร้าน กรุณาชำระยอดค้างชำระอีก 50% ที่เหลือ เป็นจำนวนเงิน: <strong>{$formatted_remain} บาท</strong><br>
                            💳 แจ้งชำระยอดคงเหลือได้ที่: <a href='" . htmlspecialchars($payment_link) . "' style='color:#610C10; font-weight:bold;'>" . htmlspecialchars($payment_link) . "</a>
                        </div>
                    ";
                } else {
                    $title = "📢 สินค้าเตรียมเสร็จเรียบร้อย พร้อมจัดส่ง! ({$order_ref})";
                    $message = "📢 สินค้าเตรียมเสร็จเรียบร้อย พร้อมจัดส่ง!\n"
                        . "สวัสดีคุณ {$customer_name} ออเดอร์หมายเลข: {$order_ref} ของคุณพร้อมจัดส่งแล้วค่ะ\n"
                        . "❗ เพื่อให้ทางเราทำการส่งสินค้า กรุณาชำระยอดค้างชำระอีก 50% ที่เหลือ เป็นจำนวนเงิน: {$formatted_remain} บาท\n"
                        . "💳 แจ้งชำระยอดคงเหลือได้ที่: {$payment_link}";
                    $html_message = "
                        <div style='font-size:15px; line-height:1.7; color:#333;'>
                            <strong>📢 สินค้าเตรียมเสร็จเรียบร้อย พร้อมจัดส่ง!</strong><br>
                            สวัสดีคุณ " . htmlspecialchars($customer_name) . " ออเดอร์หมายเลข: <strong>{$order_ref}</strong> ของคุณพร้อมจัดส่งแล้วค่ะ<br>
                            ❗ เพื่อให้ทางเราทำการส่งสินค้า กรุณาชำระยอดค้างชำระอีก 50% ที่เหลือ เป็นจำนวนเงิน: <strong>{$formatted_remain} บาท</strong><br>
                            💳 แจ้งชำระยอดคงเหลือได้ที่: <a href='" . htmlspecialchars($payment_link) . "' style='color:#610C10; font-weight:bold;'>" . htmlspecialchars($payment_link) . "</a>
                        </div>
                    ";
                }
                break;

            case 'ready_for_pickup':
            case 'shipped':
                if ($is_pickup) {
                    if ($is_wholesale) {
                        $title = "🚚 สินค้าของคุณพร้อมแล้ว! ({$order_ref})";
                        $message = "🚚 สินค้าของคุณพร้อมแล้ว!\n"
                            . "ได้รับยอดชำระครบถ้วน ออเดอร์ {$order_ref} ของคุณพร้อมรับที่หน้าร้านแล้วค่ะ\n"
                            . "กรุณามารับสินค้าในเวลา 08:00 น. - 16:30 น.\n"
                            . "ขอบคุณที่ใช้บริการจ้าวเนื้อ๑ค่ะ!";
                    } else {
                        $title = "🚚 สินค้าพร้อมรับแล้ว! ({$order_ref})";
                        $message = "🚚 สินค้าพร้อมรับแล้ว!\n"
                            . "ออเดอร์ {$order_ref} ของคุณพร้อมรับที่หน้าร้านแล้วค่ะ\n"
                            . "กรุณามารับสินค้าในเวลา 08:00 น. - 16:30 น.\n"
                            . "ขอบคุณที่ใช้บริการจ้าวเนื้อ๑ค่ะ!";
                    }
                } else {
                    if ($is_wholesale) {
                        $title = "🚚 สินค้าของคุณจัดส่งแล้ว! ({$order_ref})";
                        $message = "🚚 สินค้าของคุณจัดส่งแล้ว!\n"
                            . "ได้รับยอดชำระครบถ้วน ออเดอร์ {$order_ref} ถูกจัดส่งเรียบร้อยแล้ว\n"
                            . "📦 ขนส่งโดย: Inter Express Logistics\n"
                            . "📍 หมายเลขติดตาม: {$display_tracking}\n"
                            . "ขอบคุณที่ใช้บริการจ้าวเนื้อ๑ค่ะ!";
                    } else {
                        $title = "🚚 สินค้าจัดส่งแล้ว! ({$order_ref})";
                        $message = "🚚 สินค้าจัดส่งแล้ว!\n"
                            . "ออเดอร์ {$order_ref} ของคุณถูกจัดส่งเรียบร้อยแล้ว\n"
                            . "📦 ขนส่งโดย: Inter Express Logistics\n"
                            . "📍 หมายเลขติดตาม: {$display_tracking}\n"
                            . "ขอบคุณที่ใช้บริการจ้าวเนื้อ๑ค่ะ!";
                    }
                }
                $html_message = "<div style='font-size:15px; line-height:1.7; color:#333;'>" . nl2br(htmlspecialchars($message)) . "</div>";
                break;

            case 'completed':
                $title = "🎉 คำสั่งซื้อสำเร็จ! ({$order_ref})";
                if ($is_pickup) {
                    $message = "🎉 คำสั่งซื้อสำเร็จ!\n"
                        . "ระบบพบว่าออเดอร์ {$order_ref} ได้ถูกรับสินค้าที่หน้าร้านเรียบร้อยแล้ว\n"
                        . "หวังว่าคุณจะประทับใจในคุณภาพเนื้อจากจ้าวเนื้อ๑ หากมีข้อสงสัยหรือต้องการสั่งซื้อเพิ่มเติม ทักแชทหาเราได้ตลอดเลยนะคะ ขอบคุณค่ะ🥩✨";
                } else {
                    $message = "🎉 คำสั่งซื้อสำเร็จ!\n"
                        . "ระบบพบว่าออเดอร์ {$order_ref} ได้ถูกจัดส่งถึงมือคุณเรียบร้อยแล้ว\n"
                        . "หวังว่าคุณจะประทับใจในคุณภาพเนื้อจากจ้าวเนื้อ๑ หากมีข้อสงสัยหรือต้องการสั่งซื้อเพิ่มเติม ทักแชทหาเราได้ตลอดเลยนะคะ ขอบคุณค่ะ🥩✨";
                }
                $html_message = "<div style='font-size:15px; line-height:1.7; color:#333;'>" . nl2br(htmlspecialchars($message)) . "</div>";
                break;

            case 'cancelled':
                $title = "❌ คำสั่งซื้อถูกยกเลิก ({$order_ref})";
                $reason_text = !empty(trim((string)$cancel_reason)) ? "\nเหตุผลที่ยกเลิก: " . trim((string)$cancel_reason) : "";
                $message = "❌ คำสั่งซื้อถูกยกเลิก\n"
                    . "ออเดอร์หมายเลข: {$order_ref} ของคุณได้ถูกยกเลิกแล้ว{$reason_text}\n"
                    . "หากมีข้อสงสัยให้ติดต่อแอดมินได้เลยค่ะ\n"
                    . "📞 065-708-1497\n"
                    . "หากต้องการสั่งซื้อสินค้าใหม่ สามารถทำรายการผ่านหน้าเว็บไซต์ได้ตลอดเวลาค่ะ";
                $html_message = "<div style='font-size:15px; line-height:1.7; color:#333;'>" . nl2br(htmlspecialchars($message)) . "</div>";
                break;

            default:
                // สถานะอื่นๆ ไม่มีการแจ้งเตือน
                return false;
        }

        // 3. แยกช่องทางการแจ้งเตือนตามวิธีที่ลูกค้าสมัครสมาชิก:
        // - หากสมัครสมาชิกผ่าน LINE (auth_provider === 'line') -> แจ้งเตือนสถานะคำสั่งซื้อผ่าน LINE
        // - หากสมัครสมาชิกด้วยอีเมล (auth_provider !== 'line') -> แจ้งเตือนสถานะคำสั่งซื้อผ่านอีเมล
        if ($auth_provider === 'line') {
            if (!empty($customer_line_id)) {
                sendLineNotification($customer_line_id, $message);
            }
        } else {
            if (!empty($customer_email)) {
                sendEmailNotification($customer_email, $customer_name, $html_message, $title);
            }
        }

        return true;

    } catch (Exception $e) {
        error_log("Notification Error (sendOrderStatusNotification): " . $e->getMessage());
        return false;
    }
}
?>