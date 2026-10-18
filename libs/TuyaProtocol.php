<?php

declare(strict_types=1);

/**
 * Lokales Tuya-Protokoll 3.5 (Rahmen 0x6699, AES-128-GCM, Sitzungsschlüssel).
 *
 * Eine Instanz = eine TCP-Sitzung. Ablauf: connect() handelt den
 * Sitzungsschlüssel aus, danach query() bzw. control(), zum Schluss close().
 * Nachgebaut nach tinytuya (core/XenonDevice.py, core/message_helper.py).
 */
class TuyaProtocol
{
    public const PORT = 6668;

    private const PREFIX = 0x00006699;
    private const SUFFIX = 0x00009966;
    private const HEADER_LEN = 18;           // >IHIII: prefix, unknown, seqno, cmd, length

    private const CMD_SESS_KEY_NEG_START  = 0x03;
    private const CMD_SESS_KEY_NEG_RESP   = 0x04;
    private const CMD_SESS_KEY_NEG_FINISH = 0x05;
    private const CMD_HEART_BEAT          = 0x09;
    private const CMD_CONTROL_NEW         = 0x0d;
    private const CMD_DP_QUERY_NEW        = 0x10;

    // Diese Kommandos tragen keinen "3.5"+12-Null-Byte-Vorspann
    private const NO_VERSION_HEADER = [0x03, 0x04, 0x05, 0x09, 0x0a, 0x10, 0x12, 0x40];

    private $host;
    private $realKey;
    private $timeout;
    private $sock = null;
    private $sessionKey = '';
    private $seqno = 1;

    /** @var callable|null */
    private $debug;

    public function __construct(string $host, string $localKey, float $timeout = 3.0, ?callable $debug = null)
    {
        if (strlen($localKey) !== 16) {
            throw new InvalidArgumentException('Local Key muss genau 16 Zeichen lang sein');
        }
        $this->host = $host;
        $this->realKey = $localKey;
        $this->timeout = $timeout;
        $this->debug = $debug;
    }

    public function connect(): void
    {
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($this->host, self::PORT, $errno, $errstr, $this->timeout);
        if (!is_resource($sock)) {
            throw new RuntimeException('Keine Verbindung zu ' . $this->host . ':' . self::PORT . ' (' . $errstr . ')');
        }
        $this->sock = $sock;
        $this->negotiateSessionKey();
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            fclose($this->sock);
        }
        $this->sock = null;
        $this->sessionKey = '';
    }

    /** Liest alle Datenpunkte. Rückgabe: ['1' => true, '16' => 23, ...] */
    public function query(): array
    {
        $this->send(self::CMD_DP_QUERY_NEW, '{}', $this->sessionKey);
        $dps = [];
        $deadline = microtime(true) + $this->timeout;
        while (microtime(true) < $deadline) {
            $msg = $this->receive($this->sessionKey, $deadline);
            if ($msg === null) {
                break;
            }
            $dps = array_replace($dps, $this->extractDps($msg['payload']));
            if ($msg['cmd'] === self::CMD_DP_QUERY_NEW) {
                break;
            }
        }
        return $dps;
    }

    /**
     * Setzt Datenpunkte, z. B. ['1' => true, '16' => 25].
     * Rückgabe: die Datenpunkte, die das Gerät daraufhin gemeldet hat.
     */
    public function control(array $dps): array
    {
        $payload = json_encode([
            'protocol' => 5,
            't'        => time(),
            'data'     => ['dps' => (object)$dps],
        ]);
        $this->send(self::CMD_CONTROL_NEW, $payload, $this->sessionKey);

        // Antwort: zuerst eine Quittung (0x0d), danach ein Statusbericht (0x08)
        $reported = [];
        $deadline = microtime(true) + $this->timeout;
        while (microtime(true) < $deadline) {
            $msg = $this->receive($this->sessionKey, $deadline);
            if ($msg === null) {
                break;
            }
            $reported = array_replace($reported, $this->extractDps($msg['payload']));
            if (count(array_intersect_key($dps, $reported)) === count($dps)) {
                break;
            }
        }
        return $reported;
    }

    // ─── Sitzungsschlüssel ─────────────────────────────────────────

    private function negotiateSessionKey(): void
    {
        $localNonce = random_bytes(16);
        $this->send(self::CMD_SESS_KEY_NEG_START, $localNonce, $this->realKey);

        $msg = $this->receive($this->realKey, microtime(true) + $this->timeout);
        if ($msg === null || $msg['cmd'] !== self::CMD_SESS_KEY_NEG_RESP) {
            throw new RuntimeException('Keine Antwort auf die Schlüsselaushandlung');
        }
        $payload = $msg['payload'];
        if (strlen($payload) < 48) {
            throw new RuntimeException('Schlüsselaushandlung: Antwort zu kurz');
        }
        $remoteNonce = substr($payload, 0, 16);
        $expected = hash_hmac('sha256', $localNonce, $this->realKey, true);
        if (!hash_equals($expected, substr($payload, 16, 32))) {
            throw new RuntimeException('Schlüsselaushandlung: HMAC falsch (Local Key prüfen)');
        }

        $this->send(self::CMD_SESS_KEY_NEG_FINISH, hash_hmac('sha256', $remoteNonce, $this->realKey, true), $this->realKey);

        $xor = $localNonce ^ $remoteNonce;
        $tag = '';
        $cipher = openssl_encrypt($xor, 'aes-128-gcm', $this->realKey, OPENSSL_RAW_DATA, substr($localNonce, 0, 12), $tag);
        if ($cipher === false) {
            throw new RuntimeException('Sitzungsschlüssel: AES-GCM nicht verfügbar');
        }
        $this->sessionKey = substr($cipher, 0, 16);
        $this->log('Session', 'Sitzungsschlüssel ausgehandelt');
    }

    // ─── Rahmen ───────────────────────────────────────────────────

    private function send(int $cmd, string $payload, string $key): void
    {
        if (!in_array($cmd, self::NO_VERSION_HEADER, true)) {
            $payload = '3.5' . str_repeat("\0", 12) . $payload;
        }
        $frame = self::pack($this->seqno++, $cmd, $payload, $key);
        $this->log('TX', sprintf('cmd 0x%02x, %d Bytes', $cmd, strlen($frame)));
        if (@fwrite($this->sock, $frame) !== strlen($frame)) {
            throw new RuntimeException('Senden fehlgeschlagen');
        }
    }

    /** @return array{cmd:int, seqno:int, payload:string}|null  null = Zeitüberschreitung */
    private function receive(string $key, float $deadline): ?array
    {
        $header = $this->readExact(self::HEADER_LEN, $deadline);
        if ($header === null) {
            return null;
        }
        $h = unpack('Nprefix/nunknown/Nseqno/Ncmd/Nlength', $header);
        if ($h['prefix'] !== self::PREFIX) {
            throw new RuntimeException(sprintf('Unerwarteter Rahmenanfang 0x%08x', $h['prefix']));
        }
        if ($h['length'] < 28 || $h['length'] > 4096) {
            throw new RuntimeException('Unplausible Rahmenlänge ' . $h['length']);
        }
        $rest = $this->readExact($h['length'] + 4, $deadline);
        if ($rest === null) {
            throw new RuntimeException('Rahmen unvollständig');
        }
        $payload = self::unpack($header, $rest, $key);
        $this->log('RX', sprintf('cmd 0x%02x, %d Bytes', $h['cmd'], strlen($payload)));
        return ['cmd' => $h['cmd'], 'seqno' => $h['seqno'], 'payload' => $payload];
    }

    public static function pack(int $seqno, int $cmd, string $payload, string $key): string
    {
        $header = pack('NnNNN', self::PREFIX, 0, $seqno, $cmd, 12 + strlen($payload) + 16);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($payload, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, substr($header, 4), 16);
        return $header . $iv . $cipher . $tag . pack('N', self::SUFFIX);
    }

    /** Entschlüsselt einen empfangenen Rahmen und entfernt den 4-Byte-Returncode. */
    public static function unpack(string $header, string $rest, string $key): string
    {
        $len = strlen($rest) - 4;              // ohne Suffix
        $iv = substr($rest, 0, 12);
        $cipher = substr($rest, 12, $len - 12 - 16);
        $tag = substr($rest, $len - 16, 16);
        $plain = openssl_decrypt($cipher, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, substr($header, 4));
        if ($plain === false) {
            throw new RuntimeException('Entschlüsselung fehlgeschlagen (falscher Schlüssel?)');
        }
        return strlen($plain) >= 4 ? substr($plain, 4) : '';
    }

    /** Holt die DPs aus einer Antwort; "3.5"-Vorspann und Hüllen {"data":{"dps":…}} werden toleriert. */
    public static function extractDps(string $payload): array
    {
        $pos = strpos($payload, '{');
        if ($pos === false) {
            return [];
        }
        $json = json_decode(substr($payload, $pos), true);
        if (!is_array($json)) {
            return [];
        }
        if (isset($json['dps']) && is_array($json['dps'])) {
            return $json['dps'];
        }
        if (isset($json['data']['dps']) && is_array($json['data']['dps'])) {
            return $json['data']['dps'];
        }
        return [];
    }

    private function readExact(int $count, float $deadline): ?string
    {
        $buf = '';
        while (strlen($buf) < $count) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return null;
            }
            stream_set_timeout($this->sock, (int)$remaining, (int)(fmod($remaining, 1.0) * 1000000));
            $chunk = @fread($this->sock, $count - strlen($buf));
            if ($chunk === false || ($chunk === '' && feof($this->sock))) {
                throw new RuntimeException('Verbindung vom Gerät geschlossen');
            }
            $buf .= $chunk;
            if ($chunk === '' && stream_get_meta_data($this->sock)['timed_out']) {
                return null;
            }
        }
        return $buf;
    }

    private function log(string $label, string $text): void
    {
        if ($this->debug !== null) {
            call_user_func($this->debug, $label, $text);
        }
    }
}
