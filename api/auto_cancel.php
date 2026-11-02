<?php
require_once __DIR__ . '/notification_helper.php';

function auto_cancel_expired_orders($db) {
    // Disabled: Customers can pick up anytime because they have already paid.
    return;
}
?>
