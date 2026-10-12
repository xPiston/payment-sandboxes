<?php

declare(strict_types=1);

/**
 * Monetico's side of the exchange, so the endpoint can be exercised without a
 * contract: it seals a notification the way the platform does and posts it.
 *
 *   php simulate.php [accepted|refused|tampered] [base-url]
 *
 * The seal is computed here from scratch - sorted `name=value` pairs joined by
 * `*`, HMAC-SHA1 over the twenty bytes of the key - deliberately without using
 * the package, so that what is being tested is the package and not a mirror of
 * itself.
 */

$case = $argv[1] ?? 'accepted';
$base = rtrim($argv[2] ?? (getenv('SANDBOX_BASE_URL') ?: 'http://127.0.0.1:8456'), '/');
$key = getenv('MONETICO_KEY') ?: '0123456789ABCDEF0123456789ABCDEF01234567';
$terminal = getenv('MONETICO_TERMINAL') ?: '1234567';

$fields = [
    'TPE' => $terminal,
    'date' => date('d/m/Y').'_a_'.date('H:i:s'),
    'montant' => '62.73EUR',
    'reference' => 'SBX'.date('ymdHis'),
    'texte-libre' => "Sandbox check - l'Église & co",
    'code-retour' => 'payetest',
    'cvx' => 'oui',
    'vld' => '1230',
    'brand' => 'VI',
    'numauto' => '000042',
    'originecb' => 'FRA',
    'ipclient' => '10.45.166.76',
    // The field the sandbox invents for every notification, on purpose.
    'zRandomCheck' => bin2hex(random_bytes(3)),
];

if ($case === 'refused') {
    $fields['code-retour'] = 'Annulation';
    $fields['motifrefus'] = 'filtrage';
    $fields['motifrefusautorisation'] = 'Refus temporaire';
    $fields['filtragecause'] = '4-';
    $fields['filtragevaleur'] = 'FRA-';
    unset($fields['numauto']);
}

$sealed = $fields;
uksort($sealed, static fn (string $a, string $b): int => strcmp($a, $b));
$pairs = [];

foreach ($sealed as $name => $value) {
    $pairs[] = $name.'='.$value;
}

$fields['MAC'] = strtoupper(hash_hmac('sha1', implode('*', $pairs), (string) hex2bin(strtolower($key))));

if ($case === 'tampered') {
    // One digit of the amount, changed after sealing.
    $fields['montant'] = '6273.00EUR';
}

$context = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
    'content' => http_build_query($fields),
    'timeout' => 15,
    'ignore_errors' => true,
]]);

$answer = @file_get_contents($base.'/notify', false, $context);

printf("%-10s -> %s\n", $case, $answer === false ? '(no answer)' : json_encode($answer));
