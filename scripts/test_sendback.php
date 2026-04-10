<?php
/**
 * Test script for sendback-request.php
 */

$url = 'http://localhost:8000/api/sendback-request.php';
$data = [
    'requestId'  => '625bb7a3fcb89c5db7907f54', // REQ-20260409-0002 (AR Stage)
    'stage'      => 'ar',
    'remarks'    => 'Test sendback from AR to DA',
    'sentBackBy' => 'Accounts Representative'
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
curl_close($ch);

echo "Response from sendback-request.php:\n";
echo $response . "\n";
