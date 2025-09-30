<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\RestApiTemplate;

class RestApiTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Cisco IOS-XE RESTCONF Template
        RestApiTemplate::firstOrCreate(
            ['name' => 'Cisco IOS-XE - Interfaces'],
            [
                'vendor' => 'Cisco',
                'template_data' => [
                    'connections' => [
                        [
                            'name' => 'RESTCONF Connection',
                            'base_url' => 'https://{{ $device->hostname }}/restconf/data',
                            'endpoints' => [
                                [
                                    'name' => 'Get Interfaces',
                                    'path' => '/Cisco-IOS-XE-interfaces-oper:interfaces',
                                    'method' => 'GET',
                                    'headers' => [
                                        'Accept' => 'application/yang-data+json',
                                    ],
                                    'metric_map' => [
                                        'interface_stats' => 'Cisco-IOS-XE-interfaces-oper:interfaces.interface.*',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        // Pure Storage FlashArray Template
        RestApiTemplate::firstOrCreate(
            ['name' => 'Pure Storage FlashArray - Performance'],
            [
                'vendor' => 'Pure Storage',
                'template_data' => [
                    'connections' => [
                        [
                            'name' => 'FlashArray API',
                            'base_url' => 'https://{{ $device->hostname }}/api/1.16',
                            'endpoints' => [
                                [
                                    'name' => 'Array Performance',
                                    'path' => '/array?action=monitor&space=true',
                                    'method' => 'GET',
                                    'metric_map' => [
                                        'writes_per_sec' => 'writes_per_sec',
                                        'reads_per_sec' => 'reads_per_sec',
                                        'usec_per_write_op' => 'usec_per_write_op',
                                        'usec_per_read_op' => 'usec_per_read_op',
                                        'output_per_sec' => 'output_per_sec',
                                        'input_per_sec' => 'input_per_sec',
                                        'total_capacity' => 'capacity',
                                        'data_reduction' => 'data_reduction',
                                        'total_reduction' => 'total_reduction',
                                        'shared_space' => 'shared_space',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        // Meraki Dashboard API Template
        RestApiTemplate::firstOrCreate(
            ['name' => 'Meraki Dashboard - Network Devices'],
            [
                'vendor' => 'Meraki',
                'template_data' => [
                    'connections' => [
                        [
                            'name' => 'Meraki API',
                            'base_url' => 'https://api.meraki.com/api/v1',
                            'endpoints' => [
                                [
                                    'name' => 'Get Network Devices',
                                    'path' => '/networks/{{ $device->getAttrib("meraki_network_id") }}/devices',
                                    'method' => 'GET',
                                    'metric_map' => [
                                        'device_info' => '*', // Get all devices as a list of objects
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }
}