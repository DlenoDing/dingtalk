<?php

return [
    //异常追踪机器人配置(为空或对应配置不存在)
    'trace'   => 'default',
    'redis'   => 'default',
    //异常消息去重方式（可在分组内用同名键覆盖）：
    //message（默认）按异常消息原文；fingerprint 按异常类 + code + 出错位置 + 去除变量（数字、引号内容、长十六进制串）后的消息
    'exception_dedup' => 'message',
    'configs' => [
        'default' => [
            'enable'    => true,
            'name'      => '默认机器人',
            'frequency' => 60,
            'token'     => 'access_token',
            'secret'    => 'secret',
        ],
        'trace'   => [
            'enable'    => false,
            'name'      => '异常追踪机器人',
            'frequency' => 60,
            'configs'   => [
                [
                    'token'  => 'access_token',
                    'secret' => 'secret',
                ],
                [
                    'token'  => 'access_token',
                    'secret' => 'secret',
                ],
            ],
        ],
    ],

];
