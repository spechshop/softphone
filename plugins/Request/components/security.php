<?php

namespace plugins\Request;

use plugins\Start\cache;
use Swoole\Http\Request;
use Swoole\Http\Response;

class security
{
    public static function verifyToken(Request $request): ?bool
    {
        $_GET = $request->get;
        $_POST = $request->post;
        if (!empty($_GET['tokenBrowser'])) $tokenBrowser = $_GET['tokenBrowser'];
        if (!empty($_POST['tokenBrowser'])) $tokenBrowser = $_POST['tokenBrowser'];
        if (empty($tokenBrowser)) {
            return false;
        } elseif (!key_exists($tokenBrowser, cache::global()['dataKeys'])) {
            return false;
        } elseif (strtotime(date('Y-m-d H:i:s')) >= cache::global()['dataKeys'][$tokenBrowser]['expire']) {
            return false;
        }
        return true;
    }

    public static function invalidToken(Response $response): ?bool
    {
        $response->header('Content-Type', 'application/json');
        return $response->end(json_encode([
            'success' => false,
            'message' => 'Identifier not found'
        ]));
    }

    public static function sanitizeInput($input): ?string
    {
        return htmlspecialchars(strip_tags(trim($input)));
    }

    public static function writeChat(array $data): ?bool
    {
        $nameFile = appController::baseDir() . '/4o';
        return false;
    }


}