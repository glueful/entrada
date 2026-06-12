# Entrada (social-auth / OAuth) — Security & Quality Review

**Date:** 2026-06-11
**Reviewed version:** 1.8.1
**Framework baseline:** 1.55.0 (floor `^1.50.2`)
**Method:** three independent review passes (OAuth security, framework integration, code quality); four headline findings verified directly against source and the framework router.

---

## Verdict

**Do not release. This extension has authentication-bypass-class defects.**

Entrada is the login gateway for the ecosystem and currently has the weakest token
verification of any reviewed extension. Unlike the other extensions, its headline security
claims are **false in code**: CSRF `state` validation and Apple JWT signature verification are
advertised in the README but are stubbed out / never executed. The P1 set below is real
authentication weakness, not hygiene.

---

## P1 — Critical (auth bypass / account takeover / broken endpoint)

### P1-1 · OAuth `state` is generated but never validated — CSRF on every flow (all 4 providers) — ✅ FIXED (2026-06-11)

> **Resolved.** State generation/validation centralized in
> `AbstractSocialProvider::storeOAuthState()` / `validateOAuthState()` (per-provider key,
> single-use, `hash_equals()`, POST-body-then-query). Every `handleCallback` now rejects a
> missing/mismatched state with 401 before any code exchange. Covered by
> `tests/OAuthStateValidationTest.php` (8 tests). The four providers' inline session writes were
> removed in favor of the shared helper.

Every `initiateOAuthFlow` writes the state to the session
(`FacebookAuthProvider.php:205`, `GithubAuthProvider.php:232`, `GoogleAuthProvider.php:208`,
`AppleAuthProvider.php:230`), but **no `handleCallback` ever reads it back** — verified: only
writes to `$_SESSION['{provider}_oauth_state']` exist; zero reads anywhere in `src/`. The README
claims "State is verified on OAuth callback"; it is not.

**Exploit:** classic login-CSRF / session fixation — the attacker initiates their own flow,
captures their `code`, and feeds the victim a callback URL
(`/auth/social/google/callback?code=<attacker_code>&state=anything`). The victim's browser
completes login as the attacker's identity.

**Fix:** on callback, read `state`, compare with `hash_equals()` against the session-stored
value, unset it, and reject on mismatch/absence. Bind state to the session.

### P1-2 · Apple ID-token signature is never verified — impersonation — ✅ FIXED (2026-06-11)

> **Resolved.** `verifyIdTokenWithJwks()` now reconstructs the RSA public key from the JWKS
> `n`/`e`, verifies the RS256 signature with `openssl_verify`, pins `alg` to `RS256` (rejects
> `none` / RS-HS confusion), and asserts `iss`/`aud`/`exp` only after the signature is proven. The
> web callback and native flows share one verified-claims path (`extractUserProfile()` →
> `verifyAndDecodeIdToken()`); the unverified `JWTService::decode` / raw-base64 fallbacks were
> removed. Covered by `tests/AppleIdTokenVerificationTest.php` (9 tests, real RSA round-trip).

`AppleAuthProvider::verifyAppleIdToken()` fetches Apple's JWKS and finds the matching `kid`
(`AppleAuthProvider.php:597-608`), then discards it:

> ```
> // For now, we're assuming the token is valid if we can extract user data
> // A full implementation would verify the signature using the public key
> // But that would require more cryptography code than we can implement here
> ```
> — `AppleAuthProvider.php:609-612`

It then checks only `aud`/`exp`/`iss` on an **unverified** payload. The web callback path
(`extractUserProfile`, `AppleAuthProvider.php:447`) does no claim checks at all and decodes the
`id_token` via `JWTService::decode` — the **app's own** JWT key, not Apple's JWKS — falling back
to a raw base64 payload decode on failure.

**Exploit:** forge an `id_token` with header `kid` set to any real Apple key id and payload
`{aud:<clientId>, iss:https://appleid.apple.com, exp:future, sub:<victim>}`. The signature is
ignored, so the forgery passes and `findOrCreateUser` logs the attacker in as the victim.

**Fix:** perform real RS256/ES256 verification with the JWKS public key (`openssl_verify` with the
public key reconstructed from `n`/`e`), pin `alg` (reject `none` and HS/RS confusion), assert
`aud === clientId`, `iss === https://appleid.apple.com`, `exp > now` **before** trusting any
claim. Share one verified-claims path between the native and callback flows.

### P1-3 · Email-based account linking with no `email_verified` gate — pre-account-takeover — ✅ FIXED (2026-06-11)

> **Resolved.** `findOrCreateUser()` now links to an existing account only when the provider
> asserts the email is verified (via the strict `isVerifiedFlag()` normalizer — the `"false"`
> string and a missing flag both count as unverified); an unverified email matching an existing
> account is refused with 409 instead of linked/duplicated. GitHub no longer trusts the public
> profile email — verification is resolved authoritatively from `/user/emails` (shared
> `resolveVerifiedEmail()`); Facebook emails are treated as unverified. The unsafe `(bool)$verified`
> stamp in `createUserFromSocial()` was replaced with `isVerifiedFlag()`. Covered by
> `tests/VerifiedEmailFlagTest.php` (12 cases).

`AbstractSocialProvider::findOrCreateUser` (`AbstractSocialProvider.php:147-167`) links a social
login into an existing user purely by **matching `email`**, with no verification gate. The
`email_verified` value is only consulted later to stamp `email_verified_at` on brand-new users —
never as a precondition for linking. Worse, two providers **fabricate** the verification flag from
presence:

- `GithubAuthProvider.php:356`: `'verified_email' => !empty($userProfile['email'])`
- `FacebookAuthProvider.php:332`: `'verified_email' => !empty($userProfile['email']), // Facebook verifies emails`

**Exploit:** an attacker who gets any provider to assert `email = victim@example.com` is silently
auto-linked into the victim's existing account and logged in as them
(`linkSocialAccount` → `formatUserData($emailUser)`).

**Fix:** only perform email-based linking when the provider asserts the email is **verified**;
never infer verification from non-emptiness (for GitHub, only trust `/user/emails` entries with
`verified === true && primary === true`; treat Facebook profile emails as unverified). Prefer
explicit, authenticated, user-initiated linking over silent auto-link on login.

### P1-4 · Unlink endpoint is broken (functional, not security) — ✅ FIXED (2026-06-11)

> **Resolved.** `destroy()` now declares `string $uuid` as a method argument (the router injects
> `{uuid}` by argument name), replacing the always-empty `$request->attributes->get('uuid')` read.
> Guarded by `tests/UnlinkRouteParamTest.php`.

`SocialAccountController::destroy(Request $request)` reads
`$request->attributes->get('uuid', '')` (`SocialAccountController.php:69`), but the framework
router injects route params **by method-argument name** and stores them only in the
`_route_params` array attribute — it never sets `uuid` as an individual request attribute
(verified: `Router.php:649` sets `_route_params`; arg resolution at `Router.php:838-849` matches
by name). So `$uuid` is always `''`, the ownership lookup matches nothing, and every unlink 404s.
(Login paths are unaffected — they use per-provider methods with no `{param}` placeholders.)

**Fix:**
```php
public function destroy(Request $request, string $uuid): Response
```
and delete the `$uuid = $request->attributes->get('uuid', '');` line.

*Note:* the ownership scoping by `user_uuid` is correct, so there is **no IDOR** — the endpoint is
simply non-functional.

---

## P2 — Should block release — ✅ FIXED (2026-06-11)

> **Resolved (all six).**
> - **PKCE/nonce:** PKCE (S256) added to Google/Apple/Facebook; OIDC nonce added for Apple
>   (Google uses userinfo not an id_token; GitHub OAuth Apps don't support PKCE — both documented).
>   Helpers centralized in `AbstractSocialProvider` and tested against the RFC 7636 vector.
> - **Tests:** the extension now has a real suite (`phpunit.xml` + `tests/`, 35 tests) covering
>   state, Apple signature verification, verified-email gating, the unlink contract, and PKCE/nonce.
> - **README:** false claims corrected (see CHANGELOG "README honesty pass").
> - **Error leakage:** `providerFailureResponse()` + the `SocialAccountController` catches now log
>   detail server-side and return generic, status-preserving client messages.
> - **Version bug:** `composerVersion()` reads `extra.glueful.version`.
> - **PHPStan:** raised to **level 8** (green), pinned by a committed `phpstan.neon.dist`.

- **No PKCE and no OIDC `nonce`** on any web flow. Combined with P1-1, the authorization-code flow
  has no defense against code injection/replay. Add PKCE (S256) for all providers and `nonce` for
  the OIDC providers (Google/Apple), validating the returned `nonce` against the session.
- **Zero tests.** No `tests/` directory, no `phpunit.xml`. The `phpunit` composer script and the
  `Tests\` autoload namespace point at nothing. The security-critical paths (token verification,
  state validation, `findOrCreateUser` linking) — i.e. exactly where the P1 bugs live — have no
  regression protection.
- **README over-claims security that does not exist:**
  - "CSRF protection" / "State is verified on OAuth callback" — contradicted by P1-1.
  - "Validates Apple's JWT signatures using a custom ASN.1 parser" with a sample
    `(new ASN1Parser())->validateAppleIdToken($idToken)` — **fabricated API**: `ASN1Parser` has no
    such method, its constructor requires a `$data` argument, and signatures aren't validated
    (P1-2).
  - `verifyNativeToken($idToken, $accessToken)` example — the real signature is single-arg
    `verifyNativeToken(string $idToken)`.
- **Provider error bodies leak to clients.** `exchangeCodeForToken`/`getUserProfile` fold raw
  provider HTTP response bodies into exception messages; those reach the client via
  `SocialAuthController::providerFailureResponse()` and `SocialAccountController` (raw
  `$e->getMessage()`). Log internally with redaction; return generic client-facing messages.
- **Version reporting bug.** `EntradaServiceProvider::composerVersion()` reads a non-existent
  top-level `version` key (`$composer['version'] ?? '0.0.0'`) → reports `0.0.0` to CLI/diagnostics.
  The real version (`1.8.1`) lives at `extra.glueful.version`. (Same bug fixed in
  email-notification/aegis this cycle.) Fix:
  `$composer['extra']['glueful']['version'] ?? $composer['version'] ?? '0.0.0'`.
- **PHPStan:** passes at the configured **level 5**; **level 8 = 55 errors** (~46 mechanical
  `array<...>` shape annotations + ~9 real `string|false` → `json_decode`/`openssl_sign`/
  `base64UrlEncode` type holes at `AppleAuthProvider.php:341,342,349,472` and
  `EntradaServiceProvider.php:30`).

---

## P3 — Hygiene — ✅ MOSTLY FIXED (2026-06-11)

> **Resolved:** phpcs switched to **PSR12** with a committed `phpcs.xml` (`src/` clean, no
> errors/warnings); committed `phpstan.neon.dist` + `phpcs.xml` (config now in the repo, not just
> composer scripts); the duplicated `getDefaultRedirectUri()` hoisted to
> `AbstractSocialProvider::defaultCallbackUri()` with the `HTTP_HOST` trust removed; redundant
> `auth` middleware dropped from the unlink route; `convertToBinary()` empty-string deref guarded;
> stale README framework version corrected (in the P2 pass).
>
> **Deferred (structural — out of hygiene scope, noted intentionally):**
> - `$response->send(); exit;` in `initiateOAuthFlow()` is load-bearing: `authenticate()` returns
>   `?array`, so without `exit` the `return null` would trigger a second (error) response after the
>   redirect. Removing it cleanly requires flowing a `RedirectResponse` up through
>   `AuthenticationProviderInterface` — an interface change best done with integration coverage.
> - The remaining per-provider duplication (`loadConfig`, `exchangeCodeForToken`, `isOAuthCallback`/
>   `isOAuthInitRequest`) is provider-specific (differing endpoints, params, and Apple's
>   POST-or-query callback); hoisting it safely needs live-provider integration tests.

- phpcs uses the deprecated **Squiz** standard; PSR12 (the ecosystem standard) reports 6
  auto-fixable errors + 6 line-length warnings.
- No committed `phpstan.neon`/`phpstan.neon.dist` or `phpcs.xml` — level/standard live only in
  composer scripts, so CI/IDEs don't agree.
- **Heavy copy-paste across the 4 provider classes** (~2,046 LOC). `loadConfig()`,
  `getDefaultRedirectUri()`, `isOAuthCallback()`, `isOAuthInitRequest()`,
  `exchangeCodeForToken()`, and the `initiateOAuthFlow()` state/session boilerplate are
  near-identical and never hoisted into `AbstractSocialProvider`. Centralizing would also let the
  state-validation fix (P1-1) be written once.
- `$response->send(); exit;` in `initiateOAuthFlow` bypasses the framework response pipeline and
  makes the redirect path untestable.
- `getDefaultRedirectUri()` derives the redirect/base URL from `$_SERVER['HTTP_HOST']` when
  `app.url` is unset — host-header injection surface. Require `app.url`; never trust `HTTP_HOST`.
- Stale README header "Glueful Framework 1.22.0 or higher" — real floor is `^1.50.2`.
- Redundant `auth` middleware on the unlink route (`routes.php:338`) — the group already applies
  `['auth']` (`routes.php:301`); keep only `rate_limit`.
- `convertToBinary` (`AppleAuthProvider.php:399-418`) accesses `$value[0]` after `ltrim` without
  guarding the empty string — undefined-offset in the Apple client-secret signing path.

---

## Verified-safe (not re-flagged)

- **No SQL injection** — all DB access uses the parameterized query builder
  (`where([...])`/`insert()`), no string interpolation into SQL.
- **Migration / table ownership** — `001_CreateSocialAccountsTable.php` creates the entrada-owned
  `social_accounts` table via the schema builder (no raw SQL), references `user_uuid` as a plain
  indexed column with **no** foreign key into the user store, and migrates at `DEPENDENT` priority
  with source `glueful/entrada`. Fully compliant with the user-store decoupling rule.
- **Route gating** — both account-management routes (`index`, `destroy`) are correctly behind
  `auth`; all OAuth init/callback/native routes are correctly public. No unauthenticated
  state-changing/user-data route.
- **Unlink IDOR** — `destroy` scopes the lookup and delete by the authenticated user's
  `user_uuid`, so user A cannot unlink user B's account (the endpoint is broken, but not insecure).
- **Facebook native** — `verifyNativeToken` → `verifyFacebookAccessToken` uses Graph `debug_token`
  with the app access token and checks `is_valid` and `app_id === appId`. This is the **one** token
  path correctly bound to the app.
- **Framework 1.55.0 compatibility** — all 6 `services()` bindings are concrete instantiable
  classes (survive the S2 non-instantiable-binding guard); `boot()` does not throw (no DB hit, no
  required env), so S1's loud-failure path isn't tripped. Helper/base-class signatures
  (`app`, `config`, `mergeConfig`, `loadRoutesFrom`, `loadMigrationsFrom`, `registerProvider`,
  `registerMeta`) all match the framework source.

---

## Recommended fix order

1. **P1-1 — state/CSRF validation** (unauthenticated, no precondition; also unblocks the shared
   refactor).
2. **P1-2 — Apple signature verification** (unauthenticated impersonation).
3. **P1-3 — verified-email gate on linking** (account takeover; needs an attacker-controlled
   provider email).
4. **P1-4 — fix the unlink route param** (functional).
5. P2 set: PKCE/nonce, a real test suite over the verification paths, README honesty pass, error
   redaction, version key, PHPStan level 8.
6. P3 hygiene.
