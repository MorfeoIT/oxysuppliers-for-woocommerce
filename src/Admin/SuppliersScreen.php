<?php
/**
 * The suppliers tab.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Admin;

use Oxysoft\OxySuppliers\Domain\Supplier;
use Oxysoft\OxySuppliers\Domain\SupplierStatus;
use Oxysoft\OxySuppliers\Domain\SupplierValidator;
use Oxysoft\OxySuppliers\Persistence\SupplierRepository;
use Oxysoft\OxySuppliers\Service\AuditLogger;
use Oxysoft\OxySuppliers\Support\Capabilities;

/**
 * List, create, edit, switch off and — when nothing refers to them — remove
 * suppliers.
 *
 * Every write arrives at admin-post.php and leaves as a redirect, so a reload
 * never repeats it. What the form was holding when it failed travels in a short
 * lived transient rather than the address bar: a supplier's details are not
 * something to leave in a server log.
 */
final class SuppliersScreen implements Screen {

	public const SLUG = 'suppliers';

	public const SAVE_ACTION   = 'oxysuppliers_save_supplier';
	public const DELETE_ACTION = 'oxysuppliers_delete_supplier';
	public const TOGGLE_ACTION = 'oxysuppliers_toggle_supplier';

	/**
	 * How many suppliers a page holds.
	 */
	private const PER_PAGE = 20;

	/**
	 * Take the collaborators.
	 *
	 * @param SupplierRepository $suppliers Storage.
	 * @param SupplierValidator  $validator The rules.
	 * @param AuditLogger        $audit     The trail.
	 */
	public function __construct(
		private readonly SupplierRepository $suppliers,
		private readonly SupplierValidator $validator,
		private readonly AuditLogger $audit
	) {
	}

	/**
	 * Slug used in the address.
	 *
	 * @return string
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * Name shown on the tab.
	 *
	 * @return string
	 */
	public function title(): string {
		return __( 'Suppliers', 'oxysuppliers-for-woocommerce' );
	}

	/**
	 * Capability required to open it.
	 *
	 * @return string
	 */
	public function capability(): string {
		return Capabilities::MANAGE_SUPPLIERS;
	}

	/**
	 * Hook up the form handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( $this, 'handle_delete' ) );
		add_action( 'admin_post_' . self::TOGGLE_ACTION, array( $this, 'handle_toggle' ) );
	}

	/**
	 * Draw whichever view was asked for.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage suppliers.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation; every write goes through admin-post.php with its own nonce.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->render_notice();

		switch ( $action ) {
			case 'new':
				$this->render_form( null );
				break;

			case 'edit':
				$supplier = $this->suppliers->find( $id );

				if ( null === $supplier ) {
					$this->render_missing();
					break;
				}

				$this->render_form( $supplier );
				break;

			case 'delete':
				$supplier = $this->suppliers->find( $id );

				if ( null === $supplier ) {
					$this->render_missing();
					break;
				}

				$this->render_delete_confirmation( $supplier );
				break;

			default:
				$this->render_list();
		}
	}

	/**
	 * The list of suppliers.
	 *
	 * @return void
	 */
	private function render_list(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only listing.
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'company_name';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'asc';
		$page    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$query = array(
			'search'   => $search,
			'status'   => $status,
			'orderby'  => $orderby,
			'order'    => $order,
			'per_page' => self::PER_PAGE,
			'page'     => $page,
		);

		$suppliers = $this->suppliers->paginate( $query );
		$total     = $this->suppliers->count( $query );
		$pages     = (int) ceil( $total / self::PER_PAGE );

		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Suppliers', 'oxysuppliers-for-woocommerce' ); ?></h1>
		<a href="<?php echo esc_url( Menu::url( self::SLUG, array( 'action' => 'new' ) ) ); ?>" class="page-title-action">
			<?php esc_html_e( 'Add supplier', 'oxysuppliers-for-woocommerce' ); ?>
		</a>
		<hr class="wp-header-end">

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( Menu::SLUG ); ?>">
			<input type="hidden" name="tab" value="<?php echo esc_attr( self::SLUG ); ?>">

			<p class="search-box">
				<label class="screen-reader-text" for="oxysuppliers-search">
					<?php esc_html_e( 'Search suppliers', 'oxysuppliers-for-woocommerce' ); ?>
				</label>
				<select name="status">
					<option value=""><?php esc_html_e( 'All statuses', 'oxysuppliers-for-woocommerce' ); ?></option>
					<option value="active" <?php selected( $status, 'active' ); ?>>
						<?php esc_html_e( 'Active', 'oxysuppliers-for-woocommerce' ); ?>
					</option>
					<option value="inactive" <?php selected( $status, 'inactive' ); ?>>
						<?php esc_html_e( 'Inactive', 'oxysuppliers-for-woocommerce' ); ?>
					</option>
				</select>
				<input type="search" id="oxysuppliers-search" name="s" value="<?php echo esc_attr( $search ); ?>">
				<?php submit_button( __( 'Search', 'oxysuppliers-for-woocommerce' ), '', '', false ); ?>
			</p>
		</form>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th scope="col"><?php echo $this->sortable_header( 'company_name', __( 'Supplier', 'oxysuppliers-for-woocommerce' ), $query ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in sortable_header(). ?></th>
					<th scope="col"><?php esc_html_e( 'VAT number', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th scope="col"><?php echo $this->sortable_header( 'city', __( 'City', 'oxysuppliers-for-woocommerce' ), $query ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in sortable_header(). ?></th>
					<th scope="col"><?php echo $this->sortable_header( 'country', __( 'Country', 'oxysuppliers-for-woocommerce' ), $query ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in sortable_header(). ?></th>
					<th scope="col"><?php echo $this->sortable_header( 'lead_time_days', __( 'Lead time', 'oxysuppliers-for-woocommerce' ), $query ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped in sortable_header(). ?></th>
					<th scope="col"><?php esc_html_e( 'Minimum order', 'oxysuppliers-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'oxysuppliers-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( array() === $suppliers ) : ?>
				<tr>
					<td colspan="7">
						<?php esc_html_e( 'No suppliers yet. Add the first one to start buying.', 'oxysuppliers-for-woocommerce' ); ?>
					</td>
				</tr>
			<?php endif; ?>

			<?php foreach ( $suppliers as $supplier ) : ?>
				<tr>
					<td>
						<strong>
							<a href="
							<?php
							echo esc_url(
								Menu::url(
									self::SLUG,
									array(
										'action' => 'edit',
										'id'     => $supplier->id,
									)
								)
							);
							?>
										">
								<?php echo esc_html( $supplier->display_name() ); ?>
							</a>
						</strong>
						<?php if ( '' !== $supplier->trade_name && $supplier->trade_name !== $supplier->company_name ) : ?>
							<div class="description"><?php echo esc_html( $supplier->company_name ); ?></div>
						<?php endif; ?>
						<?php $this->render_row_actions( $supplier ); ?>
					</td>
					<td><?php echo esc_html( $supplier->vat_number ); ?></td>
					<td><?php echo esc_html( $supplier->city ); ?></td>
					<td><?php echo esc_html( $supplier->country ); ?></td>
					<td>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of days. */
								_n( '%d day', '%d days', $supplier->lead_time_days, 'oxysuppliers-for-woocommerce' ),
								$supplier->lead_time_days
							)
						);
						?>
					</td>
					<td>
						<?php
						echo $supplier->min_order_value->is_zero()
							? '&mdash;'
							: wp_kses_post( wc_price( (float) $supplier->min_order_value->to_decimal(), array( 'currency' => $supplier->currency() ) ) );
						?>
					</td>
					<td>
						<?php if ( $supplier->is_active() ) : ?>
							<span class="oxysuppliers-status is-active"><?php esc_html_e( 'Active', 'oxysuppliers-for-woocommerce' ); ?></span>
						<?php else : ?>
							<span class="oxysuppliers-status is-inactive"><?php esc_html_e( 'Inactive', 'oxysuppliers-for-woocommerce' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav">
				<div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'total'     => $pages,
								'current'   => $page,
								'prev_text' => '&laquo;',
								'next_text' => '&raquo;',
							)
						) ?? ''
					);
					?>
				</div>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * The links under a supplier's name.
	 *
	 * @param Supplier $supplier The supplier.
	 * @return void
	 */
	private function render_row_actions( Supplier $supplier ): void {
		$toggle_label = $supplier->is_active()
			? __( 'Deactivate', 'oxysuppliers-for-woocommerce' )
			: __( 'Activate', 'oxysuppliers-for-woocommerce' );

		?>
		<div class="row-actions">
			<span class="edit">
				<a href="
				<?php
				echo esc_url(
					Menu::url(
						self::SLUG,
						array(
							'action' => 'edit',
							'id'     => $supplier->id,
						)
					)
				);
				?>
							">
					<?php esc_html_e( 'Edit', 'oxysuppliers-for-woocommerce' ); ?>
				</a> |
			</span>
			<span class="toggle">
				<a href="<?php echo esc_url( $this->action_url( self::TOGGLE_ACTION, $supplier->id ) ); ?>">
					<?php echo esc_html( $toggle_label ); ?>
				</a>
			</span>
			<?php if ( $this->suppliers->is_deletable( $supplier->id ) ) : ?>
				|
				<span class="delete">
					<a class="submitdelete" href="
					<?php
					echo esc_url(
						Menu::url(
							self::SLUG,
							array(
								'action' => 'delete',
								'id'     => $supplier->id,
							)
						)
					);
					?>
													">
						<?php esc_html_e( 'Delete', 'oxysuppliers-for-woocommerce' ); ?>
					</a>
				</span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A sortable column header.
	 *
	 * @param string              $column Column key.
	 * @param string              $label  Column label.
	 * @param array<string,mixed> $query  Current query.
	 * @return string Escaped HTML.
	 */
	private function sortable_header( string $column, string $label, array $query ): string {
		$is_current = ( $query['orderby'] ?? '' ) === $column;
		$next       = $is_current && 'asc' === ( $query['order'] ?? 'asc' ) ? 'desc' : 'asc';

		return sprintf(
			'<a href="%1$s"><span>%2$s</span></a>',
			esc_url(
				Menu::url(
					self::SLUG,
					array(
						'orderby' => $column,
						'order'   => $next,
						's'       => (string) ( $query['search'] ?? '' ),
						'status'  => (string) ( $query['status'] ?? '' ),
					)
				)
			),
			esc_html( $label )
		);
	}

	/**
	 * The add or edit form.
	 *
	 * @param Supplier|null $supplier Supplier being edited, or null for a new one.
	 * @return void
	 */
	private function render_form( ?Supplier $supplier ): void {
		$state    = $this->take_form_state();
		$errors   = $state['errors'];
		$defaults = null === $supplier ? $this->blank_values() : $this->values_of( $supplier );
		$values   = array() === $state['values'] ? $defaults : array_merge( $defaults, $state['values'] );
		$id       = null === $supplier ? 0 : $supplier->id;

		?>
		<h1 class="wp-heading-inline">
			<?php
			echo esc_html(
				null === $supplier
					? __( 'Add supplier', 'oxysuppliers-for-woocommerce' )
					: __( 'Edit supplier', 'oxysuppliers-for-woocommerce' )
			);
			?>
		</h1>
		<hr class="wp-header-end">

		<?php if ( array() !== $errors ) : ?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'The supplier was not saved. Please correct the fields marked below.', 'oxysuppliers-for-woocommerce' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
			<?php wp_nonce_field( self::SAVE_ACTION ); ?>

			<?php foreach ( $this->sections() as $section => $fields ) : ?>
				<h2><?php echo esc_html( $section ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
					<?php foreach ( $fields as $name => $field ) : ?>
						<tr>
							<th scope="row">
								<label for="oxysuppliers-<?php echo esc_attr( $name ); ?>">
									<?php echo esc_html( $field['label'] ); ?>
									<?php if ( ! empty( $field['required'] ) ) : ?>
										<span class="description">*</span>
									<?php endif; ?>
								</label>
							</th>
							<td>
								<?php $this->render_field( $name, $field, (string) ( $values[ $name ] ?? '' ) ); ?>
								<?php if ( isset( $errors[ $name ] ) ) : ?>
									<p class="oxysuppliers-error" style="color:#b32d2e;">
										<?php echo esc_html( $this->error_message( $errors[ $name ], $field ) ); ?>
									</p>
								<?php elseif ( ! empty( $field['help'] ) ) : ?>
									<p class="description"><?php echo esc_html( $field['help'] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>

			<p class="submit">
				<?php submit_button( __( 'Save supplier', 'oxysuppliers-for-woocommerce' ), 'primary', 'submit', false ); ?>
				<a class="button button-secondary" href="<?php echo esc_url( Menu::url( self::SLUG ) ); ?>">
					<?php esc_html_e( 'Cancel', 'oxysuppliers-for-woocommerce' ); ?>
				</a>
			</p>
		</form>
		<?php
	}

	/**
	 * One form field.
	 *
	 * @param string              $name  Field name.
	 * @param array<string,mixed> $field Field definition.
	 * @param string              $value Current value.
	 * @return void
	 */
	private function render_field( string $name, array $field, string $value ): void {
		$id   = 'oxysuppliers-' . $name;
		$type = (string) ( $field['type'] ?? 'text' );

		switch ( $type ) {
			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="4" class="large-text">%3$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_textarea( $value )
				);
				break;

			case 'select':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );

				$options = (array) ( $field['options'] ?? array() );

				foreach ( $options as $option_value => $label ) {
					// No space before the second placeholder: selected() already
					// brings its own, and two of them is a double space in the
					// markup.
					printf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr( (string) $option_value ),
						selected( (string) $option_value, $value, false ),
						esc_html( (string) $label )
					);
				}

				echo '</select>';
				break;

			case 'number':
				printf(
					'<input type="number" min="0" step="1" id="%1$s" name="%2$s" value="%3$s" class="small-text">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;

			default:
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text" maxlength="%4$d">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value ),
					(int) ( Supplier::MAX_LENGTHS[ $name ] ?? 191 )
				);
		}
	}

	/**
	 * The confirmation shown before removing a supplier.
	 *
	 * Deleting is a POST behind a nonce, never a link somebody can be sent.
	 *
	 * @param Supplier $supplier The supplier.
	 * @return void
	 */
	private function render_delete_confirmation( Supplier $supplier ): void {
		$orders = $this->suppliers->purchase_order_count( $supplier->id );

		?>
		<h1><?php esc_html_e( 'Delete supplier', 'oxysuppliers-for-woocommerce' ); ?></h1>

		<?php if ( $orders > 0 ) : ?>
			<div class="notice notice-error">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %1$s: supplier name, %2$d: number of purchase orders. */
							_n(
								'%1$s cannot be deleted: %2$d purchase order names them. Deactivate them instead.',
								'%1$s cannot be deleted: %2$d purchase orders name them. Deactivate them instead.',
								$orders,
								'oxysuppliers-for-woocommerce'
							),
							$supplier->display_name(),
							$orders
						)
					);
					?>
				</p>
			</div>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( Menu::url( self::SLUG ) ); ?>">
					<?php esc_html_e( 'Back to suppliers', 'oxysuppliers-for-woocommerce' ); ?>
				</a>
			</p>
			<?php
			return;
		endif;
		?>

		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: supplier name. */
					__( 'Delete %s and their price list? This cannot be undone.', 'oxysuppliers-for-woocommerce' ),
					$supplier->display_name()
				)
			);
			?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::DELETE_ACTION ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $supplier->id ); ?>">
			<?php wp_nonce_field( self::DELETE_ACTION . '_' . $supplier->id ); ?>

			<?php submit_button( __( 'Delete supplier', 'oxysuppliers-for-woocommerce' ), 'delete', 'submit', false ); ?>
			<a class="button button-secondary" href="<?php echo esc_url( Menu::url( self::SLUG ) ); ?>">
				<?php esc_html_e( 'Cancel', 'oxysuppliers-for-woocommerce' ); ?>
			</a>
		</form>
		<?php
	}

	/**
	 * What to say when the id in the address does not exist.
	 *
	 * @return void
	 */
	private function render_missing(): void {
		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'That supplier no longer exists.', 'oxysuppliers-for-woocommerce' ); ?></p>
		</div>
		<p>
			<a class="button" href="<?php echo esc_url( Menu::url( self::SLUG ) ); ?>">
				<?php esc_html_e( 'Back to suppliers', 'oxysuppliers-for-woocommerce' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Save a new or edited supplier.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		check_admin_referer( self::SAVE_ACTION );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage suppliers.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		$id       = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$existing = $id > 0 ? $this->suppliers->find( $id ) : null;

		if ( $id > 0 && null === $existing ) {
			$this->redirect_to_list( 'missing' );
		}

		$values = $this->read_submitted_values();
		$errors = $this->validator->validate( $values );

		if ( array() !== $errors ) {
			$this->remember_form_state( $values, $errors );

			wp_safe_redirect(
				Menu::url(
					self::SLUG,
					$id > 0
						? array(
							'action' => 'edit',
							'id'     => $id,
						)
						: array( 'action' => 'new' )
				)
			);
			exit;
		}

		$supplier = Supplier::from_fields( $values, $id );

		if ( null === $existing ) {
			$new_id = $this->suppliers->insert( $supplier );

			if ( 0 === $new_id ) {
				$this->remember_form_state( $values, array() );
				$this->redirect_to_list( 'error' );
			}

			$this->audit->log(
				AuditLogger::OBJECT_SUPPLIER,
				(string) $new_id,
				AuditLogger::ACTION_CREATED,
				null,
				$this->values_of( $supplier->with_id( $new_id ) )
			);

			/**
			 * Fires after a supplier has been created.
			 *
			 * @since 0.1.0
			 *
			 * @param int      $new_id   The new supplier's id.
			 * @param Supplier $supplier The supplier as stored.
			 */
			do_action( 'oxysuppliers_supplier_created', $new_id, $supplier->with_id( $new_id ) );

			$this->redirect_to_list( 'created' );
		}

		if ( ! $this->suppliers->update( $supplier ) ) {
			$this->remember_form_state( $values, array() );
			$this->redirect_to_list( 'error' );
		}

		$this->audit->log(
			AuditLogger::OBJECT_SUPPLIER,
			(string) $id,
			AuditLogger::ACTION_UPDATED,
			$this->values_of( $existing ),
			$this->values_of( $supplier )
		);

		/**
		 * Fires after a supplier has been updated.
		 *
		 * @since 0.1.0
		 *
		 * @param int      $id       The supplier's id.
		 * @param Supplier $supplier The supplier as stored.
		 * @param Supplier $existing The supplier as it was.
		 */
		do_action( 'oxysuppliers_supplier_updated', $id, $supplier, $existing );

		$this->redirect_to_list( 'updated' );
	}

	/**
	 * Remove a supplier nothing refers to.
	 *
	 * @return void
	 */
	public function handle_delete(): void {
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;

		check_admin_referer( self::DELETE_ACTION . '_' . $id );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage suppliers.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		$supplier = $this->suppliers->find( $id );

		if ( null === $supplier ) {
			$this->redirect_to_list( 'missing' );
		}

		if ( ! $this->suppliers->delete( $id ) ) {
			$this->redirect_to_list( 'not_deletable' );
		}

		$this->audit->log(
			AuditLogger::OBJECT_SUPPLIER,
			(string) $id,
			AuditLogger::ACTION_DELETED,
			$this->values_of( $supplier ),
			null
		);

		$this->redirect_to_list( 'deleted' );
	}

	/**
	 * Switch a supplier on or off.
	 *
	 * @return void
	 */
	public function handle_toggle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified on the next line, against this id.
		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

		check_admin_referer( self::TOGGLE_ACTION . '_' . $id );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage suppliers.', 'oxysuppliers-for-woocommerce' ), 403 );
		}

		$supplier = $this->suppliers->find( $id );

		if ( null === $supplier ) {
			$this->redirect_to_list( 'missing' );
		}

		$values           = $this->values_of( $supplier );
		$values['status'] = $supplier->is_active()
			? SupplierStatus::INACTIVE->value
			: SupplierStatus::ACTIVE->value;

		$updated = Supplier::from_fields( $values, $id );

		if ( ! $this->suppliers->update( $updated ) ) {
			$this->redirect_to_list( 'error' );
		}

		$this->audit->log(
			AuditLogger::OBJECT_SUPPLIER,
			(string) $id,
			AuditLogger::ACTION_STATUS,
			array( 'status' => $supplier->status->value ),
			array( 'status' => $updated->status->value )
		);

		$this->redirect_to_list( $updated->is_active() ? 'activated' : 'deactivated' );
	}

	/**
	 * Read and clean what the form sent.
	 *
	 * Cleaning happens before validation so that the rules run against the same
	 * values that will be stored, and not against something the sanitiser will
	 * change afterwards.
	 *
	 * @return array<string,string>
	 */
	private function read_submitted_values(): array {
		$values = array();

		foreach ( array_keys( $this->fields() ) as $name ) {
			if ( 'notes' === $name ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is checked by the caller before this runs.
				$values[ $name ] = isset( $_POST[ $name ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $name ] ) ) : '';

				continue;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is checked by the caller before this runs.
			$values[ $name ] = isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
		}

		// The store may write amounts with a comma and thousands separators.
		// Turn that into the one shape the domain accepts, and treat something
		// that cannot be turned into a number as a number that is wrong rather
		// than as nothing at all.
		$typed = $values['min_order_value'];

		if ( '' !== $typed ) {
			$canonical = (string) wc_format_decimal( $typed, false, true );

			$values['min_order_value'] = '' === $canonical ? $typed : $canonical;
		}

		$values['country']  = strtoupper( $values['country'] );
		$values['currency'] = strtoupper( $values['currency'] );

		return $values;
	}

	/**
	 * A supplier as form values.
	 *
	 * @param Supplier $supplier The supplier.
	 * @return array<string,string>
	 */
	private function values_of( Supplier $supplier ): array {
		return array(
			'company_name'    => $supplier->company_name,
			'trade_name'      => $supplier->trade_name,
			'vat_number'      => $supplier->vat_number,
			'tax_code'        => $supplier->tax_code,
			'status'          => $supplier->status->value,
			'address'         => $supplier->address,
			'postcode'        => $supplier->postcode,
			'city'            => $supplier->city,
			'state'           => $supplier->state,
			'country'         => $supplier->country,
			'order_email'     => $supplier->order_email,
			'billing_email'   => $supplier->billing_email,
			'phone'           => $supplier->phone,
			'contact_name'    => $supplier->contact_name,
			'website'         => $supplier->website,
			'currency'        => $supplier->currency(),
			'payment_terms'   => $supplier->payment_terms,
			'lead_time_days'  => (string) $supplier->lead_time_days,
			'min_order_value' => $supplier->min_order_value->to_decimal(),
			'notes'           => $supplier->notes,
		);
	}

	/**
	 * What a new supplier's form starts with.
	 *
	 * @return array<string,string>
	 */
	private function blank_values(): array {
		$values = array_fill_keys( array_keys( $this->fields() ), '' );

		$values['status']          = SupplierStatus::ACTIVE->value;
		$values['currency']        = get_woocommerce_currency();
		$values['country']         = substr( (string) get_option( 'woocommerce_default_country', '' ), 0, 2 );
		$values['lead_time_days']  = '0';
		$values['min_order_value'] = '';

		return $values;
	}

	/**
	 * The fields, grouped as the form shows them.
	 *
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	private function sections(): array {
		$fields = $this->fields();

		$groups = array(
			__( 'Identity', 'oxysuppliers-for-woocommerce' ) => array( 'company_name', 'trade_name', 'vat_number', 'tax_code', 'status' ),
			__( 'Address', 'oxysuppliers-for-woocommerce' ) => array( 'address', 'postcode', 'city', 'state', 'country' ),
			__( 'Contacts', 'oxysuppliers-for-woocommerce' ) => array( 'contact_name', 'order_email', 'billing_email', 'phone', 'website' ),
			__( 'Buying terms', 'oxysuppliers-for-woocommerce' ) => array( 'currency', 'payment_terms', 'lead_time_days', 'min_order_value' ),
			__( 'Notes', 'oxysuppliers-for-woocommerce' ) => array( 'notes' ),
		);

		$sections = array();

		foreach ( $groups as $title => $names ) {
			foreach ( $names as $name ) {
				$sections[ $title ][ $name ] = $fields[ $name ];
			}
		}

		return $sections;
	}

	/**
	 * Every field the form holds.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function fields(): array {
		$countries = array( '' => __( '— Select —', 'oxysuppliers-for-woocommerce' ) );

		if ( function_exists( 'WC' ) && null !== WC()->countries ) {
			$countries += WC()->countries->get_countries();
		}

		return array(
			'company_name'    => array(
				'label'    => __( 'Company name', 'oxysuppliers-for-woocommerce' ),
				'type'     => 'text',
				'required' => true,
			),
			'trade_name'      => array(
				'label' => __( 'Trading name', 'oxysuppliers-for-woocommerce' ),
				'type'  => 'text',
				'help'  => __( 'Shown in lists when it is filled in.', 'oxysuppliers-for-woocommerce' ),
			),
			'vat_number'      => array(
				'label' => __( 'VAT number', 'oxysuppliers-for-woocommerce' ),
				'type'  => 'text',
			),
			'tax_code'        => array(
				'label' => __( 'Tax code', 'oxysuppliers-for-woocommerce' ),
				'type'  => 'text',
			),
			'status'          => array(
				'label'   => __( 'Status', 'oxysuppliers-for-woocommerce' ),
				'type'    => 'select',
				'options' => array(
					SupplierStatus::ACTIVE->value   => __( 'Active', 'oxysuppliers-for-woocommerce' ),
					SupplierStatus::INACTIVE->value => __( 'Inactive', 'oxysuppliers-for-woocommerce' ),
				),
				'help'    => __( 'An inactive supplier stays on past orders but is not offered on new ones.', 'oxysuppliers-for-woocommerce' ),
			),
			'address'         => array(
				'label' => __( 'Address', 'oxysuppliers-for-woocommerce' ),
				'type'  => 'text',
			),
			'postcode'        => array(
				'label' => __( 'Postcode', 'oxysuppliers-for-woocommerce' ),
				'type'  => 'text',
			),
			'city'            => array(
				'label' => __( 'City', 'oxysuppliers-for-woocommerce' ),
				'type'  => 'text',
			),
			'state'           => array(
				'label' => __( 'Province or state', 'oxysuppliers-for-woocommerce' ),
				'type'  => 'text',
			),
			'country'         => array(
				'label'   => __( 'Country', 'oxysuppliers-for-woocommerce' ),
				'type'    => 'select',
				'options' => $countries,
			),
			'contact_name'    => array(
				'label' => __( 'Contact', 'oxysuppliers-for-woocommerce' ),
				'type'  => 'text',
			),
			'order_email'     => array(
				'label' => __( 'Orders email', 'oxysuppliers-for-woocommerce' ),
				'type'  => 'text',
				'help'  => __( 'Where purchase orders are sent.', 'oxysuppliers-for-woocommerce' ),
			),
			'billing_email'   => array(
				'label' => __( 'Accounts email', 'oxysuppliers-for-woocommerce' ),
				'type'  => 'text',
			),
			'phone'           => array(
				'label' => __( 'Phone', 'oxysuppliers-for-woocommerce' ),
				'type'  => 'text',
			),
			'website'         => array(
				'label' => __( 'Website', 'oxysuppliers-for-woocommerce' ),
				'type'  => 'text',
				'help'  => __( 'Starting with http:// or https://.', 'oxysuppliers-for-woocommerce' ),
			),
			'currency'        => array(
				'label'   => __( 'Currency', 'oxysuppliers-for-woocommerce' ),
				'type'    => 'select',
				'options' => get_woocommerce_currencies(),
				'help'    => __( 'The currency this supplier prices in.', 'oxysuppliers-for-woocommerce' ),
			),
			'payment_terms'   => array(
				'label' => __( 'Payment terms', 'oxysuppliers-for-woocommerce' ),
				'type'  => 'text',
			),
			'lead_time_days'  => array(
				'label' => __( 'Lead time (days)', 'oxysuppliers-for-woocommerce' ),
				'type'  => 'number',
				'help'  => __( 'Usual number of days between ordering and delivery.', 'oxysuppliers-for-woocommerce' ),
			),
			'min_order_value' => array(
				'label' => __( 'Minimum order value', 'oxysuppliers-for-woocommerce' ),
				'type'  => 'text',
				'help'  => __( 'Leave empty when there is no minimum.', 'oxysuppliers-for-woocommerce' ),
			),
			'notes'           => array(
				'label' => __( 'Notes', 'oxysuppliers-for-woocommerce' ),
				'type'  => 'textarea',
			),
		);
	}

	/**
	 * What to tell somebody about a failed field.
	 *
	 * The domain returns codes; the sentences live here, where translation is
	 * available and the field's own label is known.
	 *
	 * @param string              $code  Error code from the validator.
	 * @param array<string,mixed> $field Field definition.
	 * @return string
	 */
	private function error_message( string $code, array $field ): string {
		$label = (string) ( $field['label'] ?? '' );

		switch ( $code ) {
			case SupplierValidator::REQUIRED:
				/* translators: %s: field label. */
				return sprintf( __( '%s is required.', 'oxysuppliers-for-woocommerce' ), $label );

			case SupplierValidator::TOO_LONG:
				return __( 'This is longer than the field can hold.', 'oxysuppliers-for-woocommerce' );

			case SupplierValidator::INVALID_EMAIL:
				return __( 'This does not look like an email address.', 'oxysuppliers-for-woocommerce' );

			case SupplierValidator::INVALID_URL:
				return __( 'This must be a web address starting with http:// or https://.', 'oxysuppliers-for-woocommerce' );

			case SupplierValidator::INVALID_COUNTRY:
				return __( 'Please choose a country from the list.', 'oxysuppliers-for-woocommerce' );

			case SupplierValidator::INVALID_CURRENCY:
				return __( 'Please choose a currency from the list.', 'oxysuppliers-for-woocommerce' );

			case SupplierValidator::INVALID_NUMBER:
				return __( 'Please enter a number.', 'oxysuppliers-for-woocommerce' );

			default:
				return __( 'This value cannot be used.', 'oxysuppliers-for-woocommerce' );
		}
	}

	/**
	 * Keep a failed submission for the redirect that follows.
	 *
	 * A transient, keyed on the user, rather than the query string: what somebody
	 * typed about a supplier should not end up in a browser history or a server
	 * log.
	 *
	 * @param array<string,string> $values What was typed.
	 * @param array<string,string> $errors What was wrong with it.
	 * @return void
	 */
	private function remember_form_state( array $values, array $errors ): void {
		set_transient(
			$this->form_state_key(),
			array(
				'values' => $values,
				'errors' => $errors,
			),
			MINUTE_IN_SECONDS * 5
		);
	}

	/**
	 * Read and forget the last failed submission.
	 *
	 * @return array{values:array<string,string>,errors:array<string,string>}
	 */
	private function take_form_state(): array {
		$state = get_transient( $this->form_state_key() );

		delete_transient( $this->form_state_key() );

		if ( ! is_array( $state ) ) {
			return array(
				'values' => array(),
				'errors' => array(),
			);
		}

		return array(
			'values' => is_array( $state['values'] ?? null ) ? $state['values'] : array(),
			'errors' => is_array( $state['errors'] ?? null ) ? $state['errors'] : array(),
		);
	}

	/**
	 * Where a failed submission is kept.
	 *
	 * @return string
	 */
	private function form_state_key(): string {
		return 'oxysuppliers_supplier_form_' . get_current_user_id();
	}

	/**
	 * A nonce-carrying address for a row action.
	 *
	 * @param string $action Action name.
	 * @param int    $id     Supplier id.
	 * @return string
	 */
	private function action_url( string $action, int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => $action,
					'id'     => $id,
				),
				admin_url( 'admin-post.php' )
			),
			$action . '_' . $id
		);
	}

	/**
	 * Go back to the list with something to say.
	 *
	 * @param string $notice Notice key.
	 * @return never
	 */
	private function redirect_to_list( string $notice ) {
		wp_safe_redirect( Menu::url( self::SLUG, array( 'notice' => $notice ) ) );
		exit;
	}

	/**
	 * Show whatever the last action left behind.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Displays a fixed message chosen from a list; changes nothing.
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'created'       => array( 'success', __( 'Supplier added.', 'oxysuppliers-for-woocommerce' ) ),
			'updated'       => array( 'success', __( 'Supplier saved.', 'oxysuppliers-for-woocommerce' ) ),
			'deleted'       => array( 'success', __( 'Supplier deleted.', 'oxysuppliers-for-woocommerce' ) ),
			'activated'     => array( 'success', __( 'Supplier activated.', 'oxysuppliers-for-woocommerce' ) ),
			'deactivated'   => array( 'success', __( 'Supplier deactivated.', 'oxysuppliers-for-woocommerce' ) ),
			'missing'       => array( 'error', __( 'That supplier no longer exists.', 'oxysuppliers-for-woocommerce' ) ),
			'not_deletable' => array( 'error', __( 'That supplier is named on purchase orders and cannot be deleted. Deactivate them instead.', 'oxysuppliers-for-woocommerce' ) ),
			'error'         => array( 'error', __( 'The supplier could not be saved. Nothing was changed.', 'oxysuppliers-for-woocommerce' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}
}
