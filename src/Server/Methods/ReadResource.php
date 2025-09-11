<?php

declare(strict_types=1);

namespace Laravel\Mcp\Server\Methods;

use Illuminate\Support\ItemNotFoundException;
use InvalidArgumentException;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;

class ReadResource implements Method
{
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        if (is_null($request->get('uri'))) {
            throw new InvalidArgumentException('Missing required parameter: uri');
        }

        $resource = $context->resources()->first(fn (Resource $resource): bool => $resource->uri() === $request->get('uri'));

        if (is_null($resource)) {
            throw new ItemNotFoundException('Resource not found');
        }

        return JsonRpcResponse::result(
            $request->id,
            $resource->handle()->toArray(),
        );
    }
}
