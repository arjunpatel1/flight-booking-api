<?php

namespace Modules\Saas\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\Controller;
use Modules\Notification\Models\NotificationLog;
use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Services\NotificationDispatcherService;
use Modules\Support\ApiResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Saas\Models\SaasCommunicationTemplate;
use Modules\Saas\Models\SaasCommunicationCampaign;

class SaasCommunicationController extends Controller
{
    public function index(): JsonResponse
    {
        $query = NotificationLog::query()->whereIn('type', ['saas_tenant_message', 'saas_onboarding_invite', 'saas_onboarding_credentials', 'saas_company_mail']);
        $logs = (clone $query)->latest()->limit(100)->get();

        return ApiResponse::success([
            'summary' => [
                'total' => (clone $query)->count(),
                'sent' => (clone $query)->where('status', 'sent')->count(),
                'failed' => (clone $query)->where('status', 'failed')->count(),
                'processing' => (clone $query)->whereIn('status', ['pending', 'processing'])->count(),
            ],
            'channels' => (clone $query)->selectRaw('channel, status, COUNT(*) as total')->groupBy('channel', 'status')->get(),
            'recent' => $logs->map(fn (NotificationLog $log) => [
                'id' => $log->id, 'type' => $log->type, 'channel' => $log->channel->value,
                'recipient' => $log->recipient, 'status' => $log->status->value,
                'title' => data_get($log->payload, 'title'), 'message' => data_get($log->payload, 'message'),
                'tenant_id' => data_get($log->payload, 'tenant_id'), 'error_message' => $log->error_message,
                'queued_at' => $log->queued_at?->toIso8601String(), 'sent_at' => $log->sent_at?->toIso8601String(),
                'failed_at' => $log->failed_at?->toIso8601String(), 'created_at' => $log->created_at?->toIso8601String(),
            ])->values(),
            'templates' => SaasCommunicationTemplate::query()->latest()->get(),
            'campaigns' => SaasCommunicationCampaign::query()->latest()->limit(50)->get(),
            'company_mail_senders' => collect(config('saas.company_mail.senders', []))
                ->map(fn (array $sender, string $key) => ['key' => $key, 'name' => $sender['name'], 'address' => $sender['address']])
                ->values(),
            'company_mailbox' => $this->companyMailbox($logs),
        ]);
    }

    public function sendCompanyMail(Request $request, NotificationDispatcherService $dispatcher): JsonResponse
    {
        $senders = collect(config('saas.company_mail.senders', []));
        $data = $request->validate([
            'sender' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) use ($senders) {
                if (! $senders->has($value)) $fail('The selected sender profile is invalid.');
            }],
            'recipients' => ['required', 'array', 'min:1', 'max:50'],
            'recipients.*' => ['required', 'email:rfc', 'max:254'],
            'subject' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:50000'],
            'message_html' => ['nullable', 'string', 'max:100000'],
            'thread_reference' => ['nullable', 'uuid'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,png,jpg,jpeg,webp,doc,docx,xls,xlsx,csv,txt'],
        ]);

        $sender = $senders->get($data['sender']);
        $recipients = collect($data['recipients'])->map(fn ($email) => strtolower(trim($email)))->unique()->values();
        $replyLog = filled($data['thread_reference'] ?? null)
            ? NotificationLog::query()->where('type', 'saas_company_mail')->where('payload->thread_reference', $data['thread_reference'])->first()
            : null;
        if (filled($data['thread_reference'] ?? null) && ! $replyLog) {
            throw ValidationException::withMessages(['thread_reference' => 'The selected mail conversation no longer exists.']);
        }
        if ($replyLog && ($recipients->count() !== 1 || $recipients->first() !== strtolower($replyLog->recipient))) {
            throw ValidationException::withMessages(['recipients' => 'Replies must use the original conversation recipient.']);
        }

        $storedAttachments = collect($request->file('attachments', []))->map(function ($file) {
            $path = $file->store('saas-company-mail/'.now()->format('Y/m'), 'local');
            return ['disk' => 'local', 'path' => $path, 'name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType(), 'size' => $file->getSize()];
        })->values()->all();

        $logs = collect();
        try {
            foreach ($recipients as $recipient) {
                $payload = [
                    'title' => trim($data['subject']),
                    'message' => trim($data['message']),
                    'message_html' => $this->sanitizeHtml((string) ($data['message_html'] ?? '')),
                    'from_address' => $sender['address'],
                    'from_name' => $sender['name'],
                    'sent_by' => $request->user()?->id,
                    'thread_reference' => $data['thread_reference'] ?? (string) Str::uuid(),
                    'restaurant_name' => config('app.name', 'NexDine'),
                    'logo_url' => config('saas.company_mail.logo_url') ?: rtrim((string) config('app.url'), '/').'/logo.svg',
                    'primary_color' => config('saas.company_mail.primary_color'),
                    'attachments' => $storedAttachments,
                    'read_at' => now()->toIso8601String(),
                ];
                $logs->push(...$dispatcher->dispatch('saas_company_mail', $recipient, $payload, [NotificationChannel::Email]));
            }
        } finally {
            foreach ($storedAttachments as $attachment) Storage::disk($attachment['disk'])->delete($attachment['path']);
        }

        activity('saas_communication')->event('company_mail_sent')->causedBy($request->user())
            ->withProperties(['sender' => $sender['address'], 'recipient_count' => $recipients->count(), 'log_ids' => $logs->pluck('id')->all()])
            ->log('Company mail sent from the NexDine control console.');

        return ApiResponse::success([
            'logs' => $logs->map(fn (NotificationLog $log) => ['id' => $log->id, 'recipient' => $log->recipient, 'status' => $log->status->value])->values(),
        ], 'Company mail processed.');
    }

    public function companyMailThread(string $reference): JsonResponse
    {
        abort_unless(Str::isUuid($reference), 404);
        $logs = NotificationLog::query()->where('type', 'saas_company_mail')
            ->where('payload->thread_reference', $reference)->oldest()->get();
        abort_if($logs->isEmpty(), 404);

        return ApiResponse::success($this->threadPayload($logs));
    }

    public function updateCompanyMailReadState(Request $request, string $reference): JsonResponse
    {
        abort_unless(Str::isUuid($reference), 404);
        $data = $request->validate(['read' => ['required', 'boolean']]);
        $logs = NotificationLog::query()->where('type', 'saas_company_mail')
            ->where('payload->thread_reference', $reference)->get();
        abort_if($logs->isEmpty(), 404);
        foreach ($logs as $log) {
            $payload = $log->payload ?? [];
            $payload['read_at'] = $data['read'] ? now()->toIso8601String() : null;
            $log->forceFill(['payload' => $payload])->save();
        }

        return ApiResponse::success(null, $data['read'] ? 'Conversation marked as read.' : 'Conversation marked as unread.');
    }

    private function companyMailbox($logs): array
    {
        $mailLogs = $logs->where('type', 'saas_company_mail')->values();
        $threads = $mailLogs->groupBy(fn (NotificationLog $log) => data_get($log->payload, 'thread_reference') ?: 'legacy-'.$log->id)
            ->map(function ($threadLogs) {
                $ordered = $threadLogs->sortBy('created_at')->values();
                $last = $ordered->last();
                return [
                    ...$this->threadPayload($ordered)['thread'],
                    'messages' => $ordered->map(fn (NotificationLog $log) => $this->mailMessage($log))->values(),
                    'last_message_at' => $last?->created_at?->toIso8601String(),
                ];
            })->sortByDesc('last_message_at')->values();

        return [
            'counts' => ['inbox' => 0, 'sent' => $threads->count(), 'unread' => $threads->whereNull('read_at')->count(), 'all' => $threads->count()],
            'threads' => $threads,
        ];
    }

    private function threadPayload($logs): array
    {
        $first = $logs->first();
        return [
            'thread' => [
                'reference' => data_get($first->payload, 'thread_reference') ?: 'legacy-'.$first->id,
                'participant_email' => strtolower($first->recipient),
                'subject' => data_get($first->payload, 'title', 'Company mail'),
                'read_at' => data_get($logs->last()?->payload, 'read_at'),
            ],
            'messages' => $logs->map(fn (NotificationLog $log) => $this->mailMessage($log))->values(),
        ];
    }

    private function mailMessage(NotificationLog $log): array
    {
        return [
            'id' => $log->id,
            'direction' => 'outbound',
            'from_address' => data_get($log->payload, 'from_address'),
            'body_text' => data_get($log->payload, 'message'),
            'body_html' => data_get($log->payload, 'message_html'),
            'attachments' => collect(data_get($log->payload, 'attachments', []))->map(fn ($item) => ['name' => $item['name'] ?? 'Attachment', 'mime' => $item['mime'] ?? null, 'size' => $item['size'] ?? null])->values(),
            'status' => $log->status->value,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    private function sanitizeHtml(string $html): string
    {
        if (blank($html)) return '';
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,br,strong,b,em,i,u,ul,ol,li,a[href|target],h1,h2,h3,blockquote');
        $config->set('URI.DisableExternalResources', true);
        $config->set('URI.AllowedSchemes', ['https' => true, 'mailto' => true]);
        $config->set('Attr.EnableID', false);
        return (new \HTMLPurifier($config))->purify($html);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data=$request->validate(['name'=>['required','string','max:120'],'key'=>['nullable','string','max:120'],'title'=>['required','string','max:160'],'message'=>['required','string','max:5000'],'channels'=>['required','array','min:1'],'channels.*'=>['in:in_app,email,whatsapp,push'],'priority'=>['nullable','in:low,normal,warning,critical'],'is_active'=>['nullable','boolean']]);
        $data['key']=$data['key']??Str::slug($data['name']).'-'.Str::lower(Str::random(5)); $data['created_by']=$request->user()?->id;
        $template=SaasCommunicationTemplate::query()->create($data);
        activity('saas_communication')->event('template_created')->causedBy($request->user())->performedOn($template)->log('Communication template created.');
        return ApiResponse::created($template,'Template created.');
    }

    public function storeCampaign(Request $request): JsonResponse
    {
        $data=$request->validate(['name'=>['required','string','max:160'],'template_id'=>['nullable','integer','exists:saas_communication_templates,id'],'tenant_ids'=>['required','array','min:1','max:500'],'tenant_ids.*'=>['integer','exists:tenants,id'],'title'=>['required','string','max:160'],'message'=>['required','string','max:5000'],'channels'=>['required','array','min:1'],'channels.*'=>['in:in_app,email,whatsapp,push'],'priority'=>['nullable','in:low,normal,warning,critical'],'scheduled_at'=>['required','date']]);
        $campaign=SaasCommunicationCampaign::query()->create([...$data,'status'=>'scheduled','created_by'=>$request->user()?->id]);
        activity('saas_communication')->event('campaign_scheduled')->causedBy($request->user())->performedOn($campaign)->withProperties(['scheduled_at'=>$campaign->scheduled_at?->toIso8601String(),'tenant_count'=>count($campaign->tenant_ids)])->log('Communication campaign scheduled.');
        return ApiResponse::created($campaign,'Campaign scheduled.');
    }

    public function retry(Request $request, NotificationLog $log, NotificationDispatcherService $dispatcher): JsonResponse
    {
        abort_unless(in_array($log->type, ['saas_tenant_message', 'saas_onboarding_invite', 'saas_onboarding_credentials', 'saas_company_mail'], true), 404);
        abort_unless($log->status->value === 'failed', 422, 'Only failed deliveries can be retried.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $retried = $dispatcher->dispatch($log->type, (string) $log->recipient, $log->payload ?? [], [$log->channel]);
        activity('saas_communication')->event('delivery_retried')->causedBy($request->user())->performedOn($log)
            ->withProperties(['reason' => $data['reason'], 'new_log_ids' => collect($retried)->pluck('id')->all()])->log('Failed tenant communication retried.');

        return ApiResponse::success(['logs' => collect($retried)->map(fn ($item) => ['id' => $item->id, 'status' => $item->status->value])->values()], 'Delivery retry completed.');
    }
}
