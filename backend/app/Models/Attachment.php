<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'work_item_id', 'uploaded_by', 'disk', 'path', 'name', 'mime_type', 'size'])]
class Attachment extends Model
{
    use BelongsToWorkspace;

    /** Types a browser may display in place; everything else is always downloaded. */
    public const INLINE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function isImage(): bool
    {
        return in_array($this->mime_type, self::INLINE_TYPES, true);
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'is_image' => $this->isImage(),
            'created_at' => $this->created_at,
        ];
    }
}
