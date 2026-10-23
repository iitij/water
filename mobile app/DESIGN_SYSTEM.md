# Design system — Water platform

The Flutter client uses a single, token-driven design system so every screen —
including the pre-existing feature modules — shares the same visual language.

## Principles

1. **Calm surfaces, confident accents.** A soft blue-tinted background, white
   surfaces, hairline borders and diffuse shadows; saturation is reserved for
   brand gradients and state.
2. **Arabic first.** IBM Plex Sans Arabic ships with the app; numbers and identifiers in
   Latin digits are wrapped in `Directionality(textDirection: ltr)` so they read
   correctly inside an RTL layout.
3. **Thumb-first.** Primary actions are reachable at the bottom, tap targets are
   at least 44 px, and the tab bar floats above the content with a 118 px safe
   scroll inset.
4. **Motion with intent.** Short, single-property transitions (150–460 ms) on
   page entrance, selection and theme changes; nothing loops except the splash.
5. **One source of truth.** Colors, spacing, radii and motion live in
   `lib/core/theme` and `lib/core/ui`; screens do not hard-code hex values.

## Palette

| Token | Light | Purpose |
| --- | --- | --- |
| `primary` | `#0E7C86` | Brand teal, primary actions |
| `primaryDeep` | `#0A4B5A` | Text on light hero buttons |
| `primaryDark` | `#062733` | Ocean ink, dark panels |
| `secondary` | `#22D3EE` | Aqua accent / hero glow |
| `mint` | `#10B981` | Success, collection |
| `brandAmber` | `#F59E0B` | Warnings, cash |
| `violet` | `#7C6CF6` | CRM + insight accents |
| `danger` | `#E11D48` | Overdue, destructive actions |

Surfaces: `lightBackground #F2F7FA`, `lightSurface #FFFFFF`,
`lightSurfaceAlt #EAF2F7`, `lightBorder #DFE9F0`; dark equivalents
`#05131B`, `#0B2029`, `#122E3A`, `#1C3B48`.

Gradients: `AppColors.oceanGradient` (hero), `aquaGradient` (primary buttons),
plus mint/amber/rose/violet/sky variants for module accents.

## Typography

IBM Plex Sans Arabic — 400 / 500 / 600 / 700, bundled in `assets/fonts`.
Heading styles ask for `w800`, which Flutter resolves to the closest bundled
weight (700) so the scale stays readable at small sizes. Scale in
`AppTypography.textTheme`:
`displayLarge 40`, `headlineMedium 24`, `titleLarge 19`, `titleMedium 16`,
`bodyMedium 14`, `labelSmall 11.5`. Line heights are generous (1.35–1.62) for
Arabic diacritics and descenders.

## Geometry & motion

- Spacing scale: 4 / 8 / 12 / 16 / 20 / 24 / 32 / 44 (`AppSpacing`).
- Radii: 10 / 12 / 16 / 20 / 26 / 32 / pill (`AppRadius`).
- Shadows: ambient card shadow, brand glow, floating shadow (`AppShadows`).
- Motion: 150 ms fast, 260 ms normal, 460 ms slow (`AppMotion`).

## Component library (`lib/core/ui`)

| File | Components |
| --- | --- |
| `app_surfaces.dart` | `AppCard`, `AppHeroPanel`, `SectionHeading`, `AppPageBody`, `Gap` |
| `app_controls.dart` | `AppPrimaryButton`, `AppSecondaryButton`, `AppIconButton`, `AppAvatar`, `AppPill`, `AppSegmentedControl` |
| `app_tiles.dart` | `AppIconBubble`, `AppModuleTile`, `AppModuleGrid`, `AppModuleRail`, `AppQuickAction`, `AppMetricCard`, `AppMetricGrid`, `AppSettingRow`, `AppNotice`, `AppStatStrip` |
| `app_viz.dart` | `AppSparkline`, `AppProgressRing`, `AppMiniBars`, `AppTrendChip` |
| `app_motion.dart` | `AppReveal`, `PressableScale`, `AquaBackdrop` |
| `app_tokens.dart` | `AppSpacing`, `AppRadius`, `AppMotion`, `AppShadows`, `AppTint` |
| `app_brand.dart` | `AppBrandMark`, `AppBrandLockup` |

Navigation-level widgets live in `lib/core/navigation`: `AppHomeShell` (custom
bottom bar + side rail), `SubPageScaffold` (pushed pages), `HeroCopy`,
`HeroActions`, `HeroButton`, `HeroGhostButton`, `HeroMetaChip`.

## Brand assets

One icon kit is the single source of truth: the SVG masters in
`assets/icons/` (see `assets/icons/README.md`) are compiled into every launcher
icon, adaptive layer, themed monochrome silhouette, Windows `.ico` and Linux
hicolor set by `tool/generate_icons.mjs`. The Flutter app draws the same mark
through `AppBrandMark`, which loads `assets/branding/app_mark.png` — a squircle
cut from the kit's `app_icon_compact_1024.png`.

```
npm --prefix tool install && node tool/generate_icons.mjs   # platform icons
python3 scripts/generate_brand_assets.py                    # in-app mark + Android splash
```

| Output | Path |
| --- | --- |
| In-app mark (`AppBrandMark`, `AppBrandLockup`) | `assets/branding/app_mark.png` (declared in `pubspec.yaml`) |
| Android legacy + round + adaptive + themed icons | `android/app/src/main/res/mipmap-*` |
| Android launch screen | `drawable*/launch_background.xml`, `values*/colors.xml` (`#F2F7FA` / `#05131B`) |
| Windows window icon | `windows/runner/resources/app_icon.ico` |
| Linux hicolor + desktop entry | `linux/` |

Rules: never paste a raw `Icons.water_drop_rounded` as the logo — use
`AppBrandMark(size:)`; inside a coloured surface pass `glow: false`; the mark is
generated from the icon kit, so a new logo means editing the SVGs, never the
PNGs.

## Navigation map

```
/splash ─▶ /login ─▶ shell
                      ├─ /home       Overview: greeting, quick actions, KPIs, module rails
                      ├─ /field      Field hub: field reader, meters, readings
                      ├─ /billing    Billing hub: collector, invoices, payments, tariffs
                      ├─ /crm        Subscribers hub: 360° profiles, squares, fees, requests
                      └─ /more       Profile, reports, admin, appearance, logout

Pushed from anywhere:
/dashboard  /reports  /exports  /settings  /users  /roles
/invoices  /invoices/create  /payments  /payments/create  /collector
/subscribers  /subscribers/new  /squares  /subscription-fees
/service-requests  /transactions  /meters  /readings  /field-reader
/sync  /profile
```

The five tabs are `StatefulShellRoute` branches, so each tab keeps its own
scroll position and stack. Everything else is pushed full-screen.

### Adding a module

Add an `AppModule` entry to `ModuleCatalog.modules` with a title, subtitle,
icon, accent, route and optional `requiredPermissions` (any-of). The module then
appears automatically in the correct hub, in the overview rails and in the
permission-filtered admin section.

## Theme modes

`AppThemeController` supports `system`, `light` and `dark`, persists the choice
in `SharedPreferences` (`ui.theme_mode`) and is restored during `bootstrap()`.
The header and side rail expose a one-tap toggle; `/more` exposes the three-way
segmented control.

## Responsive rules

| Width | Layout |
| --- | --- |
| `< 600` | Single column, floating 5-item bottom bar, 2-column KPI grid |
| `600–1019` | Single column with wider gutters, bottom bar retained |
| `≥ 1020` | Persistent 252 px side rail, multi-column dashboard, 3–4 column grids |

Page content is capped at 1240 px and centred on desktop; the dashboard accepts
up to 1500 px for dense charts.

## Accessibility

- Every icon-only control has a tooltip / semantic label.
- Status is never communicated by color alone — pills pair a dot or icon with text.
- Touch targets are ≥44 px; primary buttons are 50–52 px tall.
- Text remains readable in both themes (contrast checked against the surface,
  not the gradient, for body copy).
