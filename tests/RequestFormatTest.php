<?php

declare(strict_types=1);

namespace Dleno\DingTalk\Test;

use Dleno\DingTalk\Robot;
use Hyperf\HttpMessage\Server\Request as PsrRequest;
use PHPUnit\Framework\TestCase;

/**
 * WS 上下文里的 PSR-7 请求没有 all()；参数须从 query + body 合并，且 JSON 编码与 common-core array_to_json 一致。
 */
class RequestFormatTest extends TestCase
{
    private function call(string $method, ...$args)
    {
        $robot = (new \ReflectionClass(Robot::class))->newInstanceWithoutConstructor();
        return (new \ReflectionMethod(Robot::class, $method))->invoke($robot, ...$args);
    }

    public function testPsrRequestParamsMergeQueryAndParsedBody(): void
    {
        $request = (new PsrRequest('POST', 'http://localhost/ws?a=1'))
            ->withQueryParams(['a' => '1'])
            ->withParsedBody(['b' => '2']);

        self::assertSame(['a' => '1', 'b' => '2'], $this->call('getRequestParams', $request));
    }

    public function testPsrRequestWithoutArrayBody(): void
    {
        $request = (new PsrRequest('GET', 'http://localhost/ws'))->withQueryParams(['a' => '1']);

        self::assertSame(['a' => '1'], $this->call('getRequestParams', $request));
    }

    public function testToJsonKeepsUnicodeSlashesAndZeroFraction(): void
    {
        self::assertSame('{"msg":"中文/路径","n":1.0}', $this->call('toJson', ['msg' => '中文/路径', 'n' => 1.0]));
    }
}
