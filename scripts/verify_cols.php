<?php
$conn = mysqli_connect('localhost', 'root', '', 'ifms_db');
if (!$conn) die("Connection failed: " . mysqli_connect_error());

$res = mysqli_query($conn, "SHOW COLUMNS FROM budget_requests");
echo "Column Analysis for budget_requests:\n";

$found = [];
while ($row = mysqli_fetch_assoc($res)) {
    $name = $row['Field'];
    $hex = bin2hex($name);
    echo "- '$name' (Hex: $hex)\n";
    
    if (strtolower($name) === 'actualexpenditure') {
        $found[] = $name;
    }
}

if (count($found) > 1) {
    echo "\n⚠️ WARNING: Duplicate 'actual_exp' columns found with different casing or hidden chars!\n";
}

mysqli_close($conn);
