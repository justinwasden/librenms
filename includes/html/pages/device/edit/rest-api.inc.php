<?php

use App\Models\RestApiTemplate;

$templates = RestApiTemplate::all();
$device->load('restApiConnections.endpoints', 'restApiConnections.credential');

echo view('device.edit.rest-api', [
    'device' => $device,
    'templates' => $templates,
]);