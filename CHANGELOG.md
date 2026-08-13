# Changelog

All notable changes to this plugin are recorded here. The public changelog on
oxywp.com is generated from `readme.txt`, which follows this file.

## [Unreleased]

### Sprint 4 — purchase orders (13/08/2026)

- Purchase orders with their lines, a list that filters by supplier, state and
  lateness, and a document screen.
- **Tick rows on the reordering screen and get one draft order per supplier.**
  Fifteen articles from four suppliers are four orders, grouped for you. The
  quantities are worked out again at that moment, from the stock as it is now:
  what was on the screen a minute ago was true a minute ago.
- Nothing is sent. Everything the reordering screen produces is a draft, because
  a suggestion that posts itself is a suggestion nobody trusts.
- **The state machine lives in the domain**, not in the screen. The buttons are
  drawn from what it allows, and a move reached any other way — an old page, a
  hand-typed address — is refused before anything is written. A cancelled order
  stays cancelled; a received one can go back to partly received, because
  reversing a receipt has to leave the order saying something true.
- **Numbers are unique because the database says so.** The next number is
  proposed by looking at the highest one used this year; the unique index
  decides. Two people saving in the same instant get two numbers, and the loser
  simply asks again. A counter in an option loses one of them silently.
- Adding articles to an order is done from the supplier's own price list, with a
  quantity box. No search, no autocomplete, no JavaScript.
- **The reordering screen now subtracts what is really on order.** A draft does
  not count — it is a thought, not an order — a sent one does, and a cancelled
  one stops counting.
- 89 unit tests, 57 checks inside WordPress, 76 over HTTP, 26 against the seeded
  shop.

### Sprint 3 — the reordering screen (13/08/2026)

- **What to reorder**, the screen the plugin exists for: stock, what is held for
  orders being paid for, what is on its way, the reorder point, sales over 7, 30
  and 90 days, the supplier to buy from, and how many to order.
- The reorder point is WooCommerce's own low stock threshold, per article, with
  the shop's default behind it. Nothing new to fill in. How full to fill back up
  is a multiple of it, `requirement_target_multiplier`, two by default.
- **Goods already on order are subtracted**, which the specification puts in the
  paid add-on. Not subtracting them means telling somebody to reorder what is
  already in a van, so they order twice and pay twice. What stays paid-for is the
  hard part: predicting what will sell during the lead time.
- Filters for the two ways the screen can fail to answer — an article nobody
  sells, and a supplier with no price. Both are shown rather than hidden.
- **A warning when the sales figures cannot be believed.** WooCommerce fills its
  sales lookup table in the background; until that has run, every article looks
  as if it has sold nothing, and a reordering screen that stays quiet about it is
  telling the shop not to buy anything.
- CSV export of whatever the filters are showing, proof against a spreadsheet
  reading a supplier name as a formula.
- `oxysuppliers_requirement_strategy` — the seam the paid add-on replaces. It
  answers one question, how many units are missing; the rounding to the
  supplier's terms and the state of the row stay here, so there is only ever one
  copy of them.
- **The number of queries does not grow with the number of rows**: measured on
  the bench at seven queries for five rows and seven for two hundred.
- 65 unit tests, and 18 checks against the seeded shop.

### Sprint 2 — product to supplier (13/08/2026)

- Price lists: each product or variation can be linked to any number of
  suppliers, each with their own code, cost, minimum, order multiple, pack size
  and lead time.
- `OrderTerms`, which turns a need into a quantity the supplier will actually
  accept. Minimum, multiple and pack size are three constraints that all have to
  hold at once — packs of six and multiples of ten leave only the multiples of
  thirty — and nothing is ever rounded down.
- Preferred supplier per article, with the cheapest standing in when nobody has
  been chosen, so a shop with one supplier never has to tick a box.
- A **Suppliers** panel on the product screen and on every variation, with no
  JavaScript at all.
- Saving the same supplier for the same article twice is an edit, not a second
  line, in the code and in the database.
- The list of suppliers now tells an empty shop apart from a search that found
  nothing.
- 51 unit tests, 35 checks inside WordPress, 50 checks driving the admin screens
  over HTTP on the test bench, plus 21 that drive the product panel the way the
  product screen does.
- Test bench at test.44123.it/oxysuppliers: WordPress 7.0.4, WooCommerce 11,
  HPOS on, seeded with a small but real shop. `scripts/deploy-test-site.ps1`,
  `scripts/verify-http.sh` and `scripts/verify-product-panel.php`.

### Sprint 1 — foundations (12/08/2026)

- Plugin bootstrap, PSR-4 autoloader, WooCommerce and HPOS compatibility
  declaration, requirements check with an explanation instead of a fatal.
- Schema: the eight tables, created and versioned through `dbDelta()`.
- Seven capabilities, granted to administrators and shop managers on activation
  **and on update**.
- Supplier records: domain object, validation rules that return codes, storage,
  and the admin screen to list, search, add, edit, deactivate and delete them.
- A supplier named on a purchase order cannot be deleted, only deactivated.
- Audit log, written from the first change onwards.
- Non-destructive uninstall: the data stays unless an administrator asks for it
  to go.
- Plugin icon (128 and 256 px) in `.wordpress-org/`.
- 29 unit tests and 23 checks inside a real WordPress (schema, capabilities,
  storage), PHPCS clean, PHPStan at level 8 clean, CI on GitHub Actions
  including a job that builds the package and inspects it.
- Tested against WordPress 7.0 and WooCommerce 11: the integration suite runs on
  it in CI, and the test bench at test.44123.it/oxysuppliers runs 7.0.4 with
  HPOS on, where 41 checks drive the admin screens over HTTP.
