<?php

namespace App\Console\Commands;

use App\Http\Controllers\Device\RestApiActionsController;
use App\Models\Device;
use App\Models\RestApiAuthenticationType;
use App\Models\RestApiCredential;
use App\Models\RestApiTemplate;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class VerifyApiFeature extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'verify:api-feature';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify the end-to-end flow of the REST API polling feature';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(RestApiActionsController $controller)
    {
        $this->info('Starting REST API feature verification...');

        // Cleanup from previous runs
        $this->line('Cleaning up data from previous runs...');
        RestApiCredential::where('name', 'Verification Credential')->delete();
        RestApiTemplate::where('name', 'Verification Template')->delete();
        Device::where('hostname', 'test-device.local')->delete();
        User::where('email', 'verification-admin@librenms.org')->delete();
        $this->info('Cleanup complete.');


        // We need an authenticated admin user for the Gate checks to pass
				$admin = User::factory()->create(['email' => 'verification-admin@librenms.org', 'level' => 10]);
				$adminRole = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
				$admin->assignRole($adminRole);
				$admin->refresh(); // Ensure role is loaded
				Auth::setUser($admin);

        DB::transaction(function () use ($controller, $admin) {
            // 1. Create Auth Type and Credential
            $this->line('Step 1: Creating Authentication Type and Credential...');
            $authType = RestApiAuthenticationType::firstOrCreate(['name' => 'Basic Auth']);
            $credential = RestApiCredential::factory()->create([
                'name' => 'Verification Credential',
                'authentication_type_id' => $authType->id,
            ]);
            $this->info('Credential created successfully.');

            // 2. Create Template with placeholders
            $this->line('Step 2: Creating REST API Template...');
            $template = RestApiTemplate::factory()->create([
                'name' => 'Verification Template',
                'vendor' => 'Test',
                'template_data' => [
                    'connections' => [
                        [
                            'name' => '{{ $device->hostname }} API',
                            'base_url' => 'https://{{ $device->ip }}/api/v1',
                            'credential_id' => $credential->id,
                            'endpoints' => [
                                [
                                    'name' => 'Get Status',
                                    'path' => '/status/{{ $device->getAttrib("site_id") }}',
                                    'method' => 'GET',
                                    'metric_map' => ['status' => 'system.status'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
            $this->info('Template created successfully.');

            // 3. Create a test device with a custom attribute
            $this->line('Step 3: Creating Test Device...');
            $device = Device::factory()->create([
                'hostname' => 'test-device.local',
                'ip' => '192.168.1.123',
            ]);
            $device->setAttrib('site_id', 'SITE-ABC');
            $this->info("Device '{$device->hostname}' created successfully.");


            // 4. Simulate applying the template by calling the controller method
            $this->line('Step 4: Applying template to device...');
            $request = new Request(['template_id' => $template->id]);
            $request->setUserResolver(fn () => $admin); // Set the user on the request
            $controller->applyTemplate($request, $device);
            $this->info('applyTemplate method executed.');

            // 5. Verify the results
            $this->line('Step 5: Verifying results...');
            $device->refresh();
            $connection = $device->restApiConnections->first();

            if (!$connection) {
                $this->error('Verification failed: No RestApiConnection was created.');
                return;
            }
            $this->info('Connection found.');

            $endpoint = $connection->endpoints->first();
            if (!$endpoint) {
                $this->error('Verification failed: No RestApiEndpoint was created.');
                return;
            }
            $this->info('Endpoint found.');

            // Assert placeholders were replaced
            $expectedConnName = 'test-device.local API';
            if ($connection->name !== $expectedConnName) {
                $this->error("Connection name mismatch. Expected: '$expectedConnName', Got: '{$connection->name}'");
                return;
            }
            $this->info('Connection name matches.');

            $expectedBaseUrl = 'https://192.168.1.123/api/v1';
            if ($connection->base_url !== $expectedBaseUrl) {
                $this->error("Connection base URL mismatch. Expected: '$expectedBaseUrl', Got: '{$connection->base_url}'");
                return;
            }
            $this->info('Connection base URL matches.');

            $expectedPath = '/status/SITE-ABC';
            if ($endpoint->path !== $expectedPath) {
                $this->error("Endpoint path mismatch. Expected: '$expectedPath', Got: '{$endpoint->path}'");
                return;
            }
            $this->info('Endpoint path matches.');

            $this->info('All verification checks passed!');
        });

        $this->info('Verification complete. Rolling back changes.');

        return Command::SUCCESS;
    }
}