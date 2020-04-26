<?php
/*
Plugin Name: Woocommerce Blink Payment Gateway
Plugin URI: https://cod-ed.com
Description: Allows use of Ugandan payment processor Blink - https://www.blinkpay.co.ug.
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

// Check for woocommerce
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    // Hooks for adding/ removing the database table, and the wpcron to check them
    register_activation_hook(__FILE__, 'create_background_checks');
    register_deactivation_hook(__FILE__, 'remove_background_checks');
    register_uninstall_hook(__FILE__, 'on_uninstall');

    add_filter('woocommerce_currency_symbol', 'add_uganda_shilling_symbol', 10, 2);
    function add_uganda_shilling_symbol($currency_symbol, $currency)
    {
        switch ($currency) {
            case 'UGX':$currency_symbol = '/=';
                break;
        }
        return $currency_symbol;
    }

    // cron interval for ever 5 minuites
    add_filter('cron_schedules', 'fivemins_cron_definer');

    function fivemins_cron_definer($schedules)
    {
        $schedules['fivemins'] = array(
            'interval' => 300,
            'display' => __('Once Every 5 minuites'),
        );
        return $schedules;
    }

    /**
     * Activation, create processing order table, and table version option
     * @return void
     */
    function create_background_checks()
    {
        // Wp_cron checks pending payments in the background
        wp_schedule_event(time(), 'fivemins', 'blink_background_payment_checks');

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

        add_option('blink_db_version', $db_version);
    }

    function remove_background_checks()
    {
        $next_sheduled = wp_next_scheduled('blink_background_payment_checks');
        wp_unschedule_event($next_sheduled, 'blink_background_payment_checks');
    }

    /**
     * Clean up table and options on uninstall
     * @return [type] [description]
     */
    function on_uninstall()
    {
        // Clean up i.e. delete the table, wp_cron already removed on deacivate
        delete_option('blink_db_version');

        global $wpdb;

        $table_name = $wpdb->prefix . "blink_queue";

        $wpdb->query("DROP TABLE IF EXISTS $table_name");
    }

    add_action('plugins_loaded', 'init_woo_blink_gateway', 0);

    function init_woo_blink_gateway()
    {

        class WC_Blink_Gateway extends WC_Payment_Gateway
        {

            function __construct()
            {
                global $woocommerce;
                $this->id = 'blink';
                $this->method_title = __('Blink', 'woocommerce');
                $this->has_fields = false;
                $this->testmode = ($this->get_option('testmode') === 'yes') ? true : false;
                $this->debug = $this->get_option('debug');

                // Logs
                if ('yes' == $this->debug) {
                    if (class_exists('WC_Logger')) {
                        $this->log = new WC_Logger();
                    } else {
                        $this->log = $woocommerce->logger();
                    }

                }

                if ($this->testmode) {
                    $api = 'https://payments-dev.blink.co.ug/api/';
                    $this->username = $this->get_option('testusername');
                    $this->password = $this->get_option('testpassword');
                } else {
                    $api = 'https://payments.blink.co.ug/api/';
                    $this->username = $this->get_option('username');
                    $this->password = $this->get_option('password');
                }

                // Gateway payment URLs
                $this->gatewayURL = $api;
                $this->QueryPaymentApi = 'depositmobilemoney';
				$this->QueryRefundApi = 'withdrawmobilemoney';
           
                // IPN Request URL
                $this->notify_url = 'https://myjobug.com/payment-status/';
                $this->init_form_fields();
                $this->init_settings();

                // Settings
                $this->title = $this->get_option('title');
                $this->description = $this->get_option('description');
                $this->ipn = ($this->get_option('ipn') === 'yes') ? true : false;

                // Actions
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
                add_action('woocommerce_receipt_blink', array(&$this, 'payment_page'));
                // add_action('before_woocommerce_pay', array(&$this, 'before_pay'));
                add_action('woocommerce_thankyou_blink', array(&$this, 'thankyou_page'));
                add_action('blink_background_payment_checks', array($this, 'background_check_payment_status'));
                add_action('woocommerce_api_wc_blink_gateway', array($this, 'ipn_response'));
                add_action('blink_process_valid_ipn_request', array($this, 'process_valid_ipn_request'));
            }

            function init_form_fields()
            {
                $this->form_fields = array(
                    'enabled' => array(
                        'title' => __('Enable/Disable', 'woothemes'),
                        'type' => 'checkbox',
                        'label' => __('Enable Blink Payment', 'woothemes'),
                        'default' => 'no',
                    ),
                    'title' => array(
                        'title' => __('Title', 'woothemes'),
                        'type' => 'text',
                        'description' => __('This controls the title which the user sees during checkout.', 'woothemes'),
                        'default' => __('Blink Payment', 'woothemes'),
                    ),
                    'description' => array(
                        'title' => __('Description', 'woocommerce'),
                        'type' => 'textarea',
                        'description' => __('This is the description which the user sees during checkout.', 'woocommerce'),
                        'default' => __("Payment via Blink Gateway, you can pay by a mobile money option such as MTN.", 'woocommerce'),
                    ),
                    'ipn' => array(
                        'title' => __('Use IPN', 'woothemes'),
                        'type' => 'checkbox',
                        'label' => __('Use IPN', 'woothemes'),
                        'description' => __('Blink has the ability to send your site an Instant Payment Notification whenever there is an order update. It is highly reccomended that you enable this, as there are some issues with the "background" status checking. It is disabled by default because the IPN URL needs to be entered in the blink control panel.', 'woothemes'),
                        'default' => 'no',
                    ),
                    'ipnurl' => array(
                        'title' => __('IPN URL', 'woothemes'),
                        'type' => 'text',
                        'description' => __('This is the IPN URL that you must enter in the Blink control panel. (This is not editable)', 'woothemes'),
                        'default' => $this->notify_url,
                    ),
                    'username' => array(
                        'title' => __('Blink Username', 'woothemes'),
                        'type' => 'text',
                        'description' => __('Your Blink Username which should have been emailed to you.', 'woothemes'),
                        'default' => '',
                    ),
                    'password' => array(
                        'title' => __('Blink Password', 'woothemes'),
                        'type' => 'text',
                        'description' => __('Your Blink Password which should have been emailed to you.', 'woothemes'),
                        'default' => '',
                    ),
                    'testmode' => array(
                        'title' => __('Use Demo Gateway', 'woothemes'),
                        'type' => 'checkbox',
                        'label' => __('Use Demo Gateway', 'woothemes'),
                        'description' => __('Use demo blink gateway for testing.', 'woothemes'),
                        'default' => 'no',
                    ),
                    'testusername' => array(
                        'title' => __('Blink Demo Username', 'woothemes'),
                        'type' => 'text',
                        'description' => __('Your demo Blink Username which can be seen at payments-dev.blink.co.ug.', 'woothemes'),
                        'default' => '',
                    ),
                    'debug' => array(
                        'title' => __('Debug Log', 'woocommerce'),
                        'type' => 'checkbox',
                        'label' => __('Enable logging', 'woocommerce'),
                        'default' => 'no',
                        'description' => sprintf(__('Log Blink events, such as IPN requests, inside <code>woocommerce/logs/blink-%s.txt</code>', 'woocommerce'), sanitize_file_name(wp_hash('blink'))),
                    ),

                    'testpassword' => array(
                        'title' => __('Blink Demo Password', 'woothemes'),
                        'type' => 'text',
                        'description' => __('Your demo Blink Password which can be seen at payments-dev.blink.co.ug.', 'woothemes'),
                        'default' => '',
                    ),
                );
            }

            public function admin_options()
            {?>

          <h3><?php _e('Blink Payment API', 'woothemes');?></h3>
          <p>
            <?php _e('Allows use of the Blink Payment Gateway, all you need is an account at www.blinkpay.co.ug and your username and password.<br />', 'woothemes');?>
            <?php _e('<a href="http://docs.woothemes.com/document/managing-orders/">Click here </a> to learn about the various woocommerce Payment statuses.<br /><br />', 'woothemes');?>
            <?php _e('<strong>Developer: </strong>Gayra Ivan<br />', 'woothemes');?>
            <?php _e('<strong>Contributors: </strong>Blink Systems<br />', 'woothemes');?>
            <?php // _e('<strong>Donate link:  </strong><a href="http://jakeii.github.com/woocommerce-blink" target="_blank"> http://jakeii.github.com/woocommerce-blink</a>', 'woothemes');?>
          </p>
          <table class="form-table">
          <?php
// Generate the HTML For the settings form.
                $this->generate_settings_html();
                ?>
          </table>
          <script type="text/javascript">
          jQuery(function(){
            var testMode = jQuery("#woocommerce_blink_testmode");
            var ipn = jQuery("#woocommerce_blink_ipn");
            var ipnurl = jQuery("#woocommerce_blink_ipnurl");
            var username = jQuery("#woocommerce_blink_testusername");
            var password = jQuery("#woocommerce_blink_testpassword");

            if (testMode.is(":not(:checked)")){
              username.parents("tr").css("display","none");
              password.parents("tr").css("display","none");
            }

            if (ipn.is(":not(:checked)")){
              ipnurl.parents("tr").css("display","none");
            }

            // Add onclick handler to checkbox w/id checkme
            testMode.click(function(){
              // If checked
              if (testMode.is(":checked")) {
                //show the hidden div
                username.parents("tr").show("fast");
                password.parents("tr").show("fast");
              } else {
                //otherwise, hide it
                username.parents("tr").hide("fast");
                paasword.parents("tr").hide("fast");
              }
            });

            ipn.click(function(){
              // If checked
              if (ipn.is(":checked")) {
                //show the hidden div
                ipnurl.parents("tr").show("fast");
              } else {
                //otherwise, hide it
                ipnurl.parents("tr").hide("fast");
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
            public function thankyou_page($order_id)
            {
                // global $woocommerce;
				
				//Receive the RAW ipn data.
				$content = trim(file_get_contents($ipnurl));
				
				//Attempt to decode the incoming RAW post data from JSON.
				$transactionDetails = json_decode($content, true);

                // $order = wc_get_order( $order_id );

                // // Remove cart
                // $woocommerce->cart->empty_cart();

                if (isset($transactionDetails['reference_code'])) {

                    // $order_id = $_GET['order'];
                    $order = wc_get_order($order_id);
                  
                    $order->add_order_note(__('Payment accepted, awaiting confirmation.', 'woothemes'));
                   
                    // if immeadiatly complete mark it so
                    if ($transactionDetails["status"] === 'SUCCESSFUL') {
                        $order->add_order_note(__('Payment confirmed.', 'woothemes'));
                        $order->payment_complete();
                    } else if (!$this->ipn) {
                        $reference_code = $transactionDetails['reference_code'];

                        global $wpdb;
                        $table_name = $wpdb->prefix . 'blink_queue';
                        $wpdb->insert($table_name, array('order_id' => $order_id, 'reference_code' => $reference_code, 'time' => current_time('mysql')), array('%d', '%s', '%s'));
                    }
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
            function process_payment($order_id)
            {
                global $woocommerce;

                $order = wc_get_order($order_id);

                // Redirect to payment page
                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg('key', $order->get_order_key(), $order->get_checkout_payment_url(true)),
                );

            } //END process_payment()

            /**
             * Payment page, creates blink request and shows the gateway iframe
             *
             * @return void
             * @author Gayra Ivan
             **/
            function payment_page($order_id)
            {
                $url = $this->create_url($order_id);
                ?>
          <iframe src="<?php echo $url; ?>" width="100%" height="700px"  scrolling="yes" frameBorder="0">
            <p>Browser unable to load iFrame</p>
          </iframe>
          <?php
}

            /**
             * Before Payment
             *
             * @return void
             * @author Gayra Ivan
             **/
            function before_pay()
            {
                //Receive the RAW ipn data.
				$content = trim(file_get_contents($ipnurl));
				
				//Attempt to decode the incoming RAW post data from JSON.
				$transactionDetails = json_decode($content, true);
				
				// if we have come from the gateway do some stuff
                if (isset($transactionDetails['reference_code'])) {

                    $order_id = $_GET['order'];
                    $order = wc_get_order($order_id);
                    
                    $order->add_order_note(__('Payment accepted, awaiting confirmation.', 'woothemes'));
                    
                    if (!$this->ipn) {
                        $reference_code = $transactionDetails['reference_code'];

                        global $wpdb;
                        $table_name = $wpdb->prefix . 'blink_queue';
                        $wpdb->insert($table_name, array('order_id' => $order_id, 'reference_code' => $reference_code, 'time' => current_time('mysql')), array('%d', '%s', '%s'));
                    }

                    wp_redirect(add_query_arg('key', $order->get_order_key(), add_query_arg('order', $order_id, $order->get_checkout_order_received_ur())));
                }
            }

            /**
             * backgroud check payment
             *
             * @return void
             * @author Gayra Ivan
             **/
            function background_check_payment_status()
            {
                global $wpdb;
                $table_name = $wpdb->prefix . 'blink_queue';

                $checks = $wpdb->get_results("SELECT order_id, reference_code FROM $table_name");

                if ($wpdb->num_rows > 0) {

                    foreach ($checks as $check) {

                        $order = wc_get_order($check->order_id);

                        $status = $this->status_request($check->reference_code, $check->order_id);

                        switch ($status) {
                            case 'SUCCESSFUL':
                                // hooray payment complete
                                $order->add_order_note(__('Payment confirmed.', 'woothemes'));
                                $order->payment_complete();
                                $wpdb->query("DELETE FROM $table_name WHERE order_id = $check->order_id");
                                break;
                            case 'FAILED':
                                // aw, payment failed
                                $order->update_status('failed', __('Payment denied by gateway.', 'woocommerce'));
                                $wpdb->query("DELETE FROM $table_name WHERE order_id = $check->order_id");
                                break;
                        }
                    }
                }
            }

            /**
             * Generate blink payment url
             *
             * @param Integer $order_id
             * @return string
             * @author Gayra Ivan
             **/
            function create_url($order_id)
			{
				//API Url
				$url = $api;
				
				//Initiate cURL.
				$ch = curl_init($url);
				
				//The JSON data.
				$jsonData = $this->blink_json($order_id);
				
				//Encode the array into JSON.
				$jsonDataEncoded = json_encode($jsonData);
				
				//Tell cURL that we want to send a POST request.
				curl_setopt($ch, CURLOPT_POST, 1);
				
				//Attach our encoded JSON string to the POST fields.
				curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonDataEncoded);
				
				//Set the content type to application/json
				curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
				
				//Execute the request
				$result = curl_exec($ch);
				
				return $result;
			}

            /**
             * Create JSON order request
             *
             * @param Integer $order_id
             * @return string
             * @author Jake Lee Kennedy
             **/
            function blink_json($order_id)
            {

                $order = wc_get_order($order_id);
                $blink_args['total'] = $order->get_total();
                $blink_args['reference'] = date('Y-m-d H:i:s');
                $blink_args['first_name'] = $order->get_billing_first_name();
                $blink_args['last_name'] = $order->get_billing_last_name();
                $blink_args['email'] = $order->get_billing_email();
                $blink_args['phone'] = $order->get_billing_phone();
				
				if(  preg_match( '/^\+\d(\d{3})(\d{9})$/', $blink_args['phone'],  $matches ) )
				{
					$result = preg_replace('/\d(\d{3})(\d{9})$/', $matches, $blink_args['phone']);
					return $result;
				}
				else if(  preg_match( '/^\0\d(\d{9})$/', $blink_args['phone'],  $matches ) )
				{
					$result = preg_replace('/^\256\d(\d{9})$/', $matches, $blink_args['phone']);
					return $result;
				}
				else if(  preg_match( '/^\256\d(\d{9})$/', $blink_args['phone'],  $matches ) )
				{
					$result = $matches;
					return $result;
				}

                $i = 0;
                foreach ($order->get_items('line_item') as $item) {
                    $product = $item->get_product();

                    $cart[$i] = array(
                        'id' => ($product->get_sku() ? $product->get_sku() : $product->id),
                        'particulars' => $product->get_name(),
                        'quantity' => $item->get_quantity(),
                        'unitcost' => $product->get_regular_price(),
                        'subtotal' => $order->get_item_total($item, true),
                    );
                    $i++;
                }

                $data = array(
				'username' => $username,
				'password' => $password,
				'api' => $QueryPaymentApi,
				'msisdn' => $result,
				'amount' => $blink_args['total'],
				'narration' => 'Deposit UGX'.$blink_args['total'].' into my account',
				'reference' => $blink_args['reference'],
				'status notification url' => $notify_url
				);

                return $data;
            }

            /**
             * IPN Response
             *
             * @return null
             * @author Gayra Ivan
             **/
            function ipn_response()
            {

                //Receive the RAW ipn data.
				$content = trim(file_get_contents($ipnurl));
				
				//Attempt to decode the incoming RAW post data from JSON.
				$transactionDetails = json_decode($content, true);
				
				$order = wc_get_order($order_id);

                // We are here so lets check status and do actions
                switch ($transactionDetails['status']) {
                    case 'SUCCESSFUL':
                    case 'PENDING':

                        // Check order not already completed
                        if ($order->get_status() == 'completed') {
                            if ('yes' == $this->debug) {
                                $this->log->add('blink', 'Aborting, Order #' . $order->id . ' is already complete.');
                            }

                            exit;
                        }

                        if ($transactionDetails['status'] == 'SUCCESSFUL') {
                            $order->add_order_note(__('IPN payment completed', 'woocommerce'));
                            $order->payment_complete();
                        } else {
                            $order->update_status('on-hold', sprintf(__('Payment pending: %s', 'woocommerce'), 'Waiting blink confirmation'));
                        }

                        if ('yes' == $this->debug) {
                            $this->log->add('blink', 'Payment complete.');
                        }

                        break;
                    case 'FAILED':
                        // Order failed
                        $order->update_status('failed', sprintf(__('Payment %s via IPN.', 'woocommerce'), strtolower($transactionDetails['status'])));
                        break;

                    default:
                        // No action
                        break;
                }

                $order = wc_get_order($order_id);
                $newstatus = $order->get_status();

                if ($transactionDetails['status'] == $newstatus) {
                    $dbupdated = "True";
                } else {
                    $dbupdated = 'False';
                }
            }

        } // END WC_Blink_Gateway Class

    } // END init_woo_blink_gateway()

    /**
     * @param String[] $methods
     * @return String[]
     */
    function add_blink_gateway($methods)
    {
        $methods[] = 'WC_Blink_Gateway';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_blink_gateway');
}
