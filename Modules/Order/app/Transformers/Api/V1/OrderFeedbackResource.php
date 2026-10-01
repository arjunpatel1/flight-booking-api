<?php

namespace Modules\Order\Transformers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Models\OrderFeedback;

/** @mixin OrderFeedback */
class OrderFeedbackResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'branch_id' => $this->branch_id,
            'customer_id' => $this->customer_id,
            'order_reference' => $this->whenLoaded('order', fn() => $this->order?->reference_no),
            'order_number' => $this->whenLoaded('order', fn() => $this->order?->order_number),
            'customer_name' => $this->whenLoaded('customer', fn() => $this->customer?->name),
            'customer_phone' => $this->whenLoaded('customer', fn() => $this->customer?->phone),
            'branch_name' => $this->whenLoaded('branch', fn() => $this->branch?->name),
            'rating' => $this->rating,
            'tags' => $this->tags ?: [],
            'tags_label' => collect($this->tags ?: [])->map(fn($tag) => __("order::orders.feedback_page.tags.{$tag}"))->implode(', '),
            'comment' => $this->comment,
            'source' => $this->source,
            'submitted_at' => dateTimeFormat($this->submitted_at),
        ];
    }
}
