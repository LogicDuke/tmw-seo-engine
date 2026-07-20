'use strict';
const { chromium } = require('playwright');
const { approvedUrl } = require('./validate-request');
const { response, safeResponse } = require('./response-schema');
const { extractProfile } = require('./extract-profile');
const BLOCKED = /captcha|access denied|cloudflare|unusual traffic|geo.?blocked/i;
const LOGIN = /log in|sign in|login required/i;
const AGE = /age verification|confirm your age/i;

async function fetchProfile(request, { browserFactory = () => chromium.launch({ headless: true }), timeout = 15000 } = {}) {
  let browser; let context; const out = response('error', request);
  try {
    browser = await browserFactory(); context = await browser.newContext({ userAgent: 'TMW SEO Engine public-profile fetcher/1.0 (+operator configured)' });
    const page = await context.newPage(); await page.route('**/*', (route) => ['media', 'font'].includes(route.request().resourceType()) ? route.abort() : route.continue());
    const navigation = await page.goto(request.source_url, { waitUntil: 'domcontentloaded', timeout }); out.diagnostics.http_status = navigation?.status() || 0; out.diagnostics.final_url = page.url();
    if (!approvedUrl(page.url(), request.username)) return response('blocked', request, { ...out, message: 'The page redirected outside the approved public profile URL.' });
    const body = (await page.locator('body').innerText({ timeout: 3000 })).slice(0, 30000);
    if (BLOCKED.test(body)) { out.diagnostics.captcha_detected = /captcha/i.test(body); return response(out.diagnostics.captcha_detected ? 'captcha' : 'blocked', request, { ...out, message: 'Access protection was detected. No bypass was attempted.' }); }
    if (LOGIN.test(body)) { out.diagnostics.login_wall_detected = true; return response('login_required', request, { ...out, message: 'A login wall was detected. No login was attempted.' }); }
    if (AGE.test(body)) { out.diagnostics.age_gate_detected = true; return response('blocked', request, { ...out, message: 'An age gate requiring interaction was detected.' }); }
    if (out.diagnostics.http_status === 404) return response('not_found', request, { ...out, message: 'The public profile was not found.' });
    const extracted = await page.evaluate(extractProfile, request.username);
    if (extracted.conflict) return response('invalid', request, { ...out, message: 'Visible profile identity conflicts with the requested username.' });
    const fields = extracted.fields;
    const meaningful = fields.display_name || fields.raw_fields.bio || fields.raw_fields.schedule || fields.raw_fields.tags.length || Object.values(fields.attributes).some((value) => value !== '' && value !== null && (!Array.isArray(value) || value.length));
    if (!meaningful) return response('not_found', request, { ...out, message: 'No meaningful public profile fields were available.' });
    out.status = 'ok'; out.display_name = fields.display_name; out.raw_fields = fields.raw_fields; out.attributes = fields.attributes; out.diagnostics.fields_found = extracted.found; out.message = 'Public LiveJasmin profile data was fetched for review. Nothing was saved.';
    return safeResponse(out);
  } catch (error) { return response(/timeout/i.test(String(error.message)) ? 'timeout' : 'error', request, { ...out, message: /timeout/i.test(String(error.message)) ? 'The public profile fetch timed out.' : 'The public profile fetch could not complete.' }); }
  finally { await context?.close(); await browser?.close(); }
}
module.exports = { fetchProfile };
