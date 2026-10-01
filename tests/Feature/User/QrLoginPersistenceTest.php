<?php

namespace Tests\Feature\User;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\User\Models\User;
use Modules\User\Services\Auth\AuthServiceInterface;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
class QrLoginPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_qr_login_survives_default_cache_loss(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $service = app(AuthServiceInterface::class);

        $qr = $service->generateQrToken($user->id);
        Cache::flush();

        $this->assertDatabaseHas('user_qr_login_tokens', [
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $qr['token']),
        ]);
        $this->assertDatabaseMissing('user_qr_login_tokens', [
            'token_hash' => $qr['token'],
        ]);
        $this->assertSame('pending', $service->qrTokenStatus($qr['token'])['status']);

        $result = $service->qrLogin($qr['token']);

        $this->assertSame($user->id, $result['user']->id);
        $this->assertNotEmpty($result['token']);
        $this->assertSame('consumed', $service->qrTokenStatus($qr['token'])['status']);
    }

    public function test_qr_login_token_cannot_be_replayed(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $service = app(AuthServiceInterface::class);
        $qr = $service->generateQrToken($user->id);

        $service->qrLogin($qr['token']);

        try {
            $service->qrLogin($qr['token']);
            $this->fail('A consumed QR login token was accepted twice.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }
    }

    public function test_expired_qr_login_token_is_rejected(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $service = app(AuthServiceInterface::class);
        $qr = $service->generateQrToken($user->id);

        $this->travel(6)->minutes();

        $this->assertSame('expired', $service->qrTokenStatus($qr['token'])['status']);
        try {
            $service->qrLogin($qr['token']);
            $this->fail('An expired QR login token was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }
    }
}
