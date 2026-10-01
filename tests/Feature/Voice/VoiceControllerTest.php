<?php

namespace Tests\Feature\Voice;

use Modules\Branch\Models\Branch;
use Modules\User\Models\User;
use Modules\Voice\Models\VoiceSetting;
use Modules\Voice\Models\VoiceTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\User\Enums\DefaultRole;
use Tests\TestCase;

class VoiceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('permission:sync-permissions');
        Artisan::call('permission:sync-default-roles');
        $this->ensureBranch(1);
        $this->ensureBranch(2);

        $this->user = User::factory()->create([
            'branch_id' => 1,
        ]);
        $this->user->assignRole(DefaultRole::AdminBranch->value);
    }

    public function test_get_settings_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/voice/settings');
        $response->assertStatus(401);
    }

    public function test_get_settings_requires_permission(): void
    {
        $user = User::factory()->create(['branch_id' => 1]);
        $user->assignRole(DefaultRole::Waiter->value);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/voice/settings');
        $response->assertStatus(403);
    }

    public function test_get_settings_returns_valid_structure(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/voice/settings');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'body',
            ]);
    }

    public function test_save_settings_validates_input(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/voice/settings', [
                'voice_gender' => 'Invalid',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['voice_gender']);
    }

    public function test_save_settings_requires_permission(): void
    {
        $user = User::factory()->create(['branch_id' => 1]);
        $user->assignRole(DefaultRole::Waiter->value);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/voice/settings', [
                'voice_enabled' => true,
            ]);

        $response->assertStatus(403);
    }

    public function test_save_settings_updates_database(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/voice/settings', [
                'voice_enabled' => false,
                'voice_gender' => 'Male',
                'voice_rate' => 5,
                'voice_volume' => 90,
                'delay_threshold_minutes' => 45,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('voice_settings', [
            'branch_id' => 1,
            'voice_enabled' => false,
            'voice_gender' => 'Male',
            'voice_rate' => 5,
            'voice_volume' => 90,
            'delay_threshold_minutes' => 45,
        ]);
    }

    public function test_get_templates_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/voice/templates');
        $response->assertStatus(401);
    }

    public function test_get_templates_requires_permission(): void
    {
        $user = User::factory()->create(['branch_id' => 1]);
        $user->assignRole(DefaultRole::Waiter->value);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/voice/templates');
        $response->assertStatus(403);
    }

    public function test_save_template_validates_input(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/voice/templates', [
                'template_name' => '',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['template_name', 'template_text', 'event_type']);
    }

    public function test_save_template_requires_permission(): void
    {
        $user = User::factory()->create(['branch_id' => 1]);
        $user->assignRole(DefaultRole::Waiter->value);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/voice/templates', [
                'template_name' => 'Test',
                'template_text' => 'Test message',
                'event_type' => 'NewOrder',
            ]);

        $response->assertStatus(403);
    }

    public function test_delete_template_prevents_idor(): void
    {
        $otherBranch = User::factory()->create(['branch_id' => 2]);
        $otherBranch->assignRole(DefaultRole::AdminBranch->value);

        $template = VoiceTemplate::factory()->create([
            'branch_id' => 2,
            'template_name' => 'Other Branch Template',
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/voice/templates/{$template->id}");

        $response->assertStatus(404);

        $this->assertDatabaseHas('voice_templates', [
            'id' => $template->id,
        ]);
    }

    public function test_delete_template_returns_204(): void
    {
        $template = VoiceTemplate::factory()->create([
            'branch_id' => 1,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/voice/templates/{$template->id}");

        $response->assertStatus(204);
    }

    public function test_get_history_validates_limit(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/voice/history?per_page=2000');

        $response->assertStatus(422);
    }

    public function test_get_devices_returns_valid_structure(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/voice/devices');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'body' => [
                    'devices',
                    'total',
                ],
            ]);
    }

    private function ensureBranch(int $id): void
    {
        if (!Branch::query()->whereKey($id)->exists()) {
            Branch::factory()->create(['id' => $id]);
        }
    }

    public function test_test_voice_validates_input(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/voice/test', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    public function test_trigger_announcement_validates_event_type(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/voice/trigger', [
                'event_type' => 'InvalidType',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['event_type']);
    }
}
