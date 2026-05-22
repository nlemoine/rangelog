<?php

declare(strict_types=1);

use n5s\Rangelog\Domain\SourceTypes;

it('declares GITHUB_RELEASES constant', function (): void {
    expect(SourceTypes::GITHUB_RELEASES)->toBe('github_releases');
});

it('declares GITHUB_FILE constant', function (): void {
    expect(SourceTypes::GITHUB_FILE)->toBe('github_file');
});

it('declares WORDPRESS_ORG constant', function (): void {
    expect(SourceTypes::WORDPRESS_ORG)->toBe('wordpress_org');
});

it('declares MARKDOWN_URL constant', function (): void {
    expect(SourceTypes::MARKDOWN_URL)->toBe('markdown_url');
});

it('is a final class', function (): void {
    $reflection = new ReflectionClass(SourceTypes::class);
    expect($reflection->isFinal())->toBeTrue();
});

it('has a private constructor (cannot be instantiated)', function (): void {
    $reflection = new ReflectionClass(SourceTypes::class);
    $ctor = $reflection->getConstructor();
    expect($ctor)->toBeInstanceOf(ReflectionMethod::class);
    if ($ctor === null) {
        // expect() above already failed; this guard is for static analysis
        return;
    }
    expect($ctor->isPrivate())->toBeTrue();
});
