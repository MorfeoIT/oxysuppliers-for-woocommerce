=== OxySuppliers – Suppliers & Purchase Orders for WooCommerce ===
Contributors: oxysoft
Tags: woocommerce, suppliers, purchase orders, inventory, stock
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Know what to reorder, from which supplier and how much. Suppliers, purchase orders and goods receipts, inside WooCommerce.

== Description ==

WooCommerce handles selling. OxySuppliers handles buying: who you buy from, what
they charge, what is running low, and what to order next.

It answers one question, and the whole plugin is arranged around it:

**What do I need to order, from which supplier, and how much?**

= What it does =

* **Suppliers.** Names, addresses, contacts, payment terms, lead times and
  minimum order values, kept in WooCommerce rather than in a spreadsheet.
* **Supplier price lists.** Each product or variation can be linked to one or
  more suppliers, each with their own code, cost, minimum quantity, order
  multiple and lead time.
* **Reordering.** A single screen showing what is below its reorder level, who
  supplies it, what it costs and how many to buy.
* **Purchase orders.** Create them by hand or from the reordering screen, which
  groups the lines by supplier for you.
* **PDF and email.** A purchase order becomes a document you can send, with the
  sending recorded.
* **Goods receipts.** Receive a whole order or part of one, more than once, with
  stock and costs updated and every movement written down.
* **Goods on the way.** What has been ordered and not yet arrived is subtracted
  from what you need to reorder, and shown on the product screen with the date
  to expect it, so nothing is bought twice.
* **What things really cost.** Every delivery records the price actually paid,
  and nothing in that record is ever rewritten.
* **Reports.** Money committed, open orders, articles below their reorder point,
  and orders that are late.
* **Importing a price list.** A CSV from a supplier or a spreadsheet, read with
  its semicolons and comma decimals, shown to you in full before anything is
  written.

= What it is not =

It is not an ERP, and it does not try to be. No supplier accounting, no purchase
invoices, no payments, no MRP, no warehouse management. What it does is the
short path from "this is running out" to "it is on its way".

= Free and Pro =

The free plugin is a complete tool, not a trial: a shop with one supplier can
run its buying on it and never pay anything. The Pro add-on is for shops that
need forecasting from sales history, automatic draft orders, weighted average
cost, cost history, supplier comparison, approval workflow and notifications.

== Installation ==

1. Install and activate WooCommerce.
2. Install and activate OxySuppliers.
3. Go to WooCommerce → Purchasing and add your first supplier.

== Frequently Asked Questions ==

= Does it work with HPOS? =

Yes. The plugin never touches WooCommerce's order tables and reads sales orders
only through the official API, so it works with the high performance order
storage turned on or off.

= Does receiving goods change my stock? =

Yes, unless you turn that off in the settings, and only for products that have
WooCommerce stock management enabled. Every change is recorded with the value
before and after, and a receipt can be reversed by an opposite movement rather
than by deleting anything.

= Can I receive part of an order? =

Yes, as many times as the deliveries arrive. The plugin will not let the same
receipt be recorded twice, even if the page is reloaded or submitted twice.

= What happens to my data if I uninstall the plugin? =

Nothing is removed unless you have explicitly asked for it in the settings. A
purchase order is a document, and documents should not disappear because a
plugin was deleted.

= I use OxyProfit. Do the two work together? =

Yes, and you do not have to do anything: OxyProfit picks up what your suppliers
actually charged, which beats a cost typed into a product screen months ago. If
OxyProfit is not installed, nothing changes and nothing breaks.

= Will importing a price list overwrite what I have? =

Not before you have seen it. The file is read, and you are shown what would be
created, what would be changed and which rows have something wrong with them.
Nothing is written until you say so.

== Screenshots ==

1. What to reorder: what is running low, who supplies it, what it costs, how many to buy — and what is already on its way, so nothing is bought twice.
2. The purchase orders, with what each one is worth and what stage it has reached.
3. One order: the lines, the supplier's own codes, and the totals that go on the document.
4. Receiving what has arrived, part of an order at a time, with the price actually charged next to the price that was ordered.
5. The suppliers, with their terms and lead times.
6. The Suppliers tab on a product: who sells it, at what price, with what minimum, multiple and pack size — and which of them is the preferred one.
7. The four reports the free plugin gives you.

== Changelog ==

= 0.1.0 =
* Not released yet. In development.
