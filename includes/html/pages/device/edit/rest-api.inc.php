<?php

use App\Models\RestApiTemplate;
use Illuminate\Support\Facades\Gate;

// $device is passed as an array in legacy system
$device_model = \App\Models\Device::find($device['device_id']);

if (!$device_model) {
    echo '<div class="alert alert-danger">Device not found</div>';
    return;
}

Gate::authorize('update', $device_model);

$device_model->load('restApiConnections.endpoints', 'restApiConnections.credential');
$templates = RestApiTemplate::all();

echo view('device.edit.rest-api-content', [
    'device' => $device_model,
    'templates' => $templates,
])->render();