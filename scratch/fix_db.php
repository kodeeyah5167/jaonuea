<?php
require_once 'c:/xampp/htdocs/project2/config/database.php';

$database = new Database();
$conn = $database->getConnection();

try {
    $stmt = $conn->query("SHOW COLUMNS FROM orders");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($columns);
    
    // Add missing columns if any
    $hasOrderType = false;
    $hasDeliveryDate = false;
    foreach($columns as $c) {
        if ($c['Field'] == 'order_type') $hasOrderType = true;
        if ($c['Field'] == 'delivery_date') $hasDeliveryDate = true;
    }
    
    if (!$hasOrderType) {
        $conn->exec("ALTER TABLE orders ADD COLUMN order_type VARCHAR(20) DEFAULT 'retail' AFTER total_amount");
        echo "Added order_type column\n";
    }
    if (!$hasDeliveryDate) {
        $conn->exec("ALTER TABLE orders ADD COLUMN delivery_date VARCHAR(50) NULL AFTER order_type");
        echo "Added delivery_date column\n";
    }
    
    // Also check payments table for payment_type, total_required, amount_paid if missing
    $stmt = $conn->query("SHOW COLUMNS FROM payments");
    $pColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasPaymentType = false;
    $hasTotalReq = false;
    $hasAmtPaid = false;
    foreach($pColumns as $c) {
        if ($c['Field'] == 'payment_type') $hasPaymentType = true;
        if ($c['Field'] == 'total_required') $hasTotalReq = true;
        if ($c['Field'] == 'amount_paid') $hasAmtPaid = true;
    }
    
    if (!$hasPaymentType) {
        $conn->exec("ALTER TABLE payments ADD COLUMN payment_type VARCHAR(20) DEFAULT 'full' AFTER order_id");
        echo "Added payment_type column to payments\n";
    }
    if (!$hasTotalReq) {
        $conn->exec("ALTER TABLE payments ADD COLUMN total_required DECIMAL(10,2) DEFAULT 0 AFTER payment_type");
        echo "Added total_required column to payments\n";
    }
    if (!$hasAmtPaid) {
        $conn->exec("ALTER TABLE payments ADD COLUMN amount_paid DECIMAL(10,2) DEFAULT 0 AFTER total_required");
        echo "Added amount_paid column to payments\n";
    }
    
} catch(Exception $e) {
    echo $e->getMessage();
}
