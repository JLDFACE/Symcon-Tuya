<?php

// Live-Test gegen ein echtes Gerät, ohne Symcon:
//   php tests/live_test.php <ip> <local_key>              → Status lesen
//   php tests/live_test.php <ip> <local_key> '{"104":true}' → setzen

declare(strict_types=1);

require __DIR__ . '/../libs/TuyaProtocol.php';

[$_, $ip, $key] = $argv + [null, null, null];
if (!$ip || !$key) {
    fwrite(STDERR, "Aufruf: php live_test.php <ip> <local_key> [json-dps]\n");
    exit(1);
}

$t = new TuyaProtocol($ip, $key, 3.0, function ($l, $m) { echo "[$l] $m\n"; });
$t->connect();
echo "Status: " . json_encode($t->query()) . "\n";
if (isset($argv[3])) {
    echo "Gemeldet: " . json_encode($t->control(json_decode($argv[3], true))) . "\n";
}
$t->close();
