<?php
// phpunit_shim.php
// Minimal shim for PHPUnit\Framework\TestCase + functions used in provided tests.
// This file *must* be included BEFORE test files are loaded.

namespace PHPUnit\Framework;

class AssertionFailedError extends \Exception {}

class TestCase
{
    // PHPUnit TestCase shim: tests will extend this, but we don't need functionality.
    protected function setUp(): void {}
    protected function tearDown(): void {}
}

// simple assertion exception for runner
class AssertionFailedException extends \Exception {}

function fail(string $message = ''): void {
    throw new AssertionFailedException($message);
}

/* --- assertions used in tests --- */
function assertNotEmpty($actual, string $message = ''): void {
    if (empty($actual) && $actual !== "0") {
        throw new AssertionFailedException($message ?: 'Failed asserting that variable is not empty.');
    }
}

function assertNotNull($actual, string $message = ''): void {
    if ($actual === null) {
        throw new AssertionFailedException($message ?: 'Failed asserting that variable is not null.');
    }
}

function assertEquals($expected, $actual, string $message = ''): void {
    if ($expected != $actual) {
        throw new AssertionFailedException($message ?: 'Failed asserting that two values are equal. Expected: ' . var_export($expected, true) . ' Got: ' . var_export($actual, true));
    }
}

function assertTrue($actual, string $message = ''): void {
    if ($actual !== true) {
        throw new AssertionFailedException($message ?: 'Failed asserting that value is true.');
    }
}

function assertArrayNotHasKey($key, $array, string $message = ''): void {
    if (is_array($array) && array_key_exists($key, $array)) {
        throw new AssertionFailedException($message ?: 'Failed asserting that array does not have the key: ' . $key);
    }
}

function assertArrayHasKey($key, $array, string $message = ''): void {
    if (!is_array($array) || !array_key_exists($key, $array)) {
        throw new AssertionFailedException($message ?: 'Failed asserting that array has the key: ' . $key);
    }
}

/* --- very small hamcrest-ish helpers used in GivenGoPay --- */
function assertThat($actual, $matcher): void {
    if (is_callable($matcher)) {
        $ok = $matcher($actual);
    } else {
        $ok = ($actual == $matcher);
    }
    if (!$ok) throw new AssertionFailedException('assertThat failed.');
}

function is($expected) {
    return function($actual) use ($expected) {
        return $actual === $expected;
    };
}

function identicalTo($expected) {
    return function($actual) use ($expected) {
        return $actual === $expected;
    };
}