<?php

namespace App\Http\Resources;

use App\Models\Generation;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Generation */
class GenerationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'estimated_cost' => Money::toDecimal($this->estimated_cost_nanos),
            'max_cost' => Money::toDecimal($this->max_cost_nanos),
            'cost' => $this->cost_nanos === null ? null : Money::toDecimal($this->cost_nanos),
            'usage' => $this->usage,
            'image_url' => $this->image_url,
            'image_url_expires_at' => $this->image_url_expires_at?->toIso8601ZuluString(),
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'submit_attempts' => $this->submit_attempts,
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
        ];
    }
}
