# Data-test race scripts

Confirmed concurrency defects from the 2026-10-06 data test (`.flow/DATATEST-2026-10-06.md`),
kept as scripts that are **red today** (exit 1). PHPUnit runs one request at a time in one
process, so it cannot race. These scripts use two PHP servers on the same disposable database
instead. The parts of the same clusters that reproduce one request at a time are PHPUnit tests
in `tests/Datatest/K*Test.php`.

Never point these at the live database. `lib.mjs` refuses any `RACE_DB` that is not a `cims_dt*` copy.

## What you need

- Two `php artisan serve` servers on the **same** data-test copy, for example `cims_dt4` on
  ports 8014 and 8024 (`APP_ENV=dt4`, see `.env.dt4`).
- The copy is seeded from the prepared hardware snapshot, so the product ids used below exist
  (54, 58, 65, 69, 77, 83, 87, 95, 112 with lot 34) and the tokens in
  `tests/fixtures/datatest-accounts.json` (`hardware_accounts`) are valid.
- `curl` on the PATH and the MySQL client at `C:/wamp64/bin/mysql/mysql8.4.7/bin/mysql.exe`
  (override with `RACE_MYSQL`, or the curl binary with `RACE_CURL`).

## Running

```bash
cd tests/datatest-races
export MSYS_NO_PATHCONV=1                                   # Git Bash
RACE_A=http://127.0.0.1:8014 RACE_B=http://127.0.0.1:8024 RACE_DB=cims_dt4 node K2-adjustment-adjustment.mjs
node K78-void-delete-deadlock.mjs 300                       # rounds as the first argument (or RACE_ROUNDS)
```

The defaults are `RACE_A=http://127.0.0.1:8014`, `RACE_B=http://127.0.0.1:8024`, `RACE_DB=cims_dt4`.
Each script:

- sets up its own rows through the API or SQL;
- starts one curl per server and releases both bodies together, so each request reaches its
  server at the same moment;
- reads the rows back after every round and prints one line per round;
- ends with `K<n>: x/N rounds violated <rule>`.

The exit code is 1 when any round broke the rule, 0 when none did, and 2 when setup failed.
Scripts leave their rows behind, so reseed the copy afterwards. In the data-test scratchpad
that is `bash prep.sh reseed dt4`.

| Script | Cluster | Race | Rule checked | Default rounds |
|---|---|---|---|---|
| `K2-adjustment-adjustment.mjs` | K2 | adjustment ∥ adjustment (sub 1 each, shelf 5) | shelf = 5 − accepted = 5 + Σ movements | 20 |
| `K2-transfer-transfer.mjs` | K2 | transfer ∥ transfer (2 each, shelf 3) | not both accepted; shelf = start + Σ movements | 20 |
| `K11-payment-payment.mjs` | K11 | full payment ∥ full payment | Σ payments ≤ GrandTotal | 20 |
| `K11-credit-limit.mjs` | K11 | on-account sale ∥ sale, limit 300 | balance due ≤ credit limit | 20 |
| `K12-batch-allocate.mjs` | K12 | POS ∥ POS from one lot | lot = shelf = 10 − sold | 20 |
| `K19-offline-key-two-servers.mjs` | K19 | same offline key synced to both servers | one key, at most one sale, and no 500 | 20 |
| `K25-grn-undo-twice.mjs` | K25 | goods receipt delete ∥ delete | undone once (shelf back to 10) | 20 |
| `K25-count-complete-cancel.mjs` | K25 | count complete ∥ cancel | not both succeed; a cancelled count is never applied | 20 |
| `K44-average-cost.mjs` | K44 | received purchase ∥ received purchase | moving average 233.3333 | 20 |
| `K78-void-delete-deadlock.mjs` | K78 | void ∥ delete of one sale | no raw 5xx (deadlock) | 150 (rare: about 4 in 100) |
| `K79-product-edit-lost-update.mjs` | K79 | product edit (price) ∥ edit (cost) from stale forms | both changes survive, or the stale save is refused | 30 |

Notes:
- **K79** releases the second save a random 0–600 ms after the first. When both requests
  read the row before either writes, Eloquent's dirty-column save happens to keep both
  changes, so a race at exactly t=0 hides the defect. The script also sets product 95's
  `image` to `no-image.png` first, because `ProductsController@update` deletes image files
  from `public/images/products`, which every database shares (K94).
- **K78** is rare: 6/150 rounds on 2026-10-06 (and 6/140 in the falsification run). A green
  run of K78 alone does not prove the fix; re-run it with more rounds.
