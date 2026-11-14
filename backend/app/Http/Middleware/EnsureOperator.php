<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The operator area exists only for operators: everyone else gets "not found", not "forbidden". */
class EnsureOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_operator || $user->isAgent()) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
