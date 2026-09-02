<?php

namespace helpers\utils;

/** Extracts MESSAGE payloads independently of Content-Type parameters. */
class SipMessageBody
{
    public static function extract(string $packet, array $parsed = []): string
    {
        if (array_key_exists('body', $parsed)) return (string)$parsed['body'];

        $separatorPos = strpos($packet, "\r\n\r\n");
        $separatorLength = 4;
        if ($separatorPos === false) {
            $separatorPos = strpos($packet, "\n\n");
            $separatorLength = 2;
        }
        if ($separatorPos === false) return '';

        $headers = substr($packet, 0, $separatorPos);
        $body = substr($packet, $separatorPos + $separatorLength);
        if (!preg_match('/(?:^|\r?\n)Content-Length\s*:\s*(\d+)/i', $headers, $match)) return $body;

        $length = (int)$match[1];
        if ($length <= 0) return '';
        return substr($body, 0, $length);
    }
}
