<?php

namespace plugins\Extension;
class StringUtils
{
    private static $internalEncryptKey = 'circodesolerokkkk_lotus';

    public static function getString($stringBody, $startDelimiter, $endOfDelimiter): ?string
    {
        return @explode($endOfDelimiter, explode($startDelimiter, $stringBody)[1])[0];
    }

    public static function internalEncrypt(string $string): string
    {
        return base64_encode(openssl_encrypt($string, 'AES-128-ECB', self::$internalEncryptKey));
    }

    public static function internalDecrypt($string)
    {
        if (!is_string($string)) return $string;
        $d = openssl_decrypt(base64_decode($string), 'AES-128-ECB', self::$internalEncryptKey);
        if (is_string($d)) {
            return json_decode($d, true);
        } else {
            return $d;
        }
    }

    /**
     * @param bool $maskDocument
     * @param $state
     * @return string|null
     */
    public static function cpfRandom(bool $maskingDocument = false, int $state = 0): ?string
    {
        $numbers = [];
        for ($i = 0; $i < 9; $i++) {
            $numbers[] = $state && $i == 8 ? $state : rand(0, 9);
        }

        $numbers[] = self::calculateDigit($numbers, 10);
        $numbers[] = self::calculateDigit($numbers, 11);

        $cpf = implode('', $numbers);

        return $maskingDocument ? self::maskCpf($cpf) : $cpf;
    }

    private static function calculateDigit(array $numbers, int $length): int
    {
        $calculation = 0;
        foreach ($numbers as $index => $number) {
            $calculation += $number * ($length - $index);
        }

        $digit = 11 - self::mod($calculation);

        return $digit >= 10 ? 0 : $digit;
    }

    private static function mod($dividend): float
    {
        return round($dividend - (floor($dividend / 11) * 11));
    }

    private static function maskCpf(string $cpf): string
    {
        return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
    }

    public static function documentIsValid($number): ?bool
    {
        $documentNumber = preg_replace('/[^0-9]/is', '', $number);
        if (strlen($documentNumber) != 11) {
            return false;
        }
        if (preg_match('/(\d)\1{10}/', $documentNumber)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            for ($d = 0, $c = 0; $c < $t; $c++) {
                $d += $documentNumber[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ($documentNumber[$c] != $d) {
                return false;
            }
        }
        return true;
    }
}