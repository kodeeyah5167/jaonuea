<?php
// config/database.php

class Database {
    private $host = "localhost";
    private $db_name = "jaonuea";
    private $username = "root";
    private $password = "";
    public $conn;

    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch(PDOException $exception) {
            $msg = $exception->getMessage();
            // Automatically initialize the database if it doesn't exist
            if ($exception->getCode() == 1049 || strpos($msg, '1049') !== false || stripos($msg, 'Unknown database') !== false) {
                try {
                    $initConn = new PDO(
                        "mysql:host=" . $this->host . ";charset=utf8mb4",
                        $this->username,
                        $this->password,
                        [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                        ]
                    );

                    $schemaFile = __DIR__ . '/../database/schema.sql';
                    if (file_exists($schemaFile)) {
                        $sql = file_get_contents($schemaFile);
                        $cleanSql = preg_replace('/--.*$/m', '', $sql);
                        $cleanSql = preg_replace('/\/\*.*?\*\//s', '', $cleanSql);
                        $statements = array_filter(array_map('trim', explode(';', $cleanSql)));
                        foreach ($statements as $stmt) {
                            if (!empty($stmt)) {
                                $initConn->exec($stmt);
                            }
                        }
                    } else {
                        $initConn->exec("CREATE DATABASE IF NOT EXISTS `" . $this->db_name . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
                    }

                    $this->conn = new PDO(
                        "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                        $this->username,
                        $this->password,
                        [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES => false
                        ]
                    );
                    return $this->conn;
                } catch (PDOException $initEx) {
                    die(json_encode([
                        'status' => 'error',
                        'message' => 'Database auto-initialization error: ' . $initEx->getMessage()
                    ]));
                }
            }

            die(json_encode([
                'status' => 'error',
                'message' => 'Connection error: ' . $exception->getMessage()
            ]));
        }

        // ตรวจสอบและเพิ่มคอลัมน์ที่จำเป็นในตาราง cattles อัตโนมัติ
        if ($this->conn) {
            try { $this->conn->exec("ALTER TABLE cattles ADD COLUMN seller_name VARCHAR(150) NULL AFTER tag_number"); } catch (Exception $e) {}
            try { $this->conn->exec("ALTER TABLE cattles ADD COLUMN seller_phone VARCHAR(30) NULL AFTER seller_name"); } catch (Exception $e) {}
            try { $this->conn->exec("ALTER TABLE cattles ADD COLUMN gender VARCHAR(20) DEFAULT 'ผู้' AFTER breed"); } catch (Exception $e) {}
            try { $db_col = $this->conn->exec("ALTER TABLE cattles ADD COLUMN initial_weight_kg DECIMAL(8,2) NULL AFTER weight_kg"); } catch (Exception $e) {}
            try { $this->conn->exec("ALTER TABLE cattles ADD COLUMN image_url VARCHAR(255) NULL AFTER purchased_at"); } catch (Exception $e) {}
            try { $this->conn->exec("ALTER TABLE cattles ADD COLUMN notes TEXT NULL AFTER image_url"); } catch (Exception $e) {}

            // ลบข้อมูลที่อยู่จำลอง/ตัวอย่างที่เคยบันทึกไว้ในอดีตออกจากระบบทั้งหมดอย่างเด็ดขาด
            try {
                $this->conn->exec("
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
            } catch (Exception $e) {}
        }

        // เคลียร์ข้อมูลจำลอง/ข้อมูลทดสอบทั้งหมด 1 ครั้ง (ลูกค้า, คำสั่งซื้อ, การชำแหละเนื้อ, วัวที่รับซื้อ, สต๊อกเริ่มต้นเป็น 0) โดยคงไว้เฉพาะบัญชี Admin และรายการสินค้าหลัก
        $clearLockFile = __DIR__ . '/.cleared_all_mock_data_v2.lock';
        if ($this->conn && !file_exists($clearLockFile)) {
            try {
                $this->conn->exec("SET FOREIGN_KEY_CHECKS = 0");
                try { $this->conn->exec("TRUNCATE TABLE notifications"); } catch (Exception $e) {}
                try { $this->conn->exec("TRUNCATE TABLE payments"); } catch (Exception $e) {}
                try { $this->conn->exec("TRUNCATE TABLE order_items"); } catch (Exception $e) {}
                try { $this->conn->exec("TRUNCATE TABLE orders"); } catch (Exception $e) {}
                try { $this->conn->exec("TRUNCATE TABLE inventory_processing"); } catch (Exception $e) {}
                try { $this->conn->exec("TRUNCATE TABLE cattle_growth"); } catch (Exception $e) {}
                try { $this->conn->exec("TRUNCATE TABLE cattle_health"); } catch (Exception $e) {}
                try { $this->conn->exec("TRUNCATE TABLE cattles"); } catch (Exception $e) {}
                try { $this->conn->exec("TRUNCATE TABLE user_addresses"); } catch (Exception $e) {}
                try { $this->conn->exec("TRUNCATE TABLE login_attempts"); } catch (Exception $e) {}
                try { $this->conn->exec("DELETE FROM users WHERE role != 'admin'"); } catch (Exception $e) {}
                try { $this->conn->exec("UPDATE products SET stock_kg = 0.00"); } catch (Exception $e) {}
                $this->conn->exec("SET FOREIGN_KEY_CHECKS = 1");
                @file_put_contents($clearLockFile, date('Y-m-d H:i:s'));
            } catch (Exception $e) {
                try { $this->conn->exec("SET FOREIGN_KEY_CHECKS = 1"); } catch (Exception $ex) {}
            }
        }

        return $this->conn;
    }
}
?>