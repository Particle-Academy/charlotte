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
 * @method static void dataCatalogue(\Charlotte\Data\DataCatalogue $catalogue)
 * @method static array dataQueries(array $doc, array $viewer, array $inputs = [], ?\Closure $descendants = null, array $data = [], ?\Closure $periods = null)
 * @method static array dataContext(array $results)
 * @method static \Charlotte\CharlotteSession delegate(\Illuminate\Database\Eloquent\Model $participant, string $parentScope, string $tenant, string $page, \Charlotte\Brief\Brief $brief, string $scopeSuffix = 'charlotte', ?\Charlotte\Autonomy $autonomy = null, ?string $provider = null, ?string $model = null, ?array $document = null)
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
