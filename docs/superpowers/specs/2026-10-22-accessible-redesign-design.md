# CoopSys accessible redesign: design

Date: 2026-10-22. Status: awaiting review.

## Intent

Staff of a cooperative (cashier, accountant, manager, loan officer) use CoopSys on desktop all day. They want every screen readable and usable by keyboard and screen reader, with a calmer, clearer look. Features already work (92/92 tests pass, 40 pages load without errors), so this is a restyle plus accessibility fixes. No new modules.

## Scope (approach 1: retheme the shared layer)

Change the shared layer, so every page inherits it, then fix the pages that still fail.

Shared files:
- `public/assets/js/tailwind-config.js`: colour tokens.
- `public/assets/css/style.css`: inputs, buttons, tables, focus, motion.
- `resources/views/layouts/app.blade.php`: skip link, landmarks.
- `resources/views/components/{field,select,card,page-header}.blade.php`.

Out of scope: new features, new pages, a JS framework, a rebuild of individual views, mobile apps.

## Design

1. **Colour.** Keep the navy primary. Make every text/background pair at least 4.5:1 (3:1 for large text and UI borders). Re-check hint text, placeholders, disabled states and badges. Never use colour alone to show status: pair it with text or an icon.
2. **Type.** System font stack first, Inter kept as is. Body 16px, data tables and form text 14px minimum. Remove the 72 uses of 10-11px text. Tight tracking on large headings, normal on body. Spacing in rem.
3. **Focus.** One visible focus ring (2px, 3:1 against its surroundings) on every link, button, input and select, via `:focus-visible`.
4. **Structure.** Skip-to-content link, one `main` landmark, labelled `nav`, one `h1` per page (add to Collections workspace). `aria-current` stays on the active nav item.
5. **Forms.** Every field has a programmatic label. Known gaps: Journal entry (8), Share capital (9), Settings (6), Dashboard (2), Savings (2). Errors sit next to the field and are linked with `aria-describedby`. Icon-only controls get `aria-label`.
6. **Motion.** Transitions of 100-200ms on `transform` and `opacity` only, never blocking input. `prefers-reduced-motion` turns them into plain fades or nothing. `prefers-contrast: more` gives solid backgrounds and stronger borders.
7. **Touch/click targets.** Buttons and row actions at least 44x44px hit area on desktop-touch screens.
8. **Cleanup.** Remove the leftover `/starter` scaffold route and view.

## Verification

- Existing 92 tests keep passing.
- Add feature tests for the new behaviour: every form field on the listed pages has a label, each page has exactly one `h1`, the layout has a skip link and `main`.
- Manual pass in the browser: keyboard-only walk through login, deposit, member add, journal entry; contrast check on the final tokens; reduced-motion on.
- Click through the six roles not yet tested and the data-saving actions (deposit, loan release, journal entry).

## Risks

- The Tailwind CDN build means colour changes live in one JS file but classes like `text-[11px]` are spread across 72 views, so the type change touches many views.
- Contrast numbers are estimates until measured on the final palette.
- No git commit has been made for this spec.
