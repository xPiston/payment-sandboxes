<?php

declare(strict_types=1);

/**
 * A browser harness for the Sips simulator - NOT part of the package.
 *
 * It is the one thing the test suites cannot do: take a payment all the way
 * through, with a card typed into Worldline's own page and a 3-D Secure step,
 * and verify the response the browser carries back.
 *
 * The simulator's credentials are published, so this needs no contract. What
 * it cannot exercise is `automaticResponseUrl`: Worldline's server has to
 * reach it, and 127.0.0.1 is not reachable from the internet. Both channels
 * carry the same Data and the same Seal, so the verification path is the same
 * one - but the harness says so rather than implying coverage it has not got.
 */

require dirname(__DIR__).'/vendor/autoload.php';

use Piston\Sips\Field\Amount;
use Piston\Sips\Paypage\CaptureMode;
use Piston\Sips\Paypage\PaymentRequest;
use Piston\Sips\Sips;

const LOG = __DIR__.'/../var/responses.log';

$base = getenv('SANDBOX_BASE_URL') ?: 'http://127.0.0.1:8457';
$sips = Sips::simulation();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

switch ($path) {
    case '/':
        // No transactionReference: this simulation shop is configured to
        // generate its own, and sending one answers `code=12 Merchant
        // provided transaction key, but is configured for auto generated
        // transaction key`. The reference comes back in the response, which
        // is the whole reason the field is optional in this library.
        $orderId = 'ORD'.date('ymdHis');
        $amount = Amount::fromMinor((int) (getenv('SIPS_AMOUNT') ?: 2500), 'EUR');

        $form = $sips->form(new PaymentRequest(
            amount: $amount,
            normalReturnUrl: $base.'/retour',
            automaticResponseUrl: $base.'/notification',
            orderId: $orderId,
            captureMode: CaptureMode::AuthorAndCapture,
            customerEmail: 'ada@example.com',
            returnContext: 'order='.$orderId,
        ));

        file_put_contents(LOG, json_encode([
            'at' => date('c'), 'event' => 'form', 'order_id' => $orderId,
            'amount' => $amount->wireValue(), 'seal' => $form->seal(), 'data' => $form->data(),
        ]).PHP_EOL, FILE_APPEND);

        header('Content-Type: text/html; charset=utf-8');
        printf(
            '<!doctype html><meta charset="utf-8"><title>Sips harness</title>'
            .'<h1>%s €</h1><p>order <code id="order">%s</code></p>%s',
            htmlspecialchars($amount->toDecimal()),
            htmlspecialchars($orderId),
            $form->toHtml('Payer'),
        );
        break;

    case '/retour':
    case '/notification':
        $response = $sips->attemptResponse($_POST);

        file_put_contents(LOG, json_encode([
            'at' => date('c'),
            'event' => ltrim($path, '/'),
            'verified' => $response !== null,
            'sealed_with' => $response?->sealedWith()?->wireValue(),
            'accepted' => $response?->isAccepted(),
            'response_code' => $response?->rawResponseCode(),
            'reference' => $response?->reference()?->value(),
            'amount' => $response?->amount()?->toDecimal(),
            'currency' => $response?->amount()?->currency()->code(),
            'authorisation' => $response?->authorisationId(),
            'masked_pan' => $response?->maskedPan(),
            'brand' => $response?->paymentMeanBrand(),
            'card_expiry' => $response?->cardExpiry(),
            'authent_program' => $response?->authenticationProgram(),
            'authent_status' => $response?->authenticationStatus(),
            'return_context' => $response?->returnContext(),
            'occurred_at' => $response?->occurredAt()?->format('c'),
            'field_count' => count($response?->fields() ?? []),
            'post_keys' => array_keys($_POST),
        ], JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);

        header('Content-Type: text/html; charset=utf-8');

        if ($response === null) {
            // id attributes so the browser script can assert on them.
            echo '<!doctype html><meta charset="utf-8"><h1 id="verdict">SEAL REFUSED</h1>';
            break;
        }

        printf(
            '<!doctype html><meta charset="utf-8"><title>%s</title>'
            .'<h1 id="verdict">%s</h1>'
            .'<dl><dt>responseCode<dd id="code">%s'
            .'<dt>reference<dd id="reference">%s'
            .'<dt>amount<dd id="amount">%s %s'
            .'<dt>authorisation<dd id="authorisation">%s'
            .'<dt>card<dd id="card">%s %s'
            .'<dt>3-D Secure<dd id="authent">%s / %s'
            .'<dt>sealed with<dd id="sealed">%s</dl>',
            $response->isAccepted() ? 'ACCEPTED' : 'REFUSED',
            $response->isAccepted() ? 'ACCEPTED' : 'REFUSED',
            htmlspecialchars($response->rawResponseCode()),
            htmlspecialchars($response->reference()?->value() ?? '-'),
            htmlspecialchars($response->amount()?->toDecimal() ?? '-'),
            htmlspecialchars($response->amount()?->currency()->code() ?? ''),
            htmlspecialchars($response->authorisationId() ?? '-'),
            htmlspecialchars($response->paymentMeanBrand() ?? '-'),
            htmlspecialchars($response->maskedPan() ?? '-'),
            htmlspecialchars($response->authenticationProgram() ?? '-'),
            htmlspecialchars($response->authenticationStatus() ?? '-'),
            htmlspecialchars($response->sealedWith()?->wireValue() ?? '-'),
        );
        break;

    case '/log':
        header('Content-Type: text/plain; charset=utf-8');
        echo is_file(LOG) ? (string) file_get_contents(LOG) : "nothing yet\n";
        break;

    default:
        http_response_code(404);
        echo "not found\n";
}
