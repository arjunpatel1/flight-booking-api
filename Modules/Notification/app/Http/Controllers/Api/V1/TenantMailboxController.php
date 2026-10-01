<?php

namespace Modules\Notification\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Core\Http\Controllers\Controller;
use Modules\Notification\Jobs\SendTenantMailboxMessageJob;
use Modules\Notification\Models\TenantMailMessage;
use Modules\Notification\Models\TenantMailThread;
use Modules\Notification\Models\TenantMailAttachment;
use Modules\Notification\Services\TenantMailboxAttachmentService;
use Modules\Saas\Models\Tenant;
use Modules\Support\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class TenantMailboxController extends Controller
{
    public function syncDeliveryStatus(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $secret = (string) config('services.mailgun.secret');
        $domain = (string) config('services.mailgun.domain');
        abort_if($secret === '' || $domain === '', Response::HTTP_SERVICE_UNAVAILABLE, 'Mail delivery tracking is not configured.');

        $messages = TenantMailMessage::query()
            ->where('tenant_id', $tenantId)
            ->where('direction', 'outbound')
            ->whereNotNull('provider_message_id')
            ->whereIn('status', ['queued', 'sent', 'accepted'])
            ->latest()
            ->limit(20)
            ->get();
        $updated = 0;

        foreach ($messages as $message) {
            $response = Http::withBasicAuth('api', $secret)
                ->timeout(10)
                ->retry(2, 200)
                ->get('https://'.config('services.mailgun.endpoint', 'api.mailgun.net').'/v3/'.$domain.'/events', [
                    'message-id' => $message->provider_message_id,
                    'limit' => 10,
                ]);
            if (! $response->successful()) {
                continue;
            }

            $events = collect($response->json('items', []))->pluck('event')->map(fn ($event) => Str::lower((string) $event));
            $status = $events->contains('delivered') ? 'delivered'
                : ($events->contains(fn ($event) => in_array($event, ['failed', 'rejected'], true)) ? 'failed'
                    : ($events->contains('accepted') ? 'accepted' : null));
            if ($status && $message->status !== $status) {
                $message->update(['status' => $status]);
                $updated++;
            }
        }

        return ApiResponse::success(['checked' => $messages->count(), 'updated' => $updated]);
    }

    public function meta(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $tenant = Tenant::query()->withoutGlobalScopes()->whereKey($tenantId)->firstOrFail();
        $threads = TenantMailThread::query()->where('tenant_id', $tenantId);

        return ApiResponse::success([
            'address' => $tenant->slug.'@'.config('services.mailgun.inbound_domain', 'myteknoland.in'),
            'counts' => [
                'all' => (clone $threads)->count(),
                'inbox' => (clone $threads)->whereHas('messages', fn ($query) => $query
                    ->where('tenant_id', $tenantId)->where('direction', 'inbound'))->count(),
                'sent' => (clone $threads)->whereHas('messages', fn ($query) => $query
                    ->where('tenant_id', $tenantId)->where('direction', 'outbound'))->count(),
                'unread' => (clone $threads)->whereNull('read_at')->count(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $folder = $request->validate([
            'folder' => ['nullable', 'in:all,inbox,sent,unread'],
        ])['folder'] ?? 'inbox';
        $threads = TenantMailThread::query()->where('tenant_id', $tenantId)
            ->select('id', 'tenant_id', 'reference', 'subject', 'participant_email', 'last_message_at', 'read_at')
            ->withCount(['messages' => fn ($query) => $query->where('tenant_id', $tenantId)])
            ->with(['messages' => fn ($query) => $query
                ->where('tenant_id', $tenantId)
                ->select('id', 'tenant_id', 'thread_id', 'direction', 'body_text', 'status', 'created_at')
                ->latest()->limit(1)])
            ->when($folder === 'inbox', fn ($query) => $query->whereHas('messages', fn ($messages) => $messages
                ->where('tenant_id', $tenantId)->where('direction', 'inbound')))
            ->when($folder === 'sent', fn ($query) => $query->whereHas('messages', fn ($messages) => $messages
                ->where('tenant_id', $tenantId)->where('direction', 'outbound')))
            ->when($folder === 'unread' || $request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))
            ->when($request->filled('search'), fn ($query) => $query->where(function ($inner) use ($request) {
                $search = '%'.addcslashes((string) $request->input('search'), '%_').'%' ;
                $inner->where('subject', 'like', $search)->orWhere('participant_email', 'like', $search);
            }))
            ->orderByDesc('last_message_at')->paginate(min(50, max(10, (int) $request->input('per_page', 20))));

        return ApiResponse::pagination($threads);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $thread = TenantMailThread::query()->where('tenant_id', $this->tenantId($request))
            ->where('reference', $reference)->firstOrFail();
        $thread->update(['read_at' => now()]);

        return ApiResponse::success([
            'thread' => $thread,
            'messages' => $thread->messages()->with(['attachments' => fn ($query) => $query
                ->where('tenant_id', $thread->tenant_id)
                ->select('id', 'tenant_id', 'message_id', 'original_name', 'mime_type', 'size', 'status')])
                ->where('tenant_id', $thread->tenant_id)
                ->orderBy('created_at')->get(),
        ]);
    }

    public function send(Request $request, TenantMailboxAttachmentService $attachments): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'email:rfc', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:50000'],
            'body_html' => ['nullable', 'string', 'max:100000'],
            'thread_reference' => ['nullable', 'uuid'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240'],
        ]);
        $tenantId = $this->tenantId($request);
        $tenant = Tenant::query()->withoutGlobalScopes()->findOrFail($tenantId);
        $from = $tenant->slug.'@'.config('services.mailgun.inbound_domain', 'myteknoland.in');

        $message = DB::transaction(function () use ($data, $tenantId, $from) {
            $isReply = filled($data['thread_reference'] ?? null);
            $thread = $isReply
                ? TenantMailThread::query()->where('tenant_id', $tenantId)->where('reference', $data['thread_reference'])->lockForUpdate()->firstOrFail()
                : TenantMailThread::query()->create([
                    'tenant_id' => $tenantId,
                    'reference' => (string) Str::uuid(),
                    'subject' => trim($data['subject']),
                    'participant_email' => Str::lower($data['to']),
                    'last_message_at' => now(),
                    'read_at' => now(),
                ]);
            // A reply is always sent to the server-owned participant recorded on
            // the tenant-scoped thread. Never trust an altered recipient supplied
            // by a browser when continuing an existing conversation.
            $recipient = $isReply ? $thread->participant_email : Str::lower($data['to']);
            $thread->update(['last_message_at' => now(), 'read_at' => now()]);
            return $thread->messages()->create([
                'tenant_id' => $tenantId,
                'direction' => 'outbound',
                'from_address' => $from,
                'to_address' => $recipient,
                'body_text' => trim($data['body']),
                'body_html' => filled($data['body_html'] ?? null) ? trim($data['body_html']) : null,
                'status' => 'queued',
            ]);
        });

        foreach ($request->file('attachments', []) as $file) {
            $attachments->store($message, $file);
        }
        SendTenantMailboxMessageJob::dispatch($tenantId, $message->id);
        return ApiResponse::created(['message_id' => $message->id, 'status' => 'queued'], 'mail message');
    }

    public function updateReadState(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate(['read' => ['required', 'boolean']]);
        $thread = TenantMailThread::query()
            ->where('tenant_id', $this->tenantId($request))
            ->where('reference', $reference)
            ->firstOrFail();
        $thread->update(['read_at' => $data['read'] ? now() : null]);

        return ApiResponse::success(['reference' => $thread->reference, 'read_at' => $thread->read_at]);
    }

    public function downloadAttachment(Request $request, int $attachment): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $file = TenantMailAttachment::query()->where('tenant_id', $this->tenantId($request))
            ->whereKey($attachment)->where('status', 'accepted')
            ->whereHas('message', fn ($message) => $message
                ->where('tenant_id', $this->tenantId($request))
                ->whereHas('thread', fn ($thread) => $thread->where('tenant_id', $this->tenantId($request))))
            ->firstOrFail();
        abort_unless(Storage::disk($file->disk)->exists($file->path), Response::HTTP_NOT_FOUND);

        return Storage::disk($file->disk)->download($file->path, $file->original_name, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function tenantId(Request $request): int
    {
        $tenantId = $request->user()?->tenantId();
        abort_if(!$tenantId, Response::HTTP_FORBIDDEN);
        return (int) $tenantId;
    }
}
