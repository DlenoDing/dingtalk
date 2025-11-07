<?php

use Dleno\DingTalk\Robot;

/**
 * @param array $config
 * @return Robot
 * @throws \Exception
 */
function ding_talk($configName = null)
{
    return Robot::get($configName);
}

