<?php

declare(strict_types=1);

namespace Charlotte\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/** The host's participant model; the harness keys sessions by an Eloquent model. */
final class User extends Model
{
    protected $table = 'users';

    protected $guarded = [];
}
