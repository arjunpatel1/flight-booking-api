<?php

namespace Modules\Printer\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Core\Http\Controllers\Controller;
use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Models\PrintAgentPairing;
use Modules\Printer\Services\Reverb\ReverbConfigService;

class PrintAgentPairingController extends Controller
{
    private const EXPIRES_MINUTES = 5;

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_public_id' => ['nullable', 'string', 'max:120'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'in:windows,android'],
            'agent_version' => ['nullable', 'string', 'max:40'],
        ]);

        // A device may have only one usable challenge at a time. Invalidating
        // older challenges prevents two visible codes for the same computer
        // from being claimed by different restaurants during the expiry window.
        if (filled($data['device_public_id'] ?? null)) {
            PrintAgentPairing::query()
                ->where('device_public_id', $data['device_public_id'])
                ->where('status', 'pending')
                ->update(['status' => 'revoked']);
        }

        [$code, $codeHash] = $this->uniqueCode();
        $challengeToken = Str::random(64);
        $pairing = PrintAgentPairing::query()->create([
            ...$data,
            'code_hash' => $codeHash,
            'challenge_token_hash' => hash('sha256', $challengeToken),
            'platform' => $data['platform'] ?? 'windows',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(self::EXPIRES_MINUTES),
        ]);

        Log::info('Print agent pairing challenge created', [
            'pairing_id' => $pairing->id,
            'device_public_id' => $pairing->device_public_id,
            'expires_at' => $pairing->expires_at,
        ]);

        return response()->json([
            'data' => [
                'pairing_id' => $pairing->id,
                'pairing_code' => $code,
                'challenge_token' => $challengeToken,
                'expires_at' => $pairing->expires_at->toIso8601String(),
                'poll_after_seconds' => 3,
            ],
        ], 201);
    }

    public function status(Request $request, string $pairing): JsonResponse
    {
        $record = PrintAgentPairing::query()->with(['agent', 'tenant', 'branch'])->findOrFail($pairing);
        $this->assertChallengeToken($request, $record);

        if ($record->status === 'pending' && $record->expires_at->isPast()) {
            $record->forceFill(['status' => 'expired'])->save();
        }

        if ($record->status !== 'claimed') {
            return response()->json(['data' => [
                'status' => $record->status,
                'expires_at' => $record->expires_at->toIso8601String(),
            ]]);
        }

        return DB::transaction(function () use ($request, $record): JsonResponse {
            $locked = PrintAgentPairing::query()
                ->with(['agent', 'tenant', 'branch'])
                ->lockForUpdate()
                ->findOrFail($record->id);
            abort_unless($locked->status === 'claimed', 409, 'Pairing credentials have already been exchanged.');
            $agent = $locked->agent;
            abort_unless($agent && $agent->is_active, 409, 'The paired agent is unavailable.');

            $locked->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
            // Production exposes versioned routes as /v1 while the local/test
            // router may retain Laravel's /api/v1 prefix. Return the prefix
            // through which this pairing request actually arrived so a newly
            // connected agent never receives an unreachable server URL.
            $versionPrefix = Str::startsWith($request->path(), 'api/v1/')
                ? '/api/v1'
                : '/v1';
            $apiUrl = rtrim($request->getSchemeAndHttpHost(), '/').$versionPrefix;
            $reverb = (new ReverbConfigService)->toArray($request);

            Log::info('Print agent pairing completed', [
                'pairing_id' => $locked->id,
                'print_agent_id' => $agent->id,
                'tenant_id' => $locked->tenant_id,
                'branch_id' => $locked->branch_id,
            ]);

            return response()->json(['data' => [
                'status' => 'completed',
                'restaurant' => ['id' => $locked->tenant_id, 'name' => $locked->tenant?->name],
                'branch' => ['id' => $locked->branch_id, 'name' => $locked->branch?->name],
                'credentials' => [
                    'server_url' => $apiUrl,
                    'agent_id' => $agent->agent_id,
                    'agent_secret' => $agent->secret,
                    'branch_id' => (string) $agent->branch_id,
                    'mode' => filled($reverb['app_key'] ?? null) ? 'reverb' : 'polling',
                    'reverb_app_key' => $reverb['app_key'] ?? '',
                    'reverb_socket_url' => $reverb['socket_url'] ?? '',
                ],
            ]]);
        });
    }

    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user();
        abort_unless($user?->assignedToTenant(), 403, 'A restaurant account is required to connect an agent.');

        $branch = $user->effective_branch;
        abort_unless(
            $branch instanceof Branch && (int) $branch->tenant_id === (int) $user->tenantId(),
            422,
            'Your account does not have an active restaurant branch.'
        );

        $codeHash = $this->hashCode($data['code']);
        $result = DB::transaction(function () use ($codeHash, $user, $branch): array {
            $pairing = PrintAgentPairing::query()
                ->where('code_hash', $codeHash)
                ->lockForUpdate()
                ->first();

            abort_unless(
                $pairing && $pairing->status === 'pending' && $pairing->expires_at->isFuture(),
                422,
                'The pairing code is invalid or expired.'
            );

            $platform = $pairing->platform === 'android' ? 'android' : 'windows';
            $agent = PrintAgent::query()->create([
                'agent_id' => ($platform === 'android' ? 'and-' : 'win-').Str::lower(Str::random(24)),
                'branch_id' => $branch->id,
                'name' => ['en' => $pairing->device_name ?: ($platform === 'android' ? 'Android Print Agent' : 'Windows Agent')],
                'is_active' => true,
                'status' => 'pairing',
                'platform' => $platform,
                'machine_name' => $pairing->device_name,
                'version' => $pairing->agent_version,
            ]);

            $pairing->forceFill([
                'status' => 'claimed',
                'claimed_at' => now(),
                'claimed_by' => $user->id,
                'tenant_id' => $user->tenantId(),
                'branch_id' => $branch->id,
                'print_agent_id' => $agent->id,
            ])->save();

            return [$pairing, $agent];
        });

        [$pairing, $agent] = $result;
        Log::info('Print agent pairing claimed', [
            'pairing_id' => $pairing->id,
            'print_agent_id' => $agent->id,
            'tenant_id' => $pairing->tenant_id,
            'branch_id' => $pairing->branch_id,
            'claimed_by' => $user->id,
        ]);

        return response()->json(['data' => [
            'status' => 'connected',
            'restaurant' => ['id' => $user->tenantId(), 'name' => $user->tenant?->name],
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'computer' => $pairing->device_name,
            'agent_id' => $agent->agent_id,
        ]]);
    }

    private function uniqueCode(): array
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $hash = $this->hashCode($code);
            if (! PrintAgentPairing::query()->where('code_hash', $hash)->exists()) {
                return [$code, $hash];
            }
        }

        abort(503, 'A pairing code could not be generated. Please try again.');
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function assertChallengeToken(Request $request, PrintAgentPairing $pairing): void
    {
        $provided = (string) $request->header('X-Pairing-Token');
        abort_unless(
            $provided !== '' && hash_equals($pairing->challenge_token_hash, hash('sha256', $provided)),
            401,
            'Invalid pairing challenge.'
        );
    }
}
