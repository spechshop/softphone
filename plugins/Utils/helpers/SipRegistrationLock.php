<?php

namespace helpers\utils;

/** Cross-worker, same-host lock for one persistent SIP account identity. */
final class SipRegistrationLock
{
    /** @return resource|false */
    public static function acquire(string $fp): mixed
    {
        $lockDir = DataPath::dir() . DIRECTORY_SEPARATOR . 'sip-registration-locks';
        if (!is_dir($lockDir) && !@mkdir($lockDir, 0700, true) && !is_dir($lockDir)) {
            throw new \RuntimeException('cannot create registration lock directory');
        }
        $lock = @fopen($lockDir . DIRECTORY_SEPARATOR . hash('sha256', $fp) . '.lock', 'c');
        if ($lock === false) throw new \RuntimeException('cannot open registration lock');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return false;
        }
        return $lock;
    }

    /** @param resource $lock */
    public static function release(mixed $lock): void
    {
        if (!is_resource($lock)) return;
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
