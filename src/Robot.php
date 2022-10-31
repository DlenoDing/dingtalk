<?php

namespace Dleno\DingTalk;

use Dleno\CommonCore\Tools\Server;
use GuzzleHttp\Client;
use Hyperf\Context\Context;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Redis\Redis;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Utils\Coroutine;
use Hyperf\WebSocketServer\Context as WsContext;
use Psr\Http\Message\ServerRequestInterface;

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
    /**
     * @Inject()
     * @var Redis
     */
    public $redis;

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
     * construct
     * @param string $configName
     */
    public function __construct($configName)
    {
        $this->configName = $configName;

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
        if (!$config['enable']) {
            $this->logger->error(sprintf('[%s] dingtalk is not enable', $configName));
        }
        $this->enable       = $config['enable'] ? true : false;
        $this->name         = $config['name'];
        $this->frequencyMsg = $config['frequency'];
        $this->configs      = $config['configs'];
        $this->client       = new Client();
        $this->logger       = ApplicationContext::getContainer()
                                                ->get(StdoutLoggerInterface::class);
    }

    /**
     * @param string $configName
     * @return Robot
     */
    public static function get(string $configName = null)
    {
        $configName = $configName ?: 'default';
        return make(Robot::class, ['configName' => $configName]);
    }

    /**
     * @param string $text
     * @return bool
     */
    public function text(string $text)
    {
        if ($this->checkFrequencyMsg($text)) {
            return $this->ding('Text', 'text', $text);
        }
        return false;
    }

    /**
     * @param string $markdown
     * @return bool
     */
    public function markdown(string $markdown)
    {
        if ($this->checkFrequencyMsg($markdown)) {
            return $this->ding('Markdown', 'markdown', $markdown);
        }
        return false;
    }

    /**
     * @param string $notice
     * @return bool
     */
    public function notice(string $notice)
    {
        if ($this->checkFrequencyMsg($notice)) {
            return $this->ding('Notice', 'markdown', $this->formatNotice($notice));
        }
        return false;
    }

    /**
     * @param \Throwable $e
     * @return bool
     */
    public function exception(\Throwable $e)
    {
        if ($this->checkFrequencyMsg($e->getMessage())) {
            return $this->ding('Exception', 'markdown', $this->formatException($e));
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
        $cacheKey = $this->getFrequencyMsgCacheKey($msg);
        if ($this->redis->exists($cacheKey)) {
            return false;
        }
        $this->redis->set($cacheKey, '1', $this->frequencyMsg);
        return true;
    }

    protected function getFrequencyMsgCacheKey($msg)
    {
        return self::FREQUENCY_MSG_CACHE_KEY . $this->configName . ':' . md5($msg);
    }

    /**
     * @param string $notice
     * @return string
     */
    protected function formatNotice(string $notice)
    {
        if (class_exists(\Dleno\CommonCore\Tools\Client::class)) {
            $ip = \Dleno\CommonCore\Tools\Client::getIP();
        } else {
            $ip = '';
        }

        // 兼容本地进程执行的情况
        if (Context::has(ServerRequestInterface::class)) {
            $request = ApplicationContext::getContainer()
                                         ->get(RequestInterface::class);
        } elseif (class_exists(WsContext::class) && WsContext::has(ServerRequestInterface::class)) {
            $request = WsContext::get(ServerRequestInterface::class);
        } else {
            $request = null;
        }

        if (is_null($request)) {
            $requestUrl = 'local';
            $method     = 'local';
            $params     = 'local';
            $headers    = 'local';
        } else {
            $requestUrl = $request->getUri();
            $method     = $request->getMethod();
            /** @noinspection JsonEncodingApiUsageInspection */
            $params  = json_encode($request->all());
            $headers = $this->getHeader($request);
            $headers = json_encode($headers);
        }

        $hostName = gethostname();
        $env      = config('app_env');

        if (class_exists(Server::class)) {
            $traceId = Server::getTraceId();
        } else {
            $traceId = null;
        }

        return $this->formatMessage(
            [
                ['通知消息' => "[{$this->name}]-[{$env}]"],
                ['主机名称' => $hostName],
                ['请求地址' => "[$method]" . $requestUrl],
                ['请求头部' => $headers],
                ['请求参数' => $params],
                ['请求追踪' => $traceId . "($ip)"],
                ['消息时间' => date('Y-m-d H:i:s')],
                ['消息内容' => $notice],
            ],
            self::MSG_TYPE_NOTICE
        );
    }

    /**
     * @param \Throwable $exception
     * @return string
     */
    protected function formatException(\Throwable $exception)
    {
        $class   = get_class($exception);
        $message = $exception->getMessage();
        $file    = $exception->getFile();
        $line    = $exception->getLine();

        if (class_exists(\Dleno\CommonCore\Tools\Client::class)) {
            $ip = \Dleno\CommonCore\Tools\Client::getIP();
        } else {
            $ip = null;
        }

        //兼容 HTTP/WS/LOCAL
        if (Context::has(ServerRequestInterface::class)) {
            $request = ApplicationContext::getContainer()
                                         ->get(RequestInterface::class);
        } elseif (class_exists(WsContext::class) && WsContext::has(ServerRequestInterface::class)) {
            $request = WsContext::get(ServerRequestInterface::class);
        } else {
            $request = null;
        }

        if (is_null($request)) {
            $requestUrl = 'local';
            $method     = 'local';
            $params     = 'local';
            $headers    = 'local';
        } else {
            $requestUrl = $request->getUri();
            $method     = $request->getMethod();
            /** @noinspection JsonEncodingApiUsageInspection */
            $params  = json_encode($request->all());
            $headers = $this->getHeader($request);
            $headers = json_encode($headers);
        }

        $hostName = gethostname();
        $env      = config('app_env');

        if (class_exists(Server::class)) {
            $traceId = Server::getTraceId();
        } else {
            $traceId = null;
        }

        $messageBody = [
            ['异常消息' => "[{$this->name}]-[{$env}]"],
            ['主机名称' => $hostName],
            ['请求地址' => "[$method]" . $requestUrl],
            ['请求头部' => $headers],
            ['请求参数' => $params],
            ['请求追踪' => $traceId . "($ip)"],
            ['异常时间' => date('Y-m-d H:i:s')],
            ['异常类名' => $class],
            ['异常描述' => $message],
            ['参考位置' => sprintf('%s:%d', str_replace([BASE_PATH, '\\'], ['', '/'], $file), $line)],
        ];

        $explode = explode("\n", $exception->getTraceAsString());
        array_unshift($explode, '');
        if ($explode) {
            $messageBody[] = [
                '堆栈信息' => PHP_EOL . '>' . implode(PHP_EOL . '> - ', $explode),
            ];
        }

        return $this->formatMessage($messageBody, self::MSG_TYPE_EXCEPTION);
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
    protected function formatMessage(array $messageBody, $msgType)
    {
        $i           = 0;
        $messageBody = array_walk(
            $messageBody,
            function (&$val, $key) use ($i, $msgType) {
                if ($i <= 0) {
                    $color = $msgType == self::MSG_TYPE_EXCEPTION ? 'f00' : '00f';
                    $val   = sprintf('### <font color=#' . $color . '>%s::</font> %s> %s', $key, PHP_EOL, $val);
                } else {
                    $val = sprintf('- %s: %s> %s', $key, PHP_EOL, $val);
                }
            }
        );

        return join(PHP_EOL, $messageBody);
    }

    /**
     * @param string $title
     * @param string $type
     * @param string $content
     * @param string $contentType
     * @return bool
     */
    protected function ding(string $title, string $type, string $content, string $contentType = 'content')
    {
        if ($type === 'markdown') {
            $contentType = 'text';
        }
        return $this->sendMessage(
            [
                'msgtype' => $type,
                $type     => [
                    'title'      => $title,
                    $contentType => $content,
                ],
            ]
        );
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
            $this->logger->error('DingTalk Send Fail:' . array_to_json($result));
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
        $cacheKey = $this->getFrequencyRobotCacheKey($robot);
        $thisNum  = $this->redis->incr($cacheKey);
        if ($thisNum <= 1) {
            $this->redis->expire($cacheKey, 60);
        }
        if ($thisNum > $this->frequencyRobot) {
            return false;
        }

        return true;
    }

    protected function getFrequencyRobotCacheKey($robot)
    {
        return self::FREQUENCY_ROBOT_CACHE_KEY . $robot;
    }
}
