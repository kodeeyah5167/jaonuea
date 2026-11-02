<?php
require_once 'c:/xampp/htdocs/project2/config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $db->beginTransaction();
    
    // Clear inventory processing first due to foreign key constraints
    $db->exec("DELETE FROM inventory_processing");
    echo "Cleared inventory_processing.\n";
    
    // Then clear cattles
    $db->exec("DELETE FROM cattles");
    echo "Cleared cattles.\n";
    
    $db->commit();
    echo "Successfully deleted all cattle records.\n";
} catch(Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
}
?>
