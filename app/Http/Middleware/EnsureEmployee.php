<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmployee
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $employee = $request->user();

        if (! $employee instanceof Employee) {
            abort(403, 'Employee access required.');
        }

        if (! $employee->is_active) {
            abort(403, 'This employee account has been disabled.');
        }

        return $next($request);
    }
}
