<?php

use Dleno\DingTalk\Robot;

if (!function_exists('ding_talk')) {
    /**
     * @param array $config
     * @return Robot
     * @throws \Exception
     */
    function ding_talk($configName = null)
    {
        return Robot::get($configName);
    }
}
