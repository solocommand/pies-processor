<?php

use Symfony\Component\HttpFoundation\Request;

$loader = require __DIR__.'/../app/autoload.php';
include_once __DIR__.'/../var/bootstrap.php.cache';

$env   = strtolower(getenv('APP_ENV'));

if ('prod' === $env) {
    $kernel = new AppKernel('prod', false);
    $kernel->loadClassCache();
} else {
    $kernel = new AppKernel('dev', true);
}

$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
