<?php

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Route;

/** Every `$ref` in the document, wherever it is nested. */
function collectRefs(mixed $node): array
{
    if (! is_array($node)) {
        return [];
    }

    $refs = isset($node['$ref']) && is_string($node['$ref']) ? [$node['$ref']] : [];

    foreach ($node as $child) {
        $refs = [...$refs, ...collectRefs($child)];
    }

    return $refs;
}

beforeEach(function () {
    $this->document = app(Generator::class)(Scramble::getGeneratorConfig('default'));
});

it('generates an OpenAPI 3.1 document that describes every API route', function () {
    $document = $this->document;

    expect($document['openapi'])->toStartWith('3.1')
        ->and($document['info']['title'])->toBe('Yadeit API')
        ->and($document['components']['securitySchemes'])->not->toBeEmpty();

    $documented = collect($document['paths'])
        ->flatMap(fn (array $operations, string $path) => collect($operations)->keys()
            ->map(fn (string $method) => strtoupper($method).' '.$path));

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1'))
        ->flatMap(fn ($route) => collect($route->methods())->reject(fn ($m) => $m === 'HEAD')
            ->map(fn ($m) => $m.' '.(substr($route->uri(), strlen('api/v1')) ?: '/')));

    expect($documented->sort()->values()->all())->toBe($routes->sort()->values()->all());
});

it('is internally consistent', function () {
    $document = $this->document;

    // Every reference points at something that exists.
    foreach (array_unique(collectRefs($document)) as $ref) {
        expect($ref)->toStartWith('#/');
        expect(data_get($document, str_replace('/', '.', substr($ref, 2))))->not->toBeNull("Dangling reference {$ref}");
    }

    foreach ($document['paths'] as $path => $operations) {
        preg_match_all('/\{(\w+)\}/', $path, $placeholders);

        foreach ($operations as $method => $operation) {
            expect($operation['responses'] ?? [])->not->toBeEmpty("{$method} {$path} has no responses");

            // Every {placeholder} in the path is declared as a path parameter.
            $declared = collect($operation['parameters'] ?? [])->where('in', 'path')->pluck('name')->all();
            expect($declared)->toEqualCanonicalizing($placeholders[1], "{$method} {$path} path parameters");
        }
    }
});

it('matches the exported file that is served to clients', function () {
    $exported = json_decode(file_get_contents(public_path('openapi.json')), true);

    // Run `php artisan scramble:export` after changing the API.
    expect(array_keys($exported['paths']))->toEqualCanonicalizing(array_keys($this->document['paths']));
});
