<?php
// setup_database.php - เครื่องมือติดตั้งฐานข้อมูลสำหรับระบบจ้าวเนื้อ ๑
header('Content-Type: text/html; charset=utf-8');

$host = 'localhost';
$user = 'root';
$pass = '';
$dbName = 'jaonuea';

$logs = [];
$success = false;
$error = null;

try {
    $logs[] = "กำลังเชื่อมต่อกับ MySQL Server ที่ <code>$host</code>...";
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $logs[] = "เชื่อมต่อ MySQL Server สำเร็จ!";

    $schemaFile = __DIR__ . '/database/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new Exception("ไม่พบไฟล์ schema.sql ที่ตำแหน่ง: " . $schemaFile);
    }

    $logs[] = "กำลังอ่านไฟล์ฐานข้อมูล <code>database/schema.sql</code>...";
    $sql = file_get_contents($schemaFile);

    // Clean comments and execute statements
    $cleanSql = preg_replace('/--.*$/m', '', $sql);
    $cleanSql = preg_replace('/\/\*.*?\*\//s', '', $cleanSql);
    $statements = array_filter(array_map('trim', explode(';', $cleanSql)));

    $logs[] = "กำลังประมวลผลคำสั่ง SQL ทั้งหมด " . count($statements) . " คำสั่ง...";
    $execCount = 0;
    foreach ($statements as $stmt) {
        if (!empty($stmt)) {
            $pdo->exec($stmt);
            $execCount++;
        }
    }
    $logs[] = "ประมวลผลคำสั่งสำเร็จทั้งหมด $execCount คำสั่ง!";

    // Select database and check summary
    $pdo->exec("USE `$dbName`;");
    $tablesStmt = $pdo->query("SHOW TABLES");
    $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $cattleCount = $pdo->query("SELECT COUNT(*) FROM cattles")->fetchColumn();
    $productCount = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();

    $logs[] = "สร้างฐานข้อมูล <code>$dbName</code> และตารางเรียบร้อยแล้ว (" . implode(', ', $tables) . ")";
    $success = true;

} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ติดตั้งฐานข้อมูล - จ้าวเนื้อ ๑</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Kanit', sans-serif; background-color: #f8f9fa; }
        .card-custom { border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.06); }
        .log-box { background: #1e1e1e; color: #d4d4d4; font-family: monospace; font-size: 0.9rem; padding: 15px; border-radius: 8px; max-height: 250px; overflow-y: auto; }
        .log-success { color: #4ade80; }
        .log-info { color: #60a5fa; }
    </style>
</head>
<body class="py-5">
    <div class="container" style="max-width: 760px;">
        <div class="text-center mb-4">
            <h2 class="fw-bold text-dark"><i class="fas fa-database text-primary me-2"></i> ระบบติดตั้งฐานข้อมูล จ้าวเนื้อ ๑</h2>
            <p class="text-muted">ระบบจัดการฟาร์มโคเนื้อและจำหน่ายเนื้อโคแปรรูป</p>
        </div>

        <div class="card card-custom p-4 bg-white mb-4">
            <?php if ($success): ?>
                <div class="alert alert-success d-flex align-items-center" role="alert">
                    <i class="fas fa-check-circle fa-2x me-3"></i>
                    <div>
                        <h5 class="alert-heading mb-1 fw-bold">สร้างฐานข้อมูล 'jaonuea' สำเร็จเรียบร้อยแล้ว!</h5>
                        <div>ระบบได้ทำการสร้างตารางทั้งหมด และเพิ่มข้อมูลเริ่มต้นสำหรับการทดสอบเรียบร้อยแล้ว</div>
                    </div>
                </div>

                <div class="row g-3 my-3 text-center">
                    <div class="col-md-4">
                        <div class="border rounded p-3 bg-light">
                            <div class="text-muted small">ตารางทั้งหมด</div>
                            <div class="h3 fw-bold text-primary mb-0"><?php echo count($tables); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 bg-light">
                            <div class="text-muted small">สินค้าในร้าน</div>
                            <div class="h3 fw-bold text-success mb-0"><?php echo $productCount; ?> รายการ</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 bg-light">
                            <div class="text-muted small">วัวในฟาร์ม</div>
                            <div class="h3 fw-bold text-warning mb-0"><?php echo $cattleCount; ?> ตัว</div>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <h6 class="fw-bold text-secondary mb-2"><i class="fas fa-users me-1"></i> บัญชีผู้ใช้เริ่มต้นสำหรับทดสอบ (รหัสผ่านคือ: <code>password</code>):</h6>
                    <ul class="list-group list-group-flush small border rounded">
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><strong>Admin:</strong> <code>admin</code> (อีเมล: admin@example.com)</span>
                            <span class="badge bg-danger">ผู้ดูแลระบบ</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><strong>ลูกค้ารายย่อย:</strong> <code>retail_user</code> (อีเมล: retail@example.com)</span>
                            <span class="badge bg-primary">ลูกค้าปลีก</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><strong>ลูกค้าราคาส่ง:</strong> <code>wholesale_user</code> (อีเมล: wholesale@example.com)</span>
                            <span class="badge bg-success">ลูกค้าส่ง</span>
                        </li>
                    </ul>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-md-center mt-4">
                    <a href="checkout.php" class="btn btn-danger px-4 py-2"><i class="fas fa-map-marker-alt me-2"></i> หน้าสั่งซื้อ (checkout.php)</a>
                    <a href="shop.php" class="btn btn-primary px-4 py-2"><i class="fas fa-shopping-cart me-2"></i> ไปที่หน้าร้านค้า (shop.php)</a>
                    <a href="index.php" class="btn btn-outline-secondary px-4 py-2"><i class="fas fa-home me-2"></i> ไปที่หน้าแรก (index.php)</a>
                </div>

            <?php else: ?>
                <div class="alert alert-danger d-flex align-items-center" role="alert">
                    <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                    <div>
                        <h5 class="alert-heading mb-1 fw-bold">เกิดข้อผิดพลาดในการติดตั้ง</h5>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    </div>
                </div>
                <div class="text-center mt-3">
                    <a href="setup_database.php" class="btn btn-warning"><i class="fas fa-redo me-2"></i> ลองใหม่อีกครั้ง</a>
                </div>
            <?php endif; ?>

            <div class="mt-4">
                <label class="form-label text-muted small fw-bold">บันทึกการทำงาน (Log):</label>
                <div class="log-box">
                    <?php foreach ($logs as $log): ?>
                        <div>&gt; <?php echo $log; ?></div>
                    <?php endforeach; ?>
                    <?php if ($error): ?>
                        <div class="text-danger">&gt; ERROR: <?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
