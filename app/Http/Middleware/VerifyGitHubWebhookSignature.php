<?php

namespace App\Http\Middleware;

use App\Services\GitHub\WebhookSignatureVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyGitHubWebhookSignature
{
    public function __construct(private readonly WebhookSignatureVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Hub-Signature-256');

        if (! $this->verifier->verify($request->getContent(), is_string($signature) ? $signature : null)) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        return $next($request);
    }
}
