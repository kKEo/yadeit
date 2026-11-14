<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Agents have no mailbox; their stored address is a placeholder.
            'email' => $this->isAgent() ? null : $this->email,
            'type' => $this->type->value,
            'locale' => $this->locale,
            'email_verified' => $this->hasVerifiedEmail(),
            'email_notifications' => $this->email_notifications,
            // Runs the installation; sees the operator area.
            'is_operator' => (bool) $this->is_operator,
            'created_at' => $this->created_at,
        ];
    }
}
