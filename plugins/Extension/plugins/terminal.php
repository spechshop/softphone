<?php
declare(strict_types=1);

namespace plugins;

use Swoole\Coroutine;
use Swoole\Timer;


class terminal
{

    public static function asyncShell($command, $cli, &$sharedPid = null): void
    {
        $descriptorSpec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];
        $process = proc_open($command, $descriptorSpec, $pipes);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        if (is_resource($process)) {
             
             
            print $cli->color("Processo iniciado com sucesso\n", 'green');
            Timer::tick(10, function ($timerId) use (&$pipes, &$sharedPid, &$process, &$command, $cli) {
                $outputPipes = [$pipes[1], $pipes[2]];
                $readyPipes = $outputPipes;
                $null = null;
                $sharedPid = proc_get_status($process)['pid'];
                // corrigido, vamos pegar o pid mestre do swoole
               

                if (!is_resource($pipes[1]) || !is_resource($pipes[2])) {
                    if (is_resource($pipes[1])) fclose($pipes[1]);
                    if (is_resource($pipes[2])) fclose($pipes[2]);
                    if (is_resource($pipes[0])) fclose($pipes[0]);
                    if (is_resource($process)) proc_close($process);
                    if (is_resource($process)) proc_terminate($process, 15);
                    self::pKill(proc_get_status($process)['pid'], 9);
                    return Timer::clearAll();
                }
                if (is_resource($process) && proc_get_status($process)['running'] === false) {
                    if (is_resource($pipes[0])) fclose($pipes[0]);
                    self::pKill(proc_get_status($process)['pid'], 9);
                    return Timer::clearAll();
                } 
                // se receber 'A bug occurred in Swoole' mata o processo
                if (strpos($command, 'A bug occurred in Swoole') !== false) {
                    if (is_resource($pipes[0])) fclose($pipes[0]);
                    self::pKill(proc_get_status($process)['pid'], 9);
                    return Timer::clearAll();
                }

                
                stream_select($readyPipes, $null, $null, 0);
                foreach ($readyPipes as $pipe) {
                    $data = fgets($pipe);
                    if ($data === false) {
                        $outputPipes = array_diff($outputPipes, [$pipe]);
                    } elseif (strlen($data) > 1) {
                        print $data;
                    }
                }
            });
        }
    }

    public static function pKill(mixed $pid, mixed $sig_num = 9): bool
    {
        $idProcess = (int)$pid; 
        $idProcess2 = (int)$pid;
        if (function_exists("posix_kill")) return posix_kill($idProcess, $sig_num);
        if (function_exists("proc_terminate")) {
            $process = proc_open("kill -s $sig_num $idProcess", [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
            if (is_resource($process)) {
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return empty($output);
            }
        }
        exec("/usr/bin/kill -s $sig_num $idProcess 2>&1", $junk, $return_code);
        exec("/usr/bin/kill -s $sig_num $idProcess2 2>&1", $junk, $return_code2);
        if ($return_code === 0 || $return_code2 === 0) {
            return true;
        } else {
            print "Erro ao matar o processo $pid: " . implode("\n", $junk) . PHP_EOL;
            return false;
        }
    }
}