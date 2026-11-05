<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
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
    ])
    ->withRootFiles()
    ->withImportNames(removeUnusedImports: true)
    ->withSets([
        LevelSetList::UP_TO_PHP_83,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        PHPUnitSetList::PHPUNIT_CODE_QUALITY,
    ])
    ->withComposerBased(phpunit: true)
    ->withCache(cacheDirectory: __DIR__ . '/.rector-cache');
