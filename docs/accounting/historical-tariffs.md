# Historical tariff policy (ACC-08)

- Every measured interval is [period_start, period_end). Tariff start dates and end dates are inclusive. A change at the next reading date applies to the next interval.
- An approved tariff must cover the entire measured interval. Missing, partial or overlapping coverage blocks generation; no assumed 600 price and no estimated daily consumption distribution.
- Separate measured periods can have different prices in one invoice. Each generated invoice retains the source reading, tariff identity, name, dates, units, unit price and rounded amount.
- No historical invoices or readings are rewritten. Previously deactivated legacy tariffs are not presumed approved; review the schedule before deployment.
- Legacy test fixtures explicitly create a synthetic tariff; they do not seed production.
- Pending: enforce catalog overlap and mutation rules across API/Filament, serialize concurrent tariff changes, cover manual issuance paths, and present pricing lines in both clients. The existing current-price display and legacy historical-correction mechanism require separate review. Do not deploy this draft.
