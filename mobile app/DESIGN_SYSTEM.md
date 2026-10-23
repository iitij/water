# Design system — Water platform (v2)

Version 2 replaces the "gradient + glow + colour-per-module" treatment with a
single, quiet system: **one accent, two backgrounds, hairlines instead of
shadows, and weight instead of colour for hierarchy**. Everything below lives in
`lib/core/theme` and `lib/core/ui`; screens do not hard-code hex values.

## The five rules

1. **One accent.** Deep teal `#0C6B60` marks primary actions, the selected tab
   and the active rail item. Nothing else is saturated.
2. **No decorative gradients.** Surfaces are flat. The only gradients left in
   the product are the soft area fill under a chart line (≤12% alpha).
3. **Hairlines, not shadows.** Cards are `surface` + 1 px `border`. Elevation is
   reserved for chrome that actually floats: the tab bar, sheets, menus.
4. **One dark surface per page.** A single ink panel carries the page's headline
   number (e.g. outstanding balance). The rest of the page is light.
5. **Weight, not colour, for hierarchy.** Titles are `w600`, body `w400`,
   numbers `w600` with tabular figures. Semantic colour is used on icons and dots
   only, always next to a label.

## Palette

| Token | Light | Use |
| --- | --- | --- |
| `accent` | `#0C6B60` | Primary actions, active navigation, key figures |
| `accentStrong` | `#095149` | Pressed accent, text on `accentSoft` |
| `accentSoft` | `#E4EFEC` | Selected rows, avatar fallback, active tab pill |
| `accentOnPanel` | `#5FC9B9` | Accent *inside* the dark panel (buttons, progress) |
| `ink` / `inkSoft` / `muted` | `#0F1A18` / `#46534F` / `#78857F` | Text hierarchy |
| `paper` | `#F4F6F5` | Page background |
| `surface` | `#FFFFFF` | Cards, sheets, tab bar |
| `surfaceAlt` | `#EDF1EF` | Segmented control track, quiet chips |
| `border` / `borderStrong` | `#E4E9E7` / `#D3DBD8` | Hairlines, inputs |
| `panel` | `#0F1A18` | The single dark summary surface, splash/login backdrop |
| `success` | `#157347` | Paid, online, completed |
| `warning` | `#9A5B00` | Pending, offline, needs attention |
| `danger` | `#B02A37` | Overdue, destructive |
| `info` | `#2F5D7C` | Neutral information |

Dark theme mirrors the same roles: `darkPaper #0A0F0E`, `darkSurface #121A18`,
`darkSurfaceAlt #18221F`, `darkBorder #24302D`, `darkInk #E9EFED`,
`darkAccent #5FC9B9`, `darkPanel #16211E`.

The legacy aliases (`primary`, `primaryDark`, `secondary`, `mint`, `brandAmber`,
`violet`, `coral`, `oceanGradient`, `aquaGradient`, …) still exist so older
screens compile — they now resolve to the tokens above and are ignored by the
shared components.

## Typography

IBM Plex Sans Arabic (`IBMPlexSansArabic`, bundled in `assets/fonts`, OFL).

| Style | Size | Weight | Use |
| --- | --- | --- | --- |
| `displaySmall` | 26 | 600 | Login headline |
| `headlineMedium/Small` | 21 / 19 | 600 | Hero titles, KPI figures |
| `titleLarge` | 17 | 600 | Page and section titles |
| `titleMedium` / `titleSmall` | 15 / 13.5 | 600 | Card titles, rows |
| `bodyMedium` | 13.5 | 400 | Body copy (line-height 1.6) |
| `bodySmall` | 12 | 400 muted | Captions, section subtitles |
| `labelSmall` | 11 | 500 muted | Eyebrows, timestamps, hints |

`AppTypography.numeric()` returns the tabular, tightly-tracked style used for
money. Amounts are always wrapped in `Directionality(ltr)` so the digits stay
left-to-right inside an RTL sentence; the currency (`ر.ي`) is a separate, muted
label to the left of the figure.

## Geometry

- Spacing scale: 4 / 8 / 12 / 16 / 20 / 24 / 32 / 44 (`AppSpacing`).
- Gutter: 14 (<360) · 16 · 24 · 32 px (`AppSpacing.gutter`).
- Radii: 8 / 10 / 12 / 16 / 18 / 22 / pill (`AppRadius`). Controls 12, cards 18,
  the summary panel 22.
- Borders: 1 px everywhere; inputs thicken to 1.4 px on focus.
- Elevation: only `AppShadows.floating` (nav bar, sheets). `AppShadows.card`
  returns an empty list by design.
- Motion: 140 ms fast, 220 ms normal, 380 ms slow; entrance reveals are a single
  fade+rise, progress bars animate once.

## Components (`lib/core/ui`)

| File | Pieces |
| --- | --- |
| `app_surfaces.dart` | `AppCard` (flat + hairline), `AppHeroPanel` (ink panel), `SectionHeading`, `AppPageBody`, `AppDivider`, `Gap` |
| `app_controls.dart` | `AppPrimaryButton` (solid accent), `AppSecondaryButton`, `AppIconButton`, `AppAvatar`, `AppPill` (neutral body, coloured icon), `AppSegmentedControl` |
| `app_tiles.dart` | `AppIconBubble`, `AppModuleTile/Grid/Rail`, `AppQuickAction`, `AppMetricCard` (label → figure → unit → context), `AppSettingRow`, `AppNotice`, `AppStatStrip`, `AppMetricGrid` |
| `app_viz.dart` | `AppSparkline`, `AppProgressRing` (solid stroke), `AppMiniBars` (flat bars), `AppTrendChip` |
| `app_motion.dart` | `AppReveal`, `PressableScale`, `AquaBackdrop` (flat ink) |
| `app_brand.dart` | `AppBrandMark`, `AppBrandLockup` |

Navigation widgets live in `lib/core/navigation`: `AppHomeShell` (greeting header
+ flat tab bar; side rail ≥1020 px), `SubPageScaffold`, `HeroCopy`,
`HeroActions`, `HeroButton` (solid accent on ink), `HeroGhostButton`
(hairline on ink), `HeroMetaChip`.

### Home anatomy (the reference screen)

1. **Header** — greeting + date on one line pair, cloud-sync glyph with a status
   dot, avatar. No theme toggle in the header (it lives in `/more` and the rail).
2. **Summary panel** (ink) — label + connection state, the outstanding amount as
   the largest figure on the screen, unpaid/overdue counts, one 4 px collection
   bar with percent, then two actions: solid accent primary + hairline ghost.
3. **Quick actions** — one card, four icon+label cells separated by hairlines.
4. **Section heading** — title + one-line context + a text action.
5. **KPI grid** — 2 columns; each card: muted label, figure, unit, one line of
   context, optional sparkline.
6. **Module rails / drill-downs**, then sync status and the build footer.

Empty states use `AdminEmptyView`; failures use `AppNotice` with a retry text
button. The tab bar is 62 px tall, floats 10 px above the safe area, and pages
reserve 110 px of scroll padding for it.

## Responsive rules

| Width | Layout |
| --- | --- |
| `< 600` | Single column, floating 5-item tab bar, 2-column KPI grid |
| `600–1019` | Wider gutters, bottom bar retained, module tiles 2–3 per row |
| `≥ 1020` | 244 px side rail, multi-column chart row, 3–4 column grids |

Page content is capped at 1120 px (dashboards may pass a larger `maxWidth`).

## Accessibility

- Contrast: body copy sits on `surface`/`paper`, never on the ink panel's accent;
  `onPanelSoft` (70% white) is the minimum for secondary text on `panel`.
- Status is never colour-only: every dot is paired with a word.
- Touch targets ≥44 px; the tab bar cells are 62×~70 px.
- Numbers are LTR with tabular figures so columns of amounts line up.
- `MediaQuery.textScaler` is respected: headers wrap, KPI cards scale the figure
  down with `FittedBox` instead of clipping.

## Migrating an old screen

1. Replace `AppHeroPanel(gradient: …)` with a plain `AppHeroPanel`.
2. Replace per-module colours with `AppColors.primary` (tint is applied
   automatically at 6–7 %).
3. Swap `AppPrimaryButton(gradient: …)` for the default solid button.
4. Replace `Container(decoration: gradient …)` blocks with `AppCard`.
5. Replace `Icon(Icons.arrow_back_ios_new_rounded)` chevrons with
   `Icons.chevron_left_rounded`.
6. Delete any `Colors.white.withValues(alpha: …)` shadows or glows.

`gradient` parameters on `AppCard`, `AppHeroPanel`, `AppPrimaryButton` and
`AquaBackdrop` are accepted and ignored, so step 1 can be done gradually.
