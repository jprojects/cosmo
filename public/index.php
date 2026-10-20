<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';

(new Cosmo\Core\App($config))->run();
