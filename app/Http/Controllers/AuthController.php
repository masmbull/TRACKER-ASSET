<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\EntraOAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login', [
            'entraEnabled' => app(EntraOAuthService::class)->enabled(),
        ]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (Auth::attempt($credentials)) {
            $user = Auth::user();

            if (! $user->is_active) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return back()->withErrors([
                    'email' => 'This account is deactivated. Contact your administrator.',
                ])->onlyInput('email');
            }

            $request->session()->regenerate();

            $this->logLogin($user, 'Local');

            return redirect()->intended('/');
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    /**
     * Start the Microsoft Entra ID authorization-code flow (PRD §12, §38).
     */
    public function microsoftRedirect(Request $request, EntraOAuthService $entra)
    {
        abort_unless($entra->enabled(), 404);

        $state = Str::random(32);
        $request->session()->put('entra_state', $state);

        return redirect()->away($entra->authorizationUrl($state));
    }

    public function microsoftCallback(Request $request, EntraOAuthService $entra)
    {
        abort_unless($entra->enabled(), 404);

        if (! $request->has('code') || ! hash_equals((string) $request->session()->pull('entra_state', ''), (string) $request->input('state'))) {
            abort(419, 'Invalid Microsoft login state. Please try again.');
        }

        $tokens = $entra->exchangeCode($request->input('code'));
        $profile = $entra->getUser($tokens['access_token']);
        $email = $entra->profileEmail($profile);

        abort_if($email === null, 422, 'Microsoft did not return an email address.');

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            // The directory is the source of truth (PRD §39): auto-provision as viewer.
            $user = User::create([
                'name' => $profile['displayName'] ?? $email,
                'email' => $email,
                'entra_id' => $profile['id'] ?? null,
                'department' => $profile['department'] ?? null,
                'job_title' => $profile['jobTitle'] ?? null,
                'role' => 'viewer',
                'is_active' => true,
            ]);
        } elseif ($user->entra_id === null) {
            $user->update(['entra_id' => $profile['id'] ?? null]);
        }

        if (! $user->is_active) {
            return redirect()->route('login')->withErrors([
                'email' => 'This account is deactivated. Contact your administrator.',
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        $this->logLogin($user, 'Microsoft Entra ID');

        return redirect()->intended('/');
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    private function logLogin(User $user, string $method): void
    {
        ActivityLog::create([
            'actor_id' => $user->id,
            'action' => 'LOGIN',
            'entity_type' => 'users',
            'entity_id' => $user->id,
            'entity_identifier' => $user->email,
            'source' => $method,
        ]);
    }
}
