<?php

namespace App\Support;

use App\Enums\WorkspaceRole;
use App\Models\Membership;
use App\Models\Workspace;
use Closure;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The workspace the current request (or job) acts in. Tenant models read it
 * through BelongsToWorkspace: with no workspace set they return nothing,
 * so forgetting to scope a query fails closed instead of leaking data.
 */
class CurrentWorkspace
{
    private ?Workspace $workspace = null;

    private ?Membership $membership = null;

    private bool $unscoped = false;

    public function set(Workspace $workspace, ?Membership $membership = null): void
    {
        $this->workspace = $workspace;
        $this->membership = $membership;
    }

    public function clear(): void
    {
        $this->workspace = null;
        $this->membership = null;
    }

    public function get(): ?Workspace
    {
        return $this->workspace;
    }

    public function id(): ?int
    {
        return $this->workspace?->id;
    }

    public function role(): ?WorkspaceRole
    {
        return $this->membership?->role;
    }

    public function isUnscoped(): bool
    {
        return $this->unscoped;
    }

    /** Aborts with 403 unless the member holds at least the given role. */
    public function requireRole(WorkspaceRole $minimum): void
    {
        if (! $this->role()?->atLeast($minimum)) {
            throw new AccessDeniedHttpException('Your role in this workspace does not allow this.');
        }
    }

    /**
     * Runs system code (seeders, maintenance) across all workspaces.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runUnscoped(Closure $callback): mixed
    {
        $previous = $this->unscoped;
        $this->unscoped = true;

        try {
            return $callback();
        } finally {
            $this->unscoped = $previous;
        }
    }

    /**
     * Runs code as if inside the given workspace, then restores the previous one.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runIn(Workspace $workspace, Closure $callback): mixed
    {
        [$previousWorkspace, $previousMembership] = [$this->workspace, $this->membership];
        $this->set($workspace);

        try {
            return $callback();
        } finally {
            $this->workspace = $previousWorkspace;
            $this->membership = $previousMembership;
        }
    }
}
