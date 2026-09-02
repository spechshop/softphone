<?php

namespace helpers\utils;

/**
 * Single source of truth for writable, persistent application state.
 * /tmp is intentionally never used as an implicit fallback.
 */
class DataPath
{
    private static ?string $overrideDir = null;

    public static function dir(): string
    {
        $configured = self::$overrideDir ?? trim((string)getenv('SPECH_DATA_DIR'));
        if ($configured !== '') return self::ensureDirectory($configured);

        $xdg = trim((string)getenv('XDG_STATE_HOME'));
        if ($xdg !== '') return self::ensureDirectory($xdg . DIRECTORY_SEPARATOR . 'spechphone');

        $home = trim((string)getenv('HOME'));
        if ($home === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $user = posix_getpwuid(posix_geteuid());
            $home = is_array($user) ? trim((string)($user['dir'] ?? '')) : '';
        }
        if ($home === '') {
            throw new \RuntimeException('Defina SPECH_DATA_DIR: HOME/XDG_STATE_HOME não estão disponíveis');
        }

        return self::ensureDirectory($home . DIRECTORY_SEPARATOR . '.local/state/spechphone');
    }

    public static function file(string $filename): string
    {
        if ($filename === '' || basename($filename) !== $filename || str_contains($filename, "\0")) {
            throw new \InvalidArgumentException('Nome de arquivo de dados inválido');
        }
        return self::dir() . DIRECTORY_SEPARATOR . $filename;
    }

    /** @return list<string> */
    public static function legacyFiles(string $filename): array
    {
        if (basename($filename) !== $filename) throw new \InvalidArgumentException('Nome de arquivo de dados inválido');
        return [
            '/data/spechphone/' . $filename,
            '/tmp/data/spechphone/' . $filename,
        ];
    }

    /**
     * Adopt the highest-priority legacy file only when no new file exists.
     * The source remains untouched and the copied bytes are verified.
     */
    public static function migrateFirstExisting(string $filename, ?array $legacyFiles = null): ?string
    {
        $target = self::file($filename);
        if (is_file($target)) {
            @chmod($target, 0600);
            return null;
        }
        foreach ($legacyFiles ?? self::legacyFiles($filename) as $source) {
            if (!is_file($source) || !is_readable($source)) continue;
            $tmp = $target . '.tmp.' . bin2hex(random_bytes(6));
            if (!@copy($source, $tmp)) continue;
            @chmod($tmp, 0600);
            $sourceHash = @hash_file('sha256', $source);
            $copyHash = @hash_file('sha256', $tmp);
            if ($sourceHash === false || !hash_equals($sourceHash, (string)$copyHash) || !@rename($tmp, $target)) {
                @unlink($tmp);
                continue;
            }
            return $source;
        }
        return null;
    }

    /** Test-only/process-local override; production should use SPECH_DATA_DIR. */
    public static function setDir(?string $dir): void
    {
        self::$overrideDir = $dir === null ? null : rtrim($dir, DIRECTORY_SEPARATOR);
    }

    private static function ensureDirectory(string $dir): string
    {
        $dir = rtrim($dir, DIRECTORY_SEPARATOR);
        if ($dir === '') throw new \RuntimeException('Diretório de dados vazio');
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Não foi possível criar o diretório de dados: {$dir}");
        }
        if (!is_writable($dir)) throw new \RuntimeException("Diretório de dados sem permissão de escrita: {$dir}");
        @chmod($dir, 0700);
        $real = realpath($dir);
        return $real !== false ? $real : $dir;
    }
}
