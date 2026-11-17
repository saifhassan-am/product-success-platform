<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

foreach (['Time', 'Http', 'Db', 'Products', 'StageEngine', 'Ingest', 'Console', 'Api'] as $class) {
    require_once __DIR__ . "/$class.php";
}

Db::migrate();
