<?php

declare(strict_types=1);

namespace Dleno\DingTalk\Test;

use Dleno\DingTalk\Robot;
use Hyperf\Config\Config;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * 相同消息去重：Redis 走原子 SET NX EX；Redis 异常时熔断并退化为进程内去重，熔断到期后自动重试 Redis。
 */
class FrequencyCacheTest extends TestCase
{
    /** @var object 模拟 Redis：实现 SET NX 语义，可切换为抛异常 */
    private object $redis;

    protected function setUp(): void
    {
        $this->redis = new class {
            public array $store = [];

            public bool $down = false;

            public array $calls = [];

            public array $evals = [];

            /** 模拟 ROBOT_FREQUENCY_LUA:INCRBY ARGV[2],无 TTL 时设 ARGV[1] */
            public function eval(string $script, array $args, int $numKeys): int
            {
                $this->evals[] = $args;
                if ($this->down) {
                    $this->fail();
                }
                if ($this->partialFails('eval')) {
                    throw new \RuntimeException('redis connection lost');
                }
                [$key, $ttl, $incr] = $args;
                $this->store[$key] = (int)($this->store[$key] ?? 0) + (int)$incr;
                $this->ttl[$key] ??= (int)$ttl;
                return $this->store[$key];
            }

            public array $ttl = [];

            /** 故障时先让出协程再抛异常,模拟真实网络 I/O 失败(期间其他协程会被调度) */
            public bool $yieldBeforeFail = false;

            /** 局部故障:每个方法前 N 次调用成功(且先让出更久),之后的调用让出后抛异常;-1 关闭 */
            public int $succeedFirst = -1;

            public array $counts = ['set' => 0, 'eval' => 0];

            /** 局部故障模式下本次调用是否失败;成功调用让出更久,使无锁时失败方会先返回 */
            private function partialFails(string $method): bool
            {
                if ($this->succeedFirst < 0) {
                    return false;
                }
                $fail = $this->counts[$method]++ >= $this->succeedFirst;
                if (\Swoole\Coroutine::getCid() > 0) {
                    \Swoole\Coroutine::sleep($fail ? 0.005 : 0.02);
                }
                return $fail;
            }

            private function fail(): void
            {
                if ($this->yieldBeforeFail && \Swoole\Coroutine::getCid() > 0) {
                    \Swoole\Coroutine::sleep(0.01);
                }
                throw new \RuntimeException('redis down');
            }

            public function set(string $key, string $value, array $options = []): bool
            {
                $this->calls[] = [$key, $options];
                if ($this->down) {
                    $this->fail();
                }
                if ($this->partialFails('set')) {
                    throw new \RuntimeException('redis connection lost');
                }
                if (in_array('nx', $options, true) && isset($this->store[$key])) {
                    return false;
                }
                $this->store[$key] = $value;
                if (isset($options['ex'])) {
                    $this->ttl[$key] = (int)$options['ex'];
                }
                return true;
            }

            public bool $failTtl = false;

            public function ttl(string $key): int
            {
                if ($this->down || $this->failTtl) {
                    $this->fail();
                }
                return isset($this->store[$key]) ? ($this->ttl[$key] ?? -1) : -2;
            }
        };

        //RedisFactory::get() 声明返回 RedisProxy,用 mock 代理把 set 委托给上面的假 Redis
        $proxy = Mockery::mock(RedisProxy::class);
        $proxy->shouldReceive('set')->andReturnUsing(fn (...$args) => $this->redis->set(...$args));
        $proxy->shouldReceive('eval')->andReturnUsing(fn (...$args) => $this->redis->eval(...$args));
        $proxy->shouldReceive('ttl')->andReturnUsing(fn (...$args) => $this->redis->ttl(...$args));
        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('get')->andReturn($proxy);
        $config = new Config(['dingtalk' => ['redis' => 'default']]);

        ApplicationContext::setContainer(new class($factory, $config) implements ContainerInterface {
            public function __construct(private $factory, private $config)
            {
            }

            public function get(string $id)
            {
                return match ($id) {
                    RedisFactory::class => $this->factory,
                    ConfigInterface::class => $this->config,
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [RedisFactory::class, ConfigInterface::class], true);
            }
        });

        $this->setStatic('cacheRetryAt', 0);
        $this->setStatic('lastPruneAt', 0);
        $this->setStatic('frequencyMsgTime', []);
        $this->setStatic('frequencyRunNum', []);
        $this->setStatic('frequencyRunTime', []);
        $this->setStatic('frequencyRunShadow', []);
        $this->setStatic('mutexes', []);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    private function setStatic(string $name, $value): void
    {
        (new \ReflectionProperty(Robot::class, $name))->setValue(null, $value);
    }

    private function getStatic(string $name)
    {
        return (new \ReflectionProperty(Robot::class, $name))->getValue();
    }

    private function robot(): Robot
    {
        $robot = (new \ReflectionClass(Robot::class))->newInstanceWithoutConstructor();
        foreach (['enable' => true, 'frequencyMsg' => 60, 'configName' => 'test'] as $k => $v) {
            (new \ReflectionProperty(Robot::class, $k))->setValue($robot, $v);
        }
        return $robot;
    }

    private function checkRobot(Robot $robot, string $token): bool
    {
        return (new \ReflectionMethod(Robot::class, 'checkFrequencyRobot'))->invoke($robot, $token);
    }

    private function check(Robot $robot, string $msg): bool
    {
        return (new \ReflectionMethod(Robot::class, 'checkFrequencyMsg'))->invoke($robot, $msg);
    }

    public function testRedisDedupUsesAtomicSetNxEx(): void
    {
        $robot = $this->robot();

        self::assertTrue($this->check($robot, 'boom'));
        self::assertFalse($this->check($robot, 'boom'), 'Same message within window is suppressed');
        self::assertTrue($this->check($robot, 'other'));
        self::assertSame(['nx', 'ex' => 60], $this->redis->calls[0][1]);
    }

    public function testRedisFailureFallsBackToMemoryAndRetriesAfterBreaker(): void
    {
        $robot = $this->robot();
        $this->redis->down = true;

        self::assertTrue($this->check($robot, 'boom'));
        self::assertFalse($this->check($robot, 'boom'), 'In-memory dedup while Redis is down');
        self::assertGreaterThan(time(), $this->getStatic('cacheRetryAt'));
        $callsWhileOpen = count($this->redis->calls);
        $this->check($robot, 'third');
        self::assertCount($callsWhileOpen, $this->redis->calls, 'Breaker open: Redis is not called');

        //熔断到期且 Redis 恢复 → 重新走 Redis
        $this->redis->down = false;
        $this->setStatic('cacheRetryAt', time() - 1);
        self::assertTrue($this->check($robot, 'after-recover'));
        self::assertFalse($this->check($robot, 'after-recover'));
        self::assertSame('1', $this->redis->store[array_key_last($this->redis->store)]);
    }

    public function testLocalRecordStillSuppressesAfterBreakerRecovers(): void
    {
        $robot = $this->robot();
        $this->redis->down = true;
        self::assertTrue($this->check($robot, 'boom'), 'First report passes via in-memory dedup');

        //熔断到期、Redis 恢复,但本地记录仍在 60 秒窗口内 → 不能再次放行,并以剩余 TTL 同步到 Redis
        $this->redis->down = false;
        $this->setStatic('cacheRetryAt', time() - 1);
        self::assertFalse($this->check($robot, 'boom'));
        [$key, $options] = end($this->redis->calls);
        self::assertSame(Robot::FREQUENCY_MSG_CACHE_KEY . 'test:' . md5('boom'), $key);
        self::assertSame('nx', $options[0]);
        self::assertGreaterThan(55, $options['ex']);
        self::assertLessThanOrEqual(60, $options['ex']);
        //其他 worker 经 Redis 也被去重
        self::assertFalse($this->check($robot, 'boom'));
    }

    public function testPruneUsesEachRecordsOwnExpiry(): void
    {
        $now = time();
        $this->setStatic('frequencyMsgTime', [
            'long:a'  => $now + 50,   //60 秒窗口分组的未过期记录
            'short:b' => $now - 1,    //5 秒窗口分组的已过期记录
        ]);

        (new \ReflectionMethod(Robot::class, 'pruneMemory'))->invoke(null);

        self::assertSame(['long:a' => $now + 50], $this->getStatic('frequencyMsgTime'));
    }

    public function testLazyPruneRemovesExpiredRecordsOnWrite(): void
    {
        $now = time();
        $this->setStatic('frequencyMsgTime', ['old:x' => $now - 1, 'live:y' => $now + 30]);
        $this->setStatic('lastPruneAt', $now - Robot::PRUNE_INTERVAL);
        $this->redis->down = true;

        self::assertTrue($this->check($this->robot(), 'fresh'));

        $records = $this->getStatic('frequencyMsgTime');
        self::assertArrayNotHasKey('old:x', $records, 'Expired record pruned lazily on write');
        self::assertArrayHasKey('live:y', $records);
        self::assertCount(2, $records);
        self::assertSame($now, $this->getStatic('lastPruneAt'));
    }

    /**
     * 回归:不能留下常驻 Swoole Timer,否则 Coroutine\run()/CLI 命令/crontab 进程发完告警后无法退出。
     */
    public function testNoLingeringTimerKeepsEventLoopAlive(): void
    {
        $robot = $this->robot();
        $timers = -1;
        \Swoole\Coroutine\run(function () use ($robot, &$timers) {
            $this->check($robot, 'via-redis');          //Redis 放行
            $this->redis->down = true;
            $this->check($robot, 'via-local');          //Redis 故障 → 本地放行
            $this->checkRobot($robot, 'tok-t');
            $timers = iterator_count(\Swoole\Timer::list());
        });

        self::assertSame(0, $timers, 'No Swoole timer registered by Robot');
    }

    public function testRobotLimitHonorsLocalWindowAfterRecovery(): void
    {
        $robot = $this->robot();
        (new \ReflectionProperty(Robot::class, 'frequencyRobot'))->setValue($robot, 3);
        $this->redis->down = true;

        //熔断期间进程内计数:限额 3
        self::assertSame([true, true, true, false], array_map(fn () => $this->checkRobot($robot, 'tok'), range(1, 4)));

        //熔断到期、Redis 恢复,但本地 60 秒窗口未结束 → 本地已用次数并入 Redis,仍然拒绝,不从零计数
        $this->redis->down = false;
        $this->setStatic('cacheRetryAt', time() - 1);
        self::assertFalse($this->checkRobot($robot, 'tok'));
        [$key, $ttl, $incr] = end($this->redis->evals);
        self::assertSame(Robot::FREQUENCY_ROBOT_CACHE_KEY . 'tok', $key);
        self::assertSame(5, $incr, '4 local uses + current request merged into Redis');
        self::assertGreaterThan(55, $ttl);
        self::assertLessThanOrEqual(60, $ttl);
        self::assertSame([], $this->getStatic('frequencyRunNum'), 'Local window handed over to Redis');

        //之后继续走 Redis 计数(增量 1)
        self::assertFalse($this->checkRobot($robot, 'tok'));
        self::assertSame(1, end($this->redis->evals)[2]);
    }

    public function testRobotLimitStartsFreshWindowInRedis(): void
    {
        $robot = $this->robot();
        (new \ReflectionProperty(Robot::class, 'frequencyRobot'))->setValue($robot, 2);

        self::assertSame([true, true, false], array_map(fn () => $this->checkRobot($robot, 'tok2'), range(1, 3)));
        self::assertSame([Robot::FREQUENCY_ROBOT_CACHE_KEY . 'tok2', 60, 1], $this->redis->evals[0]);
    }

    /**
     * Redis 故障且 I/O 期间发生协程切换:并发上报同一消息最多放行一次,机器人放行次数恰为限额。
     */
    public function testConcurrentCoroutinesDuringRedisFailure(): void
    {
        $this->redis->down = true;
        $this->redis->yieldBeforeFail = true;
        $robot = $this->robot();
        (new \ReflectionProperty(Robot::class, 'frequencyRobot'))->setValue($robot, 3);

        $msgPassed = 0;
        $robotPassed = 0;
        \Swoole\Coroutine\run(function () use ($robot, &$msgPassed, &$robotPassed) {
            $wg = new \Swoole\Coroutine\WaitGroup();
            for ($i = 0; $i < 20; ++$i) {
                $wg->add(2);
                \Swoole\Coroutine::create(function () use ($robot, $wg, &$msgPassed) {
                    //每个协程首次调用都在熔断打开前进入 Redis 分支
                    $this->setStatic('cacheRetryAt', 0);
                    $msgPassed += (int)$this->check($robot, 'same-boom');
                    $wg->done();
                });
                \Swoole\Coroutine::create(function () use ($robot, $wg, &$robotPassed) {
                    $this->setStatic('cacheRetryAt', 0);
                    $robotPassed += (int)$this->checkRobot($robot, 'tok-c');
                    $wg->done();
                });
            }
            $wg->wait();
        });

        self::assertSame(1, $msgPassed, 'Same message passes at most once across coroutines');
        self::assertSame(3, $robotPassed, 'Robot passes exactly its per-minute limit');
        self::assertSame(20, $this->getStatic('frequencyRunNum')['tok-c']);
    }

    /**
     * Redis 局部故障(同一批调用里首个成功、其余让出后抛异常):成功经 Redis 放行的影子状态
     * 让后续降级判定不从零开始 → 同一消息仍只放行 1 次,机器人总放行数不超过限额。
     */
    public function testPartialRedisFailureKeepsSingleDecision(): void
    {
        $this->redis->succeedFirst = 1;
        $robot = $this->robot();
        (new \ReflectionProperty(Robot::class, 'frequencyRobot'))->setValue($robot, 3);

        $msgPassed = 0;
        $robotPassed = 0;
        \Swoole\Coroutine\run(function () use ($robot, &$msgPassed, &$robotPassed) {
            $wg = new \Swoole\Coroutine\WaitGroup();
            for ($i = 0; $i < 20; ++$i) {
                $wg->add(2);
                \Swoole\Coroutine::create(function () use ($robot, $wg, &$msgPassed) {
                    $msgPassed += (int)$this->check($robot, 'partial-boom');
                    $wg->done();
                });
                \Swoole\Coroutine::create(function () use ($robot, $wg, &$robotPassed) {
                    $robotPassed += (int)$this->checkRobot($robot, 'tok-p');
                    $wg->done();
                });
            }
            $wg->wait();
        });

        self::assertSame(1, $msgPassed, 'Same message passes once although Redis partially failed');
        self::assertSame(3, $robotPassed, 'Robot passes stay within limit across Redis success + local fallback');
        self::assertSame(1, $this->getStatic('frequencyRunShadow')['tok-p'], 'Redis-approved pass tracked as shadow');
        self::assertSame([], $this->getStatic('mutexes'), 'Mutex table is cleaned up after use');
    }

    /**
     * Redis 已判定"重复"(键由其他 worker 写入)后 Redis 故障:降级判定不能反转为放行。
     */
    public function testRedisDuplicateVerdictSurvivesFailover(): void
    {
        $robot = $this->robot();
        $key = Robot::FREQUENCY_MSG_CACHE_KEY . 'test:' . md5('seen-elsewhere');
        $this->redis->store[$key] = '1';
        $this->redis->ttl[$key] = 40;

        self::assertFalse($this->check($robot, 'seen-elsewhere'), 'Redis says duplicate');
        $this->redis->down = true;
        self::assertFalse($this->check($robot, 'seen-elsewhere'), 'Still duplicate while Redis is down');
        $expireAt = $this->getStatic('frequencyMsgTime')['test:' . md5('seen-elsewhere')];
        self::assertGreaterThan(time() + 35, $expireAt, 'Rejection shadow uses the remaining Redis TTL');
        self::assertLessThanOrEqual(time() + 40, $expireAt);
    }

    /**
     * 其他 worker 已用掉大部分额度:影子吸收 Redis 全局计数,故障降级后不会超额放行。
     */
    public function testRobotShadowAbsorbsGlobalCount(): void
    {
        $robot = $this->robot();
        (new \ReflectionProperty(Robot::class, 'frequencyRobot'))->setValue($robot, 20);
        $key = Robot::FREQUENCY_ROBOT_CACHE_KEY . 'tok-g';

        //限额 - 1:本次拿到第 20 次放行,随后 Redis 故障 → 不得再放行第 21 次
        $this->redis->store[$key] = 19;
        self::assertTrue($this->checkRobot($robot, 'tok-g'));
        self::assertSame(20, $this->getStatic('frequencyRunShadow')['tok-g']);
        $this->redis->down = true;
        self::assertFalse($this->checkRobot($robot, 'tok-g'));

        //已达限额:Redis 拒绝后故障 → 仍拒绝
        $this->setStatic('frequencyRunNum', []);
        $this->setStatic('frequencyRunTime', []);
        $this->setStatic('frequencyRunShadow', []);
        $this->setStatic('cacheRetryAt', 0);
        $this->redis->down = false;
        $this->redis->store[$key] = 20;
        self::assertFalse($this->checkRobot($robot, 'tok-g'));
        $this->redis->down = true;
        self::assertFalse($this->checkRobot($robot, 'tok-g'));
    }

    /**
     * Redis 判定重复后读取 TTL 时断开:重复结论不能丢,fail-closed 返回 false 并记录完整窗口的拒绝影子。
     */
    public function testDuplicateVerdictKeptWhenTtlCallFails(): void
    {
        $robot = $this->robot();
        $key = Robot::FREQUENCY_MSG_CACHE_KEY . 'test:' . md5('dup-then-drop');
        $this->redis->store[$key] = '1';
        $this->redis->failTtl = true;

        self::assertFalse($this->check($robot, 'dup-then-drop'));
        self::assertGreaterThan(time(), $this->getStatic('cacheRetryAt'), 'Breaker opened by the failed TTL call');
        self::assertGreaterThan(time() + 55, $this->getStatic('frequencyMsgTime')['test:' . md5('dup-then-drop')]);
        self::assertFalse($this->check($robot, 'dup-then-drop'), 'Still duplicate via local shadow');
    }
}
