<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Tests\DatabaseTestCase;
use Atannex\NwehMundani\Core\Tests\SpatieDatabasePerTenantTestCase;
use Atannex\NwehMundani\Core\Tests\SpatieTestCase;
use Atannex\NwehMundani\Core\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature');
pest()->extend(DatabaseTestCase::class)->in('Persistence');
pest()->extend(SpatieTestCase::class)->in('Tenancy/SharedDatabase');
pest()->extend(SpatieDatabasePerTenantTestCase::class)->in('Tenancy/DatabasePerTenant');

require_once __DIR__.'/helpers.php';
