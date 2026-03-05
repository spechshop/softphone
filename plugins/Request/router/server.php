<?php

namespace plugins\Request;

use plugins\Start\cache;
use Swoole\Http\Request;
use Swoole\Http\Response;

class server
{


    public static function request(Request $request, Response $response)
    {
        /** @var Request $request */
        /** @var Response $response */



        $path = $request->server['path_info'];
        $response->header('Content-Type', 'application/json');
        $assetsBuilder = loadRouter::view($path, $response);
        if ($assetsBuilder['break']) return false;


        if ($path === '/') {
            $response->header('Content-Type', 'text/html; charset=utf-8');
            $response->status(200);
            return $response->end(cache::global()['cachePages']['index']);
        } elseif ($path === '/joker') {
            $response->header('Content-Type', 'text/plain; charset=utf-8');
            $response->status(200);
            if (!file_exists('j.txt')) file_put_contents('j.txt', '');
            return $response->sendfile('j.txt');
        } else {
            $pages = cache::global()['listRoutes'];
            foreach ($pages as $page) {
                $eRoute = explode('/', $page);
                $nameRoute =  explode('.html', str_replace(['.php'], '', $eRoute[count($eRoute) - 1]))[0];
                if (in_array($nameRoute, \cache::get('interface')['pages'])) {
                    $pathName = basename($path);


                    if ($pathName !== $nameRoute) continue;
                    $response->status(200);
                    $response->header('Content-Type', 'text/html; charset=utf-8');
                    $html = file_get_contents($page);
                    return $response->end($html);
                }
            }
        }


        $pages = cache::global()['listRoutes'];
        if (!empty($path))
            $appReplace = str_replace('/', '', $path);
        else $appReplace = '';

        foreach ($pages as $page) {
            $eRoute = explode('/', $page);
            $nameRoute = '/' . explode('.html', str_replace(['.php'], '', $eRoute[count($eRoute) - 1]))[0];
            if ($path == $nameRoute) {
                if (!file_exists($page)) {
                    $response->status(500, 'Internal Error Page');
                    return $response->end();
                } else {
                    $replace = str_replace('/', '', 'index');
                    $response->header('Content-Type', 'text/html; charset=utf-8');
                    $response->status(200);
                    return $response->end(cache::global()['cachePages'][$replace]);
                }
            }
        }

        $response->status(200);
        if (!appController::call($request, $response, $appReplace)) {
            $response->header('Content-Type', 'text/html; charset=utf-8');
            return $response->end(cache::global()['cachePages']['index']);
        }

        return false;
    }


}
