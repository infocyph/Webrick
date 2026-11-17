<?php

declare(strict_types=1);

use Infocyph\Webrick\Request\Core\ServerRequest;
use Infocyph\Webrick\Request\Core\Uri;
use Infocyph\Webrick\Request\Request;

test('request fields are instance-owned even when cookies mimic URI attributes', function (): void {
    $first = new ServerRequest('GET', '/first', cookies: [
        'uri' => 'php://filter/resource=/etc/passwd',
        'path' => '../../outside',
    ]);
    $second = new ServerRequest('GET', '/second', cookies: ['path' => '/malicious']);

    expect($first->getUri()->getPath())->toBe('/first')
        ->and($second->getUri()->getPath())->toBe('/second')
        ->and($first->getCookieParams()['path'])->toBe('../../outside')
        ->and($second->getCookieParams()['path'])->toBe('/malicious');
});

test('URI cloning and public request reconstruction do not mix caller state', function (): void {
    $original = new Uri('/original');
    $modified = $original->withPath('/changed');
    $request = Request::fake(query: ['path' => '../../danger'], uri: '/request');

    expect($original->getPath())->toBe('/original')
        ->and($modified->getPath())->toBe('/changed')
        ->and($request->getUri()->getPath())->toBe('/request')
        ->and($request->query('path'))->toBe('../../danger');
});
