# Field operations mobile integration handoff

This package adds the Flutter meter-management, field-reading, reading-history, and offline queue modules under `lib/`. The repository did not contain a Flutter app entry point or a Dart/Flutter SDK in the build environment, so the host application still needs to initialize and mount these modules.

## Initialization

Use the app's authenticated shared `Dio` instance. Its `baseUrl` must include the Laravel `/api` prefix; this module does not create a second client or own authentication/token refresh.

```dart
final field = await FieldOperationsModule.initialize(dio: authenticatedDio);

// Example screen wiring:
MetersListScreen(
  metersRepository: field.metersRepository,
  readingsRepository: field.readingsRepository,
  invoiceActions: field.invoiceActions,
);

// When preparing a field route before connectivity is lost:
await field.offlinePackDownloader.download(squareId: selectedSquareId);

// On app shutdown:
await field.close();
```

`FieldOperationsModule.initialize()` starts `SyncQueueManager`'s connectivity listener and performs an initial connectivity check. `syncQueueRepository` exposes manual sync, failed-action retry, history, and pending counts. `AppDatabase.open()` is lazy and keeps its SQLite file in the platform application-support directory.

## Laravel API compatibility checked in this checkout

The current `routes/api.php` exposes these field routes:

- `GET /api/meters/search`
- `GET /api/meters/{id}/period`
- `POST /api/readings`
- `GET /api/readings` (currently hard-capped at the latest 50 records; date/square/invoice filtering over the full history and a complete history offline pack require pagination/filter support from the API)
- `GET/POST /api/squares`
- `GET/POST /api/subscribers`, `GET/PUT /api/subscribers/{id}`
- `POST /api/meters`, `PUT /api/meters/{id}`
- `GET/POST /api/settings`

The meter data source falls back from `GET /meters` to `/meters/search`; meter-period loading falls back from `/meters/{id}/period` to `/readings/calculate-dates`; bulk invoice creation tries `/readings/bulk-invoice` and then `/readings/bulk-issue-invoices`. Existing invoice data also supplies a `pdf_url`, used as a print-view fallback when a PDF API route is unavailable.

The following routes are **not present in the checked-in Laravel routes** and must be supplied by the API/backend integration before those operations can run online:

- Reading edit/delete routes (`PUT/DELETE /api/readings/{id}`)
- Smart bulk delete (`POST /api/readings/bulk-delete`)
- Bulk invoice issue (`POST /api/readings/bulk-invoice` or `/api/readings/bulk-issue-invoices`)
- Offline batch sync (`POST /api/readings/sync-batch`)
- Optional invoice PDF endpoint (`GET /api/invoices/{id}/pdf`)

The WhatsApp action builds a `wa.me` URL locally from the invoice's subscriber phone and invoice summary; it does not depend on a WhatsApp-link API route.

## Offline sync response contract

Online reading creation and queue replay use the same stable UUID as both `client_action_id`/`local_id` and the `Idempotency-Key`, so a request with an ambiguous timeout can be safely replayed. The queue posts `{"readings": [...]}` with a batch `Idempotency-Key` header. Every submitted row contains a stable `client_action_id` and `local_id`, meter/date/period/reading fields, invoice/fee choices, and an optional base64 image plus filename. The backend must deduplicate by the per-row client action ID across both `POST /readings` and `POST /readings/sync-batch`. The preferred response is a `results` (or `readings`) array with one result per `client_action_id`, a success/status indicator, and the authoritative server reading ID, for example:

```json
{
  "results": [
    {
      "client_action_id": "local-uuid",
      "success": true,
      "reading": { "id": 1234, "is_invoiced": false }
    }
  ]
}
```

The client intentionally does not mark a local row synced without an authoritative server reading ID. Missing per-row results, rejected rows, and 4xx responses remain visible in the failed queue for retry/review instead of being silently discarded.
