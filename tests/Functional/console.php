<?php

require __DIR__.'/../bootstrap.php';

use SimpleBus\RabbitMQBundleBridge\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\ErrorHandler\Debug;

$input = new ArgvInput();

Debug::enable();

$kernel = new TestKernel($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'test');
$application = new Application($kernel);
$application->run($input);
