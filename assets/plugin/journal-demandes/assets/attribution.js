(function (root) {
  'use strict';
  const KEY = 'jd_journal_session_v1', COOKIE = 'jd_prov', FIELD = 'jd_prov', TTL = 30 * 60 * 1000;
  const FORM_CLASSES = /(^|\s)(wpcf7-form|wpforms-form|elementor-form|frm-show-form|fluent_form_\d+|frm_forms)(\s|$)/;
  const clean = v => String(v || '').replace(/[\u0000-\u001f]/g, '').slice(0, 160);
  function host(value) { try { return new URL(value).hostname.toLowerCase(); } catch (_) { return ''; } }

  // Classement du canal : paramètres de campagne, puis marqueur Google Ads, puis référent observable.
  function source(url, referrer) {
    const u = new URL(url), q = u.searchParams;
    const campaign = { source: clean(q.get('utm_source')), medium: clean(q.get('utm_medium')), campaign: clean(q.get('utm_campaign')), content: clean(q.get('utm_content')) };
    const h = host(referrer), internal = h && h.replace(/^www\./, '') === u.hostname.replace(/^www\./, '');
    const s = campaign.source.toLowerCase(), m = campaign.medium.toLowerCase();
    const paid = /^(cpc|ppc|paid|paid_social|paid_search|display|cpm|retargeting|paidsocial)$/.test(m);
    let channel = 'Direct ou inconnu', evidence = 'Aucun référent externe ni campagne observable';
    if (q.has('gclid') || q.has('gbraid') || q.has('wbraid')) { channel = 'Google Ads'; evidence = 'Marqueur publicitaire Google'; }
    else if (s) {
      if (/^(instagram|ig)$/.test(s)) channel = paid ? 'Instagram payant' : 'Instagram';
      else if (/^(facebook|fb|meta)$/.test(s)) channel = paid ? 'Meta payant' : 'Facebook';
      else if (/^(linkedin|li)$/.test(s)) channel = paid ? 'LinkedIn payant' : 'LinkedIn';
      else if (/^(google|bing|yahoo|duckduckgo)$/.test(s)) channel = paid ? 'Recherche payante' : (m === 'organic' ? 'SEO' : 'Campagne renseignée');
      else if (/^(email|newsletter)$/.test(m)) channel = 'Email';
      else channel = paid ? 'Campagne payante' : 'Campagne renseignée';
      evidence = 'Paramètres de campagne déclarés';
    } else if (h && !internal) {
      const ends = name => h === name || h.endsWith('.' + name);
      if (ends('instagram.com')) channel = 'Instagram';
      else if (ends('facebook.com')) channel = 'Facebook';
      else if (ends('linkedin.com')) channel = 'LinkedIn';
      else if (/^(www\.)?google\.[a-z.]+$/.test(h) || ends('bing.com') || ends('duckduckgo.com') || ends('qwant.com') || ends('ecosia.org') || /^([a-z]+\.)?search\.yahoo\.com$/.test(h)) channel = 'SEO';
      else if (ends('chatgpt.com') || ends('perplexity.ai') || ends('claude.ai') || h === 'gemini.google.com' || ends('copilot.microsoft.com')) channel = 'Assistant IA';
      else channel = 'Site référent';
      evidence = 'Référent externe observé';
    }
    return { channel, evidence, referrer: internal ? '' : h, ...campaign };
  }

  // La première arrivée reste fixe pendant la session ; la dernière change seulement sur un nouvel apport externe.
  function start(url, referrer, previous, now) {
    const u = new URL(url), incoming = source(url, referrer);
    const valid = previous && previous.first && previous.last && Number.isFinite(previous.last_seen) && now >= previous.last_seen && now - previous.last_seen < TTL;
    const newArrival = !!incoming.source || incoming.evidence === 'Marqueur publicitaire Google' || (!!incoming.referrer && (!valid || incoming.referrer !== previous.last.referrer));
    const touch = { ...incoming, landing: u.pathname.slice(0, 400), at: new Date(now).toISOString() };
    if (!valid) return { first: touch, last: touch, last_seen: now };
    return { first: previous.first, last: newArrival ? touch : previous.last, last_seen: now };
  }

  function readCookie(doc, name) {
    if (!doc || !name) return null;
    const parts = String(doc.cookie || '').split(';');
    for (const part of parts) {
      const i = part.indexOf('=');
      if (i > -1 && part.slice(0, i).trim() === name) { try { return decodeURIComponent(part.slice(i + 1).trim()); } catch (_) { return part.slice(i + 1).trim(); } }
    }
    return null;
  }

  // Renvoie true (autorisé), false (refus explicite) ou null (pas encore décidé ou outil en chargement).
  function consentNow(w, cfg, explicit) {
    const mode = (cfg && cfg.mode) || 'wp_consent_api';
    if (mode === 'none') return true;
    if (explicit === true || explicit === false) return explicit;
    try {
      if (mode === 'wp_consent_api') return w.wp_consent_type && typeof w.wp_has_consent === 'function' ? w.wp_has_consent(cfg.category || 'statistics') === true : null;
      if (mode === 'cookiebot') { const c = w.Cookiebot; return c && c.consent && c.hasResponse ? !!c.consent[cfg.category || 'statistics'] : null; }
      if (mode === 'cookieyes') {
        if (typeof w.getCkyConsent !== 'function') return null;
        const c = w.getCkyConsent();
        return c && c.isUserActionCompleted ? !!(c.categories && c.categories[cfg.category || 'analytics']) : null;
      }
      if (mode === 'cookie') {
        const v = readCookie(w.document, cfg.cookie);
        if (v === null) return null;
        if (cfg.deny && new RegExp(cfg.deny).test(v)) return false;
        if (cfg.allow && new RegExp(cfg.allow).test(v)) return true;
        return null;
      }
    } catch (_) { return null; }
    return null; // Axeptio : seule la décision reçue par son événement compte.
  }

  function isTrackedForm(f) {
    if (!f || typeof f.querySelector !== 'function') return false;
    if (FORM_CLASSES.test(String(f.className || '')) || /^gform_\d+$/.test(String(f.id || ''))) return true;
    return !!f.querySelector('input[type="email"], input[name*="email" i]');
  }

  if (typeof module !== 'undefined' && module.exports) { module.exports = { source, start, TTL, consentNow, readCookie, isTrackedForm }; return; }
  if (!root || !root.document || !root.JDJournal) return;

  const cfg = root.JDJournal.consent || { mode: 'wp_consent_api' };
  const secure = root.location.protocol === 'https:' ? '; Secure' : '';
  const landingURL = root.location.href, landingReferrer = root.document.referrer;
  let state = null, explicit = null;

  function writeCookie(conversion) {
    if (!state) return;
    let body = { first: state.first, last: state.last, conversion: conversion || root.location.pathname };
    let value = encodeURIComponent(JSON.stringify(body));
    if (value.length > 3500) { body = { first: state.first, conversion: body.conversion }; value = encodeURIComponent(JSON.stringify(body)); }
    try { root.document.cookie = COOKIE + '=' + value + '; path=/; SameSite=Lax' + secure; } catch (_) {}
  }
  function purge() {
    state = null;
    try { root.sessionStorage.removeItem(KEY); } catch (_) {}
    try { root.document.cookie = COOKIE + '=; path=/; max-age=0; SameSite=Lax' + secure; } catch (_) {}
  }
  function sync() {
    const allowed = consentNow(root, cfg, explicit);
    // Un outil de consentement encore en chargement n’est pas un refus : ne rien effacer.
    if (allowed === null) return;
    if (!allowed) { purge(); return; }
    if (state) return;
    let previous = null;
    try { previous = JSON.parse(root.sessionStorage.getItem(KEY) || 'null'); } catch (_) {}
    state = start(landingURL, landingReferrer, previous, Date.now());
    try { root.sessionStorage.setItem(KEY, JSON.stringify(state)); } catch (_) {}
    writeCookie();
  }

  root.document.addEventListener('wp_listen_for_consent_change', function (e) {
    const key = cfg.category || 'statistics';
    if (e && e.detail && Object.prototype.hasOwnProperty.call(e.detail, key)) explicit = e.detail[key] === 'allow';
    sync();
  });
  root.document.addEventListener('wp_consent_type_defined', sync);
  root.document.addEventListener('cookieyes_consent_update', function (e) {
    const key = cfg.category || 'analytics', d = (e && e.detail) || {};
    if (Array.isArray(d.accepted) && d.accepted.indexOf(key) > -1) explicit = true;
    else if (Array.isArray(d.rejected) && d.rejected.indexOf(key) > -1) explicit = false;
    sync();
  });
  ['CookiebotOnConsentReady', 'CookiebotOnAccept', 'CookiebotOnDecline', 'load'].forEach(name => root.addEventListener(name, sync));
  if (cfg.mode === 'axeptio') {
    root._axcb = root._axcb || [];
    root._axcb.push(function (sdk) { sdk.on('cookies:complete', function (choices) { explicit = !!(choices && choices[cfg.vendor]); sync(); }); });
  }
  if (cfg.mode === 'cookie') root.setInterval(sync, 2000);
  root.document.addEventListener('click', function () { root.setTimeout(sync, 400); }, true);
  sync();

  const uuid = () => root.crypto && root.crypto.randomUUID ? root.crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2);
  // Le script ajoute un champ caché ; il n’envoie ni ne valide aucun formulaire.
  root.document.addEventListener('submit', function (event) {
    const f = event.target;
    if (!(f instanceof root.HTMLFormElement) || !isTrackedForm(f)) return;
    sync();
    let input = f.querySelector('input[name="' + FIELD + '"]');
    if (!input) { input = root.document.createElement('input'); input.type = 'hidden'; input.name = FIELD; f.appendChild(input); }
    if (!f.dataset.jdRequest) f.dataset.jdRequest = uuid();
    input.value = JSON.stringify({ first: state ? state.first : {}, last: state ? state.last : {}, conversion: root.location.pathname, request_id: f.dataset.jdRequest, unavailable_reason: state ? '' : 'Mesure non autorisée ou non disponible' });
    writeCookie(root.location.pathname);
  }, true);
})(typeof window !== 'undefined' ? window : null);
