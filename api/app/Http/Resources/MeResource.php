<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** 본인 정보. 본인에게만 성별·나이를 보여준다. 매너점수 수치는 비공개 */
class MeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nickname' => $this->nickname,
            'status' => $this->status,
            'gender' => $this->identity?->gender,
            'age' => $this->identity?->age(),
            'bio' => $this->profile?->bio,
            'interests' => $this->profile?->interests ?? [],
            'avatar' => $this->avatar?->parts,
            'avatar_version' => $this->avatar?->version,
            'has_location' => $this->region_cell !== null,
        ];
    }
}
