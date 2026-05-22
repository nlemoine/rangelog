<?php

declare(strict_types=1);

use n5s\Rangelog\Exception\ChangelogException;
use n5s\Rangelog\Exception\ChangelogNotFoundException;
use n5s\Rangelog\Exception\FetchException;
use n5s\Rangelog\Exception\ParseException;
use n5s\Rangelog\Exception\RateLimitedException;
use n5s\Rangelog\Exception\UnsupportedPackageException;

dataset('subtypes', [
    'ChangelogNotFoundException' => [ChangelogNotFoundException::class],
    'FetchException'             => [FetchException::class],
    'RateLimitedException'       => [RateLimitedException::class],
    'ParseException'             => [ParseException::class],
    'UnsupportedPackageException' => [UnsupportedPackageException::class],
]);

it('subtype extends ChangelogException directly', function (string $class): void {
    expect(class_exists($class))->toBeTrue();
    if (! class_exists($class)) {
        return;
    }
    $reflection = new ReflectionClass($class);
    $parent = $reflection->getParentClass();
    expect($parent)->not->toBeFalse();
    expect($parent === false ? null : $parent->getName())
        ->toBe(ChangelogException::class);
})->with('subtypes');

it('subtype is final', function (string $class): void {
    expect(class_exists($class))->toBeTrue();
    if (! class_exists($class)) {
        return;
    }
    $reflection = new ReflectionClass($class);
    expect($reflection->isFinal())->toBeTrue();
})->with('subtypes');

it('subtype is throwable and catchable as itself', function (string $class): void {
    expect(is_subclass_of($class, Throwable::class))->toBeTrue();
    expect(function () use ($class): void {
        if (! is_subclass_of($class, Throwable::class)) {
            return;
        }
        throw new $class('test');
    })->toThrow($class);
})->with('subtypes');

it('catch matrix routes each subtype to its own handler', function (): void {
    /** @var array<class-string<ChangelogException>, string> $cases */
    $cases = [
        ChangelogNotFoundException::class => 'not-found',
        FetchException::class             => 'fetch',
        RateLimitedException::class       => 'rate-limited',
        ParseException::class             => 'parse',
        UnsupportedPackageException::class => 'unsupported',
    ];

    foreach ($cases as $class => $expected) {
        $caught = null;
        try {
            throw new $class('test');
        } catch (RateLimitedException) {
            $caught = 'rate-limited';
        } catch (ChangelogNotFoundException) {
            $caught = 'not-found';
        } catch (FetchException) {
            $caught = 'fetch';
        } catch (ParseException) {
            $caught = 'parse';
        } catch (UnsupportedPackageException) {
            $caught = 'unsupported';
        } catch (ChangelogException) {
            $caught = 'base';
        }
        expect($caught)->toBe($expected, "catch matrix routing failed for {$class}");
    }
});

it('every class in the Exception namespace is part of the hierarchy', function (): void {
    $exceptionDir = __DIR__ . '/../../../src/Exception';
    $files = glob($exceptionDir . '/*.php');
    expect($files)->not->toBeFalse();
    expect($files)->toHaveCount(6); // 1 abstract base + 5 final subtypes

    if ($files === false) {
        return; // expect() above already failed
    }

    foreach ($files as $file) {
        $shortName = basename($file, '.php');
        $className = 'n5s\\Rangelog\\Exception\\' . $shortName;
        expect(class_exists($className))->toBeTrue("Class {$className} should be autoloadable");
        if (! class_exists($className)) {
            continue;
        }
        $reflection = new ReflectionClass($className);
        // either is the base, or extends it
        expect(
            $reflection->getName() === ChangelogException::class
            || $reflection->isSubclassOf(ChangelogException::class),
        )->toBeTrue("{$className} must be ChangelogException itself or a subclass");
    }
});
