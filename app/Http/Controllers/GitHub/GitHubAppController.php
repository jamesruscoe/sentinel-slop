<?php

namespace App\Http\Controllers\GitHub;

use App\Http\Controllers\Controller;
use App\Services\GitHub\InstallationSyncService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class GitHubAppController extends Controller
{
    /**
     * Send the user to GitHub to install the app on the repos they choose.
     */
    public function install(): RedirectResponse
    {
        $slug = (string) config('services.github.app_slug');

        abort_if($slug === '', 503, 'The GitHub App is not configured.');

        return redirect()->away("https://github.com/apps/{$slug}/installations/new");
    }

    /**
     * GitHub's post-install "Setup URL" target.
     */
    public function setup(Request $request, InstallationSyncService $sync): RedirectResponse|View
    {
        $installationId = (int) $request->query('installation_id', 0);

        if ($installationId === 0) {
            return redirect()->route('dashboard');
        }

        if ($request->query('setup_action') === 'request') {
            return redirect()->route('dashboard')
                ->with('success', 'Installation requested. An organisation owner needs to approve it before repositories appear here.');
        }

        try {
            $installation = $sync->claimForUser($request->user(), $installationId);
        } catch (Throwable $e) {
            report($e);
            $installation = null;
        }

        if ($installation !== null) {
            return redirect()->route('dashboard')->with('success', "Connected {$installation->account_login}.");
        }

        return view('github.waiting', ['installationId' => $installationId]);
    }
}
