<?php
/*
Plugin Name: Easy Digital Downloads - CCAvenue Payment Gateway
Plugin URL: https://wpgateways.com/products/ccavenue-gateway-easy-digital-downloads/
Description: Extends Easy Digital Downloads to process payments with CCAvenue payment gateway
Version: 1.0.1
Author: WP Gateways
Author URI: https://wpgateways.com
*/

require_once( 'Crypto.php' );
require_once( 'updates/updates.php' );

class EDD_CCAvenue {

	private $id;
	private $name;
	private $_live_url;
	private $_test_url;
	protected static $instance = null;

	function __construct() {
		$this->id = 'ccavenue';
		$this->name = __( 'CCAvenue', 'edd-ccavenue' );
		$this->_live_url = 'https://secure.ccavenue.com/transaction/transaction.do?command=initiateTransaction';
		$this->_test_url = 'https://test.ccavenue.com/transaction/transaction.do?command=initiateTransaction';
	}

	function init() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'check_response' ) );
		add_filter( 'edd_payment_gateways', array( $this, 'register_gateway' ) );
		add_action( 'edd_gateway_' . $this->id, array( $this, 'process_payment' ) );
		add_filter( 'edd_settings_sections_gateways', array( $this, 'settings_section' ) );
		add_filter( 'edd_settings_gateways', array( $this, 'add_settings' ) );
		add_action( 'edd_' . $this->id . '_cc_form', '__return_false' );
		add_action( 'edd_ccavenue_process_return', array( $this, 'process_return' ) );
	}

	function load_textdomain() {
		load_plugin_textdomain( 'edd-ccavenue', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	public static function get_instance() {

		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	function get_id() {
		return $this->id;
	}

	function register_gateway( $gateways ) {
		// Format: ID => Name
		$gateways[$this->id] = array( 'admin_label' => $this->name, 'checkout_label' => edd_get_option( $this->id . '_checkout_label' ) );
		return $gateways;
	}

	function process_payment( $purchase_data ) {

		if( ! wp_verify_nonce( $purchase_data['gateway_nonce'], 'edd-gateway' ) ) {
			wp_die( __( 'Nonce verification has failed', 'easy-digital-downloads' ), __( 'Error', 'easy-digital-downloads' ), array( 'response' => 403 ) );
		}

		// Collect payment data
		$payment_data = array(
			'price'         => $purchase_data['price'],
			'date'          => $purchase_data['date'],
			'user_email'    => $purchase_data['user_email'],
			'purchase_key'  => $purchase_data['purchase_key'],
			'currency'      => edd_get_currency(),
			'downloads'     => $purchase_data['downloads'],
			'user_info'     => $purchase_data['user_info'],
			'cart_details'  => $purchase_data['cart_details'],
			'gateway'       => 'ccavenue',
			'status'        => 'pending'
		);

		// record the pending payment
		$payment = edd_insert_payment( $payment_data );

		// Check payment
		if ( ! $payment ) {
			// Record the error
			edd_record_gateway_error( __( 'Payment Error', 'easy-digital-downloads' ), sprintf( __( 'Payment creation failed before sending buyer to CCAvenue. Payment data: %s', 'edd-ccavenue' ), json_encode( $payment_data ) ), $payment );
			// Problems? send back
			edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
		} else {

			// Set the session data to recover this payment in the event of abandonment or error.
			EDD()->session->set( 'edd_resume_payment', $payment );

			// only send to CCAvenue if the pending payment is created successfully
						// Get the success url
			$return_url = add_query_arg( array(
				'payment-id' => $payment,
				'payment-confirmation' => 'ccavenue',
			), get_permalink( edd_get_option( 'success_page', false ) ) );

			$post_url = edd_is_test_mode() ? $this->_test_url : $this->_live_url;

			$gateway_args = array(
				'language' => 'EN',
				'merchant_id' => edd_get_option( 'ccavenue_merchant_id' ),
				'order_id' => $payment,
				'amount' => $purchase_data['price'],
				'currency' => edd_get_currency(),
				'merchant_param5' => $purchase_data['purchase_key'],
				'redirect_url' => $return_url,
				'cancel_url' => edd_get_failed_transaction_uri( '?payment-id=' . $payment ),
				'billing_email' => $purchase_data['user_info']['email'],
				'billing_name' => $purchase_data['user_info']['first_name'] . ' ' . $purchase_data['user_info']['last_name'],
			);
			if ( ! empty( $purchase_data['user_info']['address'] ) ) {
				$gateway_args['billing_address'] = $purchase_data['user_info']['address']['line1'] . ' ' . $purchase_data['user_info']['address']['line2'];
				$gateway_args['billing_city']     = $purchase_data['user_info']['address']['city'];
				$gateway_args['billing_country']  = $purchase_data['user_info']['address']['country'];
			}

			$sep = $post_str = '';
			foreach( $gateway_args as $key => $value ) {
				$post_str .= $sep . $key . '=' . $value;
				$sep = '&';
			}

			$encrypted_data = ccavenue_encrypt( $post_str, edd_get_option( 'ccavenue_working_key' ) );

			$array = array(
				'encRequest' => $encrypted_data,
				'access_code' => edd_get_option( 'ccavenue_access_code' ),
			);
			?>
			<form action="<?php echo $post_url; ?>" method="post" name="ccavenue">
				<?php $this->print_hidden_fields( $array ); ?>
			</form>
			<script data-cfasync="false">
				var ccavenueForm = document.forms.ccavenue;
				ccavenueForm.submit();
			</script>
			<?php
			exit;

		}

	}

	private function print_hidden_fields( $data ) {
		foreach( $data as $key => $value ) { ?>
			<input type="hidden" name="<?php echo $key; ?>" value="<?php echo esc_attr( $value ); ?>" />
		<?php }
	}

	function check_response() {
		// Regular CCAvenue IPN
		if ( isset( $_POST['orderNo'] ) && isset( $_GET['payment-id'] ) ) {
			do_action( 'edd_ccavenue_process_return' );
		}
	}

	function process_return() {
		// Check the request method is POST
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] != 'POST' ) {
			return;
		}

		$response_string = ccavenue_decrypt( $_POST['encResp'], edd_get_option( 'ccavenue_working_key' ) );
		parse_str( $response_string, $response );

		$payment_id = $response['order_id'];

		$payment = new EDD_Payment( $payment_id );
		if ( $payment->gateway != $this->id ) {
			return; // this isn't a CCAvenue callback
		}

		$error = false;

		if ( !isset( $response['merchant_param5'] )  || $response['merchant_param5'] != edd_get_payment_key( $payment_id ) ) {
			edd_record_gateway_error( __( 'Callback Error', 'edd-ccavenue' ), sprintf( __( 'Callback request invalid. Aborting. Response data: %s', 'edd-ccavenue' ), json_encode( $response ) ), $payment_id );
			edd_update_payment_status( $payment_id, 'failed' );
			edd_insert_payment_note( $payment_id, __( 'Invalid callback response.', 'edd-ccavenue' ) );
			$error = true;
		}

		if ( !isset( $response['currency'] )  || strtoupper( $payment->currency  != strtoupper( $response['currency'] ) ) ) {
			edd_record_gateway_error( __( 'Callback Error', 'edd-ccavenue' ), sprintf( __( 'Invalid currency in response. Response data: %s', 'edd-ccavenue' ), json_encode( $response ) ), $payment_id );
			edd_update_payment_status( $payment_id, 'failed' );
			edd_insert_payment_note( $payment_id, __( 'Invalid currency in CCavenue response.', 'edd-ccavenue' ) );
			$error = true;
		}

		// Retrieve the total purchase amount (before CCAvenue)
		$payment_amount = edd_get_payment_amount( $payment_id );

		if ( number_format( (float) $response['amount'], 2 ) != number_format( (float) $payment_amount, 2 ) ) {
			// The prices don't match
			edd_record_gateway_error( __( 'Callback Error', 'edd-ccavenue' ), sprintf( __( 'Invalid payment amount in response. Response data: %s', 'edd-ccavenue' ), json_encode( $response ) ), $payment_id );
			edd_update_payment_status( $payment_id, 'failed' );
			edd_insert_payment_note( $payment_id, __( 'Invalid amount in CCavenue response.', 'edd-ccavenue' ) );
			$error = true;
		}

		$return_url = edd_get_failed_transaction_uri( '?payment-id=' . $payment_id );

		if ( !$error && 'success' == strtolower( $response['order_status'] ) ) {
			edd_insert_payment_note( $payment_id, sprintf( __( 'CCAvenue Transaction ID: %s', 'edd-ccavenue' ), $response['tracking_id'] ) );
			edd_set_payment_transaction_id( $payment_id, $response['tracking_id'] );
			edd_update_payment_status( $payment_id, 'publish' );
			$return_url = add_query_arg( array(
				'payment-confirmation' => 'ccavenue',
				'payment-id' => $payment_id
			), get_permalink( edd_get_option( 'success_page', false ) ) );

		} elseif ( !$error && 'failure' == strtolower( $response['order_status'] ) ) {
			edd_update_payment_status( $payment_id, 'failed' );
			edd_insert_payment_note( $payment_id, __( 'Payment failed.', 'edd-ccavenue' ) );

		} elseif ( !$error && 'aborted' == strtolower( $response['order_status'] ) ) {
			edd_update_payment_status( $payment_id, 'failed' );
			edd_insert_payment_note( $payment_id, __( 'Payment Aborted by user.', 'edd-ccavenue' ) );
		}

		wp_redirect( $return_url );
		exit;

	}


	function settings_section( $sections ) {

		$sections[$this->id] = $this->name;

		return $sections;
	}

	// adds the settings to the Payment Gateways section
	function add_settings( $settings ) {

		$edda_settings = array(
			$this->id => array(
				array(
					'id' => $this->id . '_settings',
					'name' => '<strong>' . __( 'CCAvenue Settings', 'edd-ccavenue' ) . '</strong>',
					'type' => 'header'
				),
				array(
					'id' => $this->id . '_checkout_label',
					'name' => __( 'Checkout Label', 'edd-ccavenue' ),
					'desc' =>__( 'Name of the processor as it appears on the checkout.', 'edd-ccavenue' ),
					'type' => 'text',
				),
				array(
					'id' => $this->id . '_merchant_id',
					'name' => __( 'Merchant ID', 'edd-ccavenue' ),
					'desc' => __( 'Enter the merchant ID provided by CCAvenue.', 'edd-ccavenue' ),
					'type' => 'text'
				),
				array(
					'id' => $this->id . '_access_code',
					'name' => __( 'Access Code', 'edd-ccavenue' ),
					'desc' => __( 'Enter the access code provided by CCAvenue. The domain for the access code must match the domain of your WordPress website.', 'edd-ccavenue' ),
					'type' => 'password'
				),
				array(
					'id' => $this->id . '_working_key',
					'name' => __( 'Working Key', 'edd-ccavenue' ),
					'desc' => __( 'Enter the working key provided by CCAvenue.  The domain for the working key must match the domain of your WordPress website.', 'edd-ccavenue' ),
					'type' => 'password'
				)
			)
		);

		return array_merge( $settings, $edda_settings );
	}

	public function get_settings() {
		$prefix = $this->get_id();
		return array(
			$prefix . '_checkout_label',
			$prefix . '_merchant_id',
			$prefix . '_access_code',
			$prefix . '_working_key'
		);
	}

	public static function activate() {
		$instance = self::get_instance();
		$option = $instance->get_id() . '_checkout_label';
		if( ! edd_get_option( $option ) ) {
			edd_update_option( $option, __( 'Credit/Debit Card, Netbabking, UPI or Wallet', 'edd-ccavenue' ) );
		}
	}

	public static function uninstall() {
		global $edd_options;
		$instance = self::get_instance();
		$settings = $instance->get_settings();

		foreach( $settings as $key ) {
			if( isset( $edd_options[$key] ) ) {
				unset( $edd_options[$key] );
			}
			edd_delete_option( $key );
		}
	}

}
register_activation_hook( __FILE__, array( 'EDD_CCAvenue', 'activate' ) );
register_uninstall_hook( __FILE__, array( 'EDD_CCAvenue', 'uninstall' ) );
$e_instance = EDD_CCAvenue::get_instance();
$e_instance->init();