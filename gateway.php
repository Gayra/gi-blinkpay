<?php
/*
Plugin Name: Woocommerce Blink Payment Gateway
Plugin URI: https://cod-ed.com
Description: Allows use of BlinkPay payment processor - https://www.blinkpay.co.ug.
Version: 1.0.0
Author: Gayra Ivan
Author URI: https://cod-ed.com
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html
Requires at least: 3.3
Tested up to: 5.3.2
WC requires at least: 3.0.0
WC tested up to: 3.2.6

Copyright 2020  Gayra Ivan  (email : givan9000@gmail.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 3, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USAv
 */
 
defined ( 'ABSPATH' ) or exit;

// Check for woocommerce
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	// Hooks for adding/ removing the database table, and the wpcron to check them
	register_activation_hook( __FILE__, 'create_background_check' );
	register_deactivation_hook( __FILE__, 'remove_background_check' );
	register_uninstall_hook( __FILE__, 'on_uninstalling' );
	
	// cron interval for ever 10 seconds
	add_filter( 'cron_schedules', 'oneminute_cron_definer' );
	
	function oneminute_cron_definer( $schedules )
	{
		$schedules['oneminute'] = array(
		'interval' => 60,
		'display' => esc_html__( 'Once Every 1 minute' ),
		);
		return $schedules;
	}

    /**
     * Activation, create processing order table, and table version option
     * @return void
     */
    function create_background_check()
    {
        // Wp_cron checks pending payments in the background
        wp_schedule_event( time(), 'oneminute', 'blink_payment_checks' );

        //Get the table name with the WP database prefix
        global $wpdb;
        $db_version = "1.0";
        $table_name = $wpdb->prefix . "blink_queue";
		
		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
		order_id mediumint(9) NOT NULL,
		reference_code varchar(50) NOT NULL,
		time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		PRIMARY KEY (order_id, reference_code) 
		);";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        add_option( 'blink_db_version', $db_version );
    }

    function remove_background_check()
    {
        $next_sheduled = wp_next_scheduled( 'blink_payment_checks' );
        wp_unschedule_event( $next_sheduled, 'blink_payment_checks' );
    }

    /**
     * Clean up table and options on uninstall
     * @return [type] [description]
     */
    function on_uninstalling()
    {
        // Clean up i.e. delete the table, wp_cron already removed on deacivate
        delete_option( 'blink_db_version' );

        global $wpdb;

        $table_name = $wpdb->prefix . "blink_queue";

        $wpdb->query( "DROP TABLE IF EXISTS $table_name" );
    }
	
	/**
 * Adds plugin page links
 * 
 * @since 1.0.0
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */
	function wc_blink_gateway_plugin_links( $links ) {
		$plugin_links = array( '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=blink' ) . '">' . __( 'Configure', 'woocommerce' ) . '</a>' );
		return array_merge( $plugin_links, $links );
	}
	
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_blink_gateway_plugin_links' );
	/**
	* Blink Payment Gateway
	*
	* Provides a Blink Payment Gateway; mainly for mobile money payments.
	* We load it later to ensure WC is loaded first since we're extending it.
	*
	* @class 		WC_Blink_Gateway
	* @extends		WC_Payment_Gateway
	* @version		1.0.0
	* @package		WooCommerce/Classes/Payment
	* @author 		Gayra Ivan
	*/

    add_action( 'plugins_loaded', 'init_woo_blink_gateway', 0 );

    function init_woo_blink_gateway()
    {
		
		if ( ! defined( 'ABSPATH' ) ) { exit; }

        /**
		* WC_Blink_Gateway Class.
		*/
		class WC_Blink_Gateway extends WC_Payment_Gateway
        {

            function __construct()
            {
                global $woocommerce;
                $this->id = 'blink';
                $this->method_title = __( 'BlinkPay', 'woocommerce' );
				$this->order_button_text = __( 'Proceed to BlinkPay', 'woocommerce' );
                $this->has_fields = false;
				$this->method_description = __( 'Allows mobile money payments. Very handy if you want payments via mobile money.', 'woocommerce' );
                $this->testmode = ( $this->get_option( 'testmode' ) === 'yes' ) ? true : false;
                $this->debug = $this->get_option( 'debug' );
				$this->supports = array( 'products' );

                // Logs
                if ( 'yes' == $this->debug ) {
                    if ( class_exists( 'WC_Logger' ) ) {
                        $this->log = new WC_Logger();
                    } else {
                        $this->log = $woocommerce->logger();
                    }

                }

                if ( $this->testmode ) {
                    $api = 'https://payments-dev.blink.co.ug/api/';
                    $this->username = $this->get_option( 'testusername' );
                    $this->password = $this->get_option( 'testpassword' );
                } else {
                    $api = 'https://payments.blink.co.ug/api/';
                    $this->username = $this->get_option( 'username' );
                    $this->password = $this->get_option( 'password' );
                }

                // Gateway payment URLs
                $this->gatewayURL = $api;
				$this->queryNetworkApi = 'checknetworkstatus';
                $this->queryPaymentApi = 'depositmobilemoney';
				$this->queryRefundApi = 'withdrawmobilemoney';
           
				// Notification URL
                $this->notify_url = home_url( '/' ).'wc-api/wc_blink_gateway/';
				
                $this->init_form_fields();
                $this->init_settings();

                // Settings
                $this->title = $this->get_option( 'title' );
                $this->description = $this->get_option( 'description' );
                $this->ipn = ( $this->get_option( 'ipn' ) === 'yes' ) ? true : false;

                // Actions
                add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
                add_action( 'woocommerce_receipt_blink', array( &$this, 'payment_page' ) );
                add_action( 'woocommerce_thankyou_blink', array( &$this, 'thankyou_page' ) );

            }
			/**
			* Get Blink gateway icon.
			*
			* @return string
			*/
			public function get_icon() {
				
				$icon_html = '';
				$icon_html .= '<img src="'. plugins_url( 'assets/images/blink.png' , __FILE__ ) .'" alt="' . esc_attr__( 'Blink acceptance mark', 'woocommerce' ) . '" />';
				$icon_html .= sprintf( '<a href="https://blinkpay.co.ug" class="about_blink" >' . esc_attr__( 'What is BlinkPay?', 'woocommerce' ) . '</a>' );
				
				return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );
				}

            function init_form_fields()
            {
                $this->form_fields = array(
                    'enabled' => array(
                        'title' => __( 'Enable/Disable', 'woothemes' ),
                        'type' => 'checkbox',
                        'label' => __( 'Enable Blink Payments', 'woothemes' ),
                        'default' => 'no',
                    ),
                    'title' => array(
                        'title' => __( 'Title', 'woothemes' ),
                        'type' => 'text',
                        'description' => __( 'This controls the title which the user sees during checkout.', 'woothemes' ),
                        'default' => __( 'Blink Payments', 'woothemes' ),
                    ),
                    'description' => array(
                        'title' => __( 'Description', 'woocommerce' ),
                        'type' => 'textarea',
                        'description' => __( 'This is the description which the user sees during checkout.', 'woocommerce' ),
                        'default' => __( 'Payment via BlinkPay Gateway, you can pay by a mobile money option such as MTN or Airtel.', 'woocommerce' ),
                    ),
                    'ipn' => array(
                        'title' => __( 'Use IPN', 'woothemes' ),
                        'type' => 'checkbox',
                        'label' => __( 'Use IPN', 'woothemes' ),
                        'description' => __( 'Blink has the ability to send your site an Instant Payment Notification whenever there is an order update. It is highly reccomended that you enable this, as there are some issues with the "background" status checking. It is disabled by default because the IPN URL needs to be entered in the blink control panel.', 'woothemes' ),
                        'default' => 'no',
                    ),
                    'ipnurl' => array(
                        'title' => __( 'IPN URL', 'woothemes' ),
                        'type' => 'text',
                        'description' => __( 'This is the IPN URL that you must enter in the Blink control panel. (You should not edit this URL)', 'woothemes' ),
                        'default' => $this->notify_url,
                    ),
                    'username' => array(
                        'title' => __( 'Blink Username', 'woothemes' ),
                        'type' => 'text',
                        'description' => __( 'Your Blink Username which should have been emailed to you.', 'woothemes' ),
                        'default' => '',
                    ),
                    'password' => array(
                        'title' => __( 'Blink Password', 'woothemes' ),
                        'type' => 'text',
                        'description' => __( 'Your Blink Password which should have been emailed to you.', 'woothemes' ),
                        'default' => '',
                    ),
                    'testmode' => array(
                        'title' => __( 'Use Demo Gateway', 'woothemes' ),
                        'type' => 'checkbox',
                        'label' => __( 'Use Demo Gateway', 'woothemes' ),
                        'description' => __( 'Use demo blink gateway for testing.', 'woothemes' ),
                        'default' => 'no',
                    ),
                    'testusername' => array(
                        'title' => __( 'Blink Demo Username', 'woothemes' ),
                        'type' => 'text',
                        'description' => __( 'Your demo Blink Username which can be seen at payments-dev.blink.co.ug.', 'woothemes' ),
                        'default' => '',
                    ),
					'testpassword' => array(
                        'title' => __( 'Blink Demo Password', 'woothemes' ),
                        'type' => 'text',
                        'description' => __( 'Your demo Blink Password which can be seen at payments-dev.blink.co.ug.', 'woothemes' ),
                        'default' => '',
                    ),
                    'debug' => array(
                        'title' => __( 'Debug Log', 'woocommerce' ),
                        'type' => 'checkbox',
                        'label' => __( 'Enable logging', 'woocommerce' ),
                        'default' => 'no',
                        'description' => sprintf(__( 'Log Blink events, such as IPN requests, inside <code>woocommerce/logs/blink-%s.txt</code>', 'woocommerce' ), sanitize_file_name( wp_hash( 'blink' ) ) ),
                    ),
 
				);
            }

            public function admin_options()
            { 
			?>
				<h3><?php _e( 'Blink Payment API', 'woothemes' );?></h3>
				<p>
					<?php _e( 'Allows use of the Blink Payment Gateway, all you need is an account at www.blinkpay.co.ug and your username and password.<br />', 'woothemes' );?>
					<?php _e( '<a href="http://docs.woothemes.com/document/managing-orders/">Click here </a> to learn about the various woocommerce Payment statuses.<br /><br />', 'woothemes' );?>
					<?php _e( '<strong>Developer: </strong>Gayra Ivan<br />', 'woothemes' );?>
					<?php _e( '<strong>Contributors: </strong>Blink Systems<br />', 'woothemes' );?>
				</p>
				<table class="form-table">
				<?php
					// Generate the HTML For the settings form.
					$this->generate_settings_html();
                ?>
				</table>
				<script type="text/javascript">
					jQuery( function(){
						var testMode = jQuery( "#woocommerce_blink_testmode" );
						var ipn = jQuery( "#woocommerce_blink_ipn" );
						var ipnurl = jQuery( "#woocommerce_blink_ipnurl" );
						var username = jQuery( "#woocommerce_blink_testusername" );
						var password = jQuery( "#woocommerce_blink_testpassword" );

						if ( testMode.is( ":not( :checked )" ) ){
							username.parents( "tr" ).css( "display","none" );
							password.parents( "tr" ).css( "display","none" );
						}
						
						if ( ipn.is( ":not( :checked )" ) ){
							ipnurl.parents( "tr" ).css( "display","none" );
						}

						// Add onclick handler to checkbox w/id checkme
						testMode.click( function(){
							// If checked
							if ( testMode.is( ":checked" ) ) {
								//show the hidden div
								username.parents( "tr" ).show( "fast" );
								password.parents( "tr" ).show( "fast" );
							} else {
								//otherwise, hide it
								username.parents( "tr" ).hide( "fast" );
								password.parents( "tr" ).hide( "fast" );
							}
						});
						
						ipn.click( function(){
							// If checked
							if ( ipn.is( ":checked" ) ) {
								//show the hidden div
								ipnurl.parents( "tr" ).show( "fast" );
							} else {
								//otherwise, hide it
								ipnurl.parents( "tr" ).hide( "fast" );
							}
						});
					});
				</script>
			<?php 
			} // End admin_options()

            /**
             * Thank You Page
             *
             * @param Integer $order_id
             * @return void
             * @author Gayra Ivan
             **/
            public function thankyou_page( $order_id )
            {
				global $wpdb;
                $table_name = $wpdb->prefix . 'blink_queue';

                $check = $wpdb->get_row( "SELECT order_id, reference_code FROM $table_name WHERE order_id = $order_id" );
                    
				// Setup request to send json via POST
				$url2 = $this->gatewayURL;
				 
				// Create a new cURL resource
				$cURL = curl_init( $url2 );
				 
				$checkdata = array(
				'username' => $this->username,
				'password' => $this->password,
				'api' => 'checktransactionstatus',
				'reference_code' => $check->reference_code,
				);
				
				$checkstatus = json_encode( $checkdata );
				curl_setopt( $cURL, CURLOPT_POST, 1 );
				 
				// Attach encoded JSON string to the POST fields
				curl_setopt( $cURL, CURLOPT_POSTFIELDS, $checkstatus );
				 
				// Set the content type to application/json
				curl_setopt( $cURL, CURLOPT_HTTPHEADER, array( "Content-Type:application/json","Accept: application/json" ) );
				 
				// Return response instead of outputting
				curl_setopt( $cURL, CURLOPT_RETURNTRANSFER, true );
				 
				//curl_setopt($ch, CURLOPT_TIMEOUT, 20);
				 
				$result2 = curl_exec( $cURL );
				 
				if ( !curl_errno( $cURL ) ) {
					//check the response if it's still "PENDING" or "SUCCESSFUL" or "FAILED"
					 
					curl_close( $cURL );
					$json2 = json_decode( $result2, true);
					$status = $json2['status'];
				}

                if ( $status === 'PENDING' ) {

                    $order = wc_get_order( $order_id );
                  
                    $order->add_order_note( __( 'Payment accepted, awaiting confirmation from the gateway.', 'woothemes' ) );
                   
                // If immeadiatly complete, mark it so
				} elseif ( $status === 'SUCCESSFUL' ) {
                        $order->add_order_note( __( 'Payment confirmed.', 'woothemes' ) );
                        $order->payment_complete();
				} else {
					exit;
                }
			}

            /**
             * Proccess payment
             *
             * @param Integer $order_id
             * @return void
             * @author Gayra Ivan
             *
             **/
            function process_payment( $order_id )
            {
                global $woocommerce;

                $order = wc_get_order( $order_id );

                // Redirect to payment page
                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg( 'key', $order->get_order_key(), $order->get_checkout_payment_url( true ) ),
                );

            } //END process_payment()

            /**
             * Payment page, creates blink request and send to gateway
             *
             * @return void
             * @author Gayra Ivan
             **/
            function payment_page( $order_id )
            {
                global $woocommerce;
				
				//API Url
				$url = $this->gatewayURL;
				
				$order = wc_get_order( $order_id );
				$msisdn = implode( "",$this->get_phone_number_args( $order ) );
				
				//The JSON data.
				$jsonData = array(
				'username' => $this->username,
				'password' => $this->password,
				'api' => 'checknetworkstatus',
				'msisdn' => $msisdn,
				'service' => 'MOBILE MONEY',
				);
				
				//Encode the array into JSON.
				$jsonDataEncoded = json_encode( $jsonData );
				
				$curl = curl_init();
				curl_setopt_array( $curl, array(
				CURLOPT_URL => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_POST => 1,
				CURLOPT_POSTFIELDS => $jsonDataEncoded,
				CURLOPT_HTTPHEADER => array( "Content-Type: application/json", "Accept: application/json" ),
				));
				
				$network = curl_exec( $curl );
				curl_close( $curl );
				
				//Attempt to decode the incoming RAW post data from JSON.
				$networkDetails = json_decode( $network, true );
				
				//var_dump($networkDetails);
				if( $networkDetails['status'] == 'ACTIVE' ){
					$amount = floatval( number_format( $order->get_total(), 0, '.', '' ) );
					$data1 = array(
					'username' => $this->username,
					'password' => $this->password,
					'api' => 'depositmobilemoney',
					'msisdn' => $msisdn,
					'amount' => $amount,
					'narration' => 'Deposit UGX'.number_format( $order->get_total() ).' into my account',
					'reference' => $order->get_order_key(),
					'status notification url' => $this->notify_url,
					);
					
					//Encode the array into JSON.
					$jsonData1 = json_encode( $data1 );
					$curl = curl_init();
					curl_setopt_array( $curl, array(
					CURLOPT_URL => $url,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_POST => 1,
					CURLOPT_POSTFIELDS => $jsonData1,
					CURLOPT_HTTPHEADER => array( "Content-Type: application/json", "Accept: application/json" ),
					));
					$response = curl_exec( $curl );
					curl_close( $curl );
					
					//Attempt to decode the incoming RAW post data from JSON.
					$transactionDetails = json_decode( $response, true );
					
					// if we have come from the gateway do some stuff
					if ( isset( $transactionDetails['reference_code'] ) ) {
						$order = wc_get_order( $order_id );
						$order->add_order_note( __( 'Payment accepted, awaiting confirmation.', 'woothemes' ) );
						
						$reference_code = $transactionDetails['reference_code'];
						
						global $wpdb;
						$table_name = $wpdb->prefix . 'blink_queue';
						$wpdb->insert( $table_name, array( 'order_id' => $order_id, 'reference_code' => $reference_code, 'time' => current_time( 'mysql' ) ), array( '%d', '%s', '%s' ) );
						
						wp_redirect( $this->get_return_url( $order ) ); exit;
					}
				} else {
					echo 'Phone number not valid, please check your number again';
				}
				
			}

            /**
             * backgroud check payment
             *
             * @return void
             * @author Gayra Ivan
             **/
            public function background_check_payment_status()
            {
                global $wpdb;
                $table_name = $wpdb->prefix . 'blink_queue';

                $checks = $wpdb->get_results( "SELECT order_id, reference_code FROM $table_name" );

                if ( $wpdb->num_rows > 0 ) {

                    foreach ( $checks as $check ) {

                        $order = wc_get_order( $check->order_id );

						// Setup request to send json via POST
						$url2 = $this->gatewayURL;

						// Create a new cURL resource
						$cURL = curl_init( $url2 );
						$checkdata = array(
						'username' => $this->username,
						'password' => $this->password,
						'api' => 'checktransactionstatus',
						'reference_code' => $check->reference_code,
						);

						$checkstatus = json_encode( $checkdata );
						curl_setopt( $cURL, CURLOPT_POST, 1 );

						// Attach encoded JSON string to the POST fields
						curl_setopt( $cURL, CURLOPT_POSTFIELDS, $checkstatus );

						// Set the content type to application/json
						curl_setopt( $cURL, CURLOPT_HTTPHEADER, array( 'Content-Type:application/json','Accept: application/json' ) );

						// Return response instead of outputting
						curl_setopt( $cURL, CURLOPT_RETURNTRANSFER, true );
						$result2 = curl_exec( $cURL );

						if ( !curl_errno( $cURL ) ) {
							//check the response if it's still "PENDING" or "SUCCESSFUL" or "FAILED"
							curl_close( $cURL );
							$json2= json_decode( $result2, true );
							$status=$json2['status'];
						}

                        switch ( $status ) {
                            case 'SUCCESSFUL':
                                // hooray payment complete
                                $order->add_order_note( __( 'Payment confirmed.', 'woothemes' ) );
                                $order->payment_complete();
                                $wpdb->query( "DELETE FROM $table_name WHERE order_id = $check->order_id" );
                                break;
                            case 'FAILED':
                                // aw, payment failed
                                $order->update_status( 'failed', __( 'Payment denied by gateway.', 'woocommerce' ));
                                $wpdb->query( "DELETE FROM $table_name WHERE order_id = $check->order_id" );
                                break;
                        }
                    }
                }
            }
			
			/**
			* Get phone number args for blink request.
			*
			* @param  WC_Order $order Order object.
			* @return array
			*/
			function get_phone_number_args( $order ) {
				$phone_number = wc_sanitize_phone_number( $order->get_billing_phone() );
				
				if ( in_array( $order->get_billing_country(), array( 'US', 'CA' ), true ) ) {
					$phone_number = ltrim( $phone_number, '+1' );
					$phone_args   = array(
					'night_phone_a' => substr( $phone_number, 0, 3 ),
					'night_phone_b' => substr( $phone_number, 3, 3 ),
					'night_phone_c' => substr( $phone_number, 6, 4 ),
					);
				} else {
					$calling_code = WC()->countries->get_country_calling_code( $order->get_billing_country() );
					$calling_code = is_array( $calling_code ) ? $calling_code[0] : $calling_code;
					if ( $calling_code ) {
						$phone_number = str_replace( $calling_code, '', preg_replace( '/^0/', '', $order->get_billing_phone() ) );
					}
					$phone_args = array(
					'night_phone_a' => preg_replace( '/^\+/', '', $calling_code ),
					'night_phone_b' => $phone_number,
					);
				}
				return $phone_args;
			}

        } // END WC_Blink_Gateway Class
        
        $bchecks = new WC_Blink_Gateway();
		add_action( 'blink_payment_checks', array( $bchecks, 'background_check_payment_status' ) );

    } // END init_woo_blink_gateway()

    /**
     * @param String[] $methods
     * @return String[]
     */
    function add_blink_gateway( $methods )
    {
        $methods[] = 'WC_Blink_Gateway';
        return $methods;
    }
    
    	

    add_filter( 'woocommerce_payment_gateways', 'add_blink_gateway' );
}
