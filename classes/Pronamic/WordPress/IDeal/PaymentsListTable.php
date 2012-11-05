<?php

/**
 * Title: Payments list table
 * Description: 
 * Copyright: Copyright (c) 2005 - 2011
 * Company: Pronamic
 * @author Remco Tolsma
 * @version 1.0
 */
class Pronamic_WordPress_IDeal_PaymentsListTable extends WP_List_Table {
	/**
	 * Constructs and initializes an payments list table
	 */
	public function __construct() {
		parent::__construct( array(
			'plural' => 'payments'
		) );
	}

	/**
	 * Checks the current user's permissions
	 */
	function ajax_user_can() {
		return current_user_can( 'manage_ideal_payments' );
	}

	/**
	 * Prepares the list of items for displaying
	 */
	function prepare_items() {
		global $orderby, $order;

		$per_page = $this->get_items_per_page( 'payments_per_page' );

		$paged = $this->get_pagenum();

		$total_items = Pronamic_WordPress_IDeal_PaymentsRepository::getNumberPayments();

		$this->set_pagination_args( array(
			'total_items' =>  $total_items,
			'per_page'    => $per_page
		) );

		$args = array(
			'number' => $per_page,
			'offset' => ($paged - 1) * $per_page
		);

		if ( isset( $_REQUEST['orderby'] ) ) {
			$args['orderby'] = $_REQUEST['orderby'];
		}

		if ( isset( $_REQUEST['order'] ) ) {
			$args['order'] = $_REQUEST['order'];
		}

		if ( isset($_REQUEST['s'] ) ) {
			$args['s'] = $_REQUEST['s'];
		}

		$this->items = Pronamic_WordPress_IDeal_PaymentsRepository::getPayments( $args );
	}

	/**
	 * Message to be displayed when there are no items
	 */
	function no_items() {
		_e( 'No payments found.', 'pronamic_ideal' );
	}

	/**
	 * Get a list of columns
	 */
	function get_columns() {
		return array(
			'cb'             => '<input type="checkbox" />',
			'date'           => __( 'Date', 'pronamic_ideal' ),
			'transaction_id' => __( 'Transaction ID', 'pronamic_ideal' ),
			'purchase_id'    => __( 'Purchase ID', 'pronamic_ideal' ),
			'description'    => __( 'Description', 'pronamic_ideal' ),
			'consumer'       => __( 'Consumer', 'pronamic_ideal' ),
			'amount'         => __( 'Amount', 'pronamic_ideal' ),
			'source'         => __( 'Source', 'pronamic_ideal' ),
			'status'         => __( 'Status', 'pronamic_ideal' )
		);
	}

	/**
	 * Get a list of sortable columns
	 */
	function get_sortable_columns() {
		return array(
			'date'   => 'date_gmt',
			'amount' => 'amount',
			'status' => 'status'
		);
	}

	/**
	 * Generate the table rows
	 */
	function display_rows() {
		global $cat_id;

		$alt = 0;

		foreach ( $this->items as $payment ): ?>

		<tr id="payment-<?php echo $payment->getId(); ?>" valign="middle">
			<?php

			list( $columns, $hidden ) = $this->get_column_info();
			
			// Link
			$detailsLink = Pronamic_WordPress_IDeal_Admin::getPaymentDetailsLink( $payment->getId() );

			// Date
			$date = $payment->getDate();

			$timezone = get_option( 'timezone_string' );
			if($timezone) {
				$date = clone $date;
				$date->setTimezone( new DateTimeZone( $timezone ) );
			}

			// Transaction
			$transaction = $payment->transaction;

			// Iterate through the columns
			foreach ( $columns as $column_name => $column_display_name ) {
				$class = "class='column-$column_name'";

				$style = '';
				if ( in_array( $column_name, $hidden ) )
					$style = ' style="display:none;"';

				$attributes = $class . $style;

				switch ( $column_name ) {
					case 'cb':
						echo '<th scope="row" class="check-column"><input type="checkbox" name="linkcheck[]" value="'. esc_attr( $payment->getId() ) .'" /></th>';
						break;
					case 'date':
						?>
						<td <?php echo $attributes ?>>
							<a href="<?php echo $detailsLink; ?>" title="<?php _e( 'Details', 'pronamic_ideal' ); ?>">
								<?php echo $date->format('d-m-Y @ H:i'); ?> 
							</a>
						</td>
						<?php
						break;
					case 'transaction_id':
						?>
						<td <?php echo $attributes ?>>
							<a href="<?php echo $detailsLink; ?>" title="<?php _e( 'Details', 'pronamic_ideal' ); ?>">
								<?php echo $transaction->getId(); ?> 
							</a>
						</td>
						<?php
						break;
					case 'purchase_id':
						?>
						<td <?php echo $attributes ?>>
							<?php echo $transaction->getPurchaseId(); ?> 
						</td>
						<?php
						break;
					case 'description':
						?>
						<td <?php echo $attributes ?>>
							<?php echo $transaction->getDescription(); ?>
						</td>
						<?php
						break;
					case 'consumer':
						?>
						<td <?php echo $attributes ?>>
							<?php echo $transaction->getConsumerName(); ?><br />
							<?php echo $transaction->getConsumerAccountNumber(); ?><br />
							<?php echo $transaction->getConsumerCity(); ?>
						</td>
						<?php
						break;
					case 'amount':
						?>
						<td <?php echo $attributes ?>>
							<?php echo $transaction->getAmount(); ?>
							<?php echo $transaction->getCurrency(); ?>
						</td>
						<?php
						break;
					case 'source':
						?>
						<td <?php echo $attributes ?>>
							<?php 
							
							$text = $payment->getSource() . '<br />' . $payment->getSourceId();
							$text = apply_filters( 'pronamic_ideal_source_column_' . $payment->getSource(), $text, $payment );
							$text = apply_filters( 'pronamic_ideal_source_column', $text, $payment );
							
							echo $text;
							
							?>
						</td>
						<?php
						break;
					case 'status':
						?>
						<td <?php echo $attributes ?>>
							<?php echo Pronamic_WordPress_IDeal_IDeal::translateStatus( $transaction->getStatus() ); ?>
						</td>
						<?php
						break;
					default:
						?>
						<td <?php echo $attributes ?>><?php do_action( 'manage_payment_custom_column', $column_name, $payment->getId() ); ?></td>
						<?php
						break;
				}
			}

			?>
		</tr>
		
		<?php endforeach; 
	}
}
