<?php

namespace App\Enums;

enum LinkKind: string
{
    case PullRequest = 'pr';
    case Branch = 'branch';
    case Design = 'design';
    case Url = 'url';

    /** What a link most likely is, judged by its address, for when the caller does not say. */
    public static function guess(string $url): self
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        $on = fn (string ...$sites) => (bool) array_filter($sites, fn (string $site) => $host === $site || str_ends_with($host, '.'.$site));

        return match (true) {
            $on('figma.com', 'sketch.com', 'zeplin.io', 'framer.com', 'penpot.app', 'invisionapp.com', 'miro.com', 'canva.com') => self::Design,
            (bool) preg_match('#/(pull|pulls|merge_requests|pull-requests)/\d+#', $path) => self::PullRequest,
            $on('github.com', 'gitlab.com', 'bitbucket.org') && (bool) preg_match('#/(tree|branch|branches|src)/#', $path) => self::Branch,
            default => self::Url,
        };
    }
}
