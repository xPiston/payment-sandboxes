<?php

declare(strict_types=1);

/**
 * A Monetico sandbox harness - NOT part of the package, never committed.
 *
 * It is the smallest thing that exercises all four exchanges behind a real
 * web server: the form a customer is sent, the notification endpoint the
 * platform posts to, the two return pages, and a log of everything received.
 *
 * Credentials come from the environment:
 *   MONETICO_TERMINAL, MONETICO_KEY, MONETICO_COMPANY, MONETICO_BASE_URL
 */

require dirname(__DIR__).'/vendor/autoload.php';

use Piston\Monetico\Field\Amount;
use Piston\Monetico\Field\Reference;
use Piston\Monetico\Monetico;
use Piston\Monetico\Notification\Acknowledgement;
use Piston\Monetico\Payment\Context\BillingAddress;
use Piston\Monetico\Payment\Context\ClientInformation;
use Piston\Monetico\Payment\Context\OrderContext;
use Piston\Monetico\Payment\PaymentRequest;

const LOG = __DIR__.'/../var/notifications.log';

function monetico(): Monetico
{
    return Monetico::sandbox(
        getenv('MONETICO_TERMINAL') ?: '1234567',
        getenv('MONETICO_KEY') ?: '0123456789ABCDEF0123456789ABCDEF01234567',
        getenv('MONETICO_COMPANY') ?: 'monSite1',
    );
}

function baseUrl(): string
{
    return rtrim(getenv('SANDBOX_BASE_URL') ?: 'http://127.0.0.1:8456', '/');
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

switch ($path) {
    // The payment page: one order, one button.
    case '/':
        $reference = Reference::of('SBX'.date('ymdHis'));
        $amount = Amount::fromMinor((int) (getenv('MONETICO_AMOUNT') ?: 101), 'EUR');

        $form = monetico()->form(new PaymentRequest(
            reference: $reference,
            amount: $amount,
            orderedAt: new DateTimeImmutable(),
            context: OrderContext::for(
                BillingAddress::create('1 rue de Test', 'Strasbourg', '67000', 'FR')
                    ->with(['firstName' => 'Ada', 'lastName' => 'Lovelace', 'email' => 'ada@example.com']),
                null,
                null,
                ClientInformation::guest('ada@example.com'),
            ),
            customerEmail: 'ada@example.com',
            freeText: "Sandbox check - l'Église & co",
            successUrl: baseUrl().'/ok',
            errorUrl: baseUrl().'/ko',
        ));

        header('Content-Type: text/html; charset=utf-8');
        printf(
            '<!doctype html><meta charset="utf-8"><title>Monetico sandbox</title>'
            .'<h1>%s - %s</h1><p>reference <code>%s</code>, seal <code>%s</code></p>%s',
            htmlspecialchars($amount->toMonetico()),
            htmlspecialchars(date('H:i:s')),
            htmlspecialchars($reference->value()),
            htmlspecialchars($form->seal()),
            $form->toHtml('Payer en sandbox'),
        );
        break;

    // The "Response" interface. Everything below the acknowledgement would be
    // a queued job in a real shop.
    case '/notify':
        $notification = monetico()->attemptNotification($_POST);
        $answer = Acknowledgement::for($notification !== null);

        file_put_contents(LOG, json_encode([
            'at' => date('c'),
            'verified' => $notification !== null,
            'sealed_with' => $notification?->sealedWith()?->name(),
            'accepted' => $notification?->isAccepted(),
            'reference' => $notification?->reference()?->value(),
            'amount' => $notification?->amount()?->toMonetico(),
            'return_code' => $notification?->rawReturnCode(),
            'authorisation' => $notification?->authorisationNumber(),
            'card' => [
                'brand' => $notification?->card()->brand?->value,
                'masked' => $notification?->card()->maskedNumber,
                'expires' => $notification?->card()->expiresAt()?->format('Y-m-d'),
            ],
            'liability_shift' => $notification?->authentication()?->liabilityShift(),
            'refusal' => $notification?->refusalReason()?->value,
            'answer' => $answer->body(),
            'fields' => $_POST,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);

        header('Content-Type: '.$answer->contentType());
        echo $answer->body();
        break;

    case '/ok':
    case '/ko':
        header('Content-Type: text/plain; charset=utf-8');
        echo $path === '/ok' ? "payment accepted\n" : "payment refused\n";
        break;

    case '/log':
        header('Content-Type: text/plain; charset=utf-8');
        echo is_file(LOG) ? file_get_contents(LOG) : "nothing yet\n";
        break;

    default:
        http_response_code(404);
        echo "not found\n";
}
