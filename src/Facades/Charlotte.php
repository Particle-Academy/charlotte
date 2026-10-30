<?php

declare(strict_types=1);

namespace Charlotte\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Charlotte\PendingCharlotteSession for(\Illuminate\Database\Eloquent\Model $participant)
 * @method static \Charlotte\CharlotteSession|null find(string $id, ?string $tenant = null)
 * @method static void guide(\Closure $override)
 * @method static \Charlotte\Guide\Guides guides()
 * @method static void authorizeUsing(\Closure $callback)
 * @method static void resolveTenantUsing(\Closure $resolver)
 * @method static void contextUsing(\Closure $factory)
 * @method static void routes(array $options = [])
 *
 * @see \Charlotte\Charlotte
 */
final class Charlotte extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Charlotte\Charlotte::class;
    }
}
