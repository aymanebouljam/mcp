<?php

declare(strict_types=1);

namespace Laravel\Mcp\Server;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Transport\HttpTransport;
use Laravel\Mcp\Server\Transport\StdioTransport;

class Registrar
{
    /** @var array<string, callable> */
    protected array $localServers = [];

    /** @var array<string, string> */
    protected array $registeredWebServers = [];

    /**
     * @param  class-string<Server>  $serverClass
     */
    public function web(string $route, string $serverClass): Route
    {
        $this->registeredWebServers[$route] = $serverClass;

        return Router::post($route, fn (): mixed => $this->startServer(
            $serverClass,
            fn (): HttpTransport => new HttpTransport(
                $request = request(),
                // @phpstan-ignore-next-line
                (string) $request->header('Mcp-Session-Id')
            ),
        ))->name('mcp-server.'.$route);
    }

    /**
     * @param  class-string<Server>  $serverClass
     */
    public function local(string $handle, string $serverClass): void
    {
        $this->localServers[$handle] = fn (): mixed => $this->startServer(
            $serverClass,
            fn (): StdioTransport => new StdioTransport(
                Str::uuid()->toString(),
            )
        );
    }

    public function getLocalServer(string $handle): ?callable
    {
        return $this->localServers[$handle] ?? null;
    }

    public function getWebServer(string $handle): ?string
    {
        return $this->registeredWebServers[$handle] ?? null;
    }

    public function oauthRoutes(string $oauthPrefix = 'oauth'): void
    {
        Router::get('/.well-known/oauth-protected-resource', fn () => response()->json([
            'resource' => config('app.url'),
            'authorization_server' => url('/.well-known/oauth-authorization-server'),
        ]));

        Router::get('/.well-known/oauth-authorization-server', fn () => response()->json([
            'issuer' => config('app.url'),
            'authorization_endpoint' => url($oauthPrefix.'/authorize'),
            'token_endpoint' => url($oauthPrefix.'/token'),
            'registration_endpoint' => url($oauthPrefix.'/register'),
            'response_types_supported' => ['code'],
            'code_challenge_methods_supported' => ['S256'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
        ]));

        Router::post($oauthPrefix.'/register', function (Request $request) {
            $clients = Container::getInstance()->make(
                "Laravel\Passport\ClientRepository"
            );

            $payload = $request->json()->all();

            $client = $clients->createAuthorizationCodeGrantClient(
                name: $payload['client_name'],
                redirectUris: $payload['redirect_uris'],
                confidential: false,
                user: null,
                enableDeviceFlow: false,
            );

            return response()->json([
                'client_id' => $client->id,
                'redirect_uris' => $client->redirect_uris,
            ]);
        });
    }

    /**
     * @param  class-string<Server>  $serverClass
     * @param  callable(): Transport  $transportFactory
     */
    protected function startServer(string $serverClass, callable $transportFactory): mixed
    {
        $transport = $transportFactory();

        $server = Container::getInstance()->make($serverClass, [
            'transport' => $transport,
        ]);

        $server->start();

        return $transport->run();
    }
}
