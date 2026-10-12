<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Piston\Monetico\Field\Amount;
use Piston\Monetico\Field\Reference;
use Piston\Monetico\Monetico;
use Piston\Monetico\Service\CaptureRequest;
use Piston\Monetico\Service\RefundRequest;

// A terminal that exists in nobody's contract: the platform rejects the
// request at identification, so nothing is captured and nothing is refunded.
// What is being tested is everything between here and their answer.
$monetico = Monetico::sandbox('9999999', str_repeat('AB', 20), 'nonexistent');

echo "capture_paiement\n";

$capture = $monetico->capture(CaptureRequest::of(
    reference: Reference::of('SBXTRANSPORT01'),
    orderAmount: Amount::fromMinor(101, 'EUR'),
    toCapture: Amount::fromMinor(101, 'EUR'),
    orderedOn: new DateTimeImmutable('-1 day'),
));

printf("  cdr        : %d\n", $capture->reply->returnCode());
printf("  lib        : %s\n", $capture->label());
printf("  outcome    : %s\n", $capture->outcome->name);
printf("  reason     : %s\n", $capture->reason?->name ?? '(unmapped)');
printf("  retryable  : %s\n", $capture->isRetryable() ? 'yes' : 'no');
printf("  raw fields : %s\n", json_encode($capture->reply->fields()));

echo "\nrecredit_paiement\n";

$refund = $monetico->refund(RefundRequest::wholeOrder(
    reference: Reference::of('SBXTRANSPORT01'),
    orderAmount: Amount::fromMinor(101, 'EUR'),
    orderedOn: new DateTimeImmutable('-1 day'),
));

printf("  cdr        : %d\n", $refund->reply->returnCode());
printf("  lib        : %s\n", $refund->label());
printf("  completed  : %s\n", $refund->isCompleted() ? 'yes' : 'no');
printf("  error      : %s\n", $refund->error?->name ?? '(unmapped)');
printf("  retryable  : %s\n", $refund->isRetryable() ? 'yes' : 'no');
printf("  raw fields : %s\n", json_encode($refund->reply->fields()));
