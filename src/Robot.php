<?php

namespace Dleno\DingTalk;

use Dleno\CommonCore\Tools\Client;
use Dleno\CommonCore\Tools\Server;
use GuzzleHttp\Client as HttpClient;
use Hyperf\Context\Context;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Redis\RedisFactory;
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

    protected static $robots = [];

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
        $this->logger = ApplicationContext::getContainer()
                                          ->get(StdoutLoggerInterface::class);

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
        $this->configs      = $config['configs'] ?? [];
        $this->client       = new HttpClient();
    }

    /**
     * @param string $configName
     * @return Robot
     */
    public static function get(string $configName = null)
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
    public function text(string $text)
    {
        if ($this->checkFrequencyMsg($text)) {
            $text .= "\r\n请求追踪:" . Server::getTraceId();
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
            $markdown .= "#### 请求追踪:" . Server::getTraceId();
            return $this->ding('Markdown', 'markdown', $markdown);
        }
        return false;
    }

    /**
     * @param string $notice
     * @param array $data
     * @return bool
     */
    public function notice(string $notice, $data = [])
    {
        if ($this->checkFrequencyMsg($notice)) {
            return $this->ding('Notice', 'markdown', $this->formatNotice($notice, $data));
        }
        return false;
    }

    /**
     * @param \Throwable $e
     * @param array $data
     * @return bool
     */
    public function exception(\Throwable $e, $data = [])
    {
        if ($this->checkFrequencyMsg($e->getMessage())) {
            return $this->ding('Exception', 'markdown', $this->formatException($e, $data));
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
        $redis    = get_inject_obj(RedisFactory::class)->get(config('dingtalk.redis', 'default'));
        $cacheKey = $this->getFrequencyMsgCacheKey($msg);
        if ($redis->exists($cacheKey)) {
            return false;
        }
        $redis->set($cacheKey, '1', $this->frequencyMsg);
        return true;
    }

    protected function getFrequencyMsgCacheKey($msg)
    {
        return self::FREQUENCY_MSG_CACHE_KEY . $this->configName . ':' . md5($msg);
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
            $params     = json_encode($request->all());
            $headers    = $this->getHeader($request);
            $headers    = json_encode($headers);
        }
        $messageBody         = [];
        $messageBody['通知消息'] = config('app_name') . "({$this->name})-[" . config('app_env') . "]";
        $messageBody['主机地址'] = Server::getIpAddr() . ($isLocal ? '(LOCAL)' : '(REQUEST)');
        if (!$isLocal) {
            $messageBody['请求地址'] = "[$method]" . $requestUrl;
            $messageBody['请求头部'] = $headers;
            $messageBody['请求参数'] = $params;
            $messageBody['请求IP'] = Client::getIP();
        }
        $messageBody['请求追踪'] = Server::getTraceId();
        $messageBody['消息时间'] = date('Y-m-d H:i:s');
        $messageBody['消息内容'] = $notice;
        if (!empty($data)) {
            $messageBody['数据内容'] = array_to_json($data);
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
            $params     = json_encode($request->all());
            $headers    = $this->getHeader($request);
            $headers    = json_encode($headers);
        }

        $messageBody         = [];
        $messageBody['异常消息'] = config('app_name') . "({$this->name})-[" . config('app_env') . "]";
        $messageBody['主机地址'] = Server::getIpAddr() . ($isLocal ? '(LOCAL)' : '(REQUEST)');
        if (!$isLocal) {
            $messageBody['请求地址'] = "[$method]" . $requestUrl;
            $messageBody['请求头部'] = $headers;
            $messageBody['请求参数'] = $params;
            $messageBody['请求IP'] = Client::getIP();
        }
        $messageBody['请求追踪'] = Server::getTraceId();
        $messageBody['异常时间'] = date('Y-m-d H:i:s');
        $messageBody['异常类名'] = get_class($exception);
        $messageBody['异常描述'] = $message;
        $messageBody['异常位置'] = sprintf('%s:%d', str_replace([BASE_PATH, '\\'], ['', '/'], $file), $line);
        if (!empty($data)) {
            $messageBody['数据内容'] = array_to_json($data);
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
        $redis    = get_inject_obj(RedisFactory::class)->get(config('dingtalk.redis', 'default'));
        $cacheKey = $this->getFrequencyRobotCacheKey($robot);
        if ($redis->exists($cacheKey)) {
            $thisNum = $redis->incr($cacheKey);
            $ttl     = $redis->ttl($cacheKey);
            if ($ttl <= 0) {
                //TODO 极端情况下的兜底处理（exists与incr之间key失效会导致缓存永久有效[线上出现过该情况]）
                $redis->expire($cacheKey, 60);
            }
            if ($thisNum > $this->frequencyRobot) {
                return false;
            }
        } else {
            $redis->set($cacheKey, '1', 60);
        }
        return true;
    }

    protected function getFrequencyRobotCacheKey($robot)
    {
        return self::FREQUENCY_ROBOT_CACHE_KEY . $robot;
    }
}
