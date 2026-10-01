<?php

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureIdempotentRequest;
use RuntimeException;
use Tests\TestCase;

class EnsureIdempotentRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('__test/idempotent/ok', fn () => response()->json(['paid' => true]))
            ->middleware(EnsureIdempotentRequest::class.':required');

        Route::post('__test/idempotent/throws', function (): void {
            throw new RuntimeException('gateway exploded');
        })->middleware(EnsureIdempotentRequest::class.':required');

        Route::post('__test/idempotent/server-error', fn () => response()->json(['message' => 'boom'], 500))
            ->middleware(EnsureIdempotentRequest::class.':required');
    }

    private function latest(): ?object
    {
        return DB::table('idempotency_keys')->orderByDesc('id')->first();
    }

    public function test_it_requires_a_key_when_configured(): void
    {
        $this->postJson('/__test/idempotent/ok')->assertStatus(422);
    }

    public function test_it_completes_and_replays_a_successful_request(): void
    {
        $headers = ['Idempotency-Key' => 'success-'.uniqid()];

        $this->postJson('/__test/idempotent/ok', [], $headers)->assertOk();

        $stored = $this->latest();
        $this->assertSame('completed', $stored->status);
        $this->assertNull($stored->locked_until);

        // Same key + same payload replays the stored response.
        $this->postJson('/__test/idempotent/ok', [], $headers)
            ->assertOk()
            ->assertHeader('X-NexDine-Idempotent-Replay', '1');
    }

    /**
     * Regression: a throwing request used to leave the key locked for five
     * minutes, so every retry answered "already being processed" and the real
     * error stayed hidden. This is what blocked payments on every order.
     */
    public function test_a_thrown_request_does_not_leave_the_key_locked(): void
    {
        $headers = ['Idempotency-Key' => 'throws-'.uniqid()];

        try {
            $this->postJson('/__test/idempotent/throws', [], $headers);
        } catch (\Throwable) {
            // The handler converts this to a 500; either way the lock must go.
        }

        $stored = $this->latest();
        $this->assertSame('processing', $stored->status);
        $this->assertNull($stored->locked_until, 'Lock must be released so the caller can retry.');
    }

    public function test_a_server_error_releases_the_lock(): void
    {
        $headers = ['Idempotency-Key' => 'error-'.uniqid()];

        $this->postJson('/__test/idempotent/server-error', [], $headers)->assertStatus(500);

        $stored = $this->latest();
        $this->assertSame('processing', $stored->status);
        $this->assertNull($stored->locked_until);
        $this->assertNull($stored->response_code, 'A 5xx must not be cached as a replayable result.');
    }

    public function test_a_retry_after_a_failure_reaches_the_route_instead_of_409(): void
    {
        $key = 'retry-'.uniqid();
        $headers = ['Idempotency-Key' => $key];

        $this->postJson('/__test/idempotent/server-error', [], $headers)->assertStatus(500);

        // Second attempt with the same key must be allowed through rather than
        // answering 409 "already being processed".
        $this->postJson('/__test/idempotent/server-error', [], $headers)->assertStatus(500);
    }

    public function test_an_actively_locked_key_is_still_rejected(): void
    {
        $key = 'locked-'.uniqid();
        $keyHash = hash('sha256', implode('|', ['guest', 'POST', '__test/idempotent/ok', $key]));
        $requestHash = hash('sha256', json_encode([], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        DB::table('idempotency_keys')->insert([
            'key_hash' => $keyHash,
            'user_id' => null,
            'method' => 'POST',
            'route' => '__test/idempotent/ok',
            'request_hash' => $requestHash,
            'status' => 'processing',
            'locked_until' => now()->addMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/__test/idempotent/ok', [], ['Idempotency-Key' => $key])
            ->assertStatus(409);
    }

    public function test_reusing_a_key_with_a_different_payload_is_rejected(): void
    {
        $headers = ['Idempotency-Key' => 'reuse-'.uniqid()];

        $this->postJson('/__test/idempotent/ok', ['amount' => 10], $headers)->assertOk();
        $this->postJson('/__test/idempotent/ok', ['amount' => 999], $headers)->assertStatus(409);
    }
}
