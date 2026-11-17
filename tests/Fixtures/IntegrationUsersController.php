<?php

declare(strict_types=1);

namespace Infocyph\Webrick\Tests\Fixture;

use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class IntegrationUsersController
{
    /**
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public function create(): Response
    {
        return Response::json(['action' => 'create']);
    }

    /**
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public function destroy(string $id): Response
    {
        return Response::json(['action' => 'destroy', 'id' => $id]);
    }

    /**
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public function edit(string $id): Response
    {
        return Response::json(['action' => 'edit', 'id' => $id]);
    }

    /**
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public function index(): Response
    {
        return Response::json(['action' => 'index']);
    }

    /**
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public function show(string $id): Response
    {
        return Response::json(['action' => 'show', 'id' => $id]);
    }

    /**
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public function store(Request $r): Response
    {
        return Response::json(['action' => 'store', 'data' => $r->all()], 201);
    }

    /**
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public function update(Request $r, string $id): Response
    {
        return Response::json(['action' => 'update', 'id' => $id, 'data' => $r->all()]);
    }
}
