<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

class GitHubAuthController extends Controller
{
    public function redirect(): SymfonyRedirect
    {
        return Socialite::driver('github')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $githubUser = Socialite::driver('github')->user();
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('home')->with('error', 'GitHub sign-in failed. Please try again.');
        }

        $user = User::query()->updateOrCreate(
            ['github_id' => (int) $githubUser->getId()],
            [
                'username' => (string) $githubUser->getNickname(),
                'name' => $githubUser->getName(),
                'email' => $githubUser->getEmail(),
                'avatar_url' => $githubUser->getAvatar(),
            ],
        );

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
