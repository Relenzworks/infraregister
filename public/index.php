<?php

declare(strict_types=1);

use RelenzWorks\InfraRegister\Infrastructure\Http\AssetWebApp;
use RelenzWorks\InfraRegister\Infrastructure\Http\StorePathResolver;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = dirname(__DIR__);
$basePath = realpath($projectRoot);
$storePath = StorePathResolver::resolve(getenv('INFRAREGISTER_STORE'), $_SERVER, $projectRoot);
$writeAuth = getenv('INFRAREGISTER_WRITE_AUTH');

$response = AssetWebApp::fromStore($storePath, $basePath === false ? null : $basePath, $writeAuth === false ? null : $writeAuth)
    ->handle(Request::createFromGlobals());

$response->send();
