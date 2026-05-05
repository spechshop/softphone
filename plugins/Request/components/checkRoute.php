<?php

namespace plugins\Request;

use plugins\Start\cache;

class checkRoute
{
    public static function check($path, $response): ?array
    {
        $e = explode('.', $path);
        $fileTypeKey = count($e) - 1;
        $fileType = $e[$fileTypeKey];
        if (!strpos($path, '.')) return ['break' => false];
        if (empty($e[$fileTypeKey]) or $e[$fileTypeKey] == '/') return ['break' => false];


        $staticFiles = cache::global()['interface']['staticFiles'] ?? ['/'];

        if (array_key_exists($path, $staticFiles)) {
            $ext = pathinfo($staticFiles[$path], PATHINFO_EXTENSION);
            $mime = $GLOBALS['interface']['allowExtensions'][$ext] ?? 'application/octet-stream';
            $response->header('Content-Type', $mime);
            $response->status(200);
            $response->sendfile($staticFiles[$path]);
            return ['break' => true];
        }


        if (!key_exists($fileType, $GLOBALS['interface']['allowExtensions'])) {
            $response->status(401, 'Not authorized');
            $response->end();
            return ['break' => true];
        }
        $filePath = explode('/plugins', sprintf("%s{$path}", __DIR__))[0] . $path;
        if (!file_exists($filePath)) {
            $response->status(404, 'File not found');
            $response->end();
            return ['break' => true];
        }
        foreach ($GLOBALS['interface']['allowExtensions'] as $extension => $typeContent) {
            if ($extension == $fileType) {
                $content = file_get_contents($filePath);
                $response->header('Content-Type', $typeContent);
                $response->end($content);
                return ['break' => true];
            }
        }
        $response->status(500, 'Internal Server Error');
        $response->end();
        return ['break' => true];
    }
}