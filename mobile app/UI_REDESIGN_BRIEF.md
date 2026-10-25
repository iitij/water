# UI Redesign Brief — Wave 2 (every remaining screen)

Wave 1 (worklog Task 11) modernized the main list cards. Wave 2 must bring **every
remaining page, sub-page, dialog, sheet and shared widget** onto the same design
system so the whole app feels modern, calm and consistent.

Read `DESIGN_SYSTEM.md` first. It defines the language:
one teal accent, flat surfaces, hairline borders instead of shadows, weight-based
typography hierarchy, IBM Plex Sans Arabic, RTL everywhere.

## Hard rules (violations = rejected)

1. **No behavior changes.** Do not touch cubits, repositories, route names,
   constructor signatures, validation logic, save/delete/sync flows, or the
   public API of any widget you edit. Visual/UX only.
2. **Keep every Arabic label** (you may reword slightly for clarity, keep meaning).
   The app is RTL — never force `Directionality(rtl)` wrappers around the whole
   page; existing ones stay.
3. **No raw `Scaffold(appBar: AppBar(...))` in sub-pages.** Use `SubPageScaffold`
   (see recipe). Keep any `BlocProvider/BlocConsumer/BlocBuilder` structure intact.
4. **No hard-coded hex colors, no `Colors.*` for brand/semantic colors.** Use
   `Theme.of(context).colorScheme` roles or the design-system components. The only
   acceptable `Colors.white`-style use is text/icons on the ink `AppHeroPanel` —
   prefer the components' own styling instead.
5. **No `BoxShadow`, no gradients** (chart area-fill exception only).
6. **No raw `ListTile` for data rows.** Build rows from `AppCard`/`AppGlyph`/
   `AppField`/`Column`, or use `AppSettingRow` for settings-style rows.
7. Imports stay relative, exactly like the file already does.
8. After editing each file run the balance check (below) — it must pass.

## Recipes

### A. Sub-page shell (every screen except the 5-tab home shell)
```dart
import '../../../../core/navigation/sub_page.dart';
SubPageScaffold(
  title: 'الفواتير',
  subtitle: 'اختيارية — سطر سياق قصير',
  actions: [ /* AppIconButton(s) only, 38px */ ],
  child: ...,
)
```
`SubPageScaffold` already renders the back button + `OfflineStatusBar`.

### B. Scroll body
`AppPageBody(children: [...])` — handles gutter, max width, bottom padding.
For pull-to-refresh pass `onRefresh:`. For sliver-based lists keep CustomScrollView
but keep paddings consistent (gutter 16, top 14, bottom ≥110).

### C. List screen anatomy (top→bottom)
1. `AppFilterBar` (search + filter button) and/or `AppFilterChips` for the quick filters.
2. Summary strip `AppStatStrip` or one `AppHeroPanel` for THE headline figure.
3. Cards list (wave-1 cards already exist: `InvoiceCard`, `PaymentCard`,
   `MeterCardWidget`, `_SubscriberCard`, `_ReadingCard` — reuse, don't rebuild).
4. Empty → `AdminEmptyView` (admin_shared widgets); loading → `AppLoader`
   (core/navigation/sub_page.dart) or `ShimmerSkeleton`.
5. Errors → `AppNotice(kind: AppNoticeKind.danger, ...)` + retry text button.

### D. Form screen (إضافة عميل، إضافة عداد، فاتورة يدوية، سند قبض، التعرفة…)
- `SubPageScaffold(title: ...)` + `AppPageBody`.
- Group fields into 2–4 `AppCard` blocks, each introduced by `SectionHeading(icon: ..., title: ...)`.
- Keep `TextFormField`s (theme already styles them) — add `prefixIcon: Icon(Icons.xxx_rounded)`
  and consistent `labelText`. Phone/amount inputs stay LTR-wrapped where they already are.
- Date/picker rows → `AppSettingRow(icon:, title:, value:, onTap:)` or a tappable `AppCard` row.
- Required-field hints stay as validators.
- Actions: bottom area, full-width `AppPrimaryButton(label, onPressed, loading:)`
  + secondary `AppSecondaryButton`/`TextButton` for «حفظ وإضافة آخر» style chains.
  Disable while saving; keep existing saving-state text.
- Helper captions under buttons: `bodySmall` muted.

### E. Detail screen (فاتورة، مشترك 360، كشف حساب)
- Hero: `HeroCopy` or `AppHeroPanel` with the single headline figure (amount/balance).
- Then `SectionHeading` + `AppCard` per section; label/value rows via `AppField(icon: ...)`.
- Action row: `AppPrimaryButton` primary action + 38px quiet `AppIconButton`s for
  PDF/thermal/WhatsApp/edit — never bare red icon buttons; destructive goes in a
  `PopupMenuButton`/`ConfirmDialog`.

### F. Dialogs & bottom sheets
- Sheets: `RoundedRectangleBorder(vertical: Radius.circular(22))`, drag handle,
  title `titleLarge w600`, one-line muted subtitle, scrollable body,
  full-width `AppPrimaryButton` pinned at the bottom (SafeArea).
- Confirmations: reuse `ConfirmDialog` (core/widgets) — don't invent new ones.
- Success moments (سند ناجح): big `AppGlyph` in success tone + amount hero + quiet meta + two buttons.

### G. Motion & feedback
- Wrap top-level page sections in a single `AppReveal` (fade+rise) — no staggered delays.
- Pressable cards: `AppCard(onTap:)` already includes ink splash.
- SnackBars stay as-is (finance_feedback handles them).

## Component quick reference (exact signatures)

All in `lib/core/ui/` unless noted. Import paths:
- `../../..../core/ui/app_surfaces.dart` → `AppCard`, `AppGlyph`, `AppPhotoAvatar`, `AppField`, `AppHeroPanel`, `SectionHeading`, `AppPageBody`, `AppDivider`, `Gap`
- `app_controls.dart` → `AppPrimaryButton(label, onPressed, icon?, loading?)`, `AppSecondaryButton`, `AppIconButton(icon, onPressed, tooltip?, size?)`, `AppAvatar`, `AppPill(label, icon?, tone?)`, `AppSegmentedControl`
- `app_filters.dart` → `AppFilterBar`, `AppFilterChips`, `AppFilterChip`, `AppActiveFilters`, `AppFilterSheet`, `AppFilterDateRange`, `AppFilterDropdown`, `AppSegmentedFilter`
- `app_tiles.dart` → `AppIconBubble`, `AppMetricCard`, `AppSettingRow(icon, title, value?, onTap?, trailing?)`, `AppNotice(kind, title, message?, actions?)`, `AppStatStrip`, `AppMetricGrid`, `AppModuleTile/Grid/Rail`, `AppQuickAction`
- `app_viz.dart` → `AppSparkline`, `AppProgressRing`, `AppMiniBars`, `AppTrendChip`
- `app_motion.dart` → `AppReveal(child)`, `PressableScale`
- `../../core/navigation/sub_page.dart` → `SubPageScaffold(title, child, subtitle?, actions?)`, `HeroCopy`, `HeroActions`, `HeroButton`, `HeroGhostButton`, `HeroMetaChip`, `HeroEyebrow`, `SoftDivider`, `AppLoader`
- `admin_shared/presentation/widgets/admin_widgets.dart` → `AdminEmptyView`

When unsure how a component is used, grep an already-modern screen, e.g.
`lib/features/payments/presentation/pages/payments_list_screen.dart`,
`lib/features/tariffs/presentation/pages/tariffs_screen.dart`,
`lib/features/home/presentation/pages/home_screen.dart` (if exists — else dashboard),
`lib/features/navigation/presentation/pages/billing_hub_screen.dart`.

## Verification (mandatory, per file)

```bash
cd /home/z/mobile-app-extracted
node tools/check_balance.js <file1.dart> <file2.dart> ...
```
All files must pass (balanced braces/parens/brackets, strings & comments aware).
No Flutter SDK exists in this sandbox — `flutter analyze` happens on the user's
machine, so compile-safe discipline matters: every symbol you use must already be
imported (check existing imports; add relative imports when you add components).

## Worklog

Append to `/home/z/my-project/worklog.md` (never overwrite): section starting with
`---`, then `Task ID: <yours>`, `Agent:`, `Task:`, `Work Log:` bullets, `Stage Summary:`.
