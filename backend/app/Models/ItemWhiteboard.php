<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A drawing that describes a work item: an Excalidraw scene, a picture of it,
 * and the words on it.
 *
 * @property array<string, mixed> $scene
 */
#[Fillable(['workspace_id', 'work_item_id', 'scene', 'preview_svg', 'text', 'version', 'updated_by'])]
class ItemWhiteboard extends Model
{
    use BelongsToWorkspace;

    /** Most text that is kept from one board. */
    private const TEXT_LIMIT = 20000;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['scene' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The words written on a board, in drawing order: text elements, and the
     * labels of shapes and arrows (which Excalidraw also keeps as text elements).
     *
     * @param  array<int, mixed>  $elements
     */
    public static function textOf(array $elements): ?string
    {
        $lines = [];

        foreach ($elements as $element) {
            if (is_array($element) && ($element['type'] ?? null) === 'text' && is_string($element['text'] ?? null) && trim($element['text']) !== '') {
                $lines[] = trim($element['text']);
            }
        }

        return $lines === [] ? null : mb_substr(implode("\n", $lines), 0, self::TEXT_LIMIT);
    }

    /**
     * Attachments of the item that are pictures on the board.
     *
     * @return list<int>
     */
    public function attachmentIds(): array
    {
        return array_values(array_map(
            fn (mixed $file) => (int) (is_array($file) ? ($file['attachment_id'] ?? 0) : 0),
            (array) ($this->scene['files'] ?? []),
        ));
    }

    /**
     * What an item says about its board without carrying the drawing.
     *
     * @return array<string, mixed>
     */
    public function reference(): array
    {
        return ['version' => $this->version, 'text' => $this->text, 'updated_at' => $this->updated_at];
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            ...$this->reference(),
            'scene' => $this->scene,
            // Untrusted markup: show it as an image, never put it into a page as HTML.
            'preview_svg' => $this->preview_svg,
            'updated_by' => $this->editor ? ['id' => $this->editor->id, 'name' => $this->editor->name] : null,
        ];
    }
}
