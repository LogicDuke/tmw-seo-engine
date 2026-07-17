# LiveJasmin profile fetch service boundary

The WordPress admin Import button validates and canonicalizes a public LiveJasmin URL, then only when Safe Mode is off and all `livejasmin_profile_fetch_*` settings are explicitly configured, calls the isolated HTTPS Node service with an authenticated JSON request. The shared secret is encrypted by the existing settings crypto flow and is never sent to the browser. Otherwise the existing null service remains active.

Responses are candidate-only previews: no post meta, evidence, review status, generated content, or publication is changed. Operators must review data manually. The service rejects noncanonical/redirected identities and returns non-success for CAPTCHA, login, age gate, blocked, timeout, and unavailable profiles. Configure the endpoint, secret, enable flag, and 3–30 second timeout only on staging first.
