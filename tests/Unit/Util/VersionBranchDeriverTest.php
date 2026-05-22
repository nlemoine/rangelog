<?php

declare(strict_types=1);

use n5s\Rangelog\Util\VersionBranchDeriver;

it('is a final class', function (): void {
    $reflection = new ReflectionClass(VersionBranchDeriver::class);
    expect($reflection->isFinal())->toBeTrue();
});

it('derives [{major}.{minor}, {major}.x, {major}.{minor}.x] for "7.4.1" (Symfony case)', function (): void {
    expect(VersionBranchDeriver::deriveBranches('7.4.1'))->toBe(['7.4', '7.x', '7.4.x']);
});

it('derives [{major}.{minor}, {major}.x, {major}.{minor}.x] for "3.33.0" (flysystem case)', function (): void {
    expect(VersionBranchDeriver::deriveBranches('3.33.0'))->toBe(['3.33', '3.x', '3.33.x']);
});

it('strips pre-release suffix from "10.0.0-beta.1" via VersionParser::normalize', function (): void {
    expect(VersionBranchDeriver::deriveBranches('10.0.0-beta.1'))->toBe(['10.0', '10.x', '10.0.x']);
});

it('returns [] for non-semver date version "20231015"', function (): void {
    expect(VersionBranchDeriver::deriveBranches('20231015'))->toBe([]);
});

it('returns [] for empty input', function (): void {
    expect(VersionBranchDeriver::deriveBranches(''))->toBe([]);
});

it('returns [] for v-prefixed input "v7.4.1" — the post-normalize regex anchors at \d, so v prefix is rejected (existing contract preserved)', function (): void {
    // VersionParser::normalize("v7.4.1") returns "7.4.1.0" (strips v) but the
    // regex VERSION_PATTERN = /^(\d+)\.(\d+).../ runs against the ORIGINAL
    // $version string ("v7.4.1"), not the normalized form, so it fails the
    // anchor and the util returns []. This matches the verbatim behavior of
    // GitHubFileResolver::deriveVersionBranches() prior to extraction.
    expect(VersionBranchDeriver::deriveBranches('v7.4.1'))->toBe([]);
});

it('returns [] for path-traversal input "7.4/../etc/passwd"', function (): void {
    expect(VersionBranchDeriver::deriveBranches('7.4/../etc/passwd'))->toBe([]);
});
