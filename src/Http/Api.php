<?php

declare(strict_types=1);

namespace Charlotte\Http;

use Charlotte\Autonomy;
use Charlotte\Brief\Brief;
use Charlotte\Brief\InvalidBrief;
use Charlotte\Charlotte;
use Charlotte\CharlotteSession;
use Charlotte\Policy\Refused;
use Closure;
use FancyCms\Document\PageJson;
use FancyCms\Exceptions\PageSessionException;
use Illuminate\Database\Eloquent\Model;
use stdClass;
use ValueError;

/**
 * The transport contract, without a framework: each method takes the
 * authenticated principal, the tenant the host resolved, and the decoded body,
 * and returns a status plus JSON. The Laravel controller kit is a thin adapter
 * over this; a host with its own controllers can call it the same way.
 *
 * Order of checks on every route: tenant resolved, principal present, session
 * found IN THAT TENANT (a session elsewhere is 404, indistinguishable from a
 * missing one), then the authorization hook for the ability.
 */
final class Api
{
    public function __construct(private readonly Charlotte $charlotte, private readonly Authorizer $authorizer) {}

    /** POST /sessions {page, brief, autonomy?} -> 201 {session, cursor} */
    public function open(mixed $principal, ?string $tenant, ?array $body): ApiResponse
    {
        if (($refused = $this->gate($principal, $tenant)) !== null) {
            return $refused;
        }
        if (! $this->closed($body, ['page', 'brief'], ['autonomy']) || ! is_string($body['page']) || ! is_array($body['brief'])
            || (isset($body['autonomy']) && ! is_string($body['autonomy']))) {
            return self::error(422, 'invalid_request');
        }
        if (! $this->authorizer->allows($principal, 'open', ['tenant' => $tenant, 'page' => $body['page'], 'session' => null])) {
            return self::error(403, 'forbidden');
        }

        return $this->guard(function () use ($principal, $tenant, $body): ApiResponse {
            $pending = $this->charlotte->for($principal)->tenant($tenant)->page($body['page'])->brief(Brief::fromArray($body['brief']));
            if (isset($body['autonomy'])) {
                $pending->autonomy($body['autonomy']);
            }
            $session = $pending->open();

            return new ApiResponse(201, ['session' => $session->id(), 'cursor' => $session->state()['cursor']]);
        });
    }

    /** GET /sessions/{s} */
    public function state(mixed $principal, ?string $tenant, string $id): ApiResponse
    {
        return $this->withSession($principal, $tenant, $id, 'view', fn (CharlotteSession $s): ApiResponse => new ApiResponse(200, self::wire($s->state())));
    }

    /** GET /sessions/{s}/events?after=&wait= -> {events, cursor} */
    public function events(mixed $principal, ?string $tenant, string $id, int $after, int $wait): ApiResponse
    {
        return $this->withSession($principal, $tenant, $id, 'view', function (CharlotteSession $s) use ($after, $wait): ApiResponse {
            $page = $s->events(max(0, $after), max(0, $wait));
            $page['events'] = array_map(function (array $event): array {
                if ($event['data'] === []) {
                    $event['data'] = new stdClass;
                }

                return $event;
            }, $page['events']);

            return new ApiResponse(200, $page);
        });
    }

    /** POST /sessions/{s}/messages {text} -> 202 {cursor} */
    public function message(mixed $principal, ?string $tenant, string $id, ?array $body): ApiResponse
    {
        return $this->withSession($principal, $tenant, $id, 'prompt', function (CharlotteSession $s) use ($principal, $body): ApiResponse {
            if (! $this->closed($body, ['text']) || ! is_string($body['text'])) {
                return self::error(422, 'invalid_request');
            }

            return new ApiResponse(202, ['cursor' => $s->send($body['text'], self::actor($principal))]);
        });
    }

    /** POST /sessions/{s}/stop -> 202 */
    public function stop(mixed $principal, ?string $tenant, string $id): ApiResponse
    {
        return $this->withSession($principal, $tenant, $id, 'prompt', function (CharlotteSession $s) use ($principal): ApiResponse {
            $s->stop(self::actor($principal));

            return new ApiResponse(202, ['status' => $s->record()->status]);
        });
    }

    /** POST /sessions/{s}/ops {ops, basedOn} -> 200 {revision} | 409 {error, snapshot} */
    public function ops(mixed $principal, ?string $tenant, string $id, ?array $body): ApiResponse
    {
        return $this->withSession($principal, $tenant, $id, 'edit', function (CharlotteSession $s) use ($principal, $body): ApiResponse {
            if (! $this->closed($body, ['ops', 'basedOn']) || ! is_array($body['ops']) || ! array_is_list($body['ops'])
                || count($body['ops']) > 100 || ! is_string($body['basedOn'])) {
                return self::error(422, 'invalid_request');
            }
            $result = $s->applyHuman($body['ops'], $body['basedOn'], self::actor($principal));

            return new ApiResponse(200, ['revision' => $result['revision']]);
        });
    }

    /** POST /sessions/{s}/proposals/{p}/accept|reject */
    public function resolve(mixed $principal, ?string $tenant, string $id, string $proposal, bool $accept): ApiResponse
    {
        return $this->withSession($principal, $tenant, $id, 'approve', fn (CharlotteSession $s): ApiResponse => new ApiResponse(200,
            $accept ? $s->approve($proposal, self::actor($principal)) : $s->reject($proposal, self::actor($principal))));
    }

    /** POST /sessions/{s}/publish {basedOn} */
    public function publish(mixed $principal, ?string $tenant, string $id, ?array $body): ApiResponse
    {
        return $this->withSession($principal, $tenant, $id, 'publish', function (CharlotteSession $s) use ($principal, $body): ApiResponse {
            if (! $this->closed($body, ['basedOn']) || ! is_string($body['basedOn'])) {
                return self::error(422, 'invalid_request');
            }

            return new ApiResponse(200, $s->publish($body['basedOn'], self::actor($principal)));
        });
    }

    /** @param Closure(CharlotteSession): ApiResponse $action */
    private function withSession(mixed $principal, ?string $tenant, string $id, string $ability, Closure $action): ApiResponse
    {
        if (($refused = $this->gate($principal, $tenant)) !== null) {
            return $refused;
        }
        $session = $this->charlotte->find($id, $tenant);
        if ($session === null) {
            return self::error(404, 'not_found');
        }
        $record = $session->record();
        if (! $this->authorizer->allows($principal, $ability, ['tenant' => $record->tenant, 'page' => $record->page, 'session' => $record->id])) {
            return self::error(403, 'forbidden');
        }

        return $this->guard(fn (): ApiResponse => $action($session), $session);
    }

    private function gate(mixed $principal, ?string $tenant): ?ApiResponse
    {
        if ($tenant === null || $tenant === '') {
            return self::error(403, 'tenant_unresolved');
        }
        if (! $principal instanceof Model || $principal->getKey() === null) {
            return self::error(403, 'forbidden');
        }

        return null;
    }

    /** Expected refusals become codes; anything else propagates to the host's handler. */
    private function guard(Closure $action, ?CharlotteSession $session = null): ApiResponse
    {
        try {
            return $action();
        } catch (InvalidBrief $e) {
            return self::error(422, 'invalid_brief', ['path' => $e->path]);
        } catch (ValueError) {
            return self::error(422, 'invalid_request');
        } catch (Refused $e) {
            return match ($e->reason) {
                'session_missing', 'proposal_missing', 'page_missing' => self::error(404, $e->reason === 'session_missing' ? 'not_found' : $e->reason),
                'turn_in_progress', 'proposal_resolved', 'stopped' => self::error(409, $e->reason),
                default => self::error(422, $e->reason),
            };
        } catch (PageSessionException $e) {
            if ($e->getErrorCode() === 'surface_changed_under_you' && $session !== null) {
                $state = $session->state();

                return self::error(409, 'surface_changed_under_you', ['snapshot' => self::wire(['doc' => $state['doc'], 'revision' => $state['revision']])]);
            }
            if ($e->getErrorCode() === 'invalid_state') {
                throw $e;
            }

            return self::error(422, $e->getErrorCode(), ['errors' => $e->getErrors()]);
        }
    }

    private function closed(?array $body, array $required, array $optional = []): bool
    {
        return $body !== null && array_diff($required, array_keys($body)) === []
            && array_diff(array_keys($body), [...$required, ...$optional]) === [];
    }

    private static function actor(mixed $principal): string
    {
        return $principal instanceof Model ? (string) $principal->getKey() : 'anonymous';
    }

    /** Documents in fancy-cms wire form, so {} props stay objects. */
    private static function wire(array $state): array
    {
        if (isset($state['doc'])) {
            $state['doc'] = json_decode(PageJson::encodeDocument($state['doc']), false, 128, JSON_THROW_ON_ERROR);
        }
        if (isset($state['proposal']['doc'])) {
            $state['proposal']['doc'] = json_decode(PageJson::encodeDocument($state['proposal']['doc']), false, 128, JSON_THROW_ON_ERROR);
        }

        return $state;
    }

    public static function error(int $status, string $code, array $extra = []): ApiResponse
    {
        $error = ['code' => $code];
        if (isset(Refused::MESSAGES[$code])) {
            $error['message'] = Refused::MESSAGES[$code];
        }
        if (isset($extra['path'])) {
            $error['path'] = $extra['path'];
            unset($extra['path']);
        }
        if (isset($extra['errors'])) {
            $error['errors'] = $extra['errors'];
            unset($extra['errors']);
        }

        return new ApiResponse($status, ['error' => $error, ...$extra]);
    }
}
