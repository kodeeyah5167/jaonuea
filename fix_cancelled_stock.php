<?php
session_start();
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_order_id'])) {
    $order_id = $_POST['restore_order_id'];
    
    $db->beginTransaction();
    try {
        $stmtOrder = $db->prepare("SELECT * FROM orders WHERE id = ? AND status = 'cancelled'");
        $stmtOrder->execute([$order_id]);
        $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);
        
        if ($order) {
            $stmtItems = $db->prepare("SELECT product_id, quantity_kg, actual_quantity_kg FROM order_items WHERE order_id = ?");
            $stmtItems->execute([$order_id]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
            
            $stmtRestore = $db->prepare("UPDATE products SET stock_kg = stock_kg + ? WHERE id = ?");
            $restored_info = [];
            foreach ($items as $item) {
                // สำหรับออเดอร์นี้ คืนตามที่ผู้ใช้ต้องการ
                $qty_to_restore = $item['actual_quantity_kg'] ?? $item['quantity_kg'];
                if ($qty_to_restore > 0) {
                    $stmtRestore->execute([$qty_to_restore, $item['product_id']]);
                    $restored_info[] = "คืนสินค้า ID {$item['product_id']} จำนวน {$qty_to_restore} กก.";
                }
            }
            
            $_SESSION['msg'] = "คืนสต๊อกสำหรับออเดอร์ #{$order_id} สำเร็จ! (" . implode(", ", $restored_info) . ")";
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error'] = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
    header("Location: fix_cancelled_stock.php");
    exit;
}

// Fetch all cancelled orders
$stmt = $db->query("
    SELECT o.id, o.order_type, o.status, o.total_amount, o.cancel_reason, o.created_at, u.username
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.id
    WHERE o.status = 'cancelled'
    ORDER BY o.id DESC
");
$cancelled_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>เครื่องมือคืนสต๊อก (ออเดอร์ที่ยกเลิก)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light p-5">
    <div class="container bg-white p-4 rounded shadow">
        <h2 class="mb-4 text-danger">เครื่องมือคืนสต๊อกย้อนหลัง (สำหรับออเดอร์ที่ถูกยกเลิกไปแล้ว)</h2>
        
        <?php if(isset($_SESSION['msg'])): ?>
            <div class="alert alert-success"><?php echo $_SESSION['msg']; unset($_SESSION['msg']); ?></div>
        <?php endif; ?>
        <?php if(isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <div class="alert alert-warning">
            <strong>คำเตือน:</strong> การกดคืนสต๊อกในหน้านี้ จะเป็นการนำจำนวนสินค้าของออเดอร์นั้นๆ บวกกลับเข้าไปในคลังสินค้าทันที<br>
            - <strong>สำหรับออเดอร์ขายส่ง:</strong> หากเป็นออเดอร์ที่ถูกยกเลิก "ก่อน" มีการชั่งน้ำหนักจริง คุณ <u>ไม่จำเป็นต้องกดคืนสต๊อก</u> เพราะระบบยังไม่ได้หักสต๊อกออกตั้งแต่แรกครับ (ถ้ากดคืนจะเป็นการเพิ่มสต๊อกผีเข้าไป)<br>
            - กรุณากดคืนสต๊อกเฉพาะออเดอร์ที่คุณตรวจสอบแล้วว่าสต๊อกยังไม่ได้ถูกคืนกลับเข้าระบบเท่านั้น!
        </div>

        <table class="table table-bordered table-striped">
            <thead class="table-dark">
                <tr>
                    <th>รหัสออเดอร์</th>
                    <th>ประเภท</th>
                    <th>วันที่สั่งซื้อ</th>
                    <th>ลูกค้า</th>
                    <th>ยอดรวม</th>
                    <th>เหตุผลที่ยกเลิก</th>
                    <th>การจัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($cancelled_orders as $o): ?>
                <tr>
                    <td>#ORD-<?php echo str_pad($o['id'], 5, '0', STR_PAD_LEFT); ?></td>
                    <td><?php echo $o['order_type'] === 'wholesale' ? '<span class="badge bg-warning text-dark">ขายส่ง</span>' : '<span class="badge bg-info">ขายปลีก</span>'; ?></td>
                    <td><?php echo $o['created_at']; ?></td>
                    <td><?php echo htmlspecialchars($o['username']); ?></td>
                    <td>฿<?php echo number_format($o['total_amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($o['cancel_reason']); ?></td>
                    <td>
                        <form method="POST" onsubmit="return confirm('ยืนยันที่จะบวกสต๊อกของออเดอร์นี้กลับเข้าระบบใช่หรือไม่? (กรุณาแน่ใจว่าสต๊อกยังไม่ได้ถูกคืน)');">
                            <input type="hidden" name="restore_order_id" value="<?php echo $o['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-success">กดเพื่อคืนสต๊อก</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($cancelled_orders)): ?>
                    <tr><td colspan="7" class="text-center">ไม่มีออเดอร์ที่ถูกยกเลิก</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
