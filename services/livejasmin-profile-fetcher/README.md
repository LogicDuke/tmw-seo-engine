# LiveJasmin public-profile fetcher

Isolated Node 20+ / Playwright Chromium service for **manual, staging-only** public profile candidate fetches. It exposes `GET /health` and `POST /v1/fetch-profile`. Set `TMW_PROFILE_FETCH_TOKEN` and `PORT`, run `npm ci`, `npx playwright install chromium`, then `npm start`.

`POST /v1/fetch-profile` requires `Content-Type: application/json` and `X-TMW-Profile-Fetch-Token`; body is `provider`, canonical `source_url`, matching `username`, and `request_id`. It accepts only `https://www.livejasmin.com/en/chat/{username}`. Run `npm test`; tests never contact LiveJasmin.

The service uses a fresh context per request, blocks media/fonts, has a 15-second timeout, and returns bounded JSON (PHP caps responses at 128 KiB). It never logs tokens or page content, saves cookies, authenticates, submits/clicks forms, downloads media, or bypasses CAPTCHA, login, age gates, or access controls. CAPTCHA/login/access-denied responses are structured non-successes. Deploy behind HTTPS with a distinct secret; the plugin must be explicitly configured and Safe Mode disabled.
