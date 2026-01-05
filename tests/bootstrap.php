<?php

use Symfony\Component\ErrorHandler\ErrorHandler;

foreach ([__DIR__.'/../vendor/autoload.php', __DIR__.'/../../../vendor/autoload.php'] as $file) {
    if (file_exists($file)) {
        require $file;

        break;
    }
}

set_exception_handler([new ErrorHandler(), 'handleException']);
