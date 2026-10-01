<?php
namespace Modules\Saas\Services\Communication;

use Modules\Notification\Enums\NotificationChannel;
use Modules\Notification\Services\NotificationDispatcherService;
use Modules\Saas\Models\SaasCommunicationCampaign;
use Modules\Saas\Models\Tenant;

class SaasCampaignService
{
    public function runDue(int $limit = 20): int
    {
        $campaigns = SaasCommunicationCampaign::query()->where('status','scheduled')->where('scheduled_at','<=',now())->orderBy('scheduled_at')->limit($limit)->get();
        foreach ($campaigns as $campaign) $this->dispatch($campaign);
        return $campaigns->count();
    }

    public function dispatch(SaasCommunicationCampaign $campaign): void
    {
        $campaign->update(['status'=>'processing','started_at'=>now(),'error_message'=>null]);
        try {
            $count = 0;
            $tenants = Tenant::query()->withoutGlobalScopes()->withoutGlobalActive()->whereIn('id',$campaign->tenant_ids)->get();
            foreach ($tenants as $tenant) foreach ($campaign->channels as $channelName) {
                $channel = match ($channelName) { 'email'=>NotificationChannel::Email, 'whatsapp'=>NotificationChannel::WhatsApp, 'push'=>NotificationChannel::Push, default=>NotificationChannel::InApp };
                $recipient = match ($channel) { NotificationChannel::Email=>$tenant->contact_email, NotificationChannel::WhatsApp=>$tenant->contact_phone, default=>'tenant:'.$tenant->id };
                if (! filled($recipient)) continue;
                app(NotificationDispatcherService::class)->dispatch('saas_tenant_message',(string)$recipient,['tenant_id'=>$tenant->id,'tenant'=>$tenant->name,'title'=>$campaign->title,'message'=>$campaign->message,'template'=>$campaign->template_id,'priority'=>$campaign->priority,'campaign_id'=>$campaign->id],[$channel]);
                $count++;
            }
            $campaign->update(['status'=>'completed','completed_at'=>now(),'delivery_count'=>$count]);
        } catch (\Throwable $error) { $campaign->update(['status'=>'failed','completed_at'=>now(),'error_message'=>$error->getMessage()]); report($error); }
    }
}
