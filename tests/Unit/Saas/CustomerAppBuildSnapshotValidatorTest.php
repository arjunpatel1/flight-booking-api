<?php

namespace Tests\Unit\Saas;

use Modules\Saas\Exceptions\CustomerAppAuthorizationException;
use Modules\Saas\Services\CustomerApp\CustomerAppBuildSnapshotValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerAppBuildSnapshotValidatorTest extends TestCase
{
    private CustomerAppBuildSnapshotValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'saas.customer_app.api_origin' => 'https://api.example.test/v1',
            'saas.customer_app_build.repository' => 'https://github.com/example/customer-app.git',
            'saas.customer_app_build.source_commit' => str_repeat('a', 40),
            'saas.customer_app_build.flutter_version' => '3.35.1',
            'saas.customer_app_build.android_sdk_version' => '35',
            'saas.customer_app_build.signer_sha256' => str_repeat('b', 64),
        ]);

        $this->validator = app(CustomerAppBuildSnapshotValidator::class);
    }

    public function test_it_normalizes_and_hashes_a_complete_controlled_snapshot(): void
    {
        $snapshot = $this->snapshot();

        $validated = $this->validator->validate($snapshot);
        $revision = $this->validator->revision($snapshot);

        $this->assertSame('android', data_get($validated, 'application.platform'));
        $this->assertSame('release', data_get($validated, 'build.type'));
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $revision);
        $this->assertSame($revision, $this->validator->revision(array_reverse($snapshot, true)));
    }

    #[DataProvider('unsafeSnapshotProvider')]
    public function test_it_rejects_uncontrolled_or_unsafe_snapshot_values(callable $mutate): void
    {
        $snapshot = $this->snapshot();
        $mutate($snapshot);

        $this->expectException(CustomerAppAuthorizationException::class);
        $this->expectExceptionMessage('immutable build snapshot is invalid');

        $this->validator->validate($snapshot);
    }

    public static function unsafeSnapshotProvider(): array
    {
        return [
            'api query injection' => [fn (array &$snapshot) => $snapshot['api_origin'] .= '?token=secret'],
            'api traversal' => [fn (array &$snapshot) => $snapshot['api_origin'] = 'https://api.example.test/v1/../admin'],
            'uncontrolled repository' => [fn (array &$snapshot) => $snapshot['source']['repository'] = 'https://attacker.example/app.git'],
            'commit mismatch' => [fn (array &$snapshot) => $snapshot['source']['commit'] = str_repeat('c', 40)],
            'signer mismatch' => [fn (array &$snapshot) => $snapshot['signing']['certificate_sha256'] = str_repeat('d', 64)],
            'credentialed logo URL' => [fn (array &$snapshot) => $snapshot['branding']['logo_url'] = 'https://user:secret@cdn.example.test/logo.png'],
            'private metadata asset URL' => [fn (array &$snapshot) => $snapshot['branding']['logo_url'] = 'https://169.254.169.254/latest/meta-data'],
            'loopback asset URL' => [fn (array &$snapshot) => $snapshot['branding']['app_icon_url'] = 'https://127.0.0.1/icon.png'],
            'internal asset hostname' => [fn (array &$snapshot) => $snapshot['branding']['app_icon_url'] = 'https://assets.internal/icon.png'],
            'control character in label' => [fn (array &$snapshot) => $snapshot['branding']['display_name'] = "Unsafe\nName"],
            'unexpected top-level secret field' => [fn (array &$snapshot) => $snapshot['secrets'] = ['token' => 'unsafe']],
            'unexpected nested tenant field' => [fn (array &$snapshot) => $snapshot['tenant']['database'] = 'foreign_database'],
        ];
    }

    private function snapshot(): array
    {
        return [
            'schema_version' => 2,
            'tenant' => ['uuid' => '550e8400-e29b-41d4-a716-446655440000', 'slug' => 'pilot-cafe'],
            'application' => [
                'uuid' => '1b4e28ba-2fa1-11d2-883f-0016d3cca427',
                'package_id' => 'com.example.pilot_customer',
                'platform' => 'ANDROID',
            ],
            'build' => ['type' => 'RELEASE', 'version' => '1.0.0'],
            'branding' => [
                'display_name' => ' Pilot Cafe ',
                'logo_url' => 'https://cdn.example.test/pilot/logo.png',
                'app_icon_url' => 'https://cdn.example.test/pilot/icon.png',
                'splash_logo_url' => null,
                'primary_color' => '#FF6B00',
                'secondary_color' => '#111827',
            ],
            'branding_revision' => 1,
            'api_origin' => 'https://api.example.test/v1/',
            'source' => [
                'repository' => 'https://github.com/example/customer-app.git',
                'commit' => str_repeat('a', 40),
            ],
            'signing' => ['certificate_sha256' => str_repeat('b', 64)],
            'toolchain' => ['flutter' => '3.35.1', 'android_sdk' => '35'],
        ];
    }
}
