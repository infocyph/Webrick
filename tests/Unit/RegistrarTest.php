<?php

declare(strict_types=1);

use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Route\Collection;

describe('Registrar', function () {
    beforeEach(function () {
        $this->routes = new Collection();
        $this->registrar = new Registrar(
            routes: $this->routes,
            autoSlashRedirect: false,
            exposeUrlServices: false
        );
    });

    it('registers GET routes', function () {
        $this->registrar->get('/users', fn () => Response::json([]), 'users.index');

        $route = $this->routes->findByName('users.index');

        expect($route)
            ->not->toBeNull()
            ->getMethod()->toBe('GET')
            ->getPath()->toBe('/users')
            ->getName()->toBe('users.index');
    });

    it('registers POST routes', function () {
        $this->registrar->post('/users', fn () => Response::json([]), 'users.store');

        $route = $this->routes->findByName('users.store');
        expect($route->getMethod())->toBe('POST');
    });

    it('registers routes with middleware', function () {
        $this->registrar->get('/admin', fn () => 'admin', [
            'middleware' => ['AuthMiddleware'],
        ]);

        $routes = $this->routes->all();
        expect($routes[0]->getMiddlewares())->toBe(['AuthMiddleware']);
    });

    it('registers resource routes', function () {
        $this->registrar->resource('posts', '/posts', 'PostController');

        $index = $this->routes->findByName('posts.index');
        $create = $this->routes->findByName('posts.create');
        $store = $this->routes->findByName('posts.store');
        $show = $this->routes->findByName('posts.show');
        $edit = $this->routes->findByName('posts.edit');
        $update = $this->routes->findByName('posts.update');
        $destroy = $this->routes->findByName('posts.destroy');

        expect($index)->not
            ->toBeNull()
            ->and($create)->not
            ->toBeNull()
            ->and($store)->not
            ->toBeNull()
            ->and($show)->not
            ->toBeNull()
            ->and($edit)->not
            ->toBeNull()
            ->and($update)->not
            ->toBeNull()
            ->and($destroy)->not
            ->toBeNull()
            ->and($index->getMethod())->toBe('GET')
            ->and($store->getMethod())->toBe('POST')
            ->and($update->getMethod())->toBe('PUT')
            ->and($destroy->getMethod())->toBe('DELETE');
    });

    it('handles route groups', function () {
        $this->registrar->group(
            prefix: '/api',
            namePrefix: 'api.',
            callback: function (Registrar $r) {
                $r->get('/users', fn () => 'users', 'users');
            }
        );

        $route = $this->routes->findByName('api.users');

        expect($route)
            ->not->toBeNull()
            ->getPath()->toBe('/api/users')
            ->getName()->toBe('api.users');
    });

    it('handles nested groups', function () {
        $this->registrar->group(
            prefix: '/admin',
            namePrefix: 'admin.',
            callback: function (Registrar $r) {
                $r->group(
                    prefix: '/users',
                    namePrefix: 'users.',
                    callback: function (Registrar $r2) {
                        $r2->get('/', fn () => 'list', 'index');
                    }
                );
            }
        );

        $route = $this->routes->findByName('admin.users.index');

        expect($route)->not->toBeNull();

        // Accept with or without trailing slash
        $path = $route->getPath();
        expect(in_array($path, ['/admin/users', '/admin/users/'], true))
            ->toBeTrue()
            ->and($route->getName())->toBe('admin.users.index');
    });

    it('applies group middleware', function () {
        $this->registrar->group(
            prefix: '/api',
            middleware: ['ApiMiddleware'],
            callback: function (Registrar $r) {
                $r->get('/test', fn () => 'test');
            }
        );

        $routes = $this->routes->all();
        expect($routes[0]->getMiddlewares())->toContain('ApiMiddleware');
    });
});
