<?php

namespace App\Simulator;

use App\Simulator\Models\SimContentPack;
use RuntimeException;

/** The text a simulation is made of: people's names, team names, projects with items, comments, pages and feedback. */
final readonly class ContentPack
{
    /** @param  array<string, mixed>  $content */
    public function __construct(public array $content) {}

    /** The pack a run asks for; the built-in one when it names none. */
    public static function load(?int $id = null): self
    {
        if ($id !== null) {
            return new self((array) SimContentPack::query()->findOrFail($id)->content);
        }

        $json = file_get_contents(resource_path('simulator/packs/default.json'));
        $content = json_decode((string) $json, true);

        return new self(is_array($content) ? $content : throw new RuntimeException('The built-in content pack cannot be read.'));
    }

    /** @return list<mixed> */
    public function list(string $key): array
    {
        return array_values((array) ($this->content[$key] ?? []));
    }
}
