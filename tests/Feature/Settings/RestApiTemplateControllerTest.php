<?php

namespace LibreNMS\Tests\Feature\Settings;

use App\Models\RestApiTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LibreNMS\Tests\DBTestCase;

class RestApiTemplateControllerTest extends DBTestCase
{
    use DatabaseTransactions;

    protected User $user;

    public function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['level' => 10]); // Admin user
        $this->actingAs($this->user);
    }

    public function testIndex()
    {
        RestApiTemplate::factory()->count(3)->create();

        $response = $this->get(route('settings.rest-api.templates.index'));

        $response->assertStatus(200);
        $response->assertViewIs('settings.rest-api.templates.index');
        $response->assertViewHas('templates', function ($templates) {
            return $templates->count() >= 3;
        });
    }

    public function testCreate()
    {
        $response = $this->get(route('settings.rest-api.templates.create'));

        $response->assertStatus(200);
        $response->assertViewIs('settings.rest-api.templates.create');
    }

    public function testStore()
    {
        $data = [
            'name' => 'New Test Template',
            'vendor' => 'Test Vendor',
            'template_data' => json_encode(['connections' => []]),
        ];

        $response = $this->post(route('settings.rest-api.templates.store'), $data);

        $response->assertRedirect(route('settings.rest-api.templates.index'));
        $this->assertDatabaseHas('rest_api_templates', ['name' => 'New Test Template']);
    }

    public function testEdit()
    {
        $template = RestApiTemplate::factory()->create();

        $response = $this->get(route('settings.rest-api.templates.edit', $template));

        $response->assertStatus(200);
        $response->assertViewIs('settings.rest-api.templates.edit');
        $response->assertViewHas('template', $template);
    }

    public function testUpdate()
    {
        $template = RestApiTemplate::factory()->create();
        $data = [
            'name' => 'Updated Template Name',
            'vendor' => 'Updated Vendor',
            'template_data' => json_encode(['connections' => ['updated' => true]]),
        ];

        $response = $this->put(route('settings.rest-api.templates.update', $template), $data);

        $response->assertRedirect(route('settings.rest-api.templates.index'));
        $this->assertDatabaseHas('rest_api_templates', ['id' => $template->id, 'name' => 'Updated Template Name']);
    }

    public function testDestroy()
    {
        $template = RestApiTemplate::factory()->create();

        $response = $this->delete(route('settings.rest-api.templates.destroy', $template));

        $response->assertRedirect(route('settings.rest-api.templates.index'));
        $this->assertDatabaseMissing('rest_api_templates', ['id' => $template->id]);
    }
}