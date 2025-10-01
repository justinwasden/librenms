<?php

namespace LibreNMS\Tests\Feature\Settings;

use App\Models\RestApiAuthenticationType;
use App\Models\RestApiCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LibreNMS\Tests\DBTestCase;

class RestApiCredentialControllerTest extends DBTestCase
{
    use DatabaseTransactions;

    protected User $user;

    public function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->user->assignRole('Admin');
        $this->actingAs($this->user);
    }

    public function testIndex()
    {
        RestApiCredential::factory()->count(3)->create();

        $response = $this->get(route('settings.rest-api.credentials.index'));

        $response->assertStatus(200);
        $response->assertViewIs('settings.rest-api.credentials.index');
        $response->assertViewHas('credentials', function ($credentials) {
            return $credentials->count() >= 3;
        });
    }

    public function testCreate()
    {
        $response = $this->get(route('settings.rest-api.credentials.create'));

        $response->assertStatus(200);
        $response->assertViewIs('settings.rest-api.credentials.create');
    }

    public function testStore()
    {
        $authType = RestApiAuthenticationType::factory()->create(['name' => 'Basic Auth']);
        $data = [
            'name' => 'New Test Credential',
            'authentication_type_id' => $authType->id,
            'params' => [
                'username' => 'testuser',
                'password' => 'testpass',
            ],
        ];

        $response = $this->post(route('settings.rest-api.credentials.store'), $data);

        $response->assertRedirect(route('settings.rest-api.credentials.index'));
        $this->assertDatabaseHas('rest_api_credentials', ['name' => 'New Test Credential']);
        $this->assertDatabaseHas('rest_api_credential_params', ['key' => 'username']);
    }

    public function testEdit()
    {
        $credential = RestApiCredential::factory()->create();

        $response = $this->get(route('settings.rest-api.credentials.edit', $credential));

        $response->assertStatus(200);
        $response->assertViewIs('settings.rest-api.credentials.edit');
        $response->assertViewHas('credential', $credential);
    }

    public function testUpdate()
    {
        $credential = RestApiCredential::factory()->create();
        $authType = RestApiAuthenticationType::factory()->create(['name' => 'Token']);
        $data = [
            'name' => 'Updated Credential Name',
            'authentication_type_id' => $authType->id,
            'params' => [
                'token' => 'new-secret-token',
                'header' => 'X-API-Token',
                'scheme' => '',
            ],
        ];

        $response = $this->put(route('settings.rest-api.credentials.update', $credential), $data);

        $response->assertRedirect(route('settings.rest-api.credentials.index'));
        $this->assertDatabaseHas('rest_api_credentials', ['id' => $credential->id, 'name' => 'Updated Credential Name']);
        $this->assertDatabaseHas('rest_api_credential_params', ['key' => 'token']);
    }

    public function testDestroy()
    {
        $credential = RestApiCredential::factory()->create();

        $response = $this->delete(route('settings.rest-api.credentials.destroy', $credential));

        $response->assertRedirect(route('settings.rest-api.credentials.index'));
        $this->assertDatabaseMissing('rest_api_credentials', ['id' => $credential->id]);
    }
}