<?php

namespace ScoutElastic\Tests\Stubs;

use ScoutElastic\Searchable;
use Illuminate\Database\Eloquent\SoftDeletes;

class Model extends \Illuminate\Database\Eloquent\Model
{
    use Searchable;
    use SoftDeletes;

    public static function bootSearchable(): void
    {
        // do nothing
    }
}
