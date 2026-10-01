<?php
namespace Modules\Saas\Models;
use Modules\Support\Eloquent\Model;
class SaasCommunicationCampaign extends Model {
    protected $fillable = ['name','template_id','tenant_ids','title','message','channels','priority','status','scheduled_at','started_at','completed_at','error_message','delivery_count','created_by'];
    protected function casts(): array { return ['tenant_ids'=>'array','channels'=>'array','scheduled_at'=>'datetime','started_at'=>'datetime','completed_at'=>'datetime']; }
}
