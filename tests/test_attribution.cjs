// Tests du script de provenance. Usage : node test_attribution.cjs [chemin/vers/attribution.js]
const assert = require('node:assert/strict'), vm = require('node:vm'), fs = require('node:fs'), path = require('node:path');
const file = process.argv[2] || path.join(__dirname, '..', 'assets', 'plugin', 'journal-demandes', 'assets', 'attribution.js');
const code = fs.readFileSync(file, 'utf8');
const lib = require(path.resolve(file));
let checks = 0;
const ok = (v, label) => { assert.ok(v, label); checks++; };
const eq = (a, b, label) => { assert.equal(a, b, label); checks++; };

// 1. Canaux
const o = 'https://www.exemple.fr';
const ch = (url, ref) => lib.source(url, ref).channel;
eq(ch(o + '/salon/', 'https://www.google.com/search?q=x'), 'SEO', 'Google organique');
eq(ch(o, 'https://duckduckgo.com/'), 'SEO', 'DuckDuckGo');
eq(ch(o, 'https://www.qwant.com/'), 'SEO', 'Qwant');
eq(ch(o, 'https://l.instagram.com/'), 'Instagram', 'référent Instagram');
eq(ch(o + '/?utm_source=instagram&utm_medium=paid_social', ''), 'Instagram payant', 'Instagram payant');
eq(ch(o + '/?utm_source=instagram&utm_medium=social', ''), 'Instagram', 'Instagram UTM');
eq(ch(o + '/?fbclid=abc', 'https://www.facebook.com/'), 'Facebook', 'fbclid seul non payant');
eq(ch(o + '/?gclid=abc', ''), 'Google Ads', 'gclid');
eq(ch(o + '/?utm_source=linkedin&utm_medium=social', ''), 'LinkedIn', 'LinkedIn');
eq(ch(o + '/?utm_source=google&utm_medium=cpc', ''), 'Recherche payante', 'Google cpc');
eq(ch(o + '/?utm_source=google', ''), 'Campagne renseignée', 'Google sans support');
eq(ch(o + '/?utm_source=lettre&utm_medium=email', ''), 'Email', 'newsletter');
eq(ch(o, 'https://chatgpt.com/'), 'Assistant IA', 'ChatGPT');
eq(ch(o, 'https://www.autre.fr/'), 'Site référent', 'site référent');
eq(ch(o, ''), 'Direct ou inconnu', 'direct');
eq(ch(o + '/contact/', o + '/salon/'), 'Direct ou inconnu', 'navigation interne');
eq(ch('https://exemple.fr/contact/', 'https://www.exemple.fr/'), 'Direct ou inconnu', 'www et sans www');

// 2. Session : conservation, expiration, nouveau apport, minimisation
const now = Date.now();
const s1 = lib.start(o + '/salon/?utm_source=instagram&utm_medium=social&utm_campaign=profil', '', null, now);
const s2 = lib.start(o + '/realisations/', o + '/salon/', s1, now + 1000);
const s3 = lib.start(o + '/contact/', o + '/realisations/', s2, now + 2000);
eq(s3.first.landing, '/salon/', 'première page conservée'); eq(s3.first.channel, 'Instagram', 'canal conservé'); eq(s3.first.campaign, 'profil', 'campagne conservée');
const exp = lib.start(o + '/contact/', o + '/salon/', s3, now + 2000 + lib.TTL + 1);
eq(exp.first.channel, 'Direct ou inconnu', 'expiration'); eq(exp.first.landing, '/contact/', 'nouvelle arrivée après expiration');
const back = lib.start(o + '/?utm_source=linkedin&utm_medium=social', '', s1, now + 1000);
eq(back.first.channel, 'Instagram', 'première arrivée stable'); eq(back.last.channel, 'LinkedIn', 'dernier apport mis à jour');
eq(lib.source(o + '/?email=a@b.fr', '').source, '', 'aucun paramètre libre conservé');
ok(!JSON.stringify(lib.start(o + '/?gclid=SECRET', '', null, now)).includes('SECRET'), 'identifiant publicitaire non conservé');
ok(!JSON.stringify(s1).includes('?'), 'chemins sans chaîne de requête');

// 3. Consentement : chaque outil, chargement en cours distinct du refus
const c = lib.consentNow;
eq(c({}, { mode: 'wp_consent_api' }, null), null, 'API absente : en attente');
eq(c({ wp_consent_type: 'optin', wp_has_consent: () => true }, { mode: 'wp_consent_api' }, null), true, 'WP Consent API accord');
eq(c({ wp_consent_type: 'optin', wp_has_consent: () => false }, { mode: 'wp_consent_api' }, null), false, 'WP Consent API refus');
eq(c({ wp_consent_type: 'optin', wp_has_consent: () => true }, { mode: 'wp_consent_api' }, false), false, 'décision explicite prioritaire');
eq(c({}, { mode: 'cookiebot' }, null), null, 'Cookiebot en chargement');
eq(c({ Cookiebot: { hasResponse: false, consent: { statistics: false } } }, { mode: 'cookiebot' }, null), null, 'Cookiebot sans réponse');
eq(c({ Cookiebot: { hasResponse: true, consent: { statistics: true } } }, { mode: 'cookiebot' }, null), true, 'Cookiebot accord');
eq(c({ Cookiebot: { hasResponse: true, consent: { statistics: false } } }, { mode: 'cookiebot' }, null), false, 'Cookiebot refus');
eq(c({}, { mode: 'cookieyes' }, null), null, 'CookieYes en chargement');
eq(c({ getCkyConsent: () => ({ isUserActionCompleted: false, categories: { analytics: false } }) }, { mode: 'cookieyes' }, null), null, 'CookieYes sans action');
eq(c({ getCkyConsent: () => ({ isUserActionCompleted: true, categories: { analytics: true } }) }, { mode: 'cookieyes' }, null), true, 'CookieYes accord');
eq(c({ getCkyConsent: () => ({ isUserActionCompleted: true, categories: { analytics: false } }) }, { mode: 'cookieyes' }, null), false, 'CookieYes refus');
eq(c({}, { mode: 'axeptio', vendor: 'google_analytics' }, null), null, 'Axeptio sans événement');
eq(c({}, { mode: 'axeptio', vendor: 'google_analytics' }, true), true, 'Axeptio accord reçu');
const cookieCfg = { mode: 'cookie', cookie: 'cmplz_statistics', allow: '^allow$', deny: '^deny$' };
eq(c({ document: { cookie: 'a=1' } }, cookieCfg, null), null, 'cookie absent');
eq(c({ document: { cookie: 'a=1; cmplz_statistics=allow' } }, cookieCfg, null), true, 'cookie accord');
eq(c({ document: { cookie: 'cmplz_statistics=deny' } }, cookieCfg, null), false, 'cookie refus');
eq(c({ document: { cookie: 'cmplz_statistics=bof' } }, cookieCfg, null), null, 'cookie illisible');
eq(c({}, { mode: 'none' }, null), true, 'mode sans bandeau');

// 4. Détection des formulaires
const fakeForm = (cls, hasEmail, id) => ({ className: cls, id: id || '', querySelector: () => (hasEmail ? {} : null) });
ok(lib.isTrackedForm(fakeForm('wpcf7-form init', false)), 'Contact Form 7');
ok(lib.isTrackedForm(fakeForm('', false, 'gform_3')), 'Gravity Forms');
ok(lib.isTrackedForm(fakeForm('search-form', true)), 'formulaire avec email');
ok(!lib.isTrackedForm(fakeForm('search-form', false)), 'recherche ignorée');

// 5. Navigateur simulé : aucun stockage avant accord, purge au refus, champ caché et cookie
function browser(opts) {
  const events = {}, store = { v: opts.saved || null }, jar = {};
  const counters = { reads: 0, writes: 0, removes: 0 };
  class HTMLFormElement {}
  const form = new HTMLFormElement();
  const children = [];
  form.className = 'wpcf7-form'; form.id = ''; form.dataset = {};
  form.querySelector = sel => (sel.indexOf('jd_prov') > -1 ? children[0] || null : null);
  form.appendChild = el => children.push(el);
  const document = {
    referrer: opts.referrer || '',
    addEventListener: (n, f) => { events[n] = f; },
    createElement: () => ({}),
    get cookie() { return Object.entries(jar).map(([k, v]) => k + '=' + v).join('; '); },
    set cookie(line) { const [pair, ...attrs] = line.split(';'); const i = pair.indexOf('='); const k = pair.slice(0, i), v = pair.slice(i + 1); if (attrs.some(a => a.trim() === 'max-age=0')) delete jar[k]; else jar[k] = v; },
  };
  const w = {
    JDJournal: { consent: opts.consent || { mode: 'wp_consent_api' } },
    location: { href: opts.href, pathname: new URL(opts.href).pathname, protocol: 'https:' },
    document, HTMLFormElement,
    sessionStorage: { getItem: () => { counters.reads++; return store.v; }, setItem: (k, v) => { counters.writes++; store.v = v; }, removeItem: () => { counters.removes++; store.v = null; } },
    addEventListener: (n, f) => { events[n] = f; }, setTimeout: () => 0, setInterval: () => 0,
  };
  Object.assign(w, opts.globals || {});
  vm.runInNewContext(code, { window: w, URL, Date, JSON, Number, String, Math, Object, Array, RegExp, encodeURIComponent, decodeURIComponent });
  return { w, events, store, jar, counters, form, children };
}
let allowed = false;
const b = browser({ href: o + '/?utm_source=instagram&utm_medium=social', globals: { wp_has_consent: () => allowed } });
eq(b.counters.reads + b.counters.writes, 0, 'rien avant API');
b.w.wp_consent_type = 'optin'; b.events.wp_consent_type_defined();
eq(b.counters.writes, 0, 'rien avant accord'); ok(!b.jar.jd_prov, 'pas de cookie avant accord');
allowed = true; b.events.wp_listen_for_consent_change();
eq(b.counters.writes, 1, 'mémoire après accord'); ok(!!b.jar.jd_prov, 'cookie après accord');
ok(JSON.parse(decodeURIComponent(b.jar.jd_prov)).first.channel === 'Instagram', 'cookie lisible par le serveur');
b.events.submit({ target: b.form });
eq(b.children.length, 1, 'champ caché ajouté');
const sent = JSON.parse(b.children[0].value);
eq(sent.first.channel, 'Instagram', 'provenance dans le champ'); ok(sent.request_id.length > 5, 'identifiant de requête');
const rid = sent.request_id; b.events.submit({ target: b.form });
eq(JSON.parse(b.children[0].value).request_id, rid, 'même identifiant si double clic');
allowed = false; b.events.wp_listen_for_consent_change({ detail: { statistics: 'deny' } });
ok(b.counters.removes >= 1, 'purge de session au refus'); ok(!b.jar.jd_prov, 'cookie supprimé au refus');
b.events.submit({ target: b.form });
eq(JSON.parse(b.children[0].value).unavailable_reason, 'Mesure non autorisée ou non disponible', 'demande envoyée sans provenance après refus');

// Régression du défaut 0.2.0 : une API en chargement ne doit rien effacer.
const saved = JSON.stringify(lib.start(o + '/?utm_source=google&utm_medium=organic', '', null, Date.now()));
const r = browser({ href: o + '/contact/', referrer: o + '/', saved });
eq(r.counters.removes, 0, 'API en chargement : aucune purge');
r.events.wp_listen_for_consent_change({ detail: { statistics: 'allow' } });
eq(JSON.parse(r.store.v).first.landing, '/', 'arrivée conservée après chargement');
eq(JSON.parse(r.store.v).first.channel, 'SEO', 'canal conservé après chargement');

// CookieYes par événement
const y = browser({ href: o + '/?utm_source=newsletter&utm_medium=email', consent: { mode: 'cookieyes' } });
y.events.cookieyes_consent_update({ detail: { accepted: ['necessary'], rejected: ['analytics'] } });
eq(y.counters.writes, 0, 'CookieYes refus par événement');
y.events.cookieyes_consent_update({ detail: { accepted: ['analytics'], rejected: [] } });
eq(y.counters.writes, 1, 'CookieYes accord par événement');

// Axeptio par file d'attente
const ax = browser({ href: o + '/', consent: { mode: 'axeptio', vendor: 'google_analytics' } });
let handler = null; ax.w._axcb[0]({ on: (n, f) => { if (n === 'cookies:complete') handler = f; } });
handler({ google_analytics: false }); eq(ax.counters.writes, 0, 'Axeptio refus');
handler({ google_analytics: true }); eq(ax.counters.writes, 1, 'Axeptio accord');

// Stockage indisponible : aucune erreur, formulaire toujours complété
const broken = browser({ href: o + '/', consent: { mode: 'none' } });
broken.w.sessionStorage.getItem = () => { throw new Error('bloqué'); };
broken.events.submit({ target: broken.form });
ok(broken.children.length === 1, 'formulaire complété malgré un stockage bloqué');

console.log(checks + ' contrôles réussis : canaux, session, minimisation, 6 modes de consentement, formulaires, cookie, refus, régression API retardée.');
