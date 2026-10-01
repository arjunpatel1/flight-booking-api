<?php

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneIdempotencyKeysCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_prunes_only_expired_records_in_bounded_batches(): void
    {
        $this->insertRecord('old-completed', 'completed', now()->subDays(31), null, now()->subDays(31));
        $this->insertRecord('recent-completed', 'completed', now()->subDays(2), null, now()->subDays(2));
        $this->insertRecord('stale-processing', 'processing', null, now()->subHours(25), now()->subHours(25));
        $this->insertRecord('active-processing', 'processing', null, now()->addMinutes(5), now());

        $exitCode = Artisan::call('core:prune-idempotency', [
            '--days' => 30,
            '--processing-hours' => 24,
            '--batch' => 1,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseMissing('idempotency_keys', ['key_hash' => hash('sha256', 'old-completed')]);
        $this->assertDatabaseMissing('idempotency_keys', ['key_hash' => hash('sha256', 'stale-processing')]);
        $this->assertDatabaseHas('idempotency_keys', ['key_hash' => hash('sha256', 'recent-completed')]);
        $this->assertDatabaseHas('idempotency_keys', ['key_hash' => hash('sha256', 'active-processing')]);
    }

    private function insertRecord(
        string $key,
        string $status,
        mixed $completedAt,
        mixed $lockedUntil,
        mixed $createdAt,
    ): void {
        DB::table('idempotency_keys')->insert([
            'key_hash' => hash('sha256', $key),
            'user_id' => null,
            'method' => 'POST',
            'route' => 'api/v1/test',
            'request_hash' => hash('sha256', "request-{$key}"),
            'status' => $status,
            'response_code' => $status === 'completed' ? 200 : null,
            'response_body' => $status === 'completed' ? '{}' : null,
            'locked_until' => $lockedUntil,
            'completed_at' => $completedAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
