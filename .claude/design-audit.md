# Apple Design Audit — PCO TEC

Audit of this plugin against the `apple-design` skill (`.claude/skills/apple-design/SKILL.md`), 2026-10-24. Section references (e.g. §16 *Agency*) point to headings in that skill. The audit of all five Comms.Church plugins is in the shared doc: https://claude.ai/artifact/GGvMskv9nX4bc82HuR4wmS

## Overview

The five plugins have 61 design issues between them: 4 Critical, 13 High, 20 Medium and 24 Low. The worst ones lose people's work or leave them stuck, not visual polish: an editor that hangs on a failed save, sermon edits lost without warning, a confirmation that never fires, and future event dates deleted with no warning.

Each finding gives **What** is wrong, **Why** it matters (citing the section and heading of the vendored `apple-design` skill, e.g. §16 *Agency*), and a **Fix** in the plugin's own stack: plain CSS, jQuery or plain JS, and WordPress PHP. Severity is ranked by harm to the person using it:

- **Critical:** data loss, or a broken layout most visitors will see
- **High:** blocks a task, excludes keyboard or screen-reader users, or shows a wrong state
- **Medium:** friction, unclear feedback, or contrast below WCAG minimums
- **Low:** polish and consistency

The skill is mostly about gesture-driven motion (springs, flicks, momentum). These plugins have no draggable UI, so most citations come from §1 Response, §7 Spatial consistency, §12 Materials, §14 Reduced motion & accessibility, §15 Typography and §16 Design foundations.

### Worst issues across all plugins

| Rank | Plugin | Finding | Severity | Fixed in this pass |
| --- | --- | --- | --- | --- |
| 1 | Recurring Events | Changing a pattern permanently deletes future dates without warning | Critical | In part (warning added) |
| 2 | Sermon Suite | A failed save leaves the editor stuck on "Saving…" | Critical | Yes |
| 3 | Sermon Suite | No warning before leaving with unsaved edits | Critical | Yes |
| 4 | PCO TEC | Events without a featured image show an empty gray box | Critical | Yes |
| 5 | PCO TEC | "Force Full Re-sync" never asks for confirmation | High | Yes |
| 6 | PCO | Add-to-Calendar menu opens upward and can't be used from the keyboard | High | Yes |
| 7 | Sermon Suite, PCO TEC | No visible keyboard focus | High | Yes |
| 8 | PCO | Generator preview doesn't match the live page | High | Yes |
| 9 | Groups | Filtering changes the page silently | High | Yes |
| 10 | Groups, PCO, PCO TEC | Cards lift on hover but aren't links | High / Medium / Low | Yes in Groups and PCO; TEC still open |

A pattern across the suite: Groups and PCO already handle reduced motion, focus rings and container sizing well, while Sermon Suite and PCO TEC don't. A shared base stylesheet for focus, press and reduced-motion styles would stop these drifting apart again.

## Comms Church PCO TEC

PCO TEC has 12 findings, and three are outright bugs. Two class names in the event card don't match the stylesheet, so those styles never apply. The "Force Full Re-sync" confirmation never fires. Files: `assets/front.css`, `assets/admin.css`, `assets/admin.js`, `includes/class-cctec-shortcodes.php`, `includes/class-cctec-admin.php`.

### 1. Events without a featured image show an empty gray box (Critical)

**What:** The card prints `<div class="cctec-img-placeholder">`, but the CSS styles `.cctec-image-placeholder`. The placeholder is never positioned or colored, so every event without a featured image gets an empty 16:9 gray box with the event name in plain text below it.

**Why:** §16 *Craft*: "Uncompromising attention to detail builds trust… layouts that break… read as carelessness."

**Fix** (`assets/front.css`): accept both names, so markup and any custom CSS keep working:

```css
.cctec-image-placeholder,
.cctec-img-placeholder { position: absolute; inset: 0; background: var(--cctec-brand); /* …existing rules */ }
```

### 2. "Force Full Re-sync" never asks for confirmation (High)

**What:** `admin.js` attaches the confirm to `$('button[name="cctec_force"]')`. That name is on a *hidden input*, not the button, so the selector matches nothing. One click overwrites every TEC event field from PCO, including manual edits, without warning. The button also looks the same as "Clear API Cache" beside it.

**Why:** §16 *Agency*: "a confirmation dialog only for genuinely destructive, irreversible actions". This is exactly that case.

**Fix:**

```js
$('input[name="cctec_force"]').closest('form').on('submit', function (e) { /* existing confirm */ });
```

Also style the button as destructive (`.button.cctec-btn-danger { color:#b32d2e; border-color:#b32d2e; }`) and add a gap between it and the routine buttons.

### 3. Date and location rows are unstyled (High)

**What:** The markup uses `<p class="cctec-meta-item">`, but the CSS targets `.cctec-meta-date` and `.cctec-meta-location`. The date and venue rows fall back to theme paragraph styles: full-size, full-contrast text, icons not aligned to the text, and theme paragraph margins. Only the price row is styled as designed.

**Why:** §15: "Build hierarchy from weight + size + leading as a set." The designed hierarchy never reaches the page. §16 *Craft*.

**Fix:**

```css
.cctec-meta-item,
.cctec-meta-date, .cctec-meta-location, .cctec-meta-price { display:flex; align-items:flex-start; gap:.35rem; font-size:var(--cctec-font-sm); color:var(--cctec-muted); margin:0; }
.cctec-meta-item svg { flex-shrink:0; margin-top:2px; color:var(--cctec-brand); }
```

### 4. No focus rings and no reduced-motion support (High)

**What:** `front.css` has no `:focus-visible` rules and no `prefers-reduced-motion` block, yet the card lifts on hover. The sibling PCO plugin already has both.

**Why:** §14: "Respond to three independent signals and bake them into your components." §16 *Flexibility*: the full range of abilities.

**Fix:**

```css
.cctec-btn:focus-visible, .cctec-card-title a:focus-visible { outline: 2px solid var(--cctec-brand); outline-offset: 2px; }
@media (prefers-reduced-motion: reduce) {
  .cctec-event-card, .cctec-btn { transition: none !important; }
  .cctec-event-card:hover { transform: none; }
}
```

### 5. "Sync Now" gives no feedback while it runs (Medium)

**What:** Sync Now and Force Re-sync submit a normal form that runs the whole sync inside the request. That can take a long time on a big calendar. The button stays enabled and unchanged, so people click again and start a second sync.

**Why:** §16: "expose ongoing status", one of the four feedback kinds. §1 *Response*: feedback starts on input.

**Fix** (`admin.js`):

```js
$('input[name="cctec_action"][value="sync_now"]').closest('form').on('submit', function (e) {
  if (e.isDefaultPrevented()) return;
  $(this).find('button').prop('disabled', true).text('Syncing…').after('<span class="spinner is-active" style="float:none"></span>');
});
```

### 6. Buttons don't react on press, and hover makes them lighter (Medium)

**What:** `.cctec-btn-primary:hover` applies `filter: brightness(1.12)`, which lowers white-text contrast. There is no `:active` state.

**Why:** §1 *Response*: "Highlight a button the instant it's pressed."

**Fix:** `.cctec-btn-primary:hover { filter: brightness(.92); }` and `.cctec-btn:active { transform: scale(.97); transition: transform 100ms ease-out; }`.

### 7. A mistyped hex code silently clears the brand color (Medium)

**What:** The hex text field is what gets submitted, and `sanitize_hex_color` turns an invalid value such as `1a4a8a` or `#1a4a8` into an empty string. The brand color resets to the default with no message.

**Why:** §16: "validate inline (not on submit)."

**Fix:** add `pattern="#[0-9a-fA-F]{6}" aria-describedby="cctec-color-help"` to the text input. In the existing `input` handler, toggle `aria-invalid` and a red border when the value doesn't match.

### 8. The "Free" label fails contrast (Medium)

**What:** `.cctec-price-free { color: #16a34a }` is about 3.3:1 on white.

**Why:** §16 *Craft*. §14 `prefers-contrast`.

**Fix:** `color: #15803d;` (about 5:1).

### 9. The card lifts on hover but isn't one link (Low)

**What:** The whole card lifts on hover, but only the title link and the Register button work.

**Why:** §16 *Familiarity*: "things that look the same must behave the same."

**Fix:** drop the `translateY` and keep the shadow change. Or stretch the title link over the card with `.cctec-card-title a::after { content:""; position:absolute; inset:0 }` and lift the Register button with `position:relative; z-index:1`.

### 10. Cards stop at 480 px wide (Low)

**What:** `.cctec-event-card { max-width: 480px }` stops cards filling a theme or Bricks grid column, so cards in a 3-up grid leave ragged gaps.

**Why:** §16 *Flexibility*: adapt to context. §16 *Craft*: alignment.

**Fix:** `max-width: var(--cctec-card-max, 480px);` and set `--cctec-card-max: none` when the card sits in a grid.

### 11. Card titles barely stand out (Low)

**What:** Titles are 1.05rem against 0.875rem meta text, the same weak step as PCO.

**Why:** §15 hierarchy.

**Fix:** `.cctec-card-title { font-size: 1.2rem; line-height: 1.25; letter-spacing: -0.01em; }`.

### 12. Admin panels use their own styling instead of WordPress's (Low)

**What:** The settings page uses uppercase 14 px card headings, custom green and red status pills (`#d4edda` and `#f8d7da`), and a 12 px monospace log. None of these match WordPress admin conventions.

**Why:** §16 *Familiarity*: build on what people already know.

**Fix:** use `<div class="notice notice-success inline">` and `notice-error` for status, sentence-case `<h2>`s, and `font-size: 13px` for the log.

## Fixes applied in this pass

I applied 29 of the 61 fixes in full and 5 more in part, covering every Critical and High finding, on the `claude/quirky-feynman-2ppasd` branch of each repo. Each repo also got the `apple-design` skill and a Markdown copy of its section, both under `.claude/`, which the release zip already excludes.

| Plugin | Applied in full | Applied in part | Still open |
| --- | --- | --- | --- |
| Sermon Suite | 1, 2, 3, 4, 5, 6, 7 | 8 (`composes:` bug only), 9 (heading tracking only), 11 (iframe titles, remove-button label) | 10, 12–17 |
| Groups | 1, 2, 3, 4, 7 | — | 5, 6, 8–12 |
| PCO | 1, 2, 3, 4, 7, 8 | — | 5, 6, 9–12 |
| PCO TEC | 1, 2, 3, 4, 5, 6, 7, 8 | — | 9–12 |
| Recurring Events | 4, 5, 8 | 1 (warning only), 2 (summary line only) | 3, 6, 7 |

Two changes need a second opinion before going further:

- **Recurring Events #1:** I added the warning but kept `wp_delete_post( …, true )`. Switching to `wp_trash_post()` changes what `children_of()` sees, so it needs its own change and test.
- **PCO #1:** making the menu drop down meant removing `overflow: hidden` from `.ccpco-card`. The image now rounds its own corners. Check a theme that adds a border to cards.

**Verification.** The repos' Playwright suites couldn't run here: the environment's network policy blocks `wordpress.org` and `playground.wordpress.net`, which WordPress Playground needs to boot. Instead, every edited PHP file passes `php -l` and every edited script passes `node --check`. A headless-Chromium harness loaded each plugin's real CSS and JS against markup copied from its renderer and passed 39 of 39 checks. Those covered the menu's direction, focus and keyboard behavior and that the card no longer clips it; the Groups count, reset and focus return; the TEC confirm and Syncing state; the recurrence summary text; and the Sermon Suite chip, badge and focus fixes. Run `npm test` in each repo locally before merging.
