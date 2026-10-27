<?php

declare(strict_types=1);

namespace Charlotte\Laravel;

use Charlotte\Charlotte;
use Charlotte\Http\Api;
use Charlotte\Http\ApiResponse;
use Charlotte\Http\Authorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The thin Laravel adapter over {@see Api}: size limit, JSON decoding, the
 * authenticated user and the host-resolved tenant. Nothing else lives here.
 */
final class CharlotteController
{
    public function __construct(private readonly Charlotte $charlotte, private readonly Authorizer $authorizer) {}

    public function open(Request $request): JsonResponse
    {
        return $this->respond($request, fn (Api $api, ?array $body) => $api->open($request->user(), $this->tenant($request), $body));
    }

    public function state(Request $request, string $session): JsonResponse
    {
        return $this->respond($request, fn (Api $api) => $api->state($request->user(), $this->tenant($request), $session));
    }

    public function events(Request $request, string $session): JsonResponse
    {
        $after = filter_var($request->query('after', '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $wait = filter_var($request->query('wait', '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($after === false || $wait === false) {
            return self::json(Api::error(422, 'invalid_request'));
        }

        return $this->respond($request, fn (Api $api) => $api->events($request->user(), $this->tenant($request), $session, $after, $wait));
    }

    public function message(Request $request, string $session): JsonResponse
    {
        return $this->respond($request, fn (Api $api, ?array $body) => $api->message($request->user(), $this->tenant($request), $session, $body));
    }

    public function stop(Request $request, string $session): JsonResponse
    {
        return $this->respond($request, fn (Api $api) => $api->stop($request->user(), $this->tenant($request), $session));
    }

    public function ops(Request $request, string $session): JsonResponse
    {
        return $this->respond($request, fn (Api $api, ?array $body) => $api->ops($request->user(), $this->tenant($request), $session, $body));
    }

    public function accept(Request $request, string $session, string $proposal): JsonResponse
    {
        return $this->respond($request, fn (Api $api) => $api->resolve($request->user(), $this->tenant($request), $session, $proposal, true));
    }

    public function reject(Request $request, string $session, string $proposal): JsonResponse
    {
        return $this->respond($request, fn (Api $api) => $api->resolve($request->user(), $this->tenant($request), $session, $proposal, false));
    }

    public function publish(Request $request, string $session): JsonResponse
    {
        return $this->respond($request, fn (Api $api, ?array $body) => $api->publish($request->user(), $this->tenant($request), $session, $body));
    }

    private function respond(Request $request, \Closure $call): JsonResponse
    {
        $max = (int) $this->charlotte->container()->make('config')->get('charlotte.routes.max_body_bytes', 262144);
        $content = (string) $request->getContent();
        if (strlen($content) > $max) {
            return self::json(Api::error(413, 'payload_too_large'));
        }
        $body = [];
        if (trim($content) !== '') {
            $decoded = json_decode($content, true, 64);
            $body = is_array($decoded) && (! array_is_list($decoded) || $decoded === []) ? $decoded : null;
        }

        return self::json($call(new Api($this->charlotte, $this->authorizer), $body));
    }

    private function tenant(Request $request): ?string
    {
        return $this->charlotte->tenantFor($request);
    }

    private static function json(ApiResponse $response): JsonResponse
    {
        return new JsonResponse($response->body, $response->status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
