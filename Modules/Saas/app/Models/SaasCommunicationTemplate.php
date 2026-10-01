<?php
namespace Modules\Saas\Models;
use Modules\Support\Eloquent\Model;
class SaasCommunicationTemplate extends Model {
    protected $fillable = ['name','key','title','message','channels','priority','is_active','created_by'];
    protected function casts(): array { return ['channels'=>'array','is_active'=>'boolean']; }
}
