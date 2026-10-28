<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Tests\DatabaseTestCase;
use Atannex\NwehMundani\Core\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature');
pest()->extend(DatabaseTestCase::class)->in('Persistence');

require_once __DIR__.'/helpers.php';
