<?php
// Retired destructive maintenance script. Never bootstrap or mutate the ledger.
fwrite(STDERR, "Disabled: use php artisan ledger:migrate for a read-only original posting audit.\n");
exit(1);
