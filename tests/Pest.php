<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Pest auto-binds the base TestCase. n5s/rangelog has no domain-specific
| TestCase (no Laravel app, no DB), so we leave this default.
|
*/

// uses(\PHPUnit\Framework\TestCase::class)->in('Unit'); // Pest 4 default — no override needed.

/*
|--------------------------------------------------------------------------
| Architecture Rules (pest-plugin-arch)
|--------------------------------------------------------------------------
|
| Architecture rules live in tests/Architecture/ArchTest.php so the
| phpunit.xml.dist Architecture testsuite picks them up and Pest 4
| actually runs them. Declaring arch() inside tests/Pest.php is a no-op:
| the file is the Pest bootstrapper, not a scanned test file.
|
*/

/*
|--------------------------------------------------------------------------
| Custom Expectations
|--------------------------------------------------------------------------
|
| None — domain VOs use Pest's stock expectations.
|
*/
