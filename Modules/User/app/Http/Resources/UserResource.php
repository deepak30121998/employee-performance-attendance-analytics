<?php

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn () => $this->department->name),
            'designation' => $this->designation,
            'created_at' => $this->created_at,
        ];
    }
}
