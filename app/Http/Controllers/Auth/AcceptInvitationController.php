<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class AcceptInvitationController extends Controller
{
    /**
     * Signed + not expired (route middleware). Issues a reset token and sends the
     * user to the "set password" screen.
     */
    public function __invoke(Request $request, int $user, string $hash): RedirectResponse
    {
        $invited = User::query()->withoutGlobalScopes()->findOrFail($user);

        abort_unless(hash_equals(sha1($invited->email), $hash) && $invited->is_active, 403);

        if ($request->user() !== null) {
            auth()->guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $token = Password::broker()->createToken($invited);

        return redirect()->route('password.reset', ['token' => $token, 'email' => $invited->email]);
    }
}
