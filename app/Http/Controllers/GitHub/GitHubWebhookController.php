<?php

namespace App\Http\Controllers\GitHub;

use App\Http\Controllers\Controller;
use App\Services\GitHub\WebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitHubWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookHandler $handler): JsonResponse
    {
        $event = $request->header('X-GitHub-Event');
        $delivery = $request->header('X-GitHub-Delivery');

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        $result = $handler->handle(
            is_string($event) ? $event : null,
            is_string($delivery) ? $delivery : null,
            $payload,
        );

        return response()->json(['status' => $result], $result === WebhookHandler::RESULT_OK ? 202 : 200);
    }
}
