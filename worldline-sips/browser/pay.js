const puppeteer = require('puppeteer');

const BASE = process.env.SANDBOX_BASE_URL || 'http://127.0.0.1:8457';
const CARD = process.env.SIPS_CARD || '4100000000000000';
const AUTHENT = process.env.SIPS_3DS || 'Authent accepted';
const TAG = process.env.SIPS_TAG || 'run';

const shot = (page, name) => page.screenshot({ path: `/home/pptruser/shots/${TAG}-${name}.png` });

(async () => {
  const browser = await puppeteer.launch({ args: ['--no-sandbox'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1100, height: 900 });

  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));

  // 1. The shop's page, carrying the sealed form.
  await page.goto(BASE + '/', { waitUntil: 'networkidle2' });
  const order = await page.$eval('#order', (el) => el.textContent.trim());
  await shot(page, '1-shop');

  // 2. To Worldline.
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2' }),
    page.click('form button[type=submit]'),
  ]);
  const platformReference = await page.evaluate(() => {
    const m = document.body.innerText.match(/Transaction reference:\s*(\S+)/);
    return m ? m[1] : null;
  });
  await shot(page, '2-brands');

  // 3. VISA, then the card.
  const visa = await page.waitForSelector('#VISA', { timeout: 15000 });
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {}),
    visa.click(),
  ]);
  await page.waitForSelector('#cardNumberField', { timeout: 15000 });
  await page.type('#cardNumberField', CARD, { delay: 20 });
  await page.select('#expirydatefield-month', '12');
  const years = await page.$$eval('#expirydatefield-year option', (o) => o.map((x) => x.value).filter((v) => /^\d{4}$/.test(v)));
  await page.select('#expirydatefield-year', years[years.length - 1]);
  await page.type('#cvvfield', '123', { delay: 20 });
  await shot(page, '3-card');

  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 40000 }).catch(() => {}),
    page.click('#form_submit'),
  ]);
  await new Promise((r) => setTimeout(r, 1500));

  // 4. The 3-D Secure simulator: choose the outcome.
  if (/threedauthentication/.test(page.url())) {
    await shot(page, '4-3ds');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 40000 }).catch(() => {}),
      page.click(`input[name="${AUTHENT}"]`),
    ]);
    await new Promise((r) => setTimeout(r, 2000));
  }

  // 4b. A refused card does not end the payment: Sips puts the customer back
  // on the payment-method page to try another one. The response only leaves
  // when the payment ends - so if we are back there, the customer gives up,
  // which is itself a documented outcome.
  if (/selectpaymentmethod/.test(page.url())) {
    await shot(page, '4b-refused-back-on-brands');
    const cancel = await page.$('#cancel_0');
    if (cancel) {
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {}),
        cancel.click(),
      ]);
      await new Promise((r) => setTimeout(r, 2000));
    }
  }

  // 5. Sips shows a receipt with a link back; follow it if we are not home.
  for (let i = 0; i < 3 && !/127\.0\.0\.1/.test(page.url()); i++) {
    await shot(page, `5-receipt-${i}`);
    const link = await page.evaluate(() => {
      const el = [...document.querySelectorAll('a, button, input[type=submit]')]
        .find((e) => /return|retour|continue|back|shop|merchant/i.test((e.innerText || e.value || '') + (e.id || '')));
      return el ? { tag: el.tagName, id: el.id, text: (el.innerText || el.value || '').trim() } : null;
    });
    if (!link) break;
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => {}),
      page.evaluate(() => {
        const el = [...document.querySelectorAll('a, button, input[type=submit]')]
          .find((e) => /return|retour|continue|back|shop|merchant/i.test((e.innerText || e.value || '') + (e.id || '')));
        el.click();
      }),
    ]);
    await new Promise((r) => setTimeout(r, 1500));
  }

  await shot(page, '6-final');

  const final = await page.evaluate(() => {
    const read = (id) => (document.getElementById(id) || {}).textContent;
    return {
      url: location.href,
      verdict: read('verdict'),
      code: read('code'),
      reference: read('reference'),
      amount: read('amount'),
      authorisation: read('authorisation'),
      card: read('card'),
      authent: read('authent'),
      sealed: read('sealed'),
      body: document.body.innerText.replace(/\s+/g, ' ').slice(0, 200),
    };
  });

  console.log(JSON.stringify({ order, platformReference, errors, final }, null, 2));
  await browser.close();
})().catch((e) => { console.error('FAILED', e.message); process.exit(1); });
