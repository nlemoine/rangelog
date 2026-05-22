<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/tests/Architecture/ArchTest.php',
        // PHPStan max can narrow string types from the typed test stubs
        // (ArrayLogger records, etc.), so adding (string) casts in tests/
        // creates `cast.useless` errors. Rule still useful in src/.
        NullToStrictStringFuncCallArgRector::class => [
            __DIR__ . '/tests',
        ],
    ])
    ->withRootFiles()
    ->withImportNames(removeUnusedImports: true)
    ->withSets([
        LevelSetList::UP_TO_PHP_83,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::TYPE_DECLARATION,
        PHPUnitSetList::PHPUNIT_120,
        PHPUnitSetList::PHPUNIT_CODE_QUALITY,
    ])
    ->withCache(cacheDirectory: __DIR__ . '/.rector-cache');
