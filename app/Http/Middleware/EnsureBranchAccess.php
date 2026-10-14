<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Services\BranchAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBranchAccess
{
    public function __construct(private BranchAccess $access) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $branch = $request->route('branch');

        if (! $branch instanceof Branch || ! $request->user() || ! $this->access->canAccess($request->user(), $branch)) {
            abort(403, 'You are not authorized to access this branch.');
        }

        return $next($request);
    }
}
