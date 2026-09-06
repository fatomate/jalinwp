# JalinWP Brand And Theme Guidelines

**Draft 0.2 · 6 September 2026 · Approved Logo and Palette; Application Details for Review**

The **JalinWP name, logo A · Jalin Weave, and palette option 2 · Cobalt + Coral are approved**. Typography, tagline, supporting colors, application details, and measurements remain proposed. The approved board establishes visual direction; a raster starter pack accompanies this guideline. The editable vector master, vector refinement, and final actual-size QA remain outstanding. This guideline does not change the live product.

## Brand Foundation

### Positioning

JalinWP is an open-source MCP gateway for WordPress and WooCommerce. It connects AI tools with site and store workflows through access modes that make the level of control clear.

**Proposed tagline:** Your Site. Connected.

**Short descriptor:** An open-source MCP gateway for WordPress and WooCommerce.

The name suggests weaving and connection. Translate that idea into clear relationships and purposeful joins. Malaysian weaving can inform the mark through abstract connections, without intricate craft patterns or implied national endorsement.

### Personality

Be bold, refreshing, energetic, and capable. Express excitement through confident color, strong typography, and direct language. Developer material should remain precise and approachable; store material should explain consequences plainly. Avoid exaggerated promises, magical language, aggressive hacker styling, or claims of automatic safety.

## Voice And Writing

Use **Title Case for UI headings and buttons**; sentence case for paragraphs, helper text, errors, and descriptions. Preserve established spellings: JalinWP, WordPress, WooCommerce, Gutenberg, MCP, and OAuth. Use lowercase brand spelling only for identifiers and filenames.

Lead with the action or result. Expand “Model Context Protocol (MCP)” on first use in documentation. Prefer “connect,” “review,” “allow,” and “change” over promotional language.

| Context | Proposed Copy |
| --- | --- |
| Connection heading | Connect Your WordPress Site |
| Connection button | Connect WordPress |
| OAuth helper | Sign in with WordPress to connect your site. |
| Successful connection | Your WordPress site is connected. |
| Reviewed action heading | Review Proposed Changes |
| Reviewed action helper | Check the proposed changes before approving them. |
| Failure message | We couldn't connect to your site. Check the details and try again. |

Messages must reflect actual behavior. Only promise revocation, rollback, encryption, audit history, or approval enforcement when implemented. Give errors a useful next action when the cause is known.

## Color System

**Approved palette: option 2 · Cobalt + Coral.** Cobalt provides a confident brand anchor; Coral adds energy and excitement. Midnight gives text and the wordmark depth, while White creates clear space. The application roles below are proposed uses of the approved colors.

| Token | Value | Status | Intended Role |
| --- | --- | --- | --- |
| `brand.cobalt` | `#3048FF` | Approved color | Primary actions, links, selected controls, major brand blocks |
| `brand.coral` | `#FF4D45` | Approved color | Accent blocks, highlights, and the second logo ribbon |
| `brand.midnight` | `#18153F` | Approved color | Primary text, wordmark, dark surfaces, text on Coral |
| `surface.white` | `#FFFFFF` | Approved color | Main canvas and reversed artwork |
| `surface.tint` | `#F3F4FF` | Proposed supporting color | Subtle panels and grouped content |
| `text.secondary` | `#55556A` | Proposed supporting color | Supporting text on White or Light Tint |

Create energy with deliberate Cobalt and Coral blocks, strong type, and clear whitespace. Use White text on Cobalt and Midnight text on Coral. Keep everyday reading surfaces White or the proposed Light Tint. Avoid gradients, competing accents, and clutter. Coral is a brand accent; it must not automatically become the error or warning color. Define separate success, warning, error, and information tokens, with labels and icons that make each state explicit.

Calculated palette contrast using the WCAG sRGB formula:

| Pair | Ratio |
| --- | --- |
| White / Cobalt | 6.01:1 |
| Midnight / Coral | 5.25:1 |
| Coral / White | 3.28:1 |
| Cobalt / Coral | 1.83:1 |
| Midnight / White | 17.24:1 |
| Midnight / Light Tint (proposed) | 15.76:1 |
| Cobalt / Light Tint (proposed) | 5.49:1 |
| Secondary Text / White (proposed) | 7.26:1 |
| Secondary Text / Light Tint (proposed) | 6.63:1 |

These calculations cover opaque, flat palette pairs, not rendered UI. White/Cobalt and Midnight/Coral pass normal-text AA. Coral/White passes the 3:1 threshold for qualifying large text and required non-text contrast, but fails normal-text AA. Cobalt/Coral fails both 4.5:1 and 3:1 thresholds; do not rely on that pair for readable text or required boundaries. Preserve the logo's negative-space channels where the two ribbons meet. Verify actual borders, focus, hover, and disabled states in each application.

### Draft Developer Tokens

The four core hex values are approved. Aliases, supporting colors, and font choice remain proposed; semantic status and interaction-state tokens will be specified separately.

```css
:root {
  --jalinwp-cobalt: #3048FF;
  --jalinwp-coral: #FF4D45;
  --jalinwp-midnight: #18153F;
  --jalinwp-white: #FFFFFF;
  --jalinwp-surface-tint: #F3F4FF; /* Proposed */
  --jalinwp-text-secondary: #55556A; /* Proposed */
  --jalinwp-surface: var(--jalinwp-white);
  --jalinwp-text: var(--jalinwp-midnight);
  --jalinwp-action: var(--jalinwp-cobalt);
  --jalinwp-on-action: var(--jalinwp-white);
  --jalinwp-accent: var(--jalinwp-coral);
  --jalinwp-on-accent: var(--jalinwp-midnight);
  --jalinwp-font-web: "Manrope", system-ui, sans-serif; /* Proposed */
}
/* Preserve the native WordPress admin font. Define status tokens separately. */
```

## Typography And Hierarchy

**Proposed brand and web typeface:** [Manrope](https://fonts.google.com/specimen/Manrope), weights 400, 500, 600, and 700, with a system sans-serif fallback. Manrope uses the [SIL Open Font License 1.1](https://github.com/google/fonts/blob/main/ofl/manrope/OFL.txt); retain the copyright and license with redistributed font files. Preserve the native WordPress admin font within the plugin.

| Role | Desktop Proposal | Weight | Line Height |
| --- | --- | --- | --- |
| Marketing display | 48–56 px | 700 | 1.1 |
| Page title | 32–36 px | 700 | 1.2 |
| Section heading | 24–28 px | 600 | 1.25 |
| Card heading | 18–20 px | 600 | 1.35 |
| Body | 16 px | 400 | 1.6 |
| Control label | 14–16 px | 600 | 1.4 |
| Supporting text | 14 px | 400 | 1.5 |

Reduce display text to 36–40 px on narrow screens. Keep reading columns near 60–75 characters and allow wrapping. Reserve monospace for code and technical identifiers; use tabular numerals for comparable financial figures.

## Layout, Components, And Icons

Use spacing steps of 4, 8, 12, 16, 24, 32, 48, and 64 px. Suggested radii: 8 px for controls, 12 px for panels, and 16 px for large blocks. Reserve pill shapes for compact badges.

Use visible borders, limited shadows, clear grouping, and one primary action per decision area. Maintain consistent button heights and accessible interaction targets.

On the website and in documentation, build momentum with strong headings, generous whitespace, and purposeful Cobalt or Coral accent blocks. Keep dense product screens easy to scan. Within WordPress admin, retain the native font and familiar interaction patterns while applying approved brand color selectively.

Use one simple icon family on a 24 px grid with consistent strokes. Test at display size and label unfamiliar symbols. Avoid robots, brains, AI sparkles, and dense circuit patterns.

## Approved Logo

**A · Jalin Weave is selected.** Two broad interlacing ribbons form a compact woven J, connecting the name with weaving and cooperation. Preserve the approved silhouette, overlap logic, and negative-space channels during vector refinement. Concept-board typography indicates the wordmark treatment; final typesetting remains part of production refinement.

The main color lockup will use **Cobalt for the ribbon formerly shown in teal, Coral for the ribbon formerly shown in dark ink, and Midnight for the JalinWP wordmark**. Use White as the preferred background. Match the selected source board when refining the ribbon geometry.

**Archived explorations:** B · Open Gateway and C · Linked J are unselected prior options. The earlier muted teal, gold, and warm-paper palette is superseded by Cobalt + Coral and will not guide new applications.

Avoid the WordPress W mark, official-looking seals, national emblems, and visual treatments resembling the Indonesian Jalin payments brand.

### Provisional Usage Rules

Define `x` as the main ribbon thickness; start with `2x` clear space. Initial minimums: 120 px horizontal lockup width and 24 px standard symbol size. These are provisional until vector refinement and testing.

Production work will provide full-color, one-color Midnight, and reversed White variants. Preserve proportions, joins, and negative space. Avoid stretching, rotation, outlines, shadows, busy backgrounds, and arbitrary wordmark retyping.

## Favicon And Asset Handoff

Use a **White woven J on a solid Cobalt tile** for the favicon. Preserve the approved negative-space channels so the weave remains legible. Any optical simplification will require review against the selected mark. The 32 and 48 px exports retain more weave detail; the 16 px export needs optical refinement before final production sign-off. Exclude “WP” letters and fine ornament.

The separate raster starter pack and future vector deliverables have different scopes:

| Asset | Included Raster Starter Assets | Future Vector Or Production Work |
| --- | --- | --- |
| Horizontal color lockup | White-background PNG | Editable SVG master, transparent PNG, and vector PDF export |
| Icon master | 1024 × 1024 px PNG | Refined vector symbol and one-color variants |
| Browser favicon | ICO containing 16, 32, and 48 px; PNG fallbacks at each size | Responsive SVG favicon and final small-size review |
| Apple touch icon | 180 × 180 px PNG | Review padding against final vector geometry |
| Application icons | 192 × 192 and 512 × 512 px PNG | Separate maskable artwork if required |
| Repository or social avatar | Square 512 × 512 px PNG | Review cropping for the intended platform |

The raster starter assets support initial use and review. PNG dimensions and the ICO’s embedded 16, 32, and 48 px frames were verified. Pixel enlargements were inspected: the J silhouette remains recognizable at 16 px, but the weave channels lose detail; 32 and 48 px preserve more separation. Actual browser integration, light and dark browser chrome, and platform cropping have not been tested. Editable vector and transparent logo masters remain pending. The horizontal logo has an opaque white background.

## Product Application

### Connection And Access

Show connection state with a label, icon, and a dedicated semantic color. Use explicit **Enable For My Account** and **Disable MCP** buttons, separate from access selection, so connection and permissions are independently understandable. Keep **Reconnect** beside the affected client connection. Use Cobalt for the primary action; use Coral for brand emphasis where it cannot be mistaken for a status message.

| Product Concept | Proposed Presentation |
| --- | --- |
| Read mode | **Read Only** — Explain that access is for reading; show the actual permitted scope. |
| Reviewed mode | **Reviewed** — Explain which changes require approval and when that review occurs. |
| Direct mode | **Direct (YOLO)** — State that permitted changes can run without individual review. |
| Permissions | Group by understandable resource or action; distinguish available from granted access. |
| OAuth | Identify WordPress sign-in and make the connected site visible. |

Verify these descriptions against implementation. Give Direct mode clear consequences and a deliberate selection control.

### Content And Commerce

Distinguish Gutenberg designs from independent page layouts. Where review is supported, show the destination and proposed operation before consequential changes.

For WooCommerce orders and sales, show currency, date range, and relevant metric definitions. Distinguish financial movement from operational success/error. Pair trends with signed values or arrows and text. Respect the site's currency and locale.

In Finance Mapping, lead with readable categories such as **Gateway Fees**, **Affiliate Commission**, and **Additional Fees**. Keep raw metadata keys in advanced details. Use short explanations and labeled examples; show missing or unmapped values distinctly from a recorded zero.

## Accessibility And Publishing

Target WCAG 2.2 AA: at least 4.5:1 contrast for ordinary text and 3:1 for qualifying large text. See [W3C text contrast guidance](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html). Required non-text boundaries and state indicators need 3:1 against adjacent colors. See [W3C non-text contrast guidance](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html). Provide visible keyboard focus, accessible names, semantic headings, and keyboard-operable controls. Respect reduced-motion preferences and ensure layouts work with text enlargement and narrow viewports. These are implementation requirements; this draft does not certify compliance.

On GitHub, lead with the logo, descriptor, setup, and practical examples; use selective badges and supported compatibility claims. Prioritize navigation and copyable code in docs. Use shared tokens and real product behavior or labeled concepts on the website. Do not suggest official WordPress or WooCommerce affiliation.

## Source And Review Notes

The selected raster source board is `exec-eb0925fc-07e3-4741-94a0-7196ff906368.png`. It records the approved A · Jalin Weave logo with palette option 2 · Cobalt + Coral. The accompanying raster starter assets follow this approved direction; an editable vector master and final browser-size review remain pending. Numerical hex values in this document are authoritative; raster rendering may vary. Linked font and accessibility sources support the corresponding specifications.

Use the selected board as the visual reference for the woven symbol, exact **JalinWP** spelling, color lockup, and White-on-Cobalt favicon direction. The requested energy is bold and refreshing, with excitement expressed through saturated accents and clear composition. Earlier boards remain archived exploration history. Approval of the name, logo selection, and palette does not approve Manrope, the tagline, supporting colors, or every application shown in a concept preview.

## Approval And Handoff

| Element | Status |
| --- | --- |
| Brand name: JalinWP | Approved |
| Logo: A · Jalin Weave | Approved selection; vector refinement pending |
| Palette: option 2 · Cobalt + Coral, with Midnight and White | Approved core colors |
| Brand and web typeface: Manrope | Proposed; native WordPress admin font will be preserved |
| Tagline: Your Site. Connected. | Proposed |
| Light Tint and Secondary Text colors | Proposed |
| Application details, measurements, and semantic status tokens | For review and implementation verification |

Next work will refine the selected logo into vectors, review the proposed application details, test small-size recognition, and prepare production exports. Approval already recorded for the name, logo selection, and core palette remains in effect.

Planned filenames include `jalinwp-logo-horizontal-color.svg`, `jalinwp-symbol-midnight.svg`, `jalinwp-symbol-reversed.svg`, and `jalinwp-favicon-32.png`. Approved assets, tokens, and guidelines will be versioned together. Explorations and application proposals will retain a `draft` label until reviewed.
