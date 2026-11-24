# Historical tariff policy (ACC-08)

- Every measured interval is [period_start, period_end). Tariff start dates and end dates are inclusive. A change at the next reading date applies to the next interval.
- An approved tariff must cover the entire measured interval. Missing, partial or overlapping coverage blocks generation; no assumed 600 price and no estimated daily consumption distribution.
- Separate measured periods can have different prices in one invoice. Each generated invoice retains the source reading, tariff identity, name, dates, units, unit price and rounded amount.
- No historical invoices or readings are rewritten. Previously deactivated legacy tariffs are not presumed approved; review the schedule before deployment.
- Legacy test fixtures explicitly create a synthetic tariff; they do not seed production.
- Model-level catalog guard rejects reversed dates, overlapping approved intervals (including inclusive boundaries), conflicting restorations and stale updates. Draft tariffs may overlap until approval. Historical pricing evidence prevents repricing, deactivation, deletion and changes to start dates; closing an interval must preserve every recorded period.
- Catalog operations and historical invoice quotes acquire the same singleton transaction lock, even when no tariffs exist. API bulk operations take the catalog lock before row locks. This intentionally serializes quotes and changes for the current single tariff schedule; raw SQL imports bypass model validation and must be audited before release.
- Added SQLite-compatible catalog acceptance tests and independent MySQL worker races for concurrent approval and invoice-versus-repricing. Test execution remains unverified.
- Pending: audit Filament bulk transaction behavior, cover manual issuance paths, present pricing lines in both clients, and remove the current-price fallback from consumers. The legacy historical-correction mechanism requires separate review. Verify all CI jobs and audit pre-existing tariff intervals. Do not deploy this draft.
