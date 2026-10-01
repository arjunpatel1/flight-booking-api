<?php
namespace Modules\Saas\Models;
use Modules\Support\Eloquent\Model;
class TenantServiceEvent extends Model {
    public $timestamps = false;
    protected $guarded = ['id'];
}
