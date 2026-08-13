<?php
/**
 * The purchase order document.
 *
 * Copy this file to `oxysuppliers/purchase-order.php` in your theme to change
 * it. What a shop sends its suppliers is the shop's business.
 *
 * Everything it needs is in $data:
 *   $data['order']    Oxysoft\OxySuppliers\Domain\PurchaseOrder
 *   $data['supplier'] Oxysoft\OxySuppliers\Domain\Supplier|null
 *   $data['company']  array<string,string>
 *   $data['logo']     string  Data URI, or empty
 *   $data['labels']   array<string,string>
 *
 * Written for a PDF renderer, which is why the layout is tables and inline
 * styles: there is no flexbox on paper.
 *
 * @package Oxysoft\OxySuppliers
 */

defined( 'ABSPATH' ) || exit;

/*
 * The variables below are locals of the method that includes this file, not
 * globals — but a sniff reading the file on its own cannot tell, and prefixing
 * them would make a template that a theme author copies read like machinery.
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

// The template is included from inside a method, so $data and everything
// below it are that method's locals: no global called $order is touched.
$order    = $data['order'];
$supplier = $data['supplier'];
$company  = $data['company'];
$labels   = $data['labels'];
$currency = $order->currency;
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<style>
		body { font-family: "DejaVu Sans", sans-serif; font-size: 10pt; color: #222; }
		h1 { font-size: 18pt; margin: 0 0 4pt; }
		table { width: 100%; border-collapse: collapse; }
		.head td { vertical-align: top; padding: 0 0 12pt; }
		.meta { font-size: 9pt; }
		.meta th { text-align: left; padding: 1pt 8pt 1pt 0; font-weight: normal; color: #666; }
		.lines { margin-top: 10pt; }
		.lines th { background: #f2f2f2; border-bottom: 1px solid #ccc; padding: 5pt; text-align: left; font-size: 9pt; }
		.lines td { border-bottom: 1px solid #eee; padding: 5pt; }
		/* Both the values and the headings above them: a column of figures with
			its heading on the other side reads as two columns. */
		.num, .lines th.num { text-align: right; }
		.totals { margin-top: 8pt; width: 45%; float: right; }
		.totals td { padding: 3pt 5pt; }
		.totals .grand { border-top: 2px solid #333; font-weight: bold; font-size: 12pt; }
		.notes { clear: both; padding-top: 30pt; font-size: 9pt; color: #444; }
		.logo { max-height: 60pt; }
	</style>
</head>
<body>

<table class="head">
	<tr>
		<td style="width: 55%;">
			<?php if ( '' !== $data['logo'] ) : ?>
				<img class="logo" src="<?php echo esc_attr( $data['logo'] ); ?>" alt="">
			<?php endif; ?>
			<div><strong><?php echo esc_html( $company['name'] ); ?></strong></div>
			<div><?php echo esc_html( $company['address'] ); ?></div>
			<?php if ( '' !== $company['address2'] ) : ?>
				<div><?php echo esc_html( $company['address2'] ); ?></div>
			<?php endif; ?>
			<div><?php echo esc_html( trim( $company['postcode'] . ' ' . $company['city'] ) ); ?></div>
			<div><?php echo esc_html( $company['country'] ); ?></div>
			<div><?php echo esc_html( $company['email'] ); ?></div>
		</td>
		<td style="width: 45%;">
			<h1><?php echo esc_html( $labels['title'] ); ?></h1>
			<table class="meta">
				<tr>
					<th><?php echo esc_html( $labels['number'] ); ?></th>
					<td><strong><?php echo esc_html( $order->number ); ?></strong></td>
				</tr>
				<tr>
					<th><?php echo esc_html( $labels['date'] ); ?></th>
					<td><?php echo esc_html( $order->order_date ); ?></td>
				</tr>
				<?php if ( null !== $order->expected_date ) : ?>
					<tr>
						<th><?php echo esc_html( $labels['expected'] ); ?></th>
						<td><?php echo esc_html( $order->expected_date ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( '' !== $order->supplier_reference ) : ?>
					<tr>
						<th><?php echo esc_html( $labels['reference'] ); ?></th>
						<td><?php echo esc_html( $order->supplier_reference ); ?></td>
					</tr>
				<?php endif; ?>
			</table>
		</td>
	</tr>
	<tr>
		<td colspan="2">
			<strong><?php echo esc_html( $labels['supplier'] ); ?></strong><br>
			<?php if ( null !== $supplier ) : ?>
				<?php echo esc_html( $supplier->company_name ); ?><br>
				<?php if ( '' !== $supplier->address ) : ?>
					<?php echo esc_html( $supplier->address ); ?><br>
				<?php endif; ?>
				<?php echo esc_html( trim( $supplier->postcode . ' ' . $supplier->city . ' ' . $supplier->state ) ); ?><br>
				<?php if ( '' !== $supplier->vat_number ) : ?>
					<?php echo esc_html( $supplier->vat_number ); ?><br>
				<?php endif; ?>
				<?php if ( '' !== $supplier->contact_name ) : ?>
					<?php echo esc_html( $supplier->contact_name ); ?>
				<?php endif; ?>
			<?php endif; ?>
		</td>
	</tr>
</table>

<table class="lines">
	<thead>
		<tr>
			<th style="width: 18%;"><?php echo esc_html( $labels['code'] ); ?></th>
			<th><?php echo esc_html( $labels['article'] ); ?></th>
			<th class="num" style="width: 12%;"><?php echo esc_html( $labels['quantity'] ); ?></th>
			<th class="num" style="width: 16%;"><?php echo esc_html( $labels['unit_cost'] ); ?></th>
			<th class="num" style="width: 18%;"><?php echo esc_html( $labels['line_total'] ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ( $order->lines as $line ) : ?>
		<tr>
			<td><?php echo esc_html( '' !== $line->supplier_sku ? $line->supplier_sku : $line->sku ); ?></td>
			<td><?php echo esc_html( $line->description ); ?></td>
			<td class="num"><?php echo esc_html( (string) $line->qty_ordered ); ?></td>
			<td class="num"><?php echo esc_html( $line->unit_cost->to_decimal() ); ?></td>
			<td class="num"><?php echo esc_html( $line->net_total()->to_decimal() ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<table class="totals">
	<tr>
		<td><?php echo esc_html( $labels['subtotal'] ); ?></td>
		<td class="num"><?php echo esc_html( $order->subtotal()->to_decimal() . ' ' . $currency ); ?></td>
	</tr>
	<?php if ( ! $order->tax()->is_zero() ) : ?>
		<tr>
			<td><?php echo esc_html( $labels['tax'] ); ?></td>
			<td class="num"><?php echo esc_html( $order->tax()->to_decimal() . ' ' . $currency ); ?></td>
		</tr>
	<?php endif; ?>
	<tr class="grand">
		<td><?php echo esc_html( $labels['total'] ); ?></td>
		<td class="num"><?php echo esc_html( $order->total()->to_decimal() . ' ' . $currency ); ?></td>
	</tr>
</table>

<div class="notes">
	<?php
	// The terms are copied onto the order when it is created, so that an order
	// sent last year still says what was agreed last year. An order that has
	// none falls back to the supplier's, which is better than a document that
	// quietly says nothing about when it will be paid.
	$terms = '' !== $order->payment_terms
		? $order->payment_terms
		: ( null === $supplier ? '' : $supplier->payment_terms );
	?>
	<?php if ( '' !== $terms ) : ?>
		<p><strong><?php echo esc_html( $labels['terms'] ); ?>:</strong> <?php echo esc_html( $terms ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== $order->supplier_notes ) : ?>
		<p><strong><?php echo esc_html( $labels['notes'] ); ?>:</strong><br><?php echo nl2br( esc_html( $order->supplier_notes ) ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== $order->delivery_address ) : ?>
		<p><strong><?php echo esc_html( $labels['deliver_to'] ); ?>:</strong><br><?php echo nl2br( esc_html( $order->delivery_address ) ); ?></p>
	<?php endif; ?>
</div>

</body>
</html>
