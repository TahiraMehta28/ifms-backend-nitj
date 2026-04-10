<?php
$_GET['stage'] = 'drc_office';
$_GET['type'] = 'pending';
$_GET['summary'] = '1';
$_GET['limit'] = '50';
function error_handler($errno, $errstr, $errfile, $errline) {
    if (error_reporting() === 0) return false;
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}
set_error_handler('error_handler');
try {
    require 'backend/api/get-requests-by-stage.php';
} catch (Throwable $e) {
    echo "ERROR ON LINE " . $e->getLine() . " IN " . $e->getFile() . ": " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
?>
