<?php

declare(strict_types=1);

use n5s\Rangelog\Exception\ChangelogException;
use n5s\Rangelog\Exception\ChangelogNotFoundException;

it('is abstract', function (): void {
    $reflection = new ReflectionClass(ChangelogException::class);
    expect($reflection->isAbstract())->toBeTrue();
});

it('extends RuntimeException', function (): void {
    $reflection = new ReflectionClass(ChangelogException::class);
    $parentChain = [];
    $current = $reflection;
    while (false !== $parent = $current->getParentClass()) {
        $parentChain[] = $parent->getName();
        $current = $parent;
    }
    expect($parentChain)->toContain(RuntimeException::class);
});

it('catches a subtype as base ChangelogException', function (): void {
    $caught = false;
    try {
        throw new ChangelogNotFoundException('test');
    } catch (ChangelogException) {
        $caught = true;
    }
    expect($caught)->toBeTrue();
});

it('catches a subtype as \\Throwable', function (): void {
    $caught = false;
    try {
        throw new ChangelogNotFoundException('test');
    } catch (Throwable) {
        $caught = true;
    }
    expect($caught)->toBeTrue();
});
