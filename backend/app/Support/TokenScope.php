<?php

namespace App\Support;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * How access is written into a token's abilities:
 * "read", optionally "write", and optionally one "project:<id>" per project
 * the token is limited to. No project entry means every project.
 */
class TokenScope
{
    public const READ = 'read';

    public const WRITE = 'write';

    private const PROJECT_PREFIX = 'project:';

    /**
     * @param  list<int>  $projectIds
     * @return list<string>
     */
    public static function abilities(bool $write, array $projectIds = []): array
    {
        return [
            self::READ,
            ...($write ? [self::WRITE] : []),
            ...array_map(fn (int $id) => self::PROJECT_PREFIX.$id, $projectIds),
        ];
    }

    /** @return list<int>|null null when the token is not limited to projects */
    public static function projectIds(PersonalAccessToken $token): ?array
    {
        $ids = [];

        foreach ((array) $token->abilities as $ability) {
            if (is_string($ability) && str_starts_with($ability, self::PROJECT_PREFIX)) {
                $ids[] = (int) substr($ability, strlen(self::PROJECT_PREFIX));
            }
        }

        return $ids === [] ? null : $ids;
    }

    public static function canWrite(PersonalAccessToken $token): bool
    {
        return $token->can(self::WRITE);
    }
}
