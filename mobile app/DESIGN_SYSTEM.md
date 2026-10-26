# Design system — Water platform (v2)

The October 26 refresh gives the water workspace a marine blue identity in
both light and dark modes. Shared components live in `lib/core/theme` and
`lib/core/ui`. See `../docs/v2/UI_REDESIGN_20261026_AR.md` for validation.

## The five rules

1. **One brand family.** Blue marks actions and navigation; green, amber and
   red convey operational status beside a readable label.
2. **Distinct surfaces.** Navy or pale blue backgrounds separate work areas
   from cards. A restrained marine gradient may identify the main hero.
3. **Readable metrics.** Put the label above a dominant number. Isolate signed
   numbers in LTR and Arabic currency in its own direction context.
4. **Responsive tasks.** Keep key actions at least 48dp. Let controls and lists
   scroll together on short viewports and enlarged text.
5. **Visible navigation.** The six subscriber sections remain visible in a
   responsive grid; meter balances expand without crowding the summary.

## Palette

| Token | Light | Use |
| --- | --- | --- |
| `accent` | `#1265C8` | Primary actions, active navigation, key figures |
| `accentStrong` | `#084991` | Pressed accent, text on `accentSoft` |
| `accentSoft` | `#E8F2FF` | Selected rows, avatar fallback, active tab pill |
| `accentOnPanel` | `#7CD9FF` | Accent *inside* the dark panel (buttons, progress) |
| `ink` / `inkSoft` / `muted` | `#142B46` / `#3E5570` / `#566A80` | Text hierarchy |
| `paper` | `#F1F5FA` | Page background |
| `surface` | `#FFFFFF` | Cards, sheets, tab bar |
| `surfaceAlt` | `#EAF0F7` | Segmented control track, quiet chips |
| `border` / `borderStrong` | `#DBE4EF` / `#BACADB` | Hairlines, inputs |
| `panel` | `#102C4D` | The single dark summary surface, splash/login backdrop |
| `success` | `#157347` | Paid, online, completed |
| `warning` | `#9A5B00` | Pending, offline, needs attention |
| `danger` | `#B02A37` | Overdue, destructive |
| `info` | `#24639A` | Neutral information |

Dark theme mirrors the same roles: `darkPaper #0B1423`, `darkSurface #142238`,
`darkSurfaceAlt #1B2D47`, `darkBorder #2A405D`, `darkInk #F2F7FF`,
`darkAccent #77CFFF`, `darkPanel #123658`.

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

## Card pattern v3.1 — «البطاقة الهادئة» (quiet card)

The list cards (invoice, payment, meter, subscriber, reading) share one anatomy.
No more bordered chip soup: metadata lives on **one borderless quiet line**
(icon + 11 px muted text, `spacing: 12`), separated from the header by 10 px.

1. **Header** — entity name first (`14.5 w600`), identity line second
   (number/code + type icon, muted `11.5`). Status as badge or dot at the end
   edge; row actions collapse into one `PopupMenuButton` (⋯) — bare edit/delete
   `IconButton`s are gone.
2. **Quiet meta line** — `Wrap(spacing: 12, runSpacing: 4)` of icon+text items,
   no borders, no backgrounds. Semantic colour only when it means something
   (overdue, negative balance).
3. **Money panel** — a soft `surfaceContainerHighest @ 45%` rounded panel: the
   hero figure on the start edge (`19–20 w600` tabular) + hairline + 2–3
   supporting figures (`12 w600`).
4. **Actions** — one row: primary action as a 36–38 px filled/tonal button
   (flex), secondary actions as 38 px quiet icon buttons with tooltips.

Diagnostics: the floating `API: n` overlay is **off by default**
(`ApiLogManager.showFloatingPill = false`) and is toggled only from the
diagnostics screen — it must never ship on top of user UI.
