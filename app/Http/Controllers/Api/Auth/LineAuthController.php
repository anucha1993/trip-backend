<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class LineAuthController extends Controller
{
    public function redirect()
    {
        // setScopes() (not scopes(), which merges) — LINE's default provider scopes
        // include "email", which requires separate approval and causes a 400 from
        // LINE's authorize endpoint on channels that don't have it granted.
        return Socialite::driver('line')
            ->setScopes(['openid', 'profile'])
            ->redirect();
    }

    public function callback(Request $request)
    {
        $frontend = rtrim(config('app.frontend_url'), '/');

        try {
            $lineUser = Socialite::driver('line')->user();
        } catch (\Throwable $e) {
            Log::warning('LINE login failed: ['.get_class($e).'] '.$e->getMessage());

            // "invalid_grant" / "invalid authorization code" happens when the
            // one-time LINE auth code was already consumed before this request
            // — almost always caused by an in-app browser (e.g. LINE's own,
            // or another app's) pre-fetching the callback link in the
            // background before the user actually taps it.
            $isReusedCode = str_contains($e->getMessage(), 'invalid_grant')
                || str_contains($e->getMessage(), 'invalid authorization code');

            return redirect()->away(
                $frontend.'/login?error='.($isReusedCode ? 'code_reused' : 'line_auth_failed')
            );
        }

        $employee = Employee::updateOrCreate(
            ['line_user_id' => $lineUser->getId()],
            [
                'display_name' => $lineUser->getName() ?? 'LINE User',
                'picture_url' => $lineUser->getAvatar(),
                'email' => $lineUser->getEmail(),
                'last_login_at' => now(),
            ]
        );

        if (! $employee->is_active) {
            return redirect()->away($frontend.'/login?error=account_disabled');
        }

        $token = $employee->createToken('employee-app')->plainTextToken;

        return redirect()->away($frontend.'/auth/callback#token='.urlencode($token));
    }

    public function me(Request $request)
    {
        $employee = $request->user();

        return response()->json([
            'id' => $employee->id,
            'display_name' => $employee->display_name,
            'picture_url' => $employee->picture_url,
            'role' => $employee->role,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
