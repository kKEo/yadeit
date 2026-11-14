<?php

namespace App\Http\Resources;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Workspace */
class WorkspaceResource extends JsonResource
{
    private ?WorkspaceRole $role = null;

    public function withRole(?WorkspaceRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'role' => $this->role?->value,
            'created_at' => $this->created_at,
        ];
    }
}
