<?php

namespace plugins\Utils\messages;

use libspech\Cache\cache;
use libspech\Cli\cli;
use Swoole\WebSocket\Server;

class messageStore
{
    private static string $file = '/data/spechphone/messages.json';

    public static function saveMessage(string $from, string $to, string $body): ?array
    {
        if (trim($body) === '') return null;
        if (strlen($body) > 4096) {
            $body = substr($body, 0, 4096);
        }

        $data = self::loadData();
        $convId = self::getConversationId($from, $to);

        // Deduplicate recent same message (avoid duplication if routed back by SIP PBX)
        if (isset($data['messages'][$convId])) {
            $lastMsgs = array_slice($data['messages'][$convId], -5);
            foreach ($lastMsgs as $lm) {
                if ($lm['from'] === $from && $lm['to'] === $to && $lm['body'] === $body) {
                    if (time() - $lm['timestamp'] < 10) {
                        return $lm; // Already saved recently
                    }
                }
            }
        }

        $msgId = uniqid('msg_', true);
        $msg = [
            'id' => $msgId,
            'from' => $from,
            'to' => $to,
            'body' => $body,
            'timestamp' => time(),
            'read' => false
        ];

        if (!isset($data['messages'][$convId])) {
            $data['messages'][$convId] = [];
        }
        $data['messages'][$convId][] = $msg;

        $data['conversations'][$convId] = [
            'id' => $convId,
            'participants' => [$from, $to],
            'lastMessage' => $msg,
            'updatedAt' => time()
        ];

        self::saveData($data);
        return $msg;
    }

    public static function loadData(): array
    {
        if (!is_dir(dirname(self::$file))) {
            cli::pcl('Creating directory: ' . dirname(self::$file), 'yellow');
            mkdir(dirname(self::$file), 0777, true);
        }
        if (!file_exists(self::$file)) {
            return ['conversations' => [], 'messages' => []];
        }

        $fp = fopen(self::$file, 'r');
        if (!$fp) return ['conversations' => [], 'messages' => []];

        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (empty($content)) {
            return ['conversations' => [], 'messages' => []];
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $backup = self::$file . '.corrupted.' . time();
            rename(self::$file, $backup);
            return ['conversations' => [], 'messages' => []];
        }
        return $data;
    }

    public static function getConversationId(string $user1, string $user2): string
    {
        $participants = [$user1, $user2];
        sort($participants);
        return hash('sha256', implode(':', $participants));
    }

    public static function saveData(array $data): void
    {
        if (!is_dir(dirname(self::$file))) {
            mkdir(dirname(self::$file), 0777, true);
        }

        $tempFile = self::$file . '.tmp.' . uniqid();
        $fp = fopen($tempFile, 'w');
        if ($fp) {
            flock($fp, LOCK_EX);
            fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            rename($tempFile, self::$file);
        }
    }

    public static function getHistory(string $user1, string $user2, int $limit = 50): array
    {
        $data = self::loadData();
        $convId = self::getConversationId($user1, $user2);

        if (!isset($data['messages'][$convId])) {
            return ['conversationId' => $convId, 'messages' => []];
        }

        $msgs = $data['messages'][$convId];
        // Return last $limit messages
        if (count($msgs) > $limit) {
            $msgs = array_slice($msgs, -$limit);
        }

        // Remove keys to make it a clean array list
        $msgs = array_values($msgs);

        return ['conversationId' => $convId, 'messages' => $msgs];
    }

    public static function listConversations(string $user, int $limit = 50): array
    {
        $data = self::loadData();
        $list = [];

        foreach ($data['conversations'] as $conv) {
            if (in_array($user, $conv['participants'])) {
                $otherUser = $conv['participants'][0] === $user ? $conv['participants'][1] : $conv['participants'][0];
                $conv['with'] = $otherUser;
                // Count unread
                $unread = 0;
                if (isset($data['messages'][$conv['id']])) {
                    foreach ($data['messages'][$conv['id']] as $m) {
                        if ($m['to'] === $user && empty($m['read'])) {
                            $unread++;
                        }
                    }
                }
                $conv['unread'] = $unread;
                $list[] = $conv;
            }
        }

        usort($list, function ($a, $b) {
            return $b['updatedAt'] <=> $a['updatedAt'];
        });

        if (count($list) > $limit) {
            $list = array_slice($list, 0, $limit);
        }
        return $list;
    }

    public static function markAsRead(string $user1, string $user2, array $messageIds): void
    {
        $data = self::loadData();
        $convId = self::getConversationId($user1, $user2);

        if (!isset($data['messages'][$convId])) {
            return;
        }

        $changed = false;
        foreach ($data['messages'][$convId] as &$m) {
            if (in_array($m['id'], $messageIds)) {
                $m['read'] = true;
                $changed = true;
            }
        }

        if ($changed) {
            self::saveData($data);
        }
    }

    public static function sendRealtime(Server $socket, string $toUser, array $messagePayload): void
    {
        $connections = cache::get('connections') ?? [];
        foreach ($connections as $fp => $fds) {
            $sipUser = self::getSipUserFromFp($fp);
            if ($sipUser === $toUser) {
                foreach ($fds as $fd) {
                    $socket->push($fd, json_encode($messagePayload));
                }
            }
        }
    }

    public static function getSipUserFromFp(string $fp): ?string
    {
        try {
            $vault = new \spechphoneVault('/data/spechphone/devices.vault', getenv('SPECH_VAULT_KEY_HEX'));
            if ($vault->exists($fp)) {
                return $vault->get($fp)['sipUser'] ?? null;
            }
        } catch (\Exception $e) {
        }
        return null;
    }

    public static function getFpFromFd(int $fd): ?string
    {
        $connections = cache::get('connections') ?? [];
        foreach ($connections as $fp => $fds) {
            if (in_array($fd, $fds)) {
                return $fp;
            }
        }
        return null;
    }
}
