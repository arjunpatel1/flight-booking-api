<?php
namespace Modules\Saas\Models; use Modules\Support\Eloquent\Model;
class SaasBillingRefund extends Model { protected $fillable=['saas_billing_invoice_id','amount','currency','status','gateway_reference','reason','created_by']; protected function casts():array{return ['amount'=>'decimal:2'];} public function invoice(){return $this->belongsTo(SaasBillingInvoice::class,'saas_billing_invoice_id');} }
