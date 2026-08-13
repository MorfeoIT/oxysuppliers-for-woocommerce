# Changelog

All notable changes to this plugin are recorded here. The public changelog on
oxywp.com is generated from `readme.txt`, which follows this file.

## [Unreleased]

### Sprint 8 — ready to release (14/08/2026)

**The Suppliers panel on a product had never been styled.** The stylesheet was
loaded on screens whose hook carries the plugin's slug, and WooCommerce's
product screen is not one of them — so WooCommerce's own rules, written for one
field per line, squeezed every field in the price list to a few pixels. The
supplier's code, the cost, the minimum, the multiple: all in the page, none of
them readable. Found by taking the screenshots, which is what screenshots are
for.

- **Removing the plugin proved both ways**, with uninstall.php run exactly as
  WordPress runs it: by default the tables, the settings and a real supplier
  survive; with the setting turned on, all eight tables, the options and the
  capabilities go. 18 checks.
- **Seven screenshots from a real installation**, taken by
  `scripts/screenshots.mjs` against the bench rather than mocked up, so they
  cannot drift from what the plugin does.
- `scripts/bench-seed.php` builds the same believable shop every time: three
  suppliers, a price list, an order in transit, one part-delivered and one late.
- `docs/SUBMISSION_READINESS.md` says what is ready, what was checked and how,
  and what is not.

### Sprint 7 — goods on the way, what things cost, and the world outside (13/08/2026)

**Goods on the way stop being zero.** What has been ordered and not yet received
is subtracted from what needs reordering, and shown on the product screen next
to the stock, with the date to expect it. A shop that reorders what is already
in a van pays for it twice; the specification put this in the paid add-on, and
it is here instead. What stays paid is the hard half: predicting what will sell
*while* the goods are travelling.

**Every delivery writes down what was really paid.** A separate record, never
rewritten: a cost that turns out to be wrong is followed by another entry saying
what it is now.

- Undoing a delivery puts back the cost **that delivery replaced** — which is
  written on its own entry — rather than the cost as it stands, which is the
  figure being taken away. Getting this wrong made a correction correct nothing;
  found on the bench, and now guarded by two tests.
- Undoing the *first* delivery of an article leaves "we do not know what this
  costs" rather than the ordered price. A price somebody typed is not a price
  somebody paid. Schema 2, where the cost may be null.

**OxyProfit, if it is there.** Our costs are offered to it first, ahead of one
typed into a product screen months ago. The class implementing its interface
lives outside `src/` so the autoloader cannot reach it: on a shop without
OxyProfit, merely loading that file would be a fatal error.

- **Reports**: money committed, open orders, articles below the reorder point,
  and orders that are late — with the four that need the paid add-on named
  rather than hidden behind a disabled button.
- **Importing a price list from CSV**, with a mandatory preview: a byte order
  mark, semicolons, comma decimals and Italian headings all read, bad rows
  listed rather than dropped, and nothing written until somebody has seen what
  it would do.
- **A read-only REST API** under `oxysuppliers/v1`, each route with its own
  permission check.
- 89 unit tests, 81 inside WordPress, 117 over HTTP, 47 against the seeded shop
  — including what happens with OxyProfit absent and present on the same site.

### Sprint 6 — goods receipts (13/08/2026)

The sprint where being wrong means a shop sells what it has not got. Receiving
is defended four times over, in this order:

1. **An idempotency key**, generated when the form is drawn and written before
   anything else, with a unique index behind it. A double click, a reload, a
   back button, a browser retrying a timed-out request — all carry the same key,
   and the database refuses the second.
2. **A lock on the order**, so two people receiving the same delivery at the
   same moment do not both succeed.
3. **The outstanding quantity is read again inside the transaction**, from the
   receipts themselves — not from the copy on the order line, and certainly not
   from the numbers the page was drawn with.
4. **A transaction** around everything the plugin owns.

- Stock is moved **after** the commit and outside the transaction, with
  WooCommerce's own atomic increment. Reading it and writing it back would leave
  room for a customer's order to land in between and be overwritten.
- An article WooCommerce is not counting still gets its delivery recorded, with
  the reason written on the line.
- Every movement is written down with the value before and after.
- **A mistake is corrected by an opposite entry, never by deleting one.** Both
  stay, so the history still reads; the stock goes back; and a correction cannot
  itself be corrected.
- Full, partial and per-line receiving, with the price actually charged kept
  next to the price that was ordered.
- 89 unit tests, 74 checks inside WordPress, 93 over HTTP, 115 against the
  seeded shop — including posting the very same form twice and watching the
  stock go up once.

### Sprint 5 — the document and the envelope (13/08/2026)

- **A real PDF**, from an overridable template: copy `purchase-order.php` into
  `oxysuppliers/` in your theme and it wins. Dompdf is bundled, so nothing has
  to be installed for it to work.
- **The PDF is behind a permission, not behind an address.** It is built on the
  way out, never written into the uploads folder, and needs both the capability
  and a nonce. A purchase order sitting in `wp-content/uploads` is one guessed
  URL away from anybody.
- The renderer cannot reach the network and cannot read outside the uploads
  folder. A template is HTML, and HTML that can fetch is a way out of the
  building.
- **Sending to the supplier**, with the recipient, subject and message shown and
  editable before anything is pressed. Sending is the one thing here that cannot
  be undone.
- **A resend is not a first send.** The order only remembers the last time it
  went out, so the log is what knows: the second send says so, and moving the
  order along happens once.
- The attachment lives in the system temp directory for the length of one send
  and is deleted straight after.
- Every order carries its own history on screen: created, saved, sent, sent
  again.
- The package is built by `scripts/build-package.sh` and carries `composer.json`
  so a reviewer can see what is in the box. Two DejaVu families the document
  never asks for are trimmed out, which is 3.4 MB nobody downloads on purpose.
- 89 unit tests, 57 checks inside WordPress, 84 over HTTP, 29 against the seeded
  shop — including reading a generated PDF back to check the accents survived.

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
