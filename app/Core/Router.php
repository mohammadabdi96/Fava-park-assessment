<?php

namespace Fava\Core;

class Router
{
    /** @var array[] */
    private $routes = [];

    /**
     * @param string $access 'admin' (login + CSRF on POST), 'guest' (login pages), 'public', 'install'
     */
    public function add(string $method, string $pattern, array $handler, string $access = 'admin'): void
    {
        $regex = preg_replace_callback('/\{(\w+)\}/', function ($m) {
            switch ($m[1]) {
                case 'id':
                    return '(?P<id>\d+)';
                case 'role':
                    return '(?P<role>tech|biz)';
                case 'token':
                    return '(?P<token>[A-Za-z0-9_\-]{20,64})';
                default:
                    return '(?P<' . $m[1] . '>[^/]+)';
            }
        }, $pattern);
        $this->routes[] = [$method, '#^' . $regex . '$#', $handler, $access];
    }

    public function get(string $pattern, array $handler, string $access = 'admin'): void
    {
        $this->add('GET', $pattern, $handler, $access);
    }

    public function post(string $pattern, array $handler, string $access = 'admin'): void
    {
        $this->add('POST', $pattern, $handler, $access);
    }

    /** @return array{0:array,1:string,2:array} handler, access, params */
    public function match(string $method, string $path): array
    {
        $pathMatched = false;
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        foreach ($this->routes as $route) {
            if (!preg_match($route[1], $path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route[0] !== $method) {
                continue;
            }
            $params = [];
            foreach ($m as $k => $v) {
                if (!is_int($k)) {
                    $params[$k] = $v;
                }
            }
            return [$route[2], $route[3], $params];
        }
        throw new HttpException($pathMatched ? 405 : 404, $pathMatched ? 'این عملیات مجاز نیست.' : 'صفحه مورد نظر پیدا نشد.');
    }
}
