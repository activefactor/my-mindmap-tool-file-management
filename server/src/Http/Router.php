<?php

declare(strict_types=1);

namespace App\Http;

/**
 * 最小限のルーター。パターン中の {name} を1セグメントのプレースホルダとして扱う。
 * 依存を増やさない方針（基本設計書_Phase2.md §9）に沿い自前実装とする。
 */
final class Router
{
    /** @var array<int, array{method: string, regex: string, params: array<int, string>, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $params = [];

        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            function (array $m) use (&$params): string {
                $params[] = $m[1];

                return '([^/]+)';
            },
            $pattern
        );

        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
        ];
    }

    /**
     * @return array{handler: callable, params: array<int, string>}|null マッチしない場合は null
     */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== strtoupper($method)) {
                continue;
            }

            if (preg_match($route['regex'], $path, $matches) === 1) {
                array_shift($matches);

                return ['handler' => $route['handler'], 'params' => $matches];
            }
        }

        return null;
    }

    /** 指定パスに対して、メソッド違いのルートが存在するか（405判定用）。 */
    public function pathExists(string $path): bool
    {
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path) === 1) {
                return true;
            }
        }

        return false;
    }
}
