<?php

declare(strict_types=1);

use RelenzWorks\InfraRegister\Infrastructure\Http\AssetWebApp;
use RelenzWorks\InfraRegister\Infrastructure\Http\StorePathResolver;
use RelenzWorks\InfraRegister\Infrastructure\Security\UserDirectoryFactory;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = dirname(__DIR__);
$basePath = realpath($projectRoot);
$storePath = StorePathResolver::resolve(getenv('INFRAREGISTER_STORE'), $_SERVER, $projectRoot);
$environment = [
    'INFRAREGISTER_WRITE_AUTH' => getenv('INFRAREGISTER_WRITE_AUTH'),
    'INFRAREGISTER_LOCAL_USERS' => getenv('INFRAREGISTER_LOCAL_USERS'),
    'INFRAREGISTER_LDAP_URI' => getenv('INFRAREGISTER_LDAP_URI'),
    'INFRAREGISTER_LDAP_BASE_DN' => getenv('INFRAREGISTER_LDAP_BASE_DN'),
    'INFRAREGISTER_LDAP_USER_FILTER' => getenv('INFRAREGISTER_LDAP_USER_FILTER'),
    'INFRAREGISTER_LDAP_BIND_DN' => getenv('INFRAREGISTER_LDAP_BIND_DN'),
    'INFRAREGISTER_LDAP_BIND_PASSWORD' => getenv('INFRAREGISTER_LDAP_BIND_PASSWORD'),
    'INFRAREGISTER_LDAP_GROUP_ROLE_MAP' => getenv('INFRAREGISTER_LDAP_GROUP_ROLE_MAP'),
];

$response = AssetWebApp::fromStore($storePath, $basePath === false ? null : $basePath)
    ->withUserDirectory(UserDirectoryFactory::fromEnvironment($environment))
    ->handle(Request::createFromGlobals());

$response->send();
