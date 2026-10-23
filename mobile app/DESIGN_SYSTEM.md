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
| `app_surfaces.dart` | `AppCard` (flat + hairline), `AppHeroPanel` (ink panel), `AppGlyph` (squircle icon plate), `AppPhotoAvatar`, `AppField` (label → value), `SectionHeading`, `AppPageBody`, `AppDivider`, `Gap` |
| `app_filters.dart` | **The filter kit**: `AppFilterBar`, `AppFilterChips`, `AppFilterChip`, `AppActiveFilters`, `AppFilterSheet`, `AppFilterSection`, `AppFilterOptionTile`, `AppFilterDateRange`, `AppFilterDropdown`, `AppSegmentedFilter` |
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

## Filters — one designed system

Filtering is not a pile of controls; it is a fixed sequence of five pieces.
Every list screen uses them in the same order, so the header looks identical on
invoices, readings, payments, subscribers, tariffs and service requests.

| Row | Widget | Rule |
| --- | --- | --- |
| 1 | `AppFilterBar` | One filled search field (46 px, `surfaceAlt`, no outline) with an inline clear, plus a square filter button. The button turns accent-filled and shows a count badge when `activeCount > 0`. |
| 2 | `AppFilterChips` | A single horizontally scrolling rail of the **primary** facet (status, method, trash, invoice state). Never wrap to two rows: the list header must keep one height. 34 px pills, hairline border, accent only when selected. |
| 3 | `AppActiveFilters` | Only when something outside the rail is applied. Removable chips (`x`) plus «مسح الكل». Hidden otherwise — no empty grey bar. |
| 4 | Result line | `n نتيجة` on the start edge, `جارٍ التحديث…` while loading. Selection mode shows its own label on the end edge. |
| 5 | `AppFilterSheet` | Every secondary facet (squares, dates, types, subscribers, trashed) lives here. Sectioned with `AppFilterSection`; a sticky footer holds the live result count, «إعادة تعيين» and the primary «عرض النتائج». Never more than one sheet per screen. |

Sheet rules:

- Opened only through `AppFilterSheet.show(context, builder: …)` — it owns the
  drag handle, the 22 px top radius, the 88 % height cap and the scrim.
- Draft state lives inside the sheet. «إعادة تعيين» clears the draft, not the
  applied query; «تطبيق/عرض النتائج» writes once and closes. Screens that hold
  several facets in one cubit expose a single `applyFilters(...)` so the list
  reloads **once**.
- Long option lists become `AppFilterOptionTile`s inside a 216 px scroll area
  (squares, methods), never a second dropdown.
- Dates always use `AppFilterDateRange` (two compact tiles + a clear action),
  not two `OutlinedButton`s.
- Form dropdowns use `AppFilterDropdown`; their visuals come from the theme, so
  no screen repeats fill colours or radii.

Empty results are part of the filter design: `EmptyStateWidget` explains *why*
(nothing matches the current filters) and offers «مسح الفلاتر». The same widget
is used for first-run emptiness with different copy — never a bare “لا توجد
بيانات”.

Do not: use `ChoiceChip`, `FilterChip`, `ActionChip`, `SegmentedButton`,
`OutlineInputBorder`, or a bespoke `showModalBottomSheet` for filtering. They are
all replaced by the kit above.

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
7. Replace `Card(` with `AppCard(` (keep `padding: EdgeInsets.zero` if the child
   already pads itself) and `CircleAvatar` with `AppGlyph`/`AppPhotoAvatar`.
8. Replace every filter block with the five-component sequence above, and move
   its secondary facets into one `AppFilterSheet`.
9. Replace raw radii (`circular(12/14/16/18/20)`) with `AppRadius.*`, and raw
   `Color(0x…)` values with `AppColors`/`AppSemantic.resolve`.

`gradient` parameters on `AppCard`, `AppHeroPanel`, `AppPrimaryButton` and
`AquaBackdrop` are accepted and ignored, so step 1 can be done gradually.
