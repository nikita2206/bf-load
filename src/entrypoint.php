<?php

require __DIR__ . "/../vendor/autoload.php";

$app = new \Symfony\Component\Console\Application();

$app->add(new \test\SwitchoverDbUsingProxySql());

$app->run();
