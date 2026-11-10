<?php

namespace App\Http\Resources;

use App\Support\Geo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 광장 카드. 좌표·정밀 거리는 내려주지 않고 distance_label만 준다.
 */
class PlazaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'category_label' => config('social.categories')[$this->category] ?? $this->category,
            'distance_label' => isset($this->distance_m) ? Geo::distanceLabel($this->distance_m) : null,
            'live_count' => $this->live_count ?? 0,
            'is_live' => ($this->live_count ?? 0) > 0,
            'capacity' => $this->capacity,
            'preview_avatars' => $this->preview_avatars ?? [],
            // 10분 안에 올라온 메시지만 '지금 하는 말'로 보여줌 (오래된 말풍선은 숨김)
            'last_message' => $this->last_message_at?->gt(now()->subMinutes(10)) ? $this->last_message_body : null,
            'is_host' => $request->user()?->id === $this->host_id,
            'status' => $this->status,
        ];
    }
}
