<?php
/**
 * PHPUnit-lite runner — executes the repo's PHPUnit-style test classes with a
 * minimal TestCase shim when the real PHPUnit binary is unavailable in the
 * environment (no network / no phar).
 *
 * Usage:
 *   php tests/run-phpunit-lite.php tests/CategoryTemplatePoolTest.php [more files...]
 *
 * The shim implements the assertion subset the suite uses. It is guarded with
 * class_exists so it never interferes when real PHPUnit is present.
 */

declare(strict_types=1);

namespace PHPUnit\Framework {
    if (!class_exists(TestCase::class)) {
        class AssertionFailedError extends \Exception {}

        abstract class TestCase
        {
            public static int $assertions = 0;

            protected function setUp(): void {}
            protected function tearDown(): void {}

            private static function ok(bool $cond, string $message, string $detail): void
            {
                self::$assertions++;
                if (!$cond) {
                    throw new AssertionFailedError($message !== '' ? $message . ' — ' . $detail : $detail);
                }
            }

            public function assertTrue($v, string $m = ''): void { self::ok($v === true, $m, 'expected true, got ' . var_export($v, true)); }
            public function assertFalse($v, string $m = ''): void { self::ok($v === false, $m, 'expected false, got ' . var_export($v, true)); }
            public function assertSame($e, $a, string $m = ''): void { self::ok($e === $a, $m, 'expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
            public function assertNotSame($e, $a, string $m = ''): void { self::ok($e !== $a, $m, 'both are ' . var_export($a, true)); }
            public function assertEquals($e, $a, string $m = ''): void { self::ok($e == $a, $m, 'expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
            public function assertNotEquals($e, $a, string $m = ''): void { self::ok($e != $a, $m, 'both equal ' . var_export($a, true)); }
            public function assertNull($v, string $m = ''): void { self::ok($v === null, $m, 'expected null, got ' . var_export($v, true)); }
            public function assertNotNull($v, string $m = ''): void { self::ok($v !== null, $m, 'expected non-null'); }
            public function assertEmpty($v, string $m = ''): void { self::ok(empty($v), $m, 'expected empty, got ' . var_export($v, true)); }
            public function assertNotEmpty($v, string $m = ''): void { self::ok(!empty($v), $m, 'expected non-empty'); }
            public function assertCount(int $n, $v, string $m = ''): void { self::ok(is_countable($v) && count($v) === $n, $m, 'expected count ' . $n . ', got ' . (is_countable($v) ? count($v) : 'non-countable')); }
            public function assertContains($needle, $haystack, string $m = ''): void { self::ok(is_iterable($haystack) && in_array($needle, is_array($haystack) ? $haystack : iterator_to_array($haystack), true), $m, 'missing ' . var_export($needle, true)); }
            public function assertNotContains($needle, $haystack, string $m = ''): void { self::ok(!(is_iterable($haystack) && in_array($needle, is_array($haystack) ? $haystack : iterator_to_array($haystack), true)), $m, 'unexpectedly contains ' . var_export($needle, true)); }
            public function assertStringContainsString(string $needle, string $haystack, string $m = ''): void { self::ok($needle === '' || strpos($haystack, $needle) !== false, $m, 'missing substring ' . var_export($needle, true)); }
            public function assertStringNotContainsString(string $needle, string $haystack, string $m = ''): void { self::ok(strpos($haystack, $needle) === false, $m, 'unexpectedly contains ' . var_export($needle, true)); }
            public function assertStringContainsStringIgnoringCase(string $needle, string $haystack, string $m = ''): void { self::ok($needle === '' || stripos($haystack, $needle) !== false, $m, 'missing substring (ci) ' . var_export($needle, true)); }
            public function assertStringNotContainsStringIgnoringCase(string $needle, string $haystack, string $m = ''): void { self::ok(stripos($haystack, $needle) === false, $m, 'unexpectedly contains (ci) ' . var_export($needle, true)); }
            public function assertStringStartsWith(string $prefix, string $s, string $m = ''): void { self::ok(strncmp($s, $prefix, strlen($prefix)) === 0, $m, 'does not start with ' . var_export($prefix, true)); }
            public function assertStringEndsWith(string $suffix, string $s, string $m = ''): void { self::ok($suffix === '' || substr($s, -strlen($suffix)) === $suffix, $m, 'does not end with ' . var_export($suffix, true)); }
            public function assertIsArray($v, string $m = ''): void { self::ok(is_array($v), $m, 'expected array, got ' . gettype($v)); }
            public function assertIsString($v, string $m = ''): void { self::ok(is_string($v), $m, 'expected string, got ' . gettype($v)); }
            public function assertIsInt($v, string $m = ''): void { self::ok(is_int($v), $m, 'expected int, got ' . gettype($v)); }
            public function assertIsBool($v, string $m = ''): void { self::ok(is_bool($v), $m, 'expected bool, got ' . gettype($v)); }
            public function assertIsFloat($v, string $m = ''): void { self::ok(is_float($v), $m, 'expected float, got ' . gettype($v)); }
            public function assertIsCallable($v, string $m = ''): void { self::ok(is_callable($v), $m, 'expected callable'); }
            public function assertArrayHasKey($key, $arr, string $m = ''): void { self::ok((is_array($arr) || $arr instanceof \ArrayAccess) && isset($arr[$key]) || (is_array($arr) && array_key_exists($key, $arr)), $m, 'missing key ' . var_export($key, true)); }
            public function assertArrayNotHasKey($key, $arr, string $m = ''): void { self::ok(!(is_array($arr) && array_key_exists($key, $arr)), $m, 'unexpectedly has key ' . var_export($key, true)); }
            public function assertGreaterThan($e, $a, string $m = ''): void { self::ok($a > $e, $m, var_export($a, true) . ' not > ' . var_export($e, true)); }
            public function assertGreaterThanOrEqual($e, $a, string $m = ''): void { self::ok($a >= $e, $m, var_export($a, true) . ' not >= ' . var_export($e, true)); }
            public function assertLessThan($e, $a, string $m = ''): void { self::ok($a < $e, $m, var_export($a, true) . ' not < ' . var_export($e, true)); }
            public function assertLessThanOrEqual($e, $a, string $m = ''): void { self::ok($a <= $e, $m, var_export($a, true) . ' not <= ' . var_export($e, true)); }
            public function assertMatchesRegularExpression(string $p, string $s, string $m = ''): void { self::ok((bool) preg_match($p, $s), $m, 'no match for ' . $p); }
            public function assertDoesNotMatchRegularExpression(string $p, string $s, string $m = ''): void { self::ok(!preg_match($p, $s), $m, 'unexpected match for ' . $p); }
            public function assertInstanceOf(string $class, $v, string $m = ''): void { self::ok($v instanceof $class, $m, 'not an instance of ' . $class); }
            public function fail(string $m = ''): void { self::ok(false, $m, 'fail() called'); }
            public function markTestSkipped(string $m = ''): void { throw new SkippedTest($m); }
            public function expectException(string $class): void { $this->expectedException = $class; }
            public ?string $expectedException = null;
        }

        class SkippedTest extends \Exception {}
    }
}

namespace {
    error_reporting(E_ALL);

    // Mirror real PHPUnit: load the bootstrap configured in phpunit.xml
    // (tests/bootstrap/wordpress-stubs.php) BEFORE any test file, so the
    // per-file function_exists() guards skip their inline stub versions and
    // the namespaced WP-function stubs are available.
    $bootstrap = __DIR__ . '/bootstrap/wordpress-stubs.php';
    if (is_readable($bootstrap)) {
        require_once $bootstrap;
    }

    $files = array_slice($argv, 1);
    if (empty($files)) {
        fwrite(STDERR, "usage: php tests/run-phpunit-lite.php <TestFile.php> [...]\n");
        exit(2);
    }

    $total_pass = 0; $total_fail = 0; $total_skip = 0; $failures = [];

    foreach ($files as $file) {
        if (!is_readable($file)) { fwrite(STDERR, "unreadable: {$file}\n"); $total_fail++; continue; }
        $before = get_declared_classes();
        require_once $file;
        $new = array_diff(get_declared_classes(), $before);
        $test_classes = array_values(array_filter($new, static function (string $c): bool {
            return is_subclass_of($c, \PHPUnit\Framework\TestCase::class) && !(new ReflectionClass($c))->isAbstract();
        }));
        if (empty($test_classes)) {
            // File may declare the class conditionally/already loaded — scan all.
            foreach (get_declared_classes() as $c) {
                if (is_subclass_of($c, \PHPUnit\Framework\TestCase::class) && !(new ReflectionClass($c))->isAbstract()
                    && (new ReflectionClass($c))->getFileName() === realpath($file)) {
                    $test_classes[] = $c;
                }
            }
        }

        foreach ($test_classes as $class) {
            $ref = new ReflectionClass($class);
            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (strncmp($method->getName(), 'test', 4) !== 0) { continue; }
                $instance = $ref->newInstanceWithoutConstructor();
                $label = $class . '::' . $method->getName();
                try {
                    $setUp = $ref->getMethod('setUp'); $setUp->setAccessible(true); $setUp->invoke($instance);
                    $method->invoke($instance);
                    if (!empty($instance->expectedException)) {
                        throw new \PHPUnit\Framework\AssertionFailedError('expected exception ' . $instance->expectedException . ' was not thrown');
                    }
                    $total_pass++;
                    echo "  ok  {$label}\n";
                } catch (\PHPUnit\Framework\SkippedTest $e) {
                    $total_skip++;
                    echo "  skip {$label} — {$e->getMessage()}\n";
                } catch (\Throwable $e) {
                    if (!empty($instance->expectedException) && $e instanceof $instance->expectedException) {
                        $total_pass++;
                        echo "  ok  {$label} (expected exception)\n";
                        continue;
                    }
                    $total_fail++;
                    $failures[] = $label . ' — ' . $e->getMessage();
                    echo "  FAIL {$label} — {$e->getMessage()}\n";
                } finally {
                    $tearDown = $ref->getMethod('tearDown'); $tearDown->setAccessible(true); $tearDown->invoke($instance);
                }
            }
        }
    }

    echo str_repeat('=', 60) . "\n";
    echo 'PASS: ' . $total_pass . '  FAIL: ' . $total_fail . '  SKIP: ' . $total_skip
        . '  ASSERTIONS: ' . \PHPUnit\Framework\TestCase::$assertions . "\n";
    if ($total_fail > 0) {
        foreach ($failures as $f) { echo "  - {$f}\n"; }
        exit(1);
    }
    exit(0);
}
