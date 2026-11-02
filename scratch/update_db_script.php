<?php
require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$sql = file_get_contents('../database/update_products.sql');
try {
    $db->exec($sql);
    echo "Database updated successfully.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
