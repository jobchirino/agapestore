---
name: Haute Atelier Precision
colors:
  surface: '#f9f9fb'
  surface-dim: '#d9dadc'
  surface-bright: '#f9f9fb'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f3f3f5'
  surface-container: '#eeeef0'
  surface-container-high: '#e8e8ea'
  surface-container-highest: '#e2e2e4'
  on-surface: '#1a1c1d'
  on-surface-variant: '#594046'
  inverse-surface: '#2f3132'
  inverse-on-surface: '#f0f0f2'
  outline: '#8d7075'
  outline-variant: '#e0bec4'
  surface-tint: '#b81059'
  primary: '#970046'
  on-primary: '#ffffff'
  primary-container: '#be185d'
  on-primary-container: '#ffd5dd'
  inverse-primary: '#ffb1c3'
  secondary: '#695a64'
  on-secondary: '#ffffff'
  secondary-container: '#efdae6'
  on-secondary-container: '#6e5e68'
  tertiary: '#005825'
  on-tertiary: '#ffffff'
  tertiary-container: '#007333'
  on-tertiary-container: '#93f6a4'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#ffd9e0'
  primary-fixed-dim: '#ffb1c3'
  on-primary-fixed: '#3f0019'
  on-primary-fixed-variant: '#8f0042'
  secondary-fixed: '#f1dde9'
  secondary-fixed-dim: '#d5c1cd'
  on-secondary-fixed: '#231820'
  on-secondary-fixed-variant: '#51434c'
  tertiary-fixed: '#95f8a7'
  tertiary-fixed-dim: '#79db8d'
  on-tertiary-fixed: '#00210a'
  on-tertiary-fixed-variant: '#005323'
  background: '#f9f9fb'
  on-background: '#1a1c1d'
  surface-variant: '#e2e2e4'
typography:
  display-lg:
    fontFamily: Bodoni Moda
    fontSize: 56px
    fontWeight: '400'
    lineHeight: 64px
    letterSpacing: -0.02em
  display-lg-mobile:
    fontFamily: Bodoni Moda
    fontSize: 38px
    fontWeight: '400'
    lineHeight: 44px
    letterSpacing: -0.01em
  headline-lg:
    fontFamily: Bodoni Moda
    fontSize: 32px
    fontWeight: '500'
    lineHeight: 40px
    letterSpacing: -0.01em
  headline-lg-mobile:
    fontFamily: Bodoni Moda
    fontSize: 26px
    fontWeight: '500'
    lineHeight: 34px
    letterSpacing: 0em
  headline-sm:
    fontFamily: Bodoni Moda
    fontSize: 22px
    fontWeight: '500'
    lineHeight: 30px
  title-md:
    fontFamily: Manrope
    fontSize: 16px
    fontWeight: '600'
    lineHeight: 24px
    letterSpacing: -0.01em
  body-lg:
    fontFamily: Manrope
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 26px
  body-md:
    fontFamily: Manrope
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 22px
  body-sm:
    fontFamily: Manrope
    fontSize: 12px
    fontWeight: '400'
    lineHeight: 18px
  label-md:
    fontFamily: Manrope
    fontSize: 13px
    fontWeight: '600'
    lineHeight: 16px
    letterSpacing: 0.02em
  label-sm:
    fontFamily: Manrope
    fontSize: 11px
    fontWeight: '700'
    lineHeight: 14px
    letterSpacing: 0.06em
  tabular-num:
    fontFamily: Manrope
    fontSize: 14px
    fontWeight: '500'
    lineHeight: 20px
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  gutter: 1.25rem
  gutter-mobile: 0.75rem
  margin: 2rem
  margin-mobile: 1rem
  space-xs: 0.25rem
  space-sm: 0.5rem
  space-md: 1rem
  space-lg: 1.5rem
  space-xl: 2.5rem
---

## Brand & Style

This design system synthesizes the architectural precision of macOS Sequoia with the tactile opulence of European haute perfumery and high jewelry maison curation. The visual tone balances industrial software rigor—crisp tabular data, micrometric structural padding, and whisper-quiet vibrancy—with editorial high-fashion romance. 

Targeted at discerning luxury shoppers, boutique concierges, and store directorship, the interface evokes the composed silence of an exclusive boutique showroom. Whitespace is architectural rather than empty; typography oscillates between the dramatic, razor-thin hairlines of Bodoni Moda serifs and the calibrated functionalism of Manrope numerals. The interface communicates effortless prestige, computational restraint, and absolute material clarity.

## Colors

The palette establishes an ultra-clean, gallery-grade foundation that allows fine jewelry luster, fragrance flacon glass, and supple leather textures to command focus. 

- **Primary (`#BE185D`)**: Deep, regal fuchsia-rose, deployed deliberately for decisive transactional commitments, cursor-focus rings, active selection states, and signature branded moments.
- **Secondary (`#FCE7F3`)**: Whispering rose mist. Forms the bed for micro-badges, luxury attribute tags (e.g., "Extrait de Parfum", "18k Rose Gold"), and soft secondary interactive targets.
- **Tertiary (`#15803D`)**: Tailored emerald paired with soft green (`#DCFCE7`), reserved strictly for authenticated statuses, margin gains, and concierge availability.
- **Neutral Canvas (`#F5F5F7`)**: The definitive system foundation tone, mimicking macOS system-window surfaces. Cards and elevated elements utilize pristine `#FFFFFF` to rise above this plane with quiet optical contrast.
- **Functional Warning / Low Stock**: Soft amber/terracotta (`#B45309` text over `#FEF3C7` ground) signals inventory scarcity with refined urgency rather than blunt alarm.

## Typography

The typography system operates on a dual-engine architecture:
1. **The Display Tier (Bodoni Moda)**: High-contrast didone serifs reserved for product titles, atelier provenance, and section intros (headings only). The variable optical sizing ensures hairline strokes never disintegrate on high-density Retina displays.
2. **The Operational Tier (Manrope)**: A clean, geometric neo-grotesque that handles interface commands, navigation, specifications, and data dense lists. **All numerals use Manrope, including large KPI figures** — never the display serif, whose uneven glyph widths make numbers hard to scan.
3. **Tabular Numerics**: All price displays, SKU identifiers, margin calculations, and inventory tallies enforce `font-feature-settings: "tnum" 1, "lnum" 1`. This delivers strict vertical alignment across dense macOS-inspired data tables and order summaries.

## Layout & Spacing

The layout philosophy mirrors the structural logic of a multi-pane macOS Sequoia desktop window:
- **Canvas Structure**: Fixed-width collapsible glass navigation pane (260px) paired with a responsive fluid workspace on desktop. Content max-width locks at `1440px` to maintain optimal viewing angles for luxury photography and data density.
- **Grid Hierarchy**: 12-column responsive grid with `1.25rem` (20px) gutters on desktop, collapsing to 8 columns on tablet and 4 columns on mobile with `0.75rem` (12px) gutters.
- **Vertical Rhythm**: Padded in strict 4px increments. Dense operational metrics use compact increments (`space-xs` to `space-sm`), while catalog showcases and editorial collection reveals breathe with generous spacing (`space-lg` to `space-xl`).

## Elevation & Depth

Depth is established via subtle physical layers rather than overt drop shadows, combining macOS Sequoia translucent window physics with refined luxury framing:

- **Level 0 (Canvas Base)**: Matte `#F5F5F7`. Absorbs light without glare.
- **Level 1 (Translucent Glass Panes)**: Reserved for sidebars, inspector sheets, and persistent utility headers. Employs `rgba(255, 255, 255, 0.72)` paired with `backdrop-filter: blur(24px) saturate(180%)` and a 1px inside border of `rgba(229, 231, 235, 0.6)`.
- **Level 2 (Active Studio Cards)**: Pure opaque `#FFFFFF` floating over the canvas with an ultra-diffused ambient shadow: `box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05), 0 1px 2px 0 rgba(0, 0, 0, 0.02)`. Borders use crisp, hairline definition (`1px solid rgba(229, 231, 235, 0.6)`).
- **Level 3 (Overlays, Drawers & Menus)**: `#FFFFFF` surfaces with `box-shadow: 0 12px 36px -4px rgba(0, 0, 0, 0.08), 0 4px 12px -2px rgba(0, 0, 0, 0.04)`.

## Shapes

The design system maintains a unified roundedness level of `2`. In practice:
- Standard components (buttons, text fields, item rows): `0.5rem` (8px).
- Structural containers (product cards, modal sheets, popovers): `rounded-lg` at `1rem` (16px), adhering strictly to macOS Sequoia window corner geometry.
- Display showcases and hero banners: `rounded-xl` at `1.5rem` (24px).
- Continuous-curve squircle geometry (`corner-smoothing: 60%`) must be applied in CSS wherever supported to emulate native hardware bezels.

## Components

### Buttons
- **Primary**: Solid `#BE185D` background, `#FFFFFF` text, `font-weight: 600`, 8px corner radius. High-precision hover creates a subtle inset glow: `box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.2)`. Active state drops scale to `0.985` with immediate mechanical spring response.
- **Secondary**: Opaque `#FFFFFF`, `1px solid rgba(229, 231, 235, 0.8)`, `#1F2937` Manrope typography. Hover shifts background to `#F9FAFB`.
- **Ghost / Glass**: Translucent fill `rgba(0, 0, 0, 0.03)` with hover transitioning to `rgba(0, 0, 0, 0.06)`.

### Chips & Badges
- **Luxury Attribute Chip**: `#FCE7F3` background with `#BE185D` text, 6px radius, uppercase 11px label font with `letterSpacing: 0.06em`.
- **KPI Pill (Profit / In-Stock)**: `#DCFCE7` background, `#15803D` tabular bold text, padding: `2px 8px`.
- **Low-Stock Alert Pill**: `#FEF3C7` background, `#B45309` tabular bold text.

### Product & Showcase Cards
- Base of `#FFFFFF`, `rounded-lg` (16px corner radius), enveloped in `1px solid rgba(229, 231, 235, 0.6)` and Level 2 ambient shadow.
- Imagery utilizes a neutral 4:5 or 1:1 gallery aspect ratio with faint warm-gray placeholder tints (`#F9F9FB`).
- Hover shifts card elevation through micro-translation (`translateY(-2px)`) and border tint intensification toward primary fuchsia at 20% opacity.

### Input Fields
- Height of 40px for desk operation. Background `#FFFFFF` inside panels or `#FFFFFF/80%` on translucent sheets.
- Border: `1px solid rgba(229, 231, 235, 0.8)`.
- Focused state eliminates generic browser rings; applies a razor-thin 1px `#BE185D` line supported by a soft 3px diffused outer halo of `rgba(190, 24, 93, 0.15)`.

### Selection Controls (Checkboxes & Radios)
- 18px bounding box with 5px radius (checkbox) or full circle (radio).
- Inactive: 1.5px border in `#D1D5DB`.
- Active: Solid `#BE185D` fill with `#FFFFFF` crisp optical checkmark / center dot.

### Feedback & Notifications

- **Success (default)**: Flux toast, `variant="success"`, top-right, auto-dismissing. This is the standard for every successful action (create, update, delete, stock adjustment). Do not invent inline success banners.
- **Errors**: never silent. Show the message next to the offending field with `<flux:error>` and fire a `variant="danger"` toast so the failure is noticed even if the field is out of view.
- Loading feedback is subtle: dim the affected surface (`opacity-60` with a transition) and show a small spinner near the action. Never block the whole screen.
- **Destructive actions** (delete, discard) always require a confirmation modal with a `variant="danger"` button. Never `wire:confirm` or the browser's native dialog.

### Data Tables & Lists
- Table headers set in 11px Manrope uppercase with `letterSpacing: 0.06em` and subtle `#6B7280` coloring.
- Row heights pegged at 48px with 1px border-bottom divider in `rgba(243, 244, 246, 1)`.
- Numeric columns (Price, Stock, Margin) right-aligned with monospace-calibrated Manrope tabular figures.