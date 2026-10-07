<?php

declare(strict_types=1);

namespace Dleno\DingTalk\Test;

use Dleno\DingTalk\Robot;
use PHPUnit\Framework\TestCase;

/**
 * 异常去重方式：默认按消息原文（兼容旧行为）；fingerprint 按异常类 + code + 位置 + 去除变量后的消息。
 */
class ExceptionDedupTest extends TestCase
{
    private function robot(?string $mode): Robot
    {
        $robot = (new \ReflectionClass(Robot::class))->newInstanceWithoutConstructor();
        if ($mode !== null) {
            (new \ReflectionProperty(Robot::class, 'exceptionDedup'))->setValue($robot, $mode);
        }
        return $robot;
    }

    private function text(Robot $robot, \Throwable $e): string
    {
        return (new \ReflectionMethod(Robot::class, 'getExceptionFrequencyText'))->invoke($robot, $e);
    }

    private function thrown(string $message): \RuntimeException
    {
        return new \RuntimeException($message, 7);
    }

    public function testDefaultKeepsTheOriginalMessageAsKey(): void
    {
        $robot = $this->robot(null);
        $e = new \RuntimeException("Duplicate entry '42' for key 'uniq'");
        self::assertSame($e->getMessage(), $this->text($robot, $e));
        self::assertSame($e->getMessage(), $this->text($this->robot(Robot::EXCEPTION_DEDUP_MESSAGE), $e));
    }

    public function testFingerprintMergesSameThrowPointWithDifferentVariables(): void
    {
        $robot = $this->robot(Robot::EXCEPTION_DEDUP_FINGERPRINT);
        $a = [];
        foreach (["SQLSTATE[23000]: Duplicate entry '42' for key 'uniq' (SQL: insert into t values (42, \"a\"))",
                     "SQLSTATE[23000]: Duplicate entry '1001' for key 'uniq' (SQL: insert into t values (1001, \"b\"))"] as $message) {
            $a[] = $this->text($robot, $this->thrown($message)); // 同一行抛出
        }
        self::assertSame($a[0], $a[1]);
        $timeouts = [];
        foreach ([5001, 5003] as $ms) {
            $timeouts[] = $this->text($robot, $this->thrown("cURL error 28: Operation timed out after {$ms} milliseconds"));
        }
        self::assertSame($timeouts[0], $timeouts[1]);
        self::assertNotSame($a[0], $timeouts[0], 'Different message structures stay apart');
    }

    public function testFingerprintSeparatesClassCodeAndLocation(): void
    {
        $robot = $this->robot(Robot::EXCEPTION_DEDUP_FINGERPRINT);
        $base = $this->text($robot, $this->thrown('Order 1 failed'));
        self::assertNotSame($base, $this->text($robot, new \LogicException('Order 1 failed', 7)));
        self::assertNotSame($base, $this->text($robot, new \RuntimeException('Order 1 failed', 8)));
        $other = new \RuntimeException('Order 1 failed', 7); // 不同行
        self::assertNotSame($base, $this->text($robot, $other));
    }

    public function testNormalizeMessage(): void
    {
        self::assertSame('Order # amount # token # ? ?', Robot::normalizeMessage("Order 123 amount 9.99 token 0123456789abcdef0123 'x' \"y\""));
        self::assertSame('a b', Robot::normalizeMessage("  a \n\t b "));
        $invalid = "bad \xB1\x31 utf8";
        self::assertSame($invalid, Robot::normalizeMessage($invalid), 'Regex failure keeps the original message');
    }
}
