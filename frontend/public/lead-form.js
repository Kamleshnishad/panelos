/*
 * PanelOS website enquiry form (LEADS_PLAN 4.1).
 *
 *   <div id="panelos-enquiry"></div>
 *   <script src="https://YOUR-PANELOS-ADDRESS/lead-form.js" data-token="lt_..." async></script>
 *
 * Draws a short form where the div is (or just above the script if there is no div) and sends each enquiry to PanelOS as a new lead,
 * with the page it came from and the campaign it was clicked from. Options, all on the script tag:
 *   data-target   where to draw it (a CSS selector, default #panelos-enquiry)
 *   data-button   the button text            (default "Send enquiry")
 *   data-thanks   the message after sending  (default "Thank you. We will call you shortly.")
 *   data-style    "none" to draw it with no styling at all, so your site's own CSS applies
 * After a successful send the page gets a "panelos:lead" event (document.dispatchEvent) that a conversion tracker can listen for.
 * Plain browser JavaScript, no libraries, nothing is stored except the campaign tags of the visit (sessionStorage).
 */
(function () {
  'use strict';
  var script = document.currentScript;
  if (!script) { return; }
  var token = script.getAttribute('data-token');
  if (!token) { return; }
  var api = new URL(script.src).origin + '/api/inbound/leads/' + encodeURIComponent(token);
  var thanks = script.getAttribute('data-thanks') || 'Thank you. We will call you shortly.';
  var buttonText = script.getAttribute('data-button') || 'Send enquiry';
  var UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

  // The campaign tags are in the address only on the page the visitor landed on; keep them for the rest of the visit.
  function visit() {
    var seen = {};
    try { seen = JSON.parse(sessionStorage.getItem('panelos_visit') || '{}') || {}; } catch (e) { seen = {}; }
    var q = new URLSearchParams(location.search);
    var fresh = false;
    UTM.forEach(function (k) { if (q.get(k)) { seen[k] = q.get(k).slice(0, 150); fresh = true; } });
    if (!seen.referrer && document.referrer) { seen.referrer = document.referrer.slice(0, 300); fresh = true; }
    if (fresh) { try { sessionStorage.setItem('panelos_visit', JSON.stringify(seen)); } catch (e) { /* private mode: fine */ } }
    return seen;
  }
  var tags = visit();

  var CSS = '.pl-form{max-width:480px;font:15px/1.4 system-ui,sans-serif}.pl-form label{display:block;margin:0 0 10px;font-weight:600;font-size:13px}' +
    '.pl-form input,.pl-form textarea{display:block;width:100%;box-sizing:border-box;margin-top:4px;padding:9px 10px;border:1px solid #c5c9d0;border-radius:6px;font:inherit}' +
    '.pl-form button{padding:10px 20px;border:0;border-radius:6px;background:#1a4fa0;color:#fff;font:inherit;font-weight:600;cursor:pointer}' +
    '.pl-form button[disabled]{opacity:.6;cursor:wait}.pl-msg{margin:10px 0 0;font-size:14px}.pl-msg.pl-bad{color:#b3261e}.pl-msg.pl-ok{color:#1b7a3a}' +
    '.pl-hp{position:absolute!important;left:-5000px!important;height:0;width:0;overflow:hidden}';

  function field(label, name, type, required, extra) {
    var l = document.createElement('label');
    l.appendChild(document.createTextNode(label + (required ? ' *' : '')));
    var i = document.createElement(type === 'textarea' ? 'textarea' : 'input');
    if (type !== 'textarea') { i.type = type; }
    i.name = name;
    i.maxLength = type === 'textarea' ? 2000 : 150;
    if (type === 'textarea') { i.rows = 3; }
    if (extra) { Object.keys(extra).forEach(function (k) { i.setAttribute(k, extra[k]); }); }
    l.appendChild(i);
    return l;
  }

  function build() {
    var host = document.querySelector(script.getAttribute('data-target') || '#panelos-enquiry');
    if (!host) {
      host = document.createElement('div');
      script.parentNode.insertBefore(host, script);
    }
    if (script.getAttribute('data-style') !== 'none') {
      var st = document.createElement('style');
      st.textContent = CSS;
      document.head.appendChild(st);
    }
    var started = Date.now();
    var ref = 'web-' + Math.random().toString(36).slice(2, 12);      // one per drawn form: a double click is received once

    var f = document.createElement('form');
    f.className = 'pl-form';
    f.noValidate = true;
    f.appendChild(field('Your name', 'name', 'text', true, { autocomplete: 'name' }));
    f.appendChild(field('Phone', 'phone', 'tel', false, { autocomplete: 'tel' }));
    f.appendChild(field('E-mail', 'email', 'email', false, { autocomplete: 'email' }));
    f.appendChild(field('City', 'city', 'text', false, { autocomplete: 'address-level2' }));
    f.appendChild(field('What do you need?', 'message', 'textarea', false));
    // Hidden from people, filled in by bots: PanelOS drops the enquiry silently.
    var trap = field('Leave this empty', '_hp', 'text', false, { tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' });
    trap.className = 'pl-hp';
    f.appendChild(trap);
    var b = document.createElement('button');
    b.type = 'submit';
    b.textContent = buttonText;
    f.appendChild(b);
    var msg = document.createElement('p');
    msg.className = 'pl-msg';
    msg.setAttribute('role', 'status');
    f.appendChild(msg);

    function say(text, bad) { msg.textContent = text; msg.className = 'pl-msg ' + (bad ? 'pl-bad' : 'pl-ok'); }
    function val(n) { return (f.elements[n].value || '').trim(); }

    f.addEventListener('submit', function (e) {
      e.preventDefault();
      if (b.disabled) { return; }
      if (!val('name')) { say('Please tell us your name.', true); return; }
      if (!val('phone') && !val('email')) { say('Please give a phone number or an e-mail so we can reach you.', true); return; }

      var body = {
        name: val('name'), phone: val('phone'), email: val('email'), city: val('city'), message: val('message'),
        _hp: f.elements._hp.value, _t: Date.now() - started, external_id: ref,
        landing_page: location.origin + location.pathname, referrer: tags.referrer || ''
      };
      UTM.forEach(function (k) { if (tags[k]) { body[k] = tags[k]; } });
      if (tags.utm_campaign) { body.campaign = tags.utm_campaign; }

      b.disabled = true;
      say('Sending…', false);
      fetch(api, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, status: r.status, json: j }; }); })
        .then(function (r) {
          if (r.ok) {
            f.reset();
            say(thanks, false);
            b.style.display = 'none';
            try { document.dispatchEvent(new CustomEvent('panelos:lead', { detail: { outcome: (r.json.data || {}).outcome || 'created' } })); } catch (e2) { /* old browser */ }
          } else {
            b.disabled = false;
            say(r.status === 422 && r.json.message ? r.json.message : 'Sorry, we could not send this. Please try again in a moment.', true);
          }
        })
        .catch(function () { b.disabled = false; say('Sorry, we could not send this. Please check your connection and try again.', true); });
    });
    host.appendChild(f);
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', build); } else { build(); }
})();
