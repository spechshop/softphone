<?php
declare(strict_types=1);

namespace plugins;

use Swoole\Timer;


class terminalManage
{


    public static function asyncShell($command, &$sharedPid = null, &$status = 'running', $show = true): void
    {
        $descriptorSpec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];
        $status = 'running';
        $process = proc_open($command, $descriptorSpec, $pipes);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        if (is_resource($process)) {
            Timer::tick(10, function ($timerId) use (&$pipes, &$status, &$sharedPid, &$process, &$command, $show) {
                $outputPipes = [$pipes[1], $pipes[2]];
                $readyPipes = $outputPipes;
                $null = null;
                $sharedPid = proc_get_status($process)['pid'];

                if (!is_resource($pipes[1]) || !is_resource($pipes[2])) {
                    $status = 'stop';
                    if (is_resource($pipes[1])) fclose($pipes[1]);
                    if (is_resource($pipes[2])) fclose($pipes[2]);
                    if (is_resource($pipes[0])) fclose($pipes[0]);
                    if (is_resource($process)) proc_close($process);
                    if (is_resource($process)) proc_terminate($process, 9);
                    return Timer::clear($timerId);
                }
                if (is_resource($process) && proc_get_status($process)['running'] === false) {
                    $status = 'stop';


                    if (is_resource($pipes[0])) fclose($pipes[0]);
                    if (is_resource($pipes[1])) fclose($pipes[1]);
                    if (is_resource($pipes[2])) fclose($pipes[2]);
                    if (is_resource($pipes[0])) fclose($pipes[0]);
                    if (is_resource($process)) proc_close($process);
                    if (is_resource($process)) proc_terminate($process, 9);
                    return Timer::clear($timerId);
                }
                stream_select($readyPipes, $null, $null, 0);
                foreach ($readyPipes as $pipe) {
                    $data = fgets($pipe);
                    if ($data === false) {
                        $outputPipes = array_diff($outputPipes, [$pipe]);
                    } elseif (strlen($data) > 1) {
                        if ($show)
                            print $data;
                    }
                }
            });
        }


    }

    public static function pKill(mixed $pid, mixed $sig_num = 9): int|bool
    {
        if (!is_numeric($pid) || $pid <= 0) {
            return false;
        }
        exec("pkill -TERM -P $pid; kill -$sig_num $pid > /dev/null", $o, $c);
        return $c;
    }

}