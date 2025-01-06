<?php
$jsonData = file_get_contents('php://input');
$filePath = __DIR__ . '/data.json';
file_put_contents($filePath, $jsonData);