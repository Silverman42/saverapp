# SaverApp UI Standard

SaverApp uses the **Airy Operations Dashboard** visual system for authenticated application screens and account-access flows. The retained template is the visual source of truth for hierarchy, density, spacing, surface treatment, and interaction styling; product copy and data must continue to follow SaverApp's domain specifications.

## Design principles

1. **Calm operational clarity.** Prefer open layouts, generous whitespace, short labels, and clear status language over decorative density.
2. **Truthful financial states.** Never render missing, stale, partial, or failed financial data as `0`. Use an explicit unavailable, loading, stale, or error state with an as-of label where data exists.
3. **One primary accent.** Cyan identifies the primary action, active navigation, focus, and selected state. It should not cover large surfaces.
4. **Quiet hierarchy.** White cards, cool-gray borders, dark slate text, and muted blue-gray supporting copy form the default hierarchy.
5. **Accessible by default.** Preserve visible focus, keyboard navigation, semantic labels, text-plus-icon status cues, and usable touch targets.

## Foundation tokens

The canonical implementation lives in `resources/css/app.css` and is exposed through semantic Tailwind utilities.

| Role             | Light     | Dark      | Usage                                 |
| ---------------- | --------- | --------- | ------------------------------------- |
| Canvas           | `#F8FBFD` | `#101820` | Page background                       |
| Surface          | `#FFFFFF` | `#16212B` | Cards, header, auth panel             |
| Foreground       | `#24303D` | `#EEF5F8` | Primary text                          |
| Muted foreground | `#7A8796` | `#93A4B2` | Supporting text and metadata          |
| Primary          | `#08ACEB` | `#22BDF4` | Primary actions and active states     |
| Accent           | `#E5F6FD` | `#12394A` | Selected navigation and soft emphasis |
| Border           | `#DFE8EE` | `#293946` | Dividers and surface outlines         |
| Destructive      | `#DC5A66` | `#EF6674` | Destructive actions and errors        |

- Font: Figtree, with the declared system sans-serif fallback stack.
- Base radius: `0.875rem`; primary panels generally use `rounded-2xl` or `rounded-3xl`.
- Borders: one-pixel cool-gray outlines. Avoid heavy shadows.
- Elevation: low-contrast, wide shadows reserved for menus, dialogs, and key auth surfaces.
- Icons: Lucide through `@lucide/vue`; default UI icon size is 16–20px with a 1.8–2.2px stroke.

## Application shell

### Sidebar

- Fixed desktop width: 17.5rem; collapsed width: 4rem.
- White/dark surface with a right border; do not float the primary app sidebar.
- Brand sits in an 80px header row.
- Navigation is grouped with uppercase 10px labels.
- Menu rows are 44px tall, use 12px radii, and show the active item with the pale-cyan accent surface and cyan text.
- Keep navigation scoped to routes that exist and that the current user may access.

### Header

- 80px sticky surface with a bottom border.
- Search occupies the central desktop region; user actions stay on the right.
- Use rounded inputs and restrained icon buttons. Do not add unimplemented global actions without a clearly non-destructive fallback.

### Content

- Page padding: 16px mobile, 24px tablet, 32px desktop.
- Primary page heading: 30px, semibold, tight tracking.
- Default section gap: 24px; default card grid gap: 16px.
- Metric cards use a responsive 1/2/3-column grid and a minimum height near 176px.

## Authentication layout

- Desktop uses a split layout: product context on the left and a focused form card on the right.
- Mobile removes the contextual panel and keeps a compact brand above the form.
- Authentication forms use the same Button, Input, Checkbox, Alert, and Dialog primitives as the application.
- Keep one shared sign-in experience; never ask the user to reveal or choose their account role during login.
- Form labels sit above fields. Inputs are 44px tall with a 12px radius and a visible cyan focus ring.

## Components

- **Button:** 40px default height, 12px radius, semibold label. Cyan is reserved for the primary action. Outline buttons use a card surface and cool-gray border.
- **Input / Select / OTP:** card background, subtle border, 44px default height, 12px radius, cyan focus ring, readable disabled state.
- **Card:** white/dark surface, 16px radius, 1px border, minimal elevation, 20–24px internal padding.
- **Badge:** compact pill with semibold 12px label. Prefer pale semantic backgrounds over solid fills.
- **Alert:** 12px radius, subtle border, icon-plus-text structure. Errors must use destructive text and never rely on color alone.
- **Menu / Popover / Tooltip:** 12px radius, compact but touch-safe rows, clear focus state, soft elevated shadow.
- **Dialog / Sheet:** softened overlay, 16px panel radius, strong title hierarchy, and a visible close control.
- **Skeleton:** pale accent block with 12px radius. It must not resemble a confirmed amount or status.
- **Toast:** bottom-center, rounded 16px surface, and an outcome icon for every completed non-GET request.

## Dashboard data states

Every metric or operational section must support:

- loading: skeleton plus an accessible loading label;
- available: value, unit, scope, period, and as-of metadata where required;
- zero: shown only when the authoritative source successfully confirms zero;
- unavailable: explicit `Not available` language and the missing source or reason;
- stale: last verified value with a visible as-of time;
- error: contained section failure with a safe retry where possible.

Do not derive financial totals from visible rows, mix incompatible cutoffs, or invent example balances in production UI.

## Responsive and dark mode

- Cards stack to one column on small screens and must not create horizontal page scrolling.
- Persistent actions stay reachable at 320px-wide layouts.
- Tables should become accessible compact rows or controlled horizontal regions.
- Every new component requires light and dark token coverage. Avoid hard-coded neutral colors when a semantic token exists.
- Respect reduced-motion preferences; animation is enhancement, never the only state cue.

## Implementation rules

- Reuse components from `resources/js/components/ui` for primary controls and surfaces.
- Use Wayfinder route functions with Inertia `Link` or `Form`; do not hard-code application URLs.
- Use Lucide icons, not handcrafted SVG or text glyph substitutes.
- Extend semantic tokens before introducing isolated color values.
- Preserve existing authorization, form submission, validation, and toast behavior during visual changes.
