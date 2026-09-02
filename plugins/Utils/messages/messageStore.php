<?php

namespace plugins\Utils\messages;

use helpers\utils\AccountIdentity;
use helpers\utils\DataPath;
use libspech\Cache\cache;
use libspech\Cli\cli;
use Swoole\WebSocket\Server;

class messageStore
{
    private static ?string $file = null;
    private static bool $storageInitialized = false;

    public static function setFile(string $file): void
    {
        self::$file = $file;
    }

    public static function storageFile(): string
    {
        if (self::$file !== null) return self::$file;
        $file = DataPath::file('messages.json');
        if (!self::$storageInitialized) {
            $source = DataPath::migrateFirstExisting('messages.json');
            if ($source !== null) cli::pcl("[MESSAGE:MIGRATE] source={$source} path={$file}", 'green');
            self::$storageInitialized = true;
        }
        return $file;
    }

    public static function saveMessage(
        string $accountId,
        string $localIdentity,
        string $remoteIdentity,
        string $body,
        string $direction = 'outbound'
    ): ?array {
        $body = trim($body);
        if ($accountId === '' || $body === '' || !in_array($direction, ['inbound', 'outbound'], true)) return null;
        if (strlen($body) > 4096) $body = substr($body, 0, 4096);
        $localUri = self::normalizeUri($localIdentity);
        $remoteUri = self::normalizeUri($remoteIdentity);
        if ($localUri === '' || $remoteUri === '') return null;
        $convId = self::getConversationId($accountId, $remoteUri);

        return self::mutate(function (array &$data) use ($accountId, $localUri, $remoteUri, $body, $direction, $convId): array {
            $fromUri = $direction === 'inbound' ? $remoteUri : $localUri;
            $toUri = $direction === 'inbound' ? $localUri : $remoteUri;
            foreach (array_slice($data['messages'][$convId] ?? [], -5) as $recent) {
                if (($recent['accountId'] ?? '') === $accountId
                    && ($recent['fromUri'] ?? '') === $fromUri && ($recent['toUri'] ?? '') === $toUri
                    && ($recent['body'] ?? '') === $body && time() - (int)($recent['timestamp'] ?? 0) < 10) return $recent;
            }

            $msg = [
                'id' => uniqid('msg_', true), 'accountId' => $accountId, 'conversationId' => $convId,
                'localUri' => $localUri, 'remoteUri' => $remoteUri, 'fromUri' => $fromUri, 'toUri' => $toUri,
                'from' => $fromUri, 'to' => $toUri, 'direction' => $direction, 'body' => $body,
                'timestamp' => time(), 'read' => $direction === 'outbound',
            ];
            $data['schemaVersion'] = 2;
            $data['messages'][$convId][] = $msg;
            $data['conversations'][$convId] = [
                'id' => $convId, 'accountId' => $accountId, 'remoteUri' => $remoteUri,
                'conversationKey' => $accountId . '|' . $remoteUri,
                'lastMessage' => $msg, 'updatedAt' => $msg['timestamp'],
            ];
            return $msg;
        });
    }

    public static function loadData(): array
    {
        $file = self::storageFile();
        if (!file_exists($file)) return self::emptyData();
        $fp = @fopen($file, 'r');
        if (!$fp) return self::emptyData();
        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        if ($content === '') return self::emptyData();
        $data = json_decode($content, true);
        if (!is_array($data)) {
            @rename($file, $file . '.corrupted.' . time());
            return self::emptyData();
        }
        $data = array_replace(self::emptyData(), $data);
        return self::migrateLegacyData($data, AccountIdentity::all());
    }

    /** Safely migrate username-only history only when one local account is provable. */
    public static function migrateLegacyData(array $data, array $accounts): array
    {
        $legacyConversations = array_filter($data['conversations'] ?? [], static fn(array $c): bool => empty($c['accountId']));
        if (!$legacyConversations) return $data;
        $unresolved = $data['legacyUnresolved'] ?? ['conversations' => [], 'messages' => []];

        foreach ($legacyConversations as $oldId => $conv) {
            $participants = $conv['participants'] ?? [];
            $possible = [];
            foreach ($participants as $participant) {
                $parsed = AccountIdentity::parseSipIdentity((string)$participant);
                foreach ($accounts as $accountId => $account) {
                    if (strcasecmp((string)($account['sipUser'] ?? ''), $parsed['user']) === 0) {
                        $possible[$accountId] = ['account' => $account, 'localParticipant' => (string)$participant];
                    }
                }
            }
            if (count($possible) !== 1 || count($participants) !== 2) {
                $unresolved['conversations'][$oldId] = $conv;
                $unresolved['messages'][$oldId] = $data['messages'][$oldId] ?? [];
                unset($data['conversations'][$oldId], $data['messages'][$oldId]);
                continue;
            }
            $accountId = (string)array_key_first($possible);
            $match = $possible[$accountId];
            $account = $match['account'];
            $localLegacy = $match['localParticipant'];
            $remoteLegacy = $participants[0] === $localLegacy ? $participants[1] : $participants[0];
            $localUri = AccountIdentity::sipUri((string)$account['sipUser'], (string)$account['sipDomain']);
            $remoteUri = AccountIdentity::sipUri((string)$remoteLegacy, (string)$account['sipDomain']);
            $newId = self::getConversationId($accountId, $remoteUri);
            foreach ($data['messages'][$oldId] ?? [] as $oldMessage) {
                $direction = strcasecmp((string)($oldMessage['from'] ?? ''), $localLegacy) === 0 ? 'outbound' : 'inbound';
                $fromUri = $direction === 'outbound' ? $localUri : $remoteUri;
                $toUri = $direction === 'outbound' ? $remoteUri : $localUri;
                $data['messages'][$newId][] = $oldMessage + [
                    'accountId' => $accountId, 'conversationId' => $newId, 'localUri' => $localUri,
                    'remoteUri' => $remoteUri, 'fromUri' => $fromUri, 'toUri' => $toUri, 'direction' => $direction,
                ];
                $lastIndex = array_key_last($data['messages'][$newId]);
                $data['messages'][$newId][$lastIndex]['from'] = $fromUri;
                $data['messages'][$newId][$lastIndex]['to'] = $toUri;
                if ($direction === 'outbound') $data['messages'][$newId][$lastIndex]['read'] = true;
            }
            $last = end($data['messages'][$newId]);
            $data['conversations'][$newId] = [
                'id' => $newId, 'accountId' => $accountId, 'remoteUri' => $remoteUri,
                'conversationKey' => $accountId . '|' . $remoteUri, 'lastMessage' => $last ?: null,
                'updatedAt' => (int)($last['timestamp'] ?? $conv['updatedAt'] ?? 0),
            ];
            unset($data['conversations'][$oldId], $data['messages'][$oldId]);
        }
        if ($unresolved['conversations']) $data['legacyUnresolved'] = $unresolved;
        $data['schemaVersion'] = 2;
        return $data;
    }

    public static function getConversationId(string $accountId, string $remoteSipIdentity): string
    {
        return hash('sha256', $accountId . "\n" . self::normalizeUri($remoteSipIdentity));
    }

    public static function getHistory(string $accountId, string $remoteSipIdentity, int $limit = 50): array
    {
        $remoteUri = self::normalizeUri($remoteSipIdentity);
        $convId = self::getConversationId($accountId, $remoteUri);
        $messages = self::loadData()['messages'][$convId] ?? [];
        $messages = array_values(array_filter($messages, static fn(array $m): bool => ($m['accountId'] ?? '') === $accountId));
        if (count($messages) > $limit) $messages = array_slice($messages, -$limit);
        return ['conversationId' => $convId, 'accountId' => $accountId, 'remoteUri' => $remoteUri, 'messages' => $messages];
    }

    public static function listConversations(string $accountId, int $limit = 50): array
    {
        $data = self::loadData();
        $list = [];
        foreach ($data['conversations'] as $conv) {
            if (($conv['accountId'] ?? '') !== $accountId) continue;
            $unread = 0;
            foreach ($data['messages'][$conv['id']] ?? [] as $message) {
                if (($message['accountId'] ?? '') === $accountId && ($message['direction'] ?? '') === 'inbound' && empty($message['read'])) $unread++;
            }
            $conv['with'] = $conv['remoteUri'];
            $conv['unread'] = $unread;
            $list[] = $conv;
        }
        usort($list, static fn(array $a, array $b): int => ($b['updatedAt'] ?? 0) <=> ($a['updatedAt'] ?? 0));
        return array_slice($list, 0, max(0, $limit));
    }

    public static function markAsRead(string $accountId, string $remoteSipIdentity, array $messageIds): void
    {
        if (!$messageIds) return;
        $convId = self::getConversationId($accountId, $remoteSipIdentity);
        self::mutate(function (array &$data) use ($convId, $accountId, $messageIds): bool {
            $changed = false;
            if (!isset($data['messages'][$convId])) return false;
            foreach ($data['messages'][$convId] as &$message) {
                if (($message['accountId'] ?? '') === $accountId && ($message['direction'] ?? '') === 'inbound'
                    && in_array($message['id'] ?? '', $messageIds, true)) {
                    $message['read'] = true;
                    $changed = true;
                }
            }
            unset($message);
            return $changed;
        });
    }

    public static function sendRealtime(Server $socket, string $accountId, array $messagePayload): int
    {
        $fds = self::connectionFdsForAccount(cache::get('connections') ?? [], $accountId);
        $delivered = 0;
        foreach ($fds as $fd) {
            if ($socket->isEstablished((int)$fd) && $socket->push((int)$fd, json_encode($messagePayload))) $delivered++;
        }
        cli::pcl("[MESSAGE:WS] accountId={$accountId} connections=" . count($fds) . " delivered={$delivered}", $delivered ? 'green' : 'yellow');
        return $delivered;
    }

    public static function connectionFdsForAccount(array $connections, string $accountId): array
    {
        return array_values(array_map('intval', $connections[$accountId] ?? []));
    }

    public static function getFpFromFd(int $fd): ?string
    {
        foreach (cache::get('connections') ?? [] as $accountId => $fds) {
            if (in_array($fd, $fds, true)) return (string)$accountId;
        }
        return null;
    }

    private static function normalizeUri(string $identity): string
    {
        $value = trim($identity);
        if (preg_match('/sip:([^@;>\s]+)@([^;>\s]+)/i', $value, $m)
            || preg_match('/^([^@;>\s]+)@([^;>\s]+)$/', $value, $m)) {
            return 'sip:' . strtolower($m[1]) . '@' . strtolower(rtrim($m[2], '.'));
        }
        return $value === '' ? '' : 'sip:' . strtolower(preg_replace('/^sip:/i', '', $value));
    }

    private static function emptyData(): array
    {
        return ['schemaVersion' => 2, 'conversations' => [], 'messages' => []];
    }

    private static function mutate(callable $callback): mixed
    {
        $file = self::storageFile();
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Não foi possível criar o diretório de mensagens: {$dir}");
        }
        $lock = fopen($file . '.lock', 'c');
        if (!$lock) throw new \RuntimeException('Não foi possível bloquear o messageStore');
        @chmod($file . '.lock', 0600);
        flock($lock, LOCK_EX);
        $data = self::loadData();
        $result = $callback($data);
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @chmod($tmp, 0600);
        rename($tmp, $file);
        flock($lock, LOCK_UN);
        fclose($lock);
        return $result;
    }
}
