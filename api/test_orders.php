<?php
require_once '../config/database.php';
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->query("SELECT id, status, shipping_address FROM orders ORDER BY id DESC LIMIT 5");
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
file_put_contents('test_output.txt', json_encode($res, JSON_PRETTY_PRINT));
echo json_encode($res);
?>
