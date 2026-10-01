<?php

namespace Modules\Printer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Printer\Models\PrintAgent;
use Modules\Printer\Services\AgentSignature\AgentSignatureServiceInterface;

class ValidateAgentSignature
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {

        $agentId = $request->header('X-Agent-ID');
        $signature = $request->header('X-Signature');
        $timestamp = (string) $request->header('X-Timestamp', '');
        $nonce = (string) $request->header('X-Request-Nonce', '');
        $requiresFreshSignature = $request->is('api/v1/agents/*/setup');

        if (!$agentId || !$signature) {
            return response()->json(['error' => 'Missing headers'], 401);
        }

        // Agent endpoints are intentionally unauthenticated at the user/session
        // layer. Resolve the device globally, then let its signed identity define
        // the tenant/branch boundary for the remainder of the request.
        $agent = PrintAgent::query()
            ->withoutGlobalActive()
            ->withOutGlobalBranchPermission()
            ->where('agent_id', $agentId)
            ->first();
        if (!$agent) {
            return response()->json(['error' => 'Agent not found'], 404);
        }

        if (! $agent->is_active) {
            return response()->json(['error' => 'Agent is inactive'], 403);
        }

        $routeAgentId = (string) $request->route('agent_id', '');
        if ($routeAgentId !== '' && ! hash_equals((string) $agent->agent_id, $routeAgentId)) {
            return response()->json(['error' => 'Agent identity mismatch'], 403);
        }

        // Verify signature. Agents sign the exact JSON body they send. Keep the
        // normalized payload fallback for older Laravel-based local workers.
        $payload = $request->all();
        $signatureService = app(AgentSignatureServiceInterface::class);
        $rawBody = $request->getContent();
        $hasFreshEnvelope = $timestamp !== '' || $nonce !== '';
        if ($requiresFreshSignature || $hasFreshEnvelope) {
            if (
                ! ctype_digit($timestamp)
                || abs(now()->timestamp - (int) $timestamp) > 300
                || ! preg_match('/^[A-Za-z0-9-]{16,100}$/', $nonce)
            ) {
                return response()->json(['error' => 'Expired or invalid request envelope'], 401);
            }

            $isValid = $signatureService->verifyFreshSignature(
                $agentId,
                $agent->secret,
                $rawBody,
                $timestamp,
                $nonce,
                $signature,
            );

            if ($isValid && ! Cache::add(
                'print-agent-request:'.hash('sha256', "{$agentId}:{$nonce}"),
                true,
                now()->addMinutes(6),
            )) {
                return response()->json(['error' => 'Request replay rejected'], 409);
            }
        } else {
            $isValid = $rawBody !== ''
                && $signatureService->verifyRawSignature($agentId, $agent->secret, $rawBody, $signature);

            if (!$isValid) {
                $isValid = $signatureService->verifySignature($agentId, $agent->secret, $payload, $signature);
            }
        }

        if (!$isValid) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $request->merge(['agent' => $agent]);

        return $next($request);
    }
}
