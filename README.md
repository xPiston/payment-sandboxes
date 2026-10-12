<div align="center">

# 🧪 Payment sandboxes

**Two French payment gateways, driven for real, from an empty machine.**

A harness per gateway: the shop's sealed form, the notification endpoint,
and — where the platform allows it — a complete payment typed into a browser.

</div>

---

These are not libraries and they are not part of either package. They are the
thing a test suite cannot be: the four exchanges of a payment protocol running
behind a real web server, answering a real platform, with a real browser in
front.

They exist because the two packages they exercise —
[`xpiston/monetico`](https://github.com/xPiston/monetico) and
[`xpiston/worldline-sips`](https://github.com/xPiston/worldline-sips) — make
claims that no amount of unit testing can settle. Whether a seal is accepted is
not a question a library can answer about itself.

## The one rule

**Credentials come from the environment, and `.env` is gitignored.**

Each harness has a `.env.example` to copy. Nothing in here ever holds a real
merchant key, and the `.gitignore` is written to make an accident take effort.
The defaults are the credentials the platforms *publish* for their own
simulators, which is why the Sips harness runs with no configuration at all.

## Needs only Docker

No PHP, no Composer, no Node on the machine. Official images only —
`composer:2`, `php:8.5-cli`, `ghcr.io/puppeteer/puppeteer`.

```sh
./sandbox install  worldline-sips
./sandbox serve    worldline-sips 8457
./sandbox pay      worldline-sips          # a real browser, a real card
./sandbox stop     worldline-sips
```

## 🏦 worldline-sips

The one that goes all the way. Worldline publishes the merchant id and secret
key of its simulation platform, so this needs **no contract**: the harness
renders a sealed form, a browser types a card on Worldline's own page, the
3-D Secure simulator decides the outcome, and the response that comes back is
verified by the package.

```sh
./sandbox install worldline-sips
./sandbox serve   worldline-sips 8457
./sandbox pay     worldline-sips
```

`SIPS_CARD` chooses the outcome: the first six digits pick the brand
(`410000` VISA, `420000` CB, `510000` MASTERCARD) and **the last two are the
response code**, so `4100000000000000` is accepted and `4100000000000005` is
refused. `SIPS_3DS` picks the authentication result — `Authent accepted`,
`Authent refused`, `Authent pending`, `Authent error`.

What it has shown:

| journey | result |
| :-- | :-- |
| good card, 3-D Secure succeeded | `00`, seal verified, 26 fields: VISA, `4100##########00`, authorisation, `3D_SUCCESS` |
| refused card, customer gives up | `17`, seal verified, 18 fields |
| 3-D Secure refused, customer gives up | `17`, seal verified, 18 fields |

And two behaviours that are easier to see than to read about: **a refusal does
not end the payment** — Sips returns the customer to its payment-method page
and posts nothing until the payment actually ends — and **the field count
changes with the outcome**, so an abandoned payment has no brand, no masked PAN
and no authorisation number.

What it cannot reach: `automaticResponseUrl`. Worldline's server has to call
it, and `127.0.0.1` is not on the internet. Both channels carry the same
`Data` and the same `Seal` through the same verification, but that is an
argument, not a test. A tunnel would close it.

## 💳 monetico

Monetico publishes no simulator credentials, so this half is waiting on a
sandbox contract from their support. Until then it runs against a *simulated*
platform: `simulate.php` seals a notification the way Monetico does — sorted
`name=value` pairs joined by `*`, HMAC-SHA1 over the twenty bytes of the key —
deliberately without using the package, so that what is being tested is the
package and not a mirror of itself.

```sh
./sandbox install  monetico
./sandbox serve    monetico 8456
CASE=accepted  ./sandbox simulate monetico 8456
CASE=refused   ./sandbox simulate monetico 8456
CASE=tampered  ./sandbox simulate monetico 8456
curl -s localhost:8456/log
```

| what is posted | the endpoint answers |
| :-- | :-- |
| an accepted payment, sealed as the platform seals it | `version=2` `cdr=0` |
| a **refused** payment with a valid seal | `version=2` `cdr=0` |
| the same with one digit of the amount changed after sealing | `version=2` `cdr=1` |

The middle row is the one worth staring at. The acknowledgement depends on the
**seal**, not on the payment: a refused payment that was correctly sealed is
acknowledged `cdr=0`, because the message was received and understood.
Answering `cdr=1` there makes the platform email the merchant and send the
whole thing again — and it is the single most common way to get a notification
endpoint wrong.

There is one more thing this half can do without a contract, and it talks to
the real platform:

```sh
./sandbox services monetico
```

It calls `capture_paiement` and `recredit_paiement` with a terminal that is in
nobody's contract. Nothing can be captured or refunded — the point is the
answers, which exercise TLS, the PSR-18 client, the body encoding and the reply
parser against Monetico itself, and which show the two opposite meanings of
`cdr`: `0` is a refusal for a capture and success for a refund.

When the contract arrives, the real thing needs two more pieces: the terminal
number, key and company code in `monetico/.env`, and a public hostname pointing
at port 8456, because Monetico's notification URL is registered **with the
contract** rather than sent in the form.

## License

MIT.
