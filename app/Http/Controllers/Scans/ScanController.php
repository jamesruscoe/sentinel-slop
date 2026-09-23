<?php

namespace App\Http\Controllers\Scans;

use App\Enums\ScanStatus;
use App\Exceptions\ScanAlreadyRunningException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScanRequest;
use App\Models\Repository;
use App\Models\Scan;
use App\Scanning\Enums\TargetEditor;
use App\Services\Scanning\ScanDispatcher;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ScanController extends Controller
{
    public function __construct(private readonly ScanDispatcher $dispatcher) {}

    /**
     * Every scan the current user's installations cover, newest first.
     */
    public function index(Request $request): View
    {
        $scans = Scan::query()
            ->whereIn('repository_id', $request->user()->repositories()->select('repositories.id'))
            ->with('repository')
            ->latest('id')
            ->paginate(25);

        return view('scans.index', ['scans' => $scans, 'title' => 'Scan history']);
    }

    /**
     * Scans of one repository, newest first.
     */
    public function repository(Request $request, Repository $repository): View
    {
        Gate::authorize('view', $repository);

        return view('scans.index', [
            'scans' => $repository->scans()->with('repository')->latest('id')->paginate(25),
            'repository' => $repository,
            'title' => $repository->full_name,
        ]);
    }

    public function store(StoreScanRequest $request, Repository $repository): RedirectResponse
    {
        try {
            $scan = $this->dispatcher->dispatch($repository, $request->user(), $request->model());
        } catch (ScanAlreadyRunningException $e) {
            $running = $repository->scans()->whereIn('status', ScanStatus::activeValues())->latest('id')->first();

            return $running !== null
                ? redirect()->route('scans.show', $running)->with('error', $e->getMessage())
                : back()->with('error', $e->getMessage());
        }

        return redirect()->route('scans.show', $scan)->with('success', 'Scan queued.');
    }

    /**
     * The rules file as a download, named for the editor.
     */
    public function rules(Request $request, Scan $scan, string $editor): Response
    {
        Gate::authorize('view', $scan);

        $target = TargetEditor::tryFrom($editor) ?? abort(404);
        $file = $scan->rulesFiles()->where('target_editor', $target)->firstOrFail();

        return response($file->body, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.basename($file->filename).'"',
        ]);
    }

    /**
     * All prompts for an editor in one Markdown file.
     */
    public function prompts(Request $request, Scan $scan, string $editor): Response
    {
        Gate::authorize('view', $scan);

        $target = TargetEditor::tryFrom($editor) ?? abort(404);
        $prompts = $scan->prompts()->where('target_editor', $target)->orderBy('phase')->get();
        abort_if($prompts->isEmpty(), 404);

        $body = $prompts->map(fn ($p) => $p->body)->implode("\n\n---\n\n");
        $name = 'sentinel-slop-prompts-'.str_replace('_', '-', $target->value).'.md';

        return response($body, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }
}
