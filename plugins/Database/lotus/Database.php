<?php

namespace plugins\lotus;

use plugins\Request\appController;

class Database
{
    public static function dumpAllTables(): ?array
    {
        $dir = appController::baseDir() . '/database/';
        $addressTokens = $dir . 'tokens.lotus';
        if (!file_exists($addressTokens)) file_put_contents($addressTokens, json_encode([]));
        $data = json_decode(file_get_contents($addressTokens), true);
        $tables = [];
        if (!empty($data)) {
            foreach ($data as $key => $value) {
                $tables[] = $key;
            }
        }
        return $tables;
    }

    public static function insert($table, $token, $data): void
    {
        $dir = appController::baseDir() . '/database/';
        $addressTokens = $dir . 'tokens.lotus';
        if (!file_exists($addressTokens)) file_put_contents($addressTokens, json_encode([]));
        $dataTokens = json_decode(file_get_contents($addressTokens), true);
        if (!array_key_exists($table, $dataTokens)) {
            $dataTokens[$table] = [];
        }
        $dataTokens[$table][$token][] = $data;
        file_put_contents($addressTokens, json_encode($dataTokens));
    }


    public static function exists($table): bool
    {
        $dir = appController::baseDir() . '/database/';
        $addressTokens = $dir . 'tokens.lotus';
        if (!file_exists($addressTokens)) file_put_contents($addressTokens, json_encode([]));
        $data = json_decode(file_get_contents($addressTokens), true);
        if (array_key_exists($table, $data)) {
            return true;
        } else {
            return false;
        }
    }

    public static function get($table): ?array
    {
        $dir = appController::baseDir() . '/database/';
        $addressTokens = $dir . 'tokens.lotus';
        if (!file_exists($addressTokens)) file_put_contents($addressTokens, json_encode([]));
        $data = json_decode(file_get_contents($addressTokens), true);
        if (array_key_exists($table, $data)) {
            return $data[$table];
        } else {
            return null;
        }
    }


}