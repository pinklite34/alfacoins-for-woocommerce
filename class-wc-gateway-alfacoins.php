<?php
/*
    Plugin Name: ALFAcoins for WooCommerce
    Plugin URI:  https://wordpress.org/plugins/alfacoins-for-woocommerce/
    Description: Enable your WooCommerce store to accept Bitcoin, Litecoin, Ethereum, Bitcoin Cash, Dash, XRP and Litecoin Testnet with ALFAcoins.
    Author:      alfacoins
    Author URI:  https://github.com/alfacoins

    Version:           0.7
    License:           Copyright 2013-2017 ALFAcoins Inc., MIT License
 */

// Exit if accessed directly
if (FALSE === defined('ABSPATH')) {
  exit;
}

// Ensures WooCommerce is loaded before initializing the ALFAcoins plugin
add_action('plugins_loaded', 'woocommerce_alfacoins_init', 0);
register_activation_hook(__FILE__, 'woocommerce_alfacoins_activate');

function woocommerce_alfacoins_init() {
  if (TRUE === class_exists('WC_Gateway_ALFAcoins')) {
    return;
  }

  if (FALSE === class_exists('WC_Payment_Gateway')) {
    return;
  }

  class WC_Gateway_ALFAcoins extends WC_Payment_Gateway {
    private $is_initialized = FALSE;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
      // General
      $this->id = 'alfacoins';
      $this->icon = plugin_dir_url(__FILE__) . 'assets/img/icon.png';
      $this->has_fields = FALSE;
      $this->order_button_text = __('Pay with ALFAcoins', 'alfacoins');
      $this->method_title = 'ALFAcoins';
      $this->method_description = 'ALFAcoins allows you to accept bitcoin, litecoin, ethereum, bitcoin cash, dash, xrp and litecoin testnet payments on your WooCommerce store.';

      // Load the settings.
      $this->init_form_fields();
      $this->init_settings();

      // Define user set variables
      $this->title = $this->get_option('title');
      $this->description = $this->get_option('description');
      $this->order_states = $this->get_option('order_states');
      $this->debug = 'yes' === $this->get_option('debug', 'no');

      // Define ALFAcoins settings
      $this->api_name = $this->settings['api_name'];
      $this->api_secret_key = $this->settings['api_secret_key'];
      $this->api_password = $this->settings['api_password'];
      $this->api_type_new = $this->settings['api_type_new'];
      $this->api_url = $this->settings['api_url'];

      // Define debugging & informational settings
      $this->debug_php_version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
      $this->debug_plugin_version = get_option('woocommerce_alfacoins_version');

      $this->log('ALFAcoins Woocommerce payment plugin object constructor called. Plugin is v' . $this->debug_plugin_version . ' and server is PHP v' . $this->debug_php_version);
      $this->log('    [Info] $this->api_name           = ' . $this->api_name);
      $this->log('    [Info] $this->api_secret_key     = ' . $this->api_secret_key);
      $this->log('    [Info] $this->api_password       = ' . $this->api_password);
      $this->log('    [Info] $this->api_url           = ' . $this->api_url);
      $this->log('    [Info] $this->api_type_new           = ' . $this->api_type_new);

      // Actions
      add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
        $this,
        'process_admin_options'
      ));
      add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
        $this,
        'save_order_states'
      ));


      // Valid for use and IPN Callback
      if (FALSE === $this->is_valid_for_use()) {
        $this->enabled = 'no';
        $this->log('    [Info] The plugin is NOT valid for use!');
      }
      else {
        $this->enabled = 'yes';
        $this->log('    [Info] The plugin is ok to use.');
        add_action('woocommerce_api_wc_gateway_alfacoins', array(
          $this,
          'ipn_callback'
        ));
      }

      $this->is_initialized = TRUE;
    }

    public function __destruct() {
    }

    public function is_valid_for_use() {
      // Check that API credentials are set
      if (empty($this->api_name) ||
        empty($this->api_secret_key) ||
        empty($this->api_password) ||
        empty($this->api_type_new)
      ) {
        return FALSE;
      }

      /*if (!in_array(get_woocommerce_currency(), array('USD', 'EUR'))) {
        $this->log('    [Error] In is_valid_for_use not USD/EUR ');

        return FALSE;
      }*/

      $this->log('    [Info] Plugin is valid for use.');

      return TRUE;
    }

    /**
     * Initialise Gateway Settings Form Fields
     */
    public function init_form_fields() {
      $this->log('    [Info] Entered init_form_fields()...');
      $log_file = 'alfacoins-' . sanitize_file_name(wp_hash('alfacoins')) . '-log';
      $logs_href = get_bloginfo('wpurl') . '/wp-admin/admin.php?page=wc-status&tab=logs&log_file=' . $log_file;

      $this->form_fields = array(
        'enabled' => array(
          'title' => __('Enable/Disable', 'alfacoins'),
          'type' => 'checkbox',
          'label' => __('Enable Payments via ALFAcoins', 'alfacoins'),
          'default' => 'yes'
        ),
        'title' => array(
          'title' => __('Title', 'alfacoins'),
          'type' => 'text',
          'description' => __('Controls the name of this payment method as displayed to the customer during checkout.', 'alfacoins'),
          'default' => __('ALFAcoins', 'alfacoins'),
          'desc_tip' => TRUE,
        ),
        'description' => array(
          'title' => __('Customer Message', 'alfacoins'),
          'type' => 'textarea',
          'description' => __('Message to explain how the customer will be paying for the purchase.', 'alfacoins'),
          'default' => 'You will be redirected to alfacoins.com to complete your purchase.',
          'desc_tip' => TRUE,
        ),
        'api_url' => array(
          'title' => __('API URL', 'alfacoins'),
          'type' => 'url',
          'description' => __('ALFAcoins API URL', 'alfacoins'),
          'default' => 'https://www.alfacoins.com/api/',
          'placeholder' => 'https://www.alfacoins.com/api/',
          'desc_tip' => TRUE,
        ),
        'api_name' => array(
          'title' => __('API Name', 'alfacoins'),
          'type' => 'text',
          'description' => __('ALFAcoins API Name', 'alfacoins'),
          'default' => '',
          'placeholder' => __('API Name', 'alfacoins'),
          'desc_tip' => TRUE,
        ),
        'api_secret_key' => array(
          'title' => __('API Secret Key', 'alfacoins'),
          'type' => 'text',
          'description' => __('ALFAcoins API Secret Key', 'alfacoins'),
          'default' => '',
          'placeholder' => __('API Secret Key', 'alfacoins'),
          'desc_tip' => TRUE,
        ),
        'api_password' => array(
          'title' => __('API Password', 'alfacoins'),
          'type' => 'text',
          'description' => __('ALFAcoins UPPERCASE MD5 of API Password', 'alfacoins'),
          'default' => '',
          'placeholder' => __('UPPERCASE MD5 of API Password', 'alfacoins'),
          'desc_tip' => TRUE,
        ),
        'api_type_new' => array(
          'title' => __('Default coin', 'alfacoins'),
          'type' => 'select',
          'default' => 'bitcoin',
          'description' => __('Default coin picked in payment method, you can use all or only one - can configure it in ALFAcoins API settings page', 'alfacoins'),
          'options' => array(
            'bitcoin' => 'Bitcoin',
            'litecoin' => 'Litecoin',
            'ethereum' => 'Ethereum',
            'bitcoincash' => 'Bitcoin Cash',
            'dash' => 'Dash',
            'xrp' => 'XRP',
            'litecointestnet' => 'Litecoin Testnet'
          ),
          'desc_tip' => TRUE,
        ),
        'order_states' => array(
          'type' => 'order_states'
        ),
        'debug' => array(
          'title' => __('Debug Log', 'alfacoins'),
          'type' => 'checkbox',
          'label' => sprintf(__('Enable logging <a href="%s" class="button">View Logs</a>', 'alfacoins'), $logs_href),
          'default' => 'no',
          'description' => sprintf(__('Log ALFAcoins events, such as IPN requests, inside <code>%s</code>', 'alfacoins'), wc_get_log_file_path('alfacoins')),
          'desc_tip' => TRUE,
        ),
        'notification_url' => array(
          'title' => __('Notification URL', 'alfacoins'),
          'type' => 'url',
          'description' => __('ALFAcoins will send IPNs for orders to this URL with the ALFAcoins invoice data', 'alfacoins'),
          'default' => WC()->api_request_url('WC_Gateway_ALFAcoins'),
          //'placeholder' => WC()->api_request_url('WC_Gateway_ALFAcoins'),
          'desc_tip' => TRUE,
        ),
        'redirect_url' => array(
          'title' => __('Redirect URL', 'alfacoins'),
          'type' => 'url',
          'description' => __('After paying the ALFAcoins invoice, users will be redirected back to this URL', 'alfacoins'),
          'default' => $this->get_return_url(),
          //'placeholder' => $this->get_return_url(),
          'desc_tip' => TRUE,
        ),
        'support_details' => array(
          'title' => __('Plugin & Support Information', 'alfacoins'),
          'type' => 'title',
          'description' => sprintf(__('This plugin version is %s and your PHP version is %s. If you need assistance, please contact support@alfacoins.com.  Thank you for using ALFAcoins!', 'alfacoins'), get_option('woocommerce_alfacoins_version'), PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION),
        ),
      );

      $this->log('    [Info] Initialized form fields: ' . var_export($this->form_fields, TRUE));
      $this->log('    [Info] Leaving init_form_fields()...');
    }

    /**
     * HTML output for form field type `order_states`
     */
    public function generate_order_states_html() {
      $this->log('    [Info] Entered generate_order_states_html()...');

      ob_start();

      $ac_statuses = array(
        'new' => 'New Order',
        'paid' => 'Paid',
        'completed' => 'Completed',
        'expired' => 'Expired'
      );
      $df_statuses = array(
        'new' => 'wc-on-hold',
        'paid' => 'wc-processing',
        'completed' => 'wc-completed',
        'expired' => 'wc-cancelled'
      );

      $wc_statuses = wc_get_order_statuses();

      ?>
      <tr valign="top">
        <th scope="row" class="titledesc">Order States:</th>
        <td class="forminp" id="alfacoins_order_states">
          <table cellspacing="0">
            <?php

            foreach ($ac_statuses as $ac_state => $ac_name) {
              ?>
              <tr>
                <th><?php echo $ac_name; ?></th>
                <td>
                  <select
                    name="woocommerce_alfacoins_order_states[<?php echo $ac_state; ?>]">
                    <?php

                    $order_states = get_option('woocommerce_alfacoins_settings');
                    $order_states = $order_states['order_states'];
                    foreach ($wc_statuses as $wc_state => $wc_name) {
                      $current_option = $order_states[$ac_state];

                      if (TRUE === empty($current_option)) {
                        $current_option = $df_statuses[$ac_state];
                      }

                      if ($current_option === $wc_state) {
                        echo "<option value=\"$wc_state\" selected>$wc_name</option>\n";
                      }
                      else {
                        echo "<option value=\"$wc_state\">$wc_name</option>\n";
                      }
                    }

                    ?>
                  </select>
                </td>
              </tr>
            <?php
            }

            ?>
          </table>
        </td>
      </tr>
      <?php

      $this->log('    [Info] Leaving generate_order_states_html()...');

      return ob_get_clean();
    }

    /**
     * Save order states
     */
    public function save_order_states() {
      $this->log('    [Info] Entered save_order_states()...');

      $ac_statuses = array(
        'new' => 'New Order',
        'paid' => 'Paid',
        'completed' => 'Completed',
        'expired' => 'Expired',
      );

      $wc_statuses = wc_get_order_statuses();

      if (TRUE === isset($_POST['woocommerce_alfacoins_order_states'])) {

        $ac_settings = get_option('woocommerce_alfacoins_settings');
        $order_states = $ac_settings['order_states'];

        foreach ($ac_statuses as $ac_state => $ac_name) {
          if (FALSE === isset($_POST['woocommerce_alfacoins_order_states'][$ac_state])) {
            continue;
          }

          $wc_state = sanitize_text_field($_POST['woocommerce_alfacoins_order_states'][$ac_state]);

          if (TRUE === array_key_exists($wc_state, $wc_statuses)) {
            $this->log('    [Info] Updating order state ' . $ac_state . ' to ' . $wc_state);
            $order_states[$ac_state] = $wc_state;
          }

        }
        $ac_settings['order_states'] = $order_states;
        update_option('woocommerce_alfacoins_settings', $ac_settings);
      }

      $this->log('    [Info] Leaving save_order_states()...');
    }

    /**
     * Validate API Type (Default Coin)
     */
    public function validate_api_type_new_field($key) {
      $type = $this->get_option($key);
      if (isset($_POST[$this->plugin_id . $this->id . '_' . $key])) {
        if (!in_array($_POST[$this->plugin_id . $this->id . '_' . $key], array('bitcoin', 'litecoin', 'ethereum', 'bitcoincash', 'dash', 'xrp', 'litecointestnet'))) {
          $type = 'bitcoin';
        } else {
          $type = $_POST[$this->plugin_id . $this->id . '_' . $key];
        }
      }
      return sanitize_text_field($type);

    }

    /**
     * Validate API Password
     */
    public function validate_api_password_field($key) {
      $password = $this->get_option($key);
      if (isset($_POST[$this->plugin_id . $this->id . '_' . $key])) {
        $val = $_POST[$this->plugin_id . $this->id . '_' . $key];
        if (!preg_match('/^[A-F0-9]{32}$/', $val)) {
          if (preg_match('/^[a-f0-9]{32}$/', $val))
            $password = strtoupper($val);
          else
            $password = strtoupper(md5($val));
        } else {
          // always uppercase
          $password = preg_replace('/[^\dA-Z]/', '', $val);
        }
      }
      return sanitize_text_field($password);
    }

    /**
     * Validate API Secret KEY
     */
    public function validate_api_secret_key_field($key) {
      $secret_key = $this->get_option($key);
      if (isset($_POST[$this->plugin_id . $this->id . '_' . $key])) {
        if (!preg_match('/^[a-f0-9]{32}$/', $_POST[$this->plugin_id . $this->id . '_' . $key])) {
          $secret_key = '';
        }
        else {
          // always lowercase
          $secret_key = preg_replace('/[^\da-z]/', '', $_POST[$this->plugin_id . $this->id . '_' . $key]);
        }
      }
      return sanitize_text_field($secret_key);
    }

    /**
     * Validate API Name
     */
    public function validate_api_name_field($key) {
      $name = $this->get_option($key);
      if (isset($_POST[$this->plugin_id . $this->id . '_' . $key])) {
        $name = $_POST[$this->plugin_id . $this->id . '_' . $key];
      }
      return sanitize_text_field($name);

    }

    /**
     * Validate API URL
     */
    public function validate_api_url_field($key) {
      $url = $this->get_option($key);

      if (isset($_POST[$this->plugin_id . $this->id . '_' . $key])) {
        if (filter_var($_POST[$this->plugin_id . $this->id . '_' . $key], FILTER_VALIDATE_URL) !== FALSE) {
          $url = esc_url_raw($_POST[$this->plugin_id . $this->id . '_' . $key],array('http','https'));
        }
        else {
          $url = '';
        }
      }

      return $url;
    }

    /**
     * Validate Customer Message
     */
    public function validate_description_field($key) {
      $desc = $this->get_option($key);
      if (isset($_POST[$this->plugin_id . $this->id . '_' . $key])) {
        $desc = $_POST[$this->plugin_id . $this->id . '_' . $key];
      }
      return sanitize_text_field($desc);
    }

    /**
     * Validate Title
     */
    public function validate_title_field($key) {
      $title = $this->get_option($key);
      if (isset($_POST[$this->plugin_id . $this->id . '_' . $key])) {
        $title = $_POST[$this->plugin_id . $this->id . '_' . $key];
      }
      return sanitize_text_field($title);
    }

    /**
     * Validate Order States
     */
    public function validate_order_states_field($key) {
      $order_states = $this->get_option($key);

      if (isset($_POST[$this->plugin_id . $this->id . '_order_states'])) {
        $order_states = $_POST[$this->plugin_id . $this->id . '_order_states'];
        if (!empty($order_states) && is_array($order_states)) {
          foreach ($order_states as $key=>$val) {
            $order_states[$key] = sanitize_text_field($val);
          }
        }
      }
      return $order_states;
    }

    /**
     * Validate Notification URL
     */
    public function validate_url_field($key) {
      $url = $this->get_option($key);

      if (isset($_POST[$this->plugin_id . $this->id . '_' . $key])) {
        if (filter_var($_POST[$this->plugin_id . $this->id . '_' . $key], FILTER_VALIDATE_URL) !== FALSE) {
          $url = esc_url_raw($_POST[$this->plugin_id . $this->id . '_' . $key],array('http','https'));
        }
        else {
          $url = '';
        }
      }
      return $url;
    }

    /**
     * Validate Redirect URL
     */
    public function validate_redirect_url_field() {
      $redirect_url = $this->get_option('redirect_url', '');

      if (isset($_POST['woocommerce_alfacoins_redirect_url'])) {
        if (filter_var($_POST['woocommerce_alfacoins_redirect_url'], FILTER_VALIDATE_URL) !== FALSE) {
          $redirect_url = esc_url_raw($_POST['woocommerce_alfacoins_redirect_url'],array('http','https'));
        }
        else {
          $redirect_url = '';
        }
      }
      return $redirect_url;
    }

    /**
     * Output for the order received page.
     */
    public function thankyou_page($order_id) {
      $this->log('    [Info] Entered thankyou_page with order_id =  ' . $order_id);

      // Intentionally blank.

      $this->log('    [Info] Leaving thankyou_page with order_id =  ' . $order_id);
    }

    /**
     * Process the payment and return the result
     *
     * @param   int $order_id
     * @return  array
     */
    public function process_payment($order_id) {
      $this->log('    [Info] Entered process_payment() with order_id = ' . $order_id . '...');

      if (TRUE === empty($order_id)) {
        $this->log('    [Error] The ALFAcoins payment plugin was called to process a payment but the order_id was missing.');
        throw new \Exception('The ALFAcoins payment plugin was called to process a payment but the order_id was missing. Cannot continue!');
      }

      $order = wc_get_order($order_id);

      if (FALSE === $order) {
        $this->log('    [Error] The ALFAcoins payment plugin was called to process a payment but could not retrieve the order details for order_id ' . $order_id);
        throw new \Exception('The ALFAcoins payment plugin was called to process a payment but could not retrieve the order details for order_id ' . $order_id . '. Cannot continue!');
      }

      $notification_url = $this->get_option('notification_url', WC()->api_request_url('WC_Gateway_ALFAcoins'));
      $this->log('    [Info] Generating payment form for order ' . $order->get_order_number() . '. Notify URL: ' . $notification_url);

      // Mark new order according to user settings (we're awaiting the payment)
      $new_order_states = $this->get_option('order_states');
      $new_order_status = $new_order_states['new'];
      $order->update_status($new_order_status, 'Awaiting payment notification from ALFAcoins.');

      $redirect_url = $this->get_option('redirect_url');
      if ($redirect_url == $this->get_return_url()) {
        $redirect_url = $this->get_return_url($order);
      }
      // Redirect URL & Notification URL

      $this->log('    [Info] The variable redirect_url = ' . $redirect_url . '...');

      $this->log('    [Info] Notification URL is now set to: ' . $notification_url . '...');

      // Setup the currency
      $currency_code = get_woocommerce_currency();

      $this->log('    [Info] The variable currency_code = ' . $currency_code . '...');


      $payerEmail = $order->billing_email;
      $payerName = $order->get_formatted_billing_full_name();

      $description = '';
      foreach ($order->get_items() as $item) {
        $product = $order->get_product_from_item($item);
        $description .= $product->get_title() . ', ';
      }
      if (!empty($description)) {
        $description = rtrim($description, ', ');
      }
      if (strlen($description) > 250) {
        $wrapped = wordwrap($description, 250);
        $lines = explode("\n", $wrapped);
        if (!empty($lines[0])) {
          $description = substr($lines[0], 0, 250) . '...';
        }
      }

      $params = array(
        'name' => $this->api_name,
        'secret_key' => $this->api_secret_key,
        'password' => $this->api_password,
        'type' => $this->api_type_new,
        'amount' => $order->calculate_totals(), // must be float
        'order_id' => $order->get_order_number(),
        'description' => $description,
        'currency' => $currency_code,
        'options' => array(
          'notificationURL' => $notification_url,
          'redirectURL' => $redirect_url,
          'payerName' => $payerName,
          'payerEmail' => $payerEmail,
        )
      );

      $this->log('    [Info] Attempting to generate invoice for ' . $order->get_order_number() . '...');

      try {
        $result = woocommerce_alfa_request($this->api_url . 'create.json', $params);
        if (!empty($result['error'])) {
          $this->log('    [Error] API ' . $result['error']);
          return array(
            'result' => 'success',
            'messages' => 'Sorry, but checkout with ALFAcoins does not appear to be working.'
          );
        }
        else {
          $this->log('    [Info] Call to generate invoice was successful');
        }

      } catch (Exception $e) {
        $this->log('    [Error] Error generating invoice for ' . $order->get_order_number() . ', error: ' . $e->getMessage());

        return array(
          'result' => 'success',
          'messages' => 'Sorry, but checkout with ALFAcoins does not appear to be working.'
        );

      }

      // Reduce stock levels
      $order->reduce_order_stock();

      // Remove cart
      WC()->cart->empty_cart();

      $this->log('    [Info] Leaving process_payment()...');

      // Redirect the customer to the ALFAcoins invoice
      return array(
        'result' => 'success',
        'redirect' => $result['url'],
      );
    }

    public function ipn_callback() {
      $this->log('    [Info] Entered ipn_callback()...');
      if (!empty($_POST['id'])
        && !empty($_POST['coin_received_amount'])
        && !empty($_POST['modified'])
        && !empty($_POST['received_amount'])
        && !empty($_POST['status'])
        && !empty($_POST['order_id'])
        && !empty($_POST['currency'])
        && !empty($_POST['hash'])
        && !empty($_POST['type'])
      ) {
        // validate all used variables
        $_POST['coin_received_amount'] = round((float) $_POST['coin_received_amount'], 8);
        $_POST['id'] = (int) $_POST['id'];
        $_POST['received_amount'] = round((float) $_POST['received_amount'], 8);
        $_POST['order_id'] = (int) $_POST['order_id'];
        // UPPERCASE
        $_POST['hash'] = preg_replace("/[^A-Z0-9]/","",$_POST['hash']);
        // LOWERCASE
        $_POST['currency'] = substr(preg_replace("/[^A-Z]/", '', $_POST['currency']),0,3);
        $_POST['status'] = preg_replace("/[^a-z]/","",$_POST['status']);
        $_POST['type'] = preg_replace("/[^a-z]/","",$_POST['type']);

        // since we only need the md5 checksum of that POST string to verify the payment
        // we don't need to validate and sanitize all params  at this step.
        // we don't save that checksum anywhere, we use it only to verify the payment.
        $checksum = strtoupper(md5($this->api_name . ':' . $_POST['coin_received_amount'] . ':' . $_POST['received_amount'] . ':' . $_POST['currency'] . ':' . $_POST['id'] . ':' . $_POST['order_id'] . ':' . $_POST['status'] . ':' . $_POST['modified'] . ':' . $this->api_password));

        // We check that $_POST['hash'] is exactly the same as $checksum
        // and Currency is not anything else than WooCommerce Currency
        if ($checksum == $_POST['hash'] && $_POST['currency'] == get_woocommerce_currency()) {
          $this->log('    [Info] Key and token empty checks passed.  Parameters in client set accordingly...');
          //this is for the basic and advanced woocommerce order numbering plugins
          //if we need to apply other filters, just add them in place of the this one
          $order_id = apply_filters('woocommerce_order_id_from_number', $_POST['order_id']);

          $order = wc_get_order($order_id);

          if (FALSE === $order || 'WC_Order' !== get_class($order)) {
            $this->log('    [Error] The ALFAcoins payment plugin was called to process an IPN message but could not retrieve the order details for order_id: "' . $order_id . '". If you use an alternative order numbering system, please see class-wc-gateway-alfacoins.php to apply a search filter.');
            throw new \Exception('The ALFAcoins payment plugin was called to process an IPN message but could not retrieve the order details for order_id ' . $order_id . '. Cannot continue!');
          }
          else {
            $this->log('    [Info] Order details retrieved successfully...');
          }

          $current_status = $order->get_status();

          if (FALSE === isset($current_status) && TRUE === empty($current_status)) {
            $this->log('    [Error] The ALFAcoins payment plugin was called to process an IPN message but could not obtain the current status from the order.');
            throw new \Exception('The ALFAcoins payment plugin was called to process an IPN message but could not obtain the current status from the order. Cannot continue!');
          }
          else {
            $this->log('    [Info] The current order status for this order is ' . $current_status);
          }


          if ($_POST['received_amount'] == $order->calculate_totals()) {

            $order_states = $this->get_option('order_states');

            $new_order_status = $order_states['new'];
            $paid_status = $order_states['paid'];
            $complete_status = $order_states['complete'];

            $checkStatus = $_POST['status'];

            // Based on the payment status parameter for this
            // IPN, we will update the current order status.
            switch ($checkStatus) {
              // The "paid" IPN message is received almost
              // immediately after the ALFAcoins invoice is paid.
              case 'paid':

                $this->log('    [Info] IPN response is a "paid" message.');

                if ($current_status == $complete_status ||
                  'wc_' . $current_status == $complete_status ||
                  $current_status == 'completed'
                ) {
                  $error_string = 'Paid IPN, but order has status: ' . $current_status;
                  $this->log("    [Warning] $error_string");

                }
                else {
                  $this->log('    [Info] This order has not been updated yet so setting new status...');

                  $order->update_status($paid_status);
                  $order->add_order_note(__('ALFAcoins invoice paid. Awaiting network confirmation and payment completed status.', 'alfacoins'));
                }

                break;

              // The complete status is when the Cryptocurrency network
              // obtains 6 confirmations for this transaction.
              case 'completed':

                $this->log('    [Info] IPN response is a "complete" message.');

                if ($current_status == $complete_status ||
                  'wc_' . $current_status == $complete_status ||
                  $current_status == 'completed'
                ) {
                  $error_string = 'Completed IPN, but order has status: ' . $current_status;
                  $this->log("    [Warning] $error_string");

                }
                else {
                  $this->log('    [Info] This order has not been updated yet so setting complete status...');

                  $order->payment_complete();
                  $order->update_status($complete_status);
                  $order->add_order_note(__('ALFAcoins invoice payment completed. Payment credited to your merchant account.', 'alfacoins'));
                }

                break;

              // This order is invalid for some reason.
              // Either it's a double spend or some other
              // problem occurred.
              case 'expired':

                $this->log('    [Info] IPN response is a "invalid" message.');

                if ($current_status == $complete_status ||
                  'wc_' . $current_status == $complete_status ||
                  $current_status == 'completed'
                ) {
                  $error_string = 'Expireds IPN, but order has status: ' . $current_status;
                  $this->log("    [Warning] $error_string");

                }
                else {
                  $this->log('    [Info] This order has a problem so setting "cancelled" status...');

                  $order->update_status($order_states['cancelled'], __('Payment is expired for this order! The payment was not confirmed by the network within 1 hour. Do not ship the product for this order!', 'alfacoins'));
                }

                break;

              // There was an unknown message received.
              default:

                $this->log('    [Info] IPN response is an unknown message type. See error message below:');

                $error_string = 'Unhandled invoice status: ' . $checkStatus;
                $this->log("    [Warning] $error_string");
            }
          }
        }
        else {
          $this->log('    [Warning] IPN response has invalid hash or currency');
        }

      }
      else {
        wp_die('Invalid IPN');
      }


      $this->log('    [Info] Leaving ipn_callback()...');
    }

    public function log($message) {
      if (TRUE === isset($this->debug) && 'yes' == $this->debug) {
        if (FALSE === isset($this->logger) || TRUE === empty($this->logger)) {
          $this->logger = new WC_Logger();
        }

        $this->logger->add('alfacoins', $message);
      }
    }

  }

  /**
   * Add ALFAcoins Payment Gateway to WooCommerce
   **/
  function wc_add_alfacoins($methods) {
    $methods[] = 'WC_Gateway_ALFAcoins';

    return $methods;
  }

  add_filter('woocommerce_payment_gateways', 'wc_add_alfacoins');

  /**
   * Add Settings link to the plugin entry in the plugins menu
   **/
  add_filter('plugin_action_links', 'alfacoins_plugin_action_links', 10, 2);

  function alfacoins_plugin_action_links($links, $file) {
    static $this_plugin;

    if (FALSE === isset($this_plugin) || TRUE === empty($this_plugin)) {
      $this_plugin = plugin_basename(__FILE__);
    }

    if ($file == $this_plugin) {
      $log_file = 'alfacoins-' . sanitize_file_name(wp_hash('alfacoins')) . '-log';
      $settings_link = '<a href="' . get_bloginfo('wpurl') . '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=wc_gateway_alfacoins">Settings</a>';
      $logs_link = '<a href="' . get_bloginfo('wpurl') . '/wp-admin/admin.php?page=wc-status&tab=logs&log_file=' . $log_file . '">Logs</a>';
      array_unshift($links, $settings_link, $logs_link);
    }

    return $links;
  }

  add_action('wp_ajax_alfacoins_create_invoice', 'ajax_alfacoins_create_invoice');
}

function woocommerce_alfa_request($url, $params) {
  $content = json_encode($params);

  $curl = curl_init($url);
  curl_setopt($curl, CURLOPT_HEADER, FALSE);
  curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($curl, CURLOPT_HTTPHEADER,
    array("Content-type: application/json; charset=UTF-8"));
  curl_setopt($curl, CURLOPT_POST, TRUE);
  curl_setopt($curl, CURLOPT_POSTFIELDS, $content);

  $json_response = curl_exec($curl);

  $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

  if ($status != 200) {
    //die("Error: call to URL $url failed with status $status, response $json_response, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl));
    throw new \Exception("Error: call to URL $url failed with status $status, response $json_response, curl_error " . curl_error($curl) . ", curl_errno " . curl_errno($curl));

  }

  curl_close($curl);

  $response = json_decode($json_response, TRUE);
  return $response;
}

function woocommerce_alfacoins_failed_requirements() {
  global $wp_version;
  global $woocommerce;

  $errors = array();

  // PHP 5.4+ required
  if (TRUE === version_compare(PHP_VERSION, '5.4.0', '<')) {
    $errors[] = 'Your PHP version is too old. The ALFAcoins payment plugin requires PHP 5.4 or higher to function. Please contact your web server administrator for assistance.';
  }

  // Wordpress 3.9+ required
  if (TRUE === version_compare($wp_version, '3.9', '<')) {
    $errors[] = 'Your WordPress version is too old. The ALFAcoins payment plugin requires Wordpress 3.9 or higher to function. Please contact your web server administrator for assistance.';
  }

  // WooCommerce required
  if (TRUE === empty($woocommerce)) {
    $errors[] = 'The WooCommerce plugin for WordPress needs to be installed and activated. Please contact your web server administrator for assistance.';
  }
  elseif (TRUE === version_compare($woocommerce->version, '2.2', '<')) {
    $errors[] = 'Your WooCommerce version is too old. The ALFAcoins payment plugin requires WooCommerce 2.2 or higher to function. Your version is ' . $woocommerce->version . '. Please contact your web server administrator for assistance.';
  }

  // Curl required
  if (FALSE === extension_loaded('curl')) {
    $errors[] = 'The ALFAcoins payment plugin requires the Curl extension for PHP in order to function. Please contact your web server administrator for assistance.';
  }

  if (FALSE === empty($errors)) {
    return implode("<br>\n", $errors);
  }
  else {
    return FALSE;
  }

}

// Activating the plugin
function woocommerce_alfacoins_activate() {
  // Check for Requirements
  $failed = woocommerce_alfacoins_failed_requirements();

  $plugins_url = admin_url('plugins.php');

  // Requirements met, activate the plugin
  if ($failed === FALSE) {
    update_option('woocommerce_alfacoins_version', '0.7');
  }
  else {
    // Requirements not met, return an error message
    wp_die($failed . '<br><a href="' . $plugins_url . '">Return to plugins screen</a>');
  }
}
