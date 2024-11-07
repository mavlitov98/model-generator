<?php

require __DIR__ . '/../vendor/autoload.php';

use ModelGenerator\ModelGeneratorCommand;
use Symfony\Component\Console\Application;

$application = new Application();
$application->add(new ModelGeneratorCommand());
$application->run();
