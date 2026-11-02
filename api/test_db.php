<?php
require_once '../config/database.php';
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->query("SELECT id, shipping_address, status FROM orders ORDER BY id DESC LIMIT 5");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
