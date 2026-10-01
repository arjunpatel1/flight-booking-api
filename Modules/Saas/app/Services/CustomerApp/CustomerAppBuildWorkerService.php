<?php

namespace Modules\Saas\Services\CustomerApp;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Saas\Exceptions\CustomerAppAuthorizationException;
use Modules\Saas\Models\CustomerAppBuild;
use Modules\Saas\Models\CustomerAppBuildArtifact;
use RuntimeException;

class CustomerAppBuildWorkerService
{
    private const TRANSITIONS = [
        CustomerAppBuild::STATUS_CLAIMED => [CustomerAppBuild::STATUS_BUILDING, CustomerAppBuild::STATUS_CANCELLED, CustomerAppBuild::STATUS_FAILED],
        CustomerAppBuild::STATUS_BUILDING => [CustomerAppBuild::STATUS_TESTING, CustomerAppBuild::STATUS_CANCELLED, CustomerAppBuild::STATUS_FAILED],
        CustomerAppBuild::STATUS_TESTING => [CustomerAppBuild::STATUS_SIGNING, CustomerAppBuild::STATUS_CANCELLED, CustomerAppBuild::STATUS_FAILED],
        CustomerAppBuild::STATUS_SIGNING => [CustomerAppBuild::STATUS_VERIFYING, CustomerAppBuild::STATUS_CANCELLED, CustomerAppBuild::STATUS_FAILED],
        CustomerAppBuild::STATUS_VERIFYING => [CustomerAppBuild::STATUS_UPLOADING, CustomerAppBuild::STATUS_CANCELLED, CustomerAppBuild::STATUS_FAILED],
        CustomerAppBuild::STATUS_UPLOADING => [CustomerAppBuild::STATUS_READY, CustomerAppBuild::STATUS_CANCELLED, CustomerAppBuild::STATUS_FAILED],
        CustomerAppBuild::STATUS_CANCEL_REQUESTED => [CustomerAppBuild::STATUS_CANCELLED, CustomerAppBuild::STATUS_FAILED],
    ];

    public function __construct(private readonly CustomerAppBuildSnapshotValidator $snapshots) {}

    public function claim(string $workerId): ?CustomerAppBuild
    {
        $this->recoverExpiredBuilds();
        // Do not allow one poisoned snapshot to starve every build queued
        // behind it. Invalid records are terminally failed inside the same
        // lock transaction and the worker advances to the next candidate.
        // Bound this request to the queue depth observed at entry. Every
        // rejected candidate becomes terminal, so the loop always makes
        // progress without an arbitrary limit that could starve build 11.
        $candidateCount = $this->claimableBuilds()->count();
        for ($inspected = 0; $inspected < $candidateCount; $inspected++) {
            $result = $this->claimNext($workerId);
            if ($result instanceof CustomerAppBuild || $result === null) {
                return $result;
            }
        }

        return null;
    }

    private function recoverExpiredBuilds(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $maxAttempts = max(1, (int) config('saas.customer_app_build.max_attempts', 3));
            CustomerAppBuild::query()->withoutGlobalScopes()
                ->whereIn('status', CustomerAppBuild::LEASED_STATUSES)
                ->whereNotNull('lease_expires_at')->where('lease_expires_at', '<', $now)
                ->where('attempt', '>=', $maxAttempts)->lockForUpdate()->get()->each(function (CustomerAppBuild $build) use ($now): void {
                    $build->forceFill(['status' => CustomerAppBuild::STATUS_FAILED, 'failed_at' => $now, 'active_fingerprint' => null, 'lease_expires_at' => null, 'worker_id' => null, 'error_code' => 'BUILD_ATTEMPTS_EXHAUSTED', 'error_message' => 'The build worker could not complete this request after the allowed retries.'])->save();
                });
            CustomerAppBuild::query()->withoutGlobalScopes()
                ->where('status', CustomerAppBuild::STATUS_CANCEL_REQUESTED)
                ->whereNotNull('lease_expires_at')->where('lease_expires_at', '<', $now)
                ->lockForUpdate()->get()->each(function (CustomerAppBuild $build) use ($now): void {
                    $build->forceFill(['status' => CustomerAppBuild::STATUS_CANCELLED, 'cancelled_at' => $now, 'active_fingerprint' => null, 'lease_expires_at' => null, 'worker_id' => null])->save();
                });
        });
    }

    private function claimNext(string $workerId): CustomerAppBuild|false|null
    {
        return DB::transaction(function () use ($workerId): CustomerAppBuild|false|null {
            $now = now();
            $build = $this->claimableBuilds($now)
                ->oldest('queued_at')->lockForUpdate()->first();

            if (! $build) {
                return null;
            }

            try {
                $this->snapshots->validateForBuild($build);
            } catch (CustomerAppAuthorizationException $exception) {
                $build->forceFill([
                    'status' => CustomerAppBuild::STATUS_FAILED,
                    'failed_at' => $now,
                    'active_fingerprint' => null,
                    'lease_expires_at' => null,
                    'worker_id' => null,
                    'error_code' => 'BUILD_SNAPSHOT_INVALID',
                    'error_message' => 'The immutable build configuration failed validation.',
                ])->save();

                return false;
            }

            $build->forceFill([
                'status' => CustomerAppBuild::STATUS_CLAIMED, 'worker_id' => $workerId,
                'attempt' => ((int) $build->attempt) + 1, 'claimed_at' => $now,
                'started_at' => $build->started_at ?? $now,
                'last_heartbeat_at' => $now,
                'lease_expires_at' => $now->copy()->addSeconds($this->leaseSeconds()),
                'error_code' => null, 'error_message' => null,
            ])->save();

            return $build->fresh();
        });
    }

    private function claimableBuilds(mixed $now = null): Builder
    {
        $now ??= now();

        return CustomerAppBuild::query()->withoutGlobalScopes()
            ->where(function (Builder $query) use ($now): void {
                $query->where('status', CustomerAppBuild::STATUS_QUEUED)
                    ->orWhere(function (Builder $expired) use ($now): void {
                        $expired->whereIn('status', CustomerAppBuild::LEASED_STATUSES)
                            ->where('lease_expires_at', '<', $now);
                    });
            })
            ->where('attempt', '<', max(1, (int) config('saas.customer_app_build.max_attempts', 3)));
    }

    public function heartbeat(CustomerAppBuild $build, string $workerId): CustomerAppBuild
    {
        return DB::transaction(function () use ($build, $workerId): CustomerAppBuild {
            $locked = CustomerAppBuild::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($build->id);
            $this->assertLeaseOwner($locked, $workerId);
            if ($locked->status === CustomerAppBuild::STATUS_CANCEL_REQUESTED) {
                return $locked;
            }
            $claimedAt = $locked->claimed_at ?? $locked->started_at;
            if ($claimedAt && $claimedAt->copy()->addSeconds($this->maxRuntimeSeconds())->isPast()) {
                $locked->forceFill([
                    'status' => CustomerAppBuild::STATUS_FAILED,
                    'failed_at' => now(),
                    'active_fingerprint' => null,
                    'lease_expires_at' => null,
                    'worker_id' => null,
                    'last_heartbeat_at' => now(),
                    'error_code' => 'BUILD_RUNTIME_EXCEEDED',
                    'error_message' => 'The build exceeded the maximum verified runtime and was stopped safely.',
                ])->save();

                return $locked->fresh();
            }
            $locked->forceFill(['last_heartbeat_at' => now(), 'lease_expires_at' => now()->addSeconds($this->leaseSeconds())])->save();

            return $locked->fresh();
        });
    }

    public function transition(CustomerAppBuild $build, string $workerId, string $status, array $metadata = []): CustomerAppBuild
    {
        return DB::transaction(function () use ($build, $workerId, $status, $metadata): CustomerAppBuild {
            $locked = CustomerAppBuild::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($build->id);
            $this->assertLeaseOwner($locked, $workerId);
            $this->assertTransition($locked, $workerId, $status);

            $values = [
                'status' => $status,
                'last_heartbeat_at' => now(),
                'lease_expires_at' => in_array($status, CustomerAppBuild::TERMINAL_STATUSES, true) ? null : now()->addSeconds($this->leaseSeconds()),
                'build_metadata' => array_merge((array) $locked->build_metadata, $this->safeMetadata($metadata)),
            ];
            if ($status === CustomerAppBuild::STATUS_TESTING) {
                $values['testing_at'] = now();
            }
            if ($status === CustomerAppBuild::STATUS_FAILED) {
                $values += ['failed_at' => now(), 'active_fingerprint' => null, 'worker_id' => null];
            }
            if ($status === CustomerAppBuild::STATUS_CANCELLED) {
                $values += ['cancelled_at' => now(), 'active_fingerprint' => null, 'worker_id' => null];
            }
            $locked->forceFill($values)->save();

            return $locked->fresh('artifact');
        });
    }

    public function fail(CustomerAppBuild $build, string $workerId, string $code, string $message): CustomerAppBuild
    {
        $safeCode = strtoupper((string) preg_replace('/[^A-Z0-9_]/i', '_', $code)) ?: 'BUILD_FAILED';
        // Never persist compiler output: it can contain paths, credentials or
        // signed URLs. CI keeps restricted logs; tenants receive a safe reason.
        $safeMessage = 'The dedicated build worker reported a failure. Review worker logs for details.';

        return DB::transaction(function () use ($build, $workerId, $safeCode, $safeMessage): CustomerAppBuild {
            $locked = $this->lockBuild($build);
            $this->assertTransition($locked, $workerId, CustomerAppBuild::STATUS_FAILED);
            $locked->forceFill([
                'status' => CustomerAppBuild::STATUS_FAILED,
                'failed_at' => now(),
                'active_fingerprint' => null,
                'lease_expires_at' => null,
                'worker_id' => null,
                'last_heartbeat_at' => now(),
                'error_code' => $safeCode,
                'error_message' => $safeMessage,
            ])->save();

            return $locked->fresh('artifact');
        });
    }

    public function complete(CustomerAppBuild $build, string $workerId, UploadedFile $file, array $verification): CustomerAppBuild
    {
        $this->assertLeaseOwner($build, $workerId);
        if ($build->status !== CustomerAppBuild::STATUS_UPLOADING) {
            throw new CustomerAppAuthorizationException('INVALID_BUILD_TRANSITION', 'Artifact upload requires uploading state.', 409);
        }
        $expectedExtension = $build->build_type === 'app_bundle' ? 'aab' : 'apk';
        if (strtolower($file->getClientOriginalExtension()) !== $expectedExtension || ! $file->isValid()) {
            throw new CustomerAppAuthorizationException('INVALID_ARTIFACT', 'The build artifact type is invalid.', 422);
        }
        $size = (int) $file->getSize();
        if ($size < 1024 || $size > (int) config('saas.customer_app_build.max_artifact_bytes')) {
            throw new CustomerAppAuthorizationException('INVALID_ARTIFACT_SIZE', 'The build artifact size is outside the allowed range.', 422);
        }
        $checksum = hash_file('sha256', $file->getRealPath());
        if (! hash_equals(strtolower((string) ($verification['checksum'] ?? '')), $checksum)) {
            throw new CustomerAppAuthorizationException('ARTIFACT_CHECKSUM_MISMATCH', 'Artifact checksum verification failed.', 422);
        }
        $snapshot = $this->snapshots->validateForBuild($build);
        $expectedPackage = (string) data_get($snapshot, 'application.package_id');
        $expectedCommit = strtolower((string) data_get($snapshot, 'source.commit'));
        $expectedVersion = (string) data_get($snapshot, 'build.version');
        $expectedLabel = (string) data_get($snapshot, 'branding.display_name');
        $expectedSigner = strtolower((string) data_get($snapshot, 'signing.certificate_sha256'));
        $package = (string) ($verification['package_id'] ?? '');
        $commit = strtolower((string) ($verification['commit'] ?? ''));
        $version = (string) ($verification['version'] ?? '');
        $applicationLabel = (string) ($verification['application_label'] ?? '');
        $signer = strtolower((string) ($verification['signer_sha256'] ?? ''));
        if (! hash_equals($expectedPackage, $package)
            || ! hash_equals($expectedCommit, $commit)
            || ! hash_equals($expectedVersion, $version)
            || ! hash_equals($expectedLabel, $applicationLabel)) {
            throw new CustomerAppAuthorizationException('ARTIFACT_IDENTITY_MISMATCH', 'Artifact identity verification failed.', 422);
        }
        if (! preg_match('/\A[0-9a-f]{64}\z/', $signer)
            || ! preg_match('/\A[0-9a-f]{64}\z/', $expectedSigner)
            || ! hash_equals($expectedSigner, $signer)) {
            throw new CustomerAppAuthorizationException('ARTIFACT_SIGNATURE_INVALID', 'Artifact signing verification is missing or invalid.', 422);
        }

        // A unique object reference prevents competing/stale worker callbacks
        // from overwriting or deleting a verified artifact owned by the winner.
        $path = "private/customer-app/{$build->tenant_id}/{$build->uuid}/".Str::uuid().".{$expectedExtension}";
        $diskName = (string) config('saas.customer_app_build.artifact_disk', 'local');
        $disk = Storage::disk($diskName);
        $this->storeArtifact($file, $diskName, $path);

        try {
            return DB::transaction(function () use ($build, $workerId, $verification, $checksum, $size, $expectedExtension, $path, $diskName, $signer): CustomerAppBuild {
                $locked = $this->lockBuild($build);
                $this->assertLeaseOwner($locked, $workerId);
                if ($locked->status !== CustomerAppBuild::STATUS_UPLOADING) {
                    throw new CustomerAppAuthorizationException('INVALID_BUILD_TRANSITION', 'Artifact upload requires an active uploading state.', 409);
                }

                CustomerAppBuildArtifact::query()->updateOrCreate(
                    ['customer_app_build_id' => $locked->id],
                    [
                        'tenant_id' => $locked->tenant_id, 'platform' => $locked->platform,
                        'build_type' => $locked->build_type, 'version' => $locked->requested_version,
                        'checksum' => $checksum, 'storage_disk' => $diskName, 'storage_reference' => $path,
                        'size_bytes' => $size, 'mime_type' => $this->artifactMimeType($expectedExtension), 'verified_at' => now(),
                        'retention_expires_at' => now()->addDays(max(1, (int) config('saas.customer_app_build.retention_days'))),
                        'verification_metadata' => $this->safeMetadata($verification),
                    ]
                );
                $locked->registration()->withoutGlobalScopes()->update([
                    'signing_certificate_fingerprint' => $signer,
                ]);
                $locked->forceFill([
                    'status' => CustomerAppBuild::STATUS_READY, 'artifact_checksum' => $checksum, 'completed_at' => now(),
                    'active_fingerprint' => null, 'lease_expires_at' => null, 'last_heartbeat_at' => now(),
                    'worker_id' => null,
                ])->save();

                return $locked->fresh('artifact');
            });
        } catch (\Throwable $exception) {
            // Storage is external to the database transaction. Compensate on
            // every persistence failure so an unpublished artifact cannot be
            // left behind under a predictable private reference.
            $disk->delete($path);

            throw $exception;
        }
    }

    private function assertLeaseOwner(CustomerAppBuild $build, string $workerId): void
    {
        if (! hash_equals((string) $build->worker_id, $workerId) || ($build->lease_expires_at && $build->lease_expires_at->isPast())) {
            throw new CustomerAppAuthorizationException('BUILD_LEASE_INVALID', 'The build lease is missing, expired, or belongs to another worker.', 409);
        }
    }

    private function assertTransition(CustomerAppBuild $build, string $workerId, string $status): void
    {
        $this->assertLeaseOwner($build, $workerId);
        if (! in_array($status, self::TRANSITIONS[$build->status] ?? [], true)) {
            throw new CustomerAppAuthorizationException(
                'INVALID_BUILD_TRANSITION',
                "Cannot change build from {$build->status} to {$status}.",
                409,
            );
        }
    }

    private function lockBuild(CustomerAppBuild $build): CustomerAppBuild
    {
        return CustomerAppBuild::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($build->id);
    }

    private function storeArtifact(UploadedFile $file, string $diskName, string $path): void
    {
        $stream = fopen($file->getRealPath(), 'rb');
        if (! is_resource($stream)) {
            throw new RuntimeException('The verified artifact could not be opened.');
        }

        try {
            if (! Storage::disk($diskName)->put($path, $stream)) {
                throw new RuntimeException('Private artifact storage failed.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function artifactMimeType(string $extension): string
    {
        return $extension === 'aab'
            ? 'application/octet-stream'
            : 'application/vnd.android.package-archive';
    }

    private function safeMetadata(array $metadata): array
    {
        return collect($metadata)->only([
            'repository', 'commit', 'flutter', 'dart', 'gradle', 'android_sdk',
            'runner', 'duration_seconds', 'checksum', 'package_id',
            'signer_sha256', 'version', 'application_label',
        ])->map(fn ($value) => mb_substr((string) $value, 0, 255))->all();
    }

    private function leaseSeconds(): int
    {
        return min(max((int) config('saas.customer_app_build.lease_seconds', 900), 60), 3600);
    }

    private function maxRuntimeSeconds(): int
    {
        return min(max((int) config('saas.customer_app_build.max_runtime_seconds', 2400), 300), 3600);
    }
}
