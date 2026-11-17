<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Tests\Fixture;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class IntegrationUsersController
{
    public function create(): Response
    {
        return Response::json(['action' => 'create']);
    }

    public function destroy(string $id): Response
    {
        return Response::json(['action' => 'destroy', 'id' => $id]);
    }

    public function edit(string $id): Response
    {
        return Response::json(['action' => 'edit', 'id' => $id]);
    }

    public function index(): Response
    {
        return Response::json(['action' => 'index']);
    }

    public function show(string $id): Response
    {
        return Response::json(['action' => 'show', 'id' => $id]);
    }

    public function store(Request $r): Response
    {
        return Response::json(['action' => 'store', 'data' => $r->all()], 201);
    }

    public function update(Request $r, string $id): Response
    {
        return Response::json(['action' => 'update', 'id' => $id, 'data' => $r->all()]);
    }
}
