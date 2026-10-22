# CoopSys Accessible Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every CoopSys screen keyboard- and screen-reader-accessible with readable contrast and type, and give it a calmer look, without adding features.

**Architecture:** Retheme the shared layer (Tailwind tokens, `style.css`, layout, four Blade components) so all pages inherit it, then fix the few pages whose own markup still fails. A feature test walks every page and pins the accessibility rules.

**Tech Stack:** Laravel 12 / PHP 8.4, Blade, Tailwind via CDN (`public/assets/js/tailwind-config.js`), plain CSS (`public/assets/css/style.css`), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-22-accessible-redesign-design.md`

## Global Constraints

- No new modules, pages, JS framework or dependencies.
- Text contrast at least 4.5:1 (3:1 for large text and UI borders and focus rings).
- Body text 16px; table and form text 14px minimum; captions and badges 12px minimum (nothing at 10px or 11px).
- One visible `:focus-visible` ring (2px) on every link, button, input, select.
- Transitions 100-200ms on `transform`/`opacity` only; `prefers-reduced-motion` and `prefers-contrast: more` honored.
- Buttons and row actions have a 44x44px hit area.
- Run `vendor/bin/pint --dirty --format agent` after PHP edits. The 92 existing tests must keep passing.
- Do not make git commits unless the user asks.

## Review Focus

- Pages reached by a role other than Super Admin (Cashier, Auditor) render the skip link and landmarks too. Task 1 tests Cashier on `/savings`.
- A validation error on a field is announced: the error `<p>` is linked via `aria-describedby`. Task 2 tests a failed member form.
- Disabled and readonly inputs (locked account code) still meet 4.5:1 for their text. Task 3 test covers it.
- Printing still hides the sidebar and header. Task 3 keeps the print block and checks it by hand.
- Dark/tinted badge colors (status chips) keep contrast. Task 3 test covers each badge pair.

---

### Task 1: Accessibility feature test (fails first)

**Files:**
- Create: `tests/Feature/AccessibilityTest.php`

**Interfaces:**
- Produces: `AccessibilityTest::pages(): array` data provider of the 38 page URLs listed in the spec audit (dashboard, account, every `accounting/*` page, cash-receipts, cash-disbursements, collections, collections/new, loan-products(+create), loans(+create), members(+create), reports, savings, share-capital, transactions, settings, users(+create)). Reuse the user/org setup helper from `tests/Feature/TellerTest.php`.

- [ ] **Step 1: Write the tests**, each over `pages()` as Super Admin:
  - `test_page_has_one_h1` asserts `substr_count($html, '<h1')` equals 1.
  - `test_page_has_skip_link_and_main` asserts the HTML contains `href="#main"` and exactly one `<main id="main"`.
  - `test_every_field_has_a_label` parses the HTML with `DOMDocument`; every `input` (not hidden/checkbox/radio/submit), `select`, `textarea` has `aria-label`, or an `id` matched by a `label[for]`, or sits inside a `label`.
  - `test_cashier_savings_page_has_landmarks` signs in as Cashier and asserts the same skip link and `main` on `/savings`.
  - `test_starter_route_is_gone` asserts GET `/starter` returns 404.
- [ ] **Step 2: Run** `php artisan test --compact --filter=AccessibilityTest` — Expected: FAIL (no skip link, unlabeled fields, `/starter` exists, collections/new has no h1).

### Task 2: Layout structure and cleanup

**Files:**
- Modify: `resources/views/layouts/app.blade.php` (skip link as first body child; `<main id="main" tabindex="-1">`; `aria-label="Main menu"` on the sidebar `nav`; `aria-hidden="true"` on decorative `material-symbols-outlined` spans)
- Modify: `resources/views/collections/workspace.blade.php` (add one visible or `sr-only` `<h1>`)
- Modify: `resources/views/components/field.blade.php`, `select.blade.php` (`aria-describedby` pointing to the hint and error ids, `aria-invalid="true"` when `$errors->has($name)`)
- Modify: `routes/web.php` (remove `/starter` route), delete `app/Http/Controllers/HomeController.php` index use only if nothing else references it, and its starter view.

**Interfaces:**
- Produces: `#main` anchor and `.skip-link` CSS class (styled in Task 3). Field/select component ids `{name}-hint` and `{name}-error`.

- [ ] **Step 1: Implement** the changes above; field and select keep their current props.
- [ ] **Step 2: Add a test** `test_failed_member_form_links_error_to_field` in `AccessibilityTest` that posts an empty member form and asserts the response HTML has `aria-invalid="true"` and an `aria-describedby` that matches an existing `id`.
- [ ] **Step 3: Run** `php artisan test --compact --filter=AccessibilityTest` — Expected: skip link, main, h1, starter and error-link tests PASS; label test still fails.

### Task 3: Tokens, type, focus, motion

**Files:**
- Modify: `public/assets/js/tailwind-config.js` (colour tokens that fail contrast; keep navy primary)
- Modify: `public/assets/css/style.css`
- Modify: the 36 views containing `text-[10px]` or `text-[11px]` (find with `grep -rln "text-\[1[01]px\]" resources/views`)
- Create: `tests/Unit/ContrastTest.php`

**Interfaces:**
- Consumes: `.skip-link` and `#main` from Task 2.
- Produces: CSS classes `.skip-link`, `.focus-ring`-free global `:focus-visible` rule, `.sr-only` utility (already in Tailwind).

- [ ] **Step 1: Write `ContrastTest`** with `contrastRatio(string $fg, string $bg): float` (WCAG relative luminance) and a data provider of the real pairs: `on-surface` on `surface`, `on-surface-variant` on `surface-container-low`, `outline` (hint text) on white and on `#f2f3ff`, `error` on white, `on-error-container` on `error-container`, `on-secondary-fixed` on `secondary-fixed`, `primary` on white, white on `primary`, white on `secondary`, disabled input text on its background. Assert each is >= 4.5.
- [ ] **Step 2: Run** `php artisan test --compact --filter=ContrastTest` — Expected: FAIL for any pair under 4.5 (likely `outline` hint text on `#f2f3ff`).
- [ ] **Step 3: Fix tokens** in `tailwind-config.js` and the hard-coded hex values in `style.css` until every pair passes; `.field-hint` colour darkened to a passing value.
- [ ] **Step 4: Update `style.css`:** `body` 16px/1.5; `.input` 14px; `.btn` 14px with `min-height: 44px`; `.btn-sm` `min-height: 36px` with padding so the hit area stays 44px via `position: relative` pseudo-element; `.data-table` 14px, `th` 12px; `.tl-*` 10px and 11px rules raised to 12px; headings `letter-spacing: -0.02em`.
- [ ] **Step 5: Add** `:focus-visible { outline: 2px solid #1e3a8a; outline-offset: 2px; }` for `a, button, input, select, textarea, [tabindex]`; `.skip-link` hidden until focused; `@media (prefers-reduced-motion: reduce)` setting `transition: none; animation: none`; `@media (prefers-contrast: more)` solid backgrounds and 2px borders. Transitions use 150ms on `transform`/`opacity`/`background-color` only. Keep the `@media print` block untouched.
- [ ] **Step 6: Sweep views:** replace `text-[10px]` and `text-[11px]` with `text-xs` (12px) in all 36 views. Re-run grep, Expected: no matches.
- [ ] **Step 7: Run** `php artisan test --compact --filter=ContrastTest` — Expected: PASS. Then reload `/dashboard`, `/members`, `/savings` in the browser and confirm no layout overflow.

### Task 4: Label the remaining fields

**Files:**
- Modify: `resources/views/accounting/journal/create.blade.php` (8 fields), `resources/views/share/index.blade.php` (9 inline `subscribed_shares` inputs), `resources/views/settings/edit.blade.php` (6 fields including `allocation_custom[]`), `resources/views/dashboard.blade.php` (2), `resources/views/savings/index.blade.php` (2)

**Interfaces:**
- Consumes: the `x-field`/`x-select` components from Task 2.

- [ ] **Step 1: Run** `php artisan test --compact --filter=test_every_field_has_a_label` — Expected: FAIL naming those pages.
- [ ] **Step 2: Fix each:** table-row inputs get `aria-label` built from the row (for example `"Subscribed shares for {member name}"`, `"Debit amount, line {n}"`); standalone fields get a real `<label for>`.
- [ ] **Step 3: Run** the whole `AccessibilityTest` — Expected: all PASS.

### Task 5: Full verification

**Files:** none changed unless a check fails.

- [ ] **Step 1: Run** `php artisan test --compact` — Expected: all tests pass (92 existing plus the new ones).
- [ ] **Step 2: Run** `vendor/bin/pint --dirty --format agent` — Expected: passed.
- [ ] **Step 3: Keyboard walk** (browser, Tab/Shift+Tab/Enter only): demo login, a deposit on `/savings`, add a member, post a journal entry. Every control reachable, focus ring always visible, skip link works.
- [ ] **Step 4: Role pass:** sign in with each of the 7 demo buttons and open its sidebar pages; none shows an error or an overlapping layout.
- [ ] **Step 5: Reduced motion and print:** emulate `prefers-reduced-motion: reduce` and confirm no sliding; print-preview `/transactions` and confirm sidebar and header are hidden.
- [ ] **Step 6: Report** what passed and anything not checked.
