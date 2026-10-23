<?php

namespace Dleno\DingTalk;

use Dleno\CommonCore\Tools\Client;
use Dleno\CommonCore\Tools\Server;
use GuzzleHttp\Client as HttpClient;
use Hyperf\Context\Context;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Redis\RedisFactory;
use Hyperf\Context\ApplicationContext;
use Hyperf\Coroutine\Coroutine;
use Hyperf\WebSocketServer\Context as WsContext;
use Psr\Http\Message\ServerRequestInterface;

use function Hyperf\Config\config;
use function Hyperf\Coroutine\go;
use function Hyperf\Coroutine\run;
use function Hyperf\Support\make;

/**
 * Class Robot
 * @package Dleno\DingTalk
 */
class Robot
{
    //相同消息频率限制
    const FREQUENCY_MSG_CACHE_KEY = 'DING_TALK:FREQUENCY:MSG:';
    //同机器人频率限制
    const FREQUENCY_ROBOT_CACHE_KEY = 'DING_TALK:FREQUENCY:ROBOT:';

    const MSG_TYPE_NOTICE    = 1;
    const MSG_TYPE_EXCEPTION = 2;

    //异常消息去重方式：按异常消息原文（默认，兼容旧行为）
    const EXCEPTION_DEDUP_MESSAGE = 'message';
    //异常消息去重方式：按异常指纹（异常类 + code + 出错位置 + 去除变量后的消息）
    const EXCEPTION_DEDUP_FINGERPRINT = 'fingerprint';

    protected static $robots = [];

    //Redis 异常后暂停使用 Redis 的秒数,到期自动重试(避免一次抖动后整个进程永久退化为进程内限频)
    const CACHE_RETRY_SECONDS = 30;

    //机器人分钟计数:INCRBY ARGV[2] 后若无 TTL(新建或历史遗留的无 TTL 键)则设置窗口 ARGV[1] 秒;返回当前计数
    const ROBOT_FREQUENCY_LUA = <<<'LUA'
local n = redis.call('INCRBY', KEYS[1], ARGV[2])
if redis.call('TTL', KEYS[1]) < 0 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
end
return n
LUA;

    //Redis 恢复重试时间点(unix 秒);<= time() 表示可用
    protected static $cacheRetryAt = 0;

    //进程内去重记录上次清理时间(unix 秒):写入时按间隔惰性清理,不用常驻定时器
    protected static $lastPruneAt = 0;

    //惰性清理过期记录的最小间隔秒数
    const PRUNE_INTERVAL = 10;

    protected static $frequencyRunNum  = [];
    protected static $frequencyRunTime = [];
    //本进程当前窗口内已知的机器人 Redis 全局计数(影子状态)
    protected static $frequencyRunShadow = [];

    //进程内按 key 的协程互斥锁:key => ['ch' => Channel(1), 'refs' => 等待/持有者数]
    protected static $mutexes = [];

    //等待同一 key 互斥锁的最长秒数(Redis 自身有超时,此值仅防异常长时间阻塞)
    const LOCK_TIMEOUT = 5.0;

    protected static $frequencyMsgTime = [];

    /**
     * @var string
     */
    protected $gateway = 'https://oapi.dingtalk.com/robot/send?access_token=%s&timestamp=%s&sign=%s';

    /**
     * 每个机器人每分钟最多发送20条消息到群里，如果超过20条，会限流10分钟。
     * @var int
     */
    protected $frequencyRobot = 20;

    /**
     * 配置名称
     * @var string
     */
    protected $configName;

    /**
     * robot配置
     * @var array
     */
    protected $configs;

    /**
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * @var StdoutLoggerInterface
     */
    protected $logger;

    /**
     * 是否启用
     * @var bool
     */
    protected $enable;

    /**
     * 机器人名称
     * @var string
     */
    protected $name;

    /**
     * 相同消息发生时每多少秒上报一次
     * @var int
     */
    protected $frequencyMsg;

    /**
     * 异常消息去重方式（message|fingerprint）
     * @var string
     */
    protected $exceptionDedup = self::EXCEPTION_DEDUP_MESSAGE;

    /**
     * construct
     * @param string $configName
     */
    public function __construct($configName)
    {
        $this->logger = ApplicationContext::getContainer()
                                          ->get(StdoutLoggerInterface::class);

        $this->configName     = $configName;
        $this->frequencyRobot = config('dingtalk.frequency', $this->frequencyRobot);

        $config = config('dingtalk.configs.' . $configName);
        if (empty($config)) {
            $this->logger->error(sprintf('[%s] dingtalk no config', $configName));
            $config = [
                'enable'    => false,
                'name'      => 'Robot',
                'frequency' => 0,
                'token'     => '',
                'secret'    => '',
            ];
        }
        if (!isset($config['configs'])) {
            $config['configs'][] = [
                'token'  => $config['token'],
                'secret' => $config['secret'],
            ];
            unset($config['token'], $config['secret']);
        }
        if (!($config['enable'] ?? false)) {
            $this->logger->error(sprintf('[%s] dingtalk is not enable', $configName));
        }
        foreach ($config['configs'] as $key => $val) {
            if (empty($val['token']) || empty($val['secret'])) {
                unset($config['configs'][$key]);
            }
        }
        $config['configs'] = array_values($config['configs']);
        if (empty($config['configs'])) {
            $config['enable'] = false;
        }
        $this->enable       = $config['enable'] ? true : false;
        $this->name         = $config['name'] ?? '';
        $this->frequencyMsg = $config['frequency'] ?? 60;
        //分组配置优先，其次全局配置，默认按消息原文
        $this->exceptionDedup = (string)($config['exception_dedup'] ?? config('dingtalk.exception_dedup', self::EXCEPTION_DEDUP_MESSAGE));
        $this->configs      = $config['configs'] ?? [];
        $this->client       = new HttpClient();
    }

    /**
     * @param string $configName
     * @return Robot
     */
    public static function get(?string $configName = null)
    {
        $configName = $configName ?: 'default';
        if (!isset(self::$robots[$configName])) {
            self::$robots[$configName] = make(Robot::class, ['configName' => $configName]);
        }
        return self::$robots[$configName];
    }

    /**
     * @param string $text
     * @return bool
     */
    public function text(string $text, $at = [])
    {
        if ($this->checkFrequencyMsg($text)) {
            $text .= PHP_EOL . "> ** 请求追踪：**" . $this->getTraceId();
            return $this->ding('Text', 'text', $text, $at);
        }
        return false;
    }

    /**
     * @param string $markdown
     * @return bool
     */
    public function markdown(string $markdown, $at = [])
    {
        if ($this->checkFrequencyMsg($markdown)) {
            $markdown .= PHP_EOL . "> ** 请求追踪：**" . $this->getTraceId();
            return $this->ding('Markdown', 'markdown', $markdown, $at);
        }
        return false;
    }

    /**
     * @param string $notice
     * @param array $data
     * @return bool
     */
    public function notice(string $notice, $data = [], $at = [])
    {
        if ($this->checkFrequencyMsg($notice)) {
            return $this->ding('Notice', 'markdown', $this->formatNotice($notice, $data), $at);
        }
        return false;
    }

    /**
     * @param \Throwable $e
     * @param array $data
     * @return bool
     */
    public function exception(\Throwable $e, $data = [], $at = [])
    {
        if ($this->checkFrequencyMsg($this->getExceptionFrequencyText($e))) {
            return $this->ding('Exception', 'markdown', $this->formatException($e, $data), $at);
        }
        return false;
    }

    /**
     * 消息频率检查
     * @param $msg
     * @return bool
     */
    protected function checkFrequencyMsg($msg)
    {
        if (!$this->enable) {
            return false;
        }
        if ($this->frequencyMsg <= 0) {
            return true;
        }
        $msgKey = $this->configName . ':' . md5($msg);

        //同一消息的判定在进程内按 key 串行(协程互斥):Redis 部分成功/部分失败时也不会各自放行;
        //等锁超时说明同一消息正有协程在判定 → 按重复处理(fail-closed)
        return self::synchronized('msg:' . $msgKey, fn () => $this->decideFrequencyMsg($msg, $msgKey), false);
    }

    /**
     * 消息去重判定(调用方已持有该消息的进程内锁)。
     * 进程内记录 self::$frequencyMsgTime 存绝对过期时间,作为"影子状态":
     * 经 Redis 放行与经本地放行的消息都会记录,故 Redis 局部故障/熔断切换时不会从零判定。
     */
    protected function decideFrequencyMsg(string $msg, string $msgKey): bool
    {
        $now      = time();
        $cacheKey = $this->getFrequencyMsgCacheKey($msg);

        //本地记录未过期即视为重复(即便 Redis 已恢复也不能提前放行);
        //Redis 可用时顺带以剩余 TTL 占位,让其他 worker 在剩余窗口内同样去重
        $localExpireAt = self::$frequencyMsgTime[$msgKey] ?? 0;
        if ($localExpireAt > $now) {
            if (self::useCache()) {
                try {
                    $this->frequencyRedis()->set($cacheKey, '1', ['nx', 'ex' => $localExpireAt - $now]);
                } catch (\Throwable $e) {
                    self::cacheFailed();
                }
            }
            return false;
        }
        unset(self::$frequencyMsgTime[$msgKey]);

        if (self::useCache()) {
            try {
                //SET NX EX 原子占位:多 worker 并发上报同一消息时只有一个能写入成功,其余视为重复
                $redis  = $this->frequencyRedis();
                $passed = (bool)$redis->set($cacheKey, '1', ['nx', 'ex' => (int)$this->frequencyMsg]);
                if ($passed) {
                    $this->rememberFrequencyMsg($msgKey, time());
                    return true;
                }
                //Redis 判定重复(可能由其他 worker 写入):以键的剩余 TTL 记录"拒绝影子",
                //使随后 Redis 故障降级时仍按重复处理,不会把 Redis 的结论反转(持有本消息锁,两次调用间无同 key 穿插)
                //读 TTL 失败时 Redis 的"重复"结论仍然成立:打开熔断,按完整窗口记录拒绝影子并返回 false(fail-closed)
                try {
                    $ttl = (int)$redis->ttl($cacheKey);
                } catch (\Throwable $e) {
                    self::cacheFailed();
                    $ttl = 0;
                }
                $this->rememberFrequencyMsg($msgKey, time(), $ttl > 0 ? $ttl : (int)$this->frequencyMsg);
                return false;
            } catch (\Throwable $e) {
                self::cacheFailed();
            }
        }

        //Redis 不可用(无论熔断由哪条路径打开):进程内去重,记录本条消息自身的过期时间(各分组窗口独立)
        $this->rememberFrequencyMsg($msgKey, time());
        return true;
    }

    /**
     * 记录本进程已放行的消息(绝对过期时间);写入时按 PRUNE_INTERVAL 惰性清理过期记录。
     * 不使用常驻 Swoole Timer:自续期的定时器会让 Coroutine\run()/CLI 命令/crontab 进程永远无法退出。
     */
    protected function rememberFrequencyMsg(string $msgKey, int $now, ?int $ttl = null): void
    {
        if ($now - self::$lastPruneAt >= self::PRUNE_INTERVAL) {
            self::pruneMemory();
            self::$lastPruneAt = $now;
        }
        self::$frequencyMsgTime[$msgKey] = $now + ($ttl ?? (int)$this->frequencyMsg);
    }

    protected function frequencyRedis()
    {
        return ApplicationContext::getContainer()->get(RedisFactory::class)->get(config('dingtalk.redis', 'default'));
    }

    /**
     * 异常去重文本
     * message：异常消息原文（旧行为）；
     * fingerprint：异常类 + code + 出错位置 + 去除变量（数字、引号内容、长十六进制串）后的消息，
     * 同一处抛出、仅变量不同的异常（如带绑定值的 SQL、带耗时的网络错误）在去重窗口内只上报一次。
     * @param \Throwable $e
     * @return string
     */
    protected function getExceptionFrequencyText(\Throwable $e)
    {
        if ($this->exceptionDedup !== self::EXCEPTION_DEDUP_FINGERPRINT) {
            return $e->getMessage();
        }

        return 'exception:' . get_class($e) . '|' . $e->getCode() . '|' . $e->getFile() . ':' . $e->getLine() . '|'
            . self::normalizeMessage($e->getMessage());
    }

    /**
     * 去除消息中的变量部分，用于生成去重指纹
     * @param string $message
     * @return string
     */
    public static function normalizeMessage(string $message)
    {
        $normalized = preg_replace([
            '/\'(?:[^\'\\\\]|\\\\.)*\'/su',   //单引号内容
            '/"(?:[^"\\\\]|\\\\.)*"/su',        //双引号内容
            '/\b(?:0x)?[0-9a-f]{16,}\b/iu',     //长十六进制串（hash、uuid 片段、token）
            '/\d+(?:\.\d+)?/u',               //数字（id、金额、耗时、时间）
            '/\s+/u',                          //空白
        ], ['?', '?', '#', '#', ' '], $message);

        //非法 UTF-8 等导致正则失败时保留原文，不把不同异常归为同一指纹
        return $normalized === null ? $message : trim($normalized);
    }

    protected function getFrequencyMsgCacheKey($msg)
    {
        return self::FREQUENCY_MSG_CACHE_KEY . $this->configName . ':' . md5($msg);
    }

    /**
     * 在进程内按 key 串行执行 $callback(协程互斥);非协程环境无并发,直接执行。
     * 等锁超过 LOCK_TIMEOUT 秒返回 $timeoutResult。
     */
    protected static function synchronized(string $key, callable $callback, $timeoutResult)
    {
        if (\Swoole\Coroutine::getCid() <= 0) {
            return $callback();
        }
        if (!isset(self::$mutexes[$key])) {
            $channel = new \Swoole\Coroutine\Channel(1);
            $channel->push(true);
            self::$mutexes[$key] = ['ch' => $channel, 'refs' => 0];
        }
        $channel = self::$mutexes[$key]['ch'];
        ++self::$mutexes[$key]['refs'];
        try {
            if ($channel->pop(self::LOCK_TIMEOUT) === false) {
                return $timeoutResult;
            }
            try {
                return $callback();
            } finally {
                $channel->push(true);
            }
        } finally {
            if (--self::$mutexes[$key]['refs'] <= 0) {
                unset(self::$mutexes[$key]);
            }
        }
    }

    /**
     * Redis 当前是否可用于频率控制(熔断期内返回 false)
     */
    protected static function useCache(): bool
    {
        return self::$cacheRetryAt <= time();
    }

    /**
     * Redis 调用失败:熔断 CACHE_RETRY_SECONDS 秒,期间退化为进程内频率控制,到期自动重试 Redis
     */
    protected static function cacheFailed(): void
    {
        self::$cacheRetryAt = time() + self::CACHE_RETRY_SECONDS;
    }

    /**
     * 清理已过期的进程内去重记录(记录值为各自的绝对过期时间,与调用的实例/分组无关)
     */
    protected static function pruneMemory(): void
    {
        $now = time();
        foreach (self::$frequencyMsgTime as $k => $expireAt) {
            if ($expireAt <= $now) {
                unset(self::$frequencyMsgTime[$k]);
            }
        }
    }

    /**
     * @param string $notice
     * @param array $data
     * @return string
     */
    protected function formatNotice(string $notice, $data = [])
    {
        // 兼容本地进程执行的情况
        if (Context::has(ServerRequestInterface::class)) {
            $request = ApplicationContext::getContainer()
                                         ->get(RequestInterface::class);
        } elseif (class_exists(WsContext::class) && WsContext::has(ServerRequestInterface::class)) {
            $request = WsContext::get(ServerRequestInterface::class);
        } else {
            $request = null;
        }

        $isLocal = false;
        if (is_null($request)) {
            $isLocal = true;
        } else {
            $requestUrl = $request->getUri();
            $method     = $request->getMethod();
            $params     = $this->toJson($this->getRequestParams($request));
            $headers    = $this->getHeader($request);
            $headers    = $this->toJson($headers);
        }
        $messageBody             = [];
        $messageBody['通知消息'] = config('app_name') . "({$this->name})-[" . config('app_env') . "]";
        $messageBody['主机地址'] = $this->getHostIp() . ($isLocal ? '(LOCAL)' : '(REQUEST)');
        if (!$isLocal) {
            $messageBody['请求地址'] = "[$method]" . $requestUrl;
            $messageBody['请求头部'] = $headers;
            $messageBody['请求参数'] = $params;
            $messageBody['请求IP']   = $this->getClientIp();
        }
        $messageBody['请求追踪'] = $this->getTraceId();
        $messageBody['消息时间'] = date('Y-m-d H:i:s');
        $messageBody['消息内容'] = $notice;
        if (!empty($data)) {
            $messageBody['数据内容'] = $this->toJson($data);
        }

        return $this->formatMessage($messageBody, self::MSG_TYPE_NOTICE);
    }

    /**
     * @param \Throwable $exception
     * @param array $data
     * @return string
     */
    protected function formatException(\Throwable $exception, $data = [])
    {
        $message = $exception->getMessage();
        $file    = $exception->getFile();
        $line    = $exception->getLine();

        //兼容 HTTP/WS/LOCAL
        if (Context::has(ServerRequestInterface::class)) {
            $request = ApplicationContext::getContainer()
                                         ->get(RequestInterface::class);
        } elseif (class_exists(WsContext::class) && WsContext::has(ServerRequestInterface::class)) {
            $request = WsContext::get(ServerRequestInterface::class);
        } else {
            $request = null;
        }

        $isLocal = false;
        if (is_null($request)) {
            $isLocal = true;
        } else {
            $requestUrl = $request->getUri();
            $method     = $request->getMethod();
            $params     = $this->toJson($this->getRequestParams($request));
            $headers    = $this->getHeader($request);
            $headers    = $this->toJson($headers);
        }

        $messageBody             = [];
        $messageBody['异常消息'] = config('app_name') . "({$this->name})-[" . config('app_env') . "]";
        $messageBody['主机地址'] = $this->getHostIp() . ($isLocal ? '(LOCAL)' : '(REQUEST)');
        if (!$isLocal) {
            $messageBody['请求地址'] = "[$method]" . $requestUrl;
            $messageBody['请求头部'] = $headers;
            $messageBody['请求参数'] = $params;
            $messageBody['请求IP']   = $this->getClientIp();
        }
        $messageBody['请求追踪'] = $this->getTraceId();
        $messageBody['异常时间'] = date('Y-m-d H:i:s');
        $messageBody['异常类名'] = get_class($exception);
        $messageBody['异常描述'] = $message;
        $messageBody['异常位置'] = sprintf('%s:%d', str_replace([BASE_PATH, '\\'], ['', '/'], $file), $line);
        if (!empty($data)) {
            $messageBody['数据内容'] = $this->toJson($data);
        }

        $trace = $exception->getTraceAsString();
        $trace = str_replace([BASE_PATH, '\\'], ['', '/'], $trace);
        $trace = explode("\n", $trace);
        array_unshift($trace, '');
        if ($trace) {
            $messageBody['堆栈信息'] = PHP_EOL . '>' . implode(PHP_EOL . '> - ', $trace);
        }

        return $this->formatMessage($messageBody, self::MSG_TYPE_EXCEPTION);
    }

    /**
     * 请求参数:Hyperf RequestInterface 用 all();WS 上下文里是 PSR-7 请求(无 all()),合并 query 与 body
     * @param ServerRequestInterface $request
     * @return array
     */
    protected function getRequestParams(ServerRequestInterface $request): array
    {
        if ($request instanceof RequestInterface) {
            return $request->all();
        }
        $body = $request->getParsedBody();
        return array_merge($request->getQueryParams(), is_array($body) ? $body : []);
    }

    /**
     * 与 common-core array_to_json 相同的编码选项(本包不反向依赖 common-core)
     */
    protected function toJson(array $data): string
    {
        return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * 安装了 dleno/common-core 时沿用其 traceId,否则留空
     */
    protected function getTraceId(): string
    {
        return class_exists(Server::class) ? (string)Server::getTraceId() : '';
    }

    protected function getHostIp(): string
    {
        if (class_exists(Server::class)) {
            return (string)Server::getIpAddr();
        }
        $ips = function_exists('swoole_get_local_ip') ? swoole_get_local_ip() : [];
        unset($ips['lo']);
        return (string)($ips['eth1'] ?? $ips['eth0'] ?? (reset($ips) ?: 'UnKnown'));
    }

    protected function getClientIp(): string
    {
        return class_exists(Client::class) ? (string)Client::getIP() : '';
    }

    /**
     * 获取头部信息
     * @param ServerRequestInterface $request
     * @return array
     */
    protected function getHeader(ServerRequestInterface $request)
    {
        $headers      = $request->getHeaders();
        $allowHeaders = config('app.ac_allow_headers', []);
        array_walk(
            $allowHeaders,
            function (&$val) {
                $val = strtolower($val);
            }
        );
        $filterHeaders = config(
            'app.filter_headers',
            [
                'content-type',
                'client-key',
                'client-timestamp',
                'client-nonce',
                'client-sign',
                'client-accesskey',
            ]
        );
        array_walk(
            $filterHeaders,
            function (&$val) {
                $val = strtolower($val);
            }
        );
        $allowHeaders = array_diff($allowHeaders, $filterHeaders);
        foreach ($headers as $key => $val) {
            unset($headers[$key]);
            $key = strtolower($key);
            if (in_array($key, $allowHeaders)) {
                $headers[$key] = is_array($val) ? join('; ', $val) : $val;
            }
        }

        return $headers;
    }

    /**
     * @param array $messageBody
     * @return string
     */
    protected function formatMessage(array $messageBody, $msgType = self::MSG_TYPE_NOTICE)
    {
        $i = 0;
        array_walk(
            $messageBody,
            function (&$val, $key) use (&$i, $msgType) {
                if ($i <= 0) {
                    $color = $msgType == self::MSG_TYPE_EXCEPTION ? 'f00' : '00f';
                    $val   = sprintf(
                        '#### **<font color=#' . $color . '>%s::</font>** %s> **%s**',
                        $key,
                        PHP_EOL,
                        $val
                    );
                } else {
                    $val = sprintf('- %s: %s> %s', $key, PHP_EOL, $val);
                }
                $i++;
            }
        );

        return join(PHP_EOL, $messageBody);
    }

    /**
     * @param string $title
     * @param string $type
     * @param string $content
     * @param array $at
     * @return bool
     */
    protected function ding(string $title, string $type, string $content, array $at = [])
    {
        $contentType = 'content';
        if ($type === 'markdown') {
            $contentType = 'text';
        }
        $msg = [
            'msgtype' => $type,
            $type     => [
                'title'      => $title,
                $contentType => $content,
            ],
        ];
        if (!empty($at)) {
            if (isset($at['atMobiles'])) {
                $msg['at']['atMobiles'] = is_array($at['atMobiles']) ? $at['atMobiles'] : [$at['atMobiles']];
            } elseif (isset($at['atUserIds'])) {
                $msg['at']['atUserIds'] = is_array($at['atUserIds']) ? $at['atUserIds'] : [$at['atUserIds']];
            } elseif (isset($at['isAtAll'])) {
                $msg['at']['isAtAll'] = $at['isAtAll'] ? true : false;
            }
        }
        return $this->sendMessage($msg);
    }

    /**
     * @param array $msg
     */
    protected function sendMessage(array $msg)
    {
        $this->goRun(
            function () use ($msg) {
                $this->__sendMessage($msg);
            }
        );
        return true;
    }

    /**
     * @param array $msg
     */
    protected function __sendMessage(array $msg)
    {
        $config = $this->getConfig();
        if (empty($config)) {
            //所有机器人都满了则延迟发送
            $this->goRun(
                function () use ($msg) {
                    sleep(60);
                    $this->__sendMessage($msg);
                }
            );
            return;
        }
        $timestamp = (string)(time() * 1000);
        $secret    = $config['secret'];
        $token     = $config['token'];
        $sign      = urlencode(base64_encode(hash_hmac('sha256', $timestamp . "\n" . $secret, $secret, true)));
        $response  = $this->client->post(sprintf($this->gateway, $token, $timestamp, $sign), ['json' => $msg]);
        $result    = json_decode($response->getBody(), true);
        if (!isset($result['errcode']) || $result['errcode']) {
            $this->logger->error('DingTalk Send Fail:' . $this->toJson((array)$result));
            $this->logger->error('DingTalk Send Data:' . $this->toJson($msg));
        }
    }

    /**
     * @param array|callable $callbacks
     */
    private function goRun($callbacks)
    {
        if (!Coroutine::inCoroutine()) {
            run($callbacks);
        } else {
            go($callbacks);
        }
    }

    protected function getConfig()
    {
        $configs = $this->configs;
        shuffle($configs);
        foreach ($configs as $config) {
            if (!$this->checkFrequencyRobot($config['token'])) {
                continue;
            }
            return $config;
        }

        return [];
    }

    /**
     * 当机器人消息频率检查
     * @param $robot
     * @return bool
     */
    protected function checkFrequencyRobot($robot)
    {
        //同一机器人的计数在进程内按 token 串行(协程互斥);等锁超时按已限流处理(fail-closed,发送方会延迟重试)
        return self::synchronized('robot:' . $robot, fn () => $this->decideFrequencyRobot((string)$robot), false);
    }

    /**
     * 机器人分钟限频判定(调用方已持有该 token 的进程内锁)。本进程当前 60 秒窗口内的状态:
     *  - frequencyRunTime  窗口起点
     *  - frequencyRunShadow 本窗口已知的 Redis 全局计数(影子,Redis 故障时用于本地判定,不再从零计数)
     *  - frequencyRunNum   仅本地计数、尚未并入 Redis 的次数(Redis 恢复时连同剩余窗口原子并入)
     */
    protected function decideFrequencyRobot(string $robot): bool
    {
        $now = time();
        if (isset(self::$frequencyRunTime[$robot]) && self::$frequencyRunTime[$robot] + 60 <= $now) {
            unset(self::$frequencyRunNum[$robot], self::$frequencyRunTime[$robot], self::$frequencyRunShadow[$robot]);
        }
        self::$frequencyRunTime[$robot] ??= $now;
        $pending = (int)(self::$frequencyRunNum[$robot] ?? 0);
        $shadow  = (int)(self::$frequencyRunShadow[$robot] ?? 0);

        if (self::useCache()) {
            try {
                //INCRBY(本地未并入次数 + 本次) + 补 TTL 在一段 Lua 内原子执行;
                //有未并入次数时用本地窗口的剩余秒数,恢复后不从零计数,也不会出现无 TTL 计数键
                $ttl     = $pending > 0 ? max(1, self::$frequencyRunTime[$robot] + 60 - $now) : 60;
                $thisNum = (int)$this->frequencyRedis()->eval(
                    self::ROBOT_FREQUENCY_LUA,
                    [$this->getFrequencyRobotCacheKey($robot), $ttl, $pending + 1],
                    1
                );
                unset(self::$frequencyRunNum[$robot]);
                //影子吸收 Redis 返回的全局计数(含其他 worker 的次数),而不只是本进程增量:
                //随后 Redis 故障降级时按已知的全局用量判定,不会把"额度已满"反转为放行
                self::$frequencyRunShadow[$robot] = max($shadow + $pending + 1, $thisNum);
                return $thisNum <= $this->frequencyRobot;
            } catch (\Throwable $e) {
                self::cacheFailed();
            }
        }

        //Redis 不可用:进程内计数,已计入 Redis 的影子次数一并计入额度
        self::$frequencyRunNum[$robot] = $pending + 1;
        return $shadow + self::$frequencyRunNum[$robot] <= $this->frequencyRobot;
    }

    protected function getFrequencyRobotCacheKey($robot)
    {
        return self::FREQUENCY_ROBOT_CACHE_KEY . $robot;
    }
}
