<?php
declare(strict_types=1);

namespace Dleno\DingTalk;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config for dingtalk.',
                    'source' => __DIR__ . '/../publish/dingtalk.php',
                    'destination' => BASE_PATH . '/config/autoload/dingtalk.php'
                ]
            ],
            'dependencies' => [
            ],
            'commands' => [
            ],
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
        ];
    }
}
