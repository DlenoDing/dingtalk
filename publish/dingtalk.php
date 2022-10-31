<?php

return [
    //异常追踪机器人配置(为空或对应配置不存在)
    'trace'   => 'default',
    'redis'   => 'default',
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
