<?php
/*
Plugin Name: LinkPay
Plugin URI:  http://linkpay.id
Description: LinkPay Checkout Plugin for WooCommerce
Version: 1.0.2
Author: linkpayid@gmail.com
Text Domain: linkpay
 */

// Prevent direct access
if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', 'woocommerce_linkpay', 0);

function woocommerce_linkpay()
{
    load_plugin_textdomain('linkpay', false, dirname(plugin_basename(__FILE__)) . '/lang/');

    // Do nothing, if WooCommerce is not available
    if (!class_exists('WC_Payment_Gateway'))
        return;

    // Do not re-declare class
    if (class_exists('WC_LINKPAY'))
        return;

    class WC_LINKPAY extends WC_Payment_Gateway
    {
        protected $merchant_id;
        protected $checkout_url;

        public function __construct()
        {
            $plugin_dir = plugin_dir_url(__FILE__);
            $this->id = 'linkpay';
            $this->title = 'LinkPay';
            $this->description = __('Payment system LinkPay', 'linkpay');
            $this->icon = apply_filters('woocommerce_linkpay_icon', '' . $plugin_dir . 'linkpay.png');
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();

            // Populate options from the saved settings
            $this->merchant_id = $this->get_option('merchant_id');
            $this->checkout_url = $this->get_option('checkout_url');

            add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            add_action('woocommerce_api_wc_' . $this->id, [$this, 'callback']);
        }

        public function admin_options()
        {
            ?>
            <h3><?php _e('LinkPay', 'linkpay'); ?></h3>

            <p><?php _e('Configure checkout settings', 'linkpay'); ?></p>

            <p>
                <strong><?php _e('Your Web Cash Endpoint URL to handle requests is:', 'linkpay'); ?></strong>
                <em><?= site_url('/?wc-api=wc_linkpay'); ?></em>
            </p>

            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table>
            <?php
        }

        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title' => __('Enable/Disable', 'linkpay'),
                    'type' => 'checkbox',
                    'label' => __('Enabled', 'linkpay'),
                    'default' => 'yes'
                ],
                'merchant_id' => [
                    'title' => __('Merchant ID', 'linkpay'),
                    'type' => 'text',
                    'description' => __('Obtain and set Merchant ID from LinkPay', 'linkpay'),
                    'default' => ''
                ],
                'checkout_url' => [
                    'title' => __('Checkout URL', 'linkpay'),
                    'type' => 'text',
                    'description' => __('Set LinkPay Checkout URL to submit a payment', 'linkpay'),
                    'default' => 'http://www.linkpay.id/SCI/form'
                ]
            ];
        }

        public function generate_form($order_id)
        {
            // get order by id
            $order = new WC_Order($order_id);

            // convert an amount to the coins (Linkpay accepts only coins)
            $sum = $order->order_total * 100;

            // format the amount
            $sum = number_format($sum, 0, '.', '');

            $description = sprintf(__('Payment for Order #%1$s', 'linkpay'), $order_id);

            $lang_codes = ['ru_RU' => 'ru', 'en_US' => 'en', 'uz_UZ' => 'uz'];
            $lang = isset($lang_codes[get_locale()]) ? $lang_codes[get_locale()] : 'en';

            $label_pay = __('Pay', 'linkpay');
            $label_cancel = __('Cancel payment and return back', 'linkpay');

            $form =
<<<FORM
    <form action="{$this->checkout_url}" method="POST" id="linkpay_form">
        <input type="hidden" name="merchant" value="{$this->merchant_id}">
        <input type="hidden" name="item_name" value="Baju">
        <input type="hidden" name="amount" value="$sum">
        <input type="hidden" name="currency" value="debit_base">
        <input type="hidden" name="custom" value="$description">
        <input type="submit" class="button alt" id="submit_linkpay_form" value="$label_pay">
        <input type="button" class="button cancel" id="cancel_linkpay_form" value="$label_cancel" onclick="location.href='{$order->get_cancel_order_url()}'">
    </form>
FORM;

            return $form;
        }

        public function process_payment($order_id)
        {
            $order = new WC_Order($order_id);

            return [
                'result' => 'success',
                'redirect' => add_query_arg(
                    'order',
                    $order->get_id(),
                    add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay')))
                )
            ];
        }

        public function receipt_page($order_id)
        {
            $order = new WC_Order($order_id);
            
            echo '<p>' . __('Thank you for your order, press "Pay" button to continue.', 'payme') . '</p>';
            echo $this->generate_form($order_id);

            if (('#cancel_linkpay_form') == TRUE) {
              <<<FORM
    <form action="{$this->checkout_url}" method="POST" id="linkpay_form">
        <input type="hidden" name="merchant" value="{$this->merchant_id}">
        <input type="hidden" name="item_name" value="Baju">
        <input type="hidden" name="amount" value="$sum">
        <input type="hidden" name="currency" value="debit_base">
        <input type="hidden" name="custom" value="$description">
        <input type="submit" class="button alt" id="submit_linkpay_form" value="$label_pay">
        <input type="button" class="button cancel" id="cancel_linkpay_form" value="$label_cancel" onclick="location.href='{$order->get_cancel_order_url()}'">
    </form>
FORM;
            }
            else {
                // Reduce stock levels
                wc_reduce_stock_levels( $order_id );

                // Remove cart
                WC()->cart->empty_cart();

                $order->payment_complete();
            }
        }

        /**
         * Endpoint method. This method handles requests from Paycom.
         */
        public function callback()
        {
            // Parse payload
            $payload = json_decode(file_get_contents('php://input'), true);

            if (json_last_error() !== JSON_ERROR_NONE) { // handle Parse error
                $this->respond($this->error_invalid_json());
            }

            // Authorize client
            $headers = getallheaders();
            $encoded_credentials = base64_encode("Paycom:{$this->merchant_key}");
            if (!$headers || // there is no headers
                !isset($headers['Authorization']) || // there is no Authorization
                !preg_match('/^\s*Basic\s+(\S+)\s*$/i', $headers['Authorization'], $matches) || // invalid Authorization value
                $matches[1] != $encoded_credentials // invalid credentials
            ) {
                $this->respond($this->error_authorization($payload));
            }

            // Execute appropriate method
            $response = method_exists($this, $payload['method'])
                ? $this->{$payload['method']}($payload)
                : $this->error_unknown_method($payload);

            // Respond with result
            $this->respond($response);
        }

        /**
         * Responds and terminates request processing.
         * @param array $response specified response
         */
        private function respond($response)
        {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }

            echo json_encode($response);
            die();
        }

        /**
         * Gets order instance by id.
         * @param array $payload request payload
         * @return WC_Order found order by id
         */
        private function get_order(array $payload)
        {
            try {
                return new WC_Order($payload['params']['account']['order_id']);
            } catch (Exception $ex) {
                $this->respond($this->error_order_id($payload));
            }
        }

        /**
         * Gets order instance by transaction id.
         * @param array $payload request payload
         * @return WC_Order found order by id
         */
        private function get_order_by_transaction($payload)
        {
            global $wpdb;

            try {
                $prepared_sql = $wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_value = '%s' AND meta_key = '_payme_transaction_id'", $payload['params']['id']);
                $order_id = $wpdb->get_var($prepared_sql);
                return new WC_Order($order_id);
            } catch (Exception $ex) {
                $this->respond($this->error_transaction($payload));
            }
        }

        /**
         * Converts amount to coins.
         * @param float $amount amount value.
         * @return int Amount representation in coins.
         */
        private function amount_to_coin($amount)
        {
            return 100 * number_format($amount, 0, '.', '');
        }

        /**
         * Gets current timestamp in milliseconds.
         * @return float current timestamp in ms.
         */
        private function current_timestamp()
        {
            return round(microtime(true) * 1000);
        }

        /**
         * Get order's create time.
         * @param WC_Order $order order
         * @return float create time as timestamp
         */
        private function get_create_time(WC_Order $order)
        {
            return (double)get_post_meta($order->get_id(), '_linkpay_create_time', true);
        }

        /**
         * Get order's perform time.
         * @param WC_Order $order order
         * @return float perform time as timestamp
         */
        private function get_perform_time(WC_Order $order)
        {
            return (double)get_post_meta($order->get_id(), '_linkpay_perform_time', true);
        }

        /**
         * Get order's cancel time.
         * @param WC_Order $order order
         * @return float cancel time as timestamp
         */
        private function get_cancel_time(WC_Order $order)
        {
            return (double)get_post_meta($order->get_id(), '_linkpay_cancel_time', true);
        }

        /**
         * Get order's transaction id
         * @param WC_Order $order order
         * @return string saved transaction id
         */
        private function get_transaction_id(WC_Order $order)
        {
            return (string)get_post_meta($order->get_id(), '_linkpay_transaction_id', true);
        }

        private function CheckPerformTransaction($payload)
        {
            $order = $this->get_order($payload);
            $amount = $this->amount_to_coin($order->order_total);

            if ($amount != $payload['params']['amount']) {
                $response = $this->error_amount($payload);
            } else {
                $response = [
                    'id' => $payload['id'],
                    'result' => [
                        'allow' => true
                    ],
                    'error' => null
                ];
            }

            return $response;
        }

        private function CreateTransaction($payload)
        {
            $order = $this->get_order($payload);
            $amount = $this->amount_to_coin($order->order_total);

            if ($amount != $payload['params']['amount']) {
                $response = $this->error_amount($payload);
            } else {
                $create_time = $this->current_timestamp();
                $transaction_id = $payload['params']['id'];
                $saved_transaction_id = $this->get_transaction_id($order);

                if ($order->status == "pending") { // handle new transaction
                    // Save time and transaction id
                    add_post_meta($order->get_id(), '_linkpay_create_time', $create_time, true);
                    add_post_meta($order->get_id(), '_linkpay_transaction_id', $transaction_id, true);

                    // Change order's status to Processing
                    $order->update_status('processing');

                    $response = [
                        "id" => $payload['id'],
                        "result" => [
                            "create_time" => $create_time,
                            "transaction" => "000" . $order->get_id(),
                            "state" => 1
                        ]
                    ];
                } elseif ($order->status == "processing" && $transaction_id == $saved_transaction_id) { // handle existing transaction
                    $response = [
                        "id" => $payload['id'],
                        "result" => [
                            "create_time" => $create_time,
                            "transaction" => "000" . $order->get_id(),
                            "state" => 1
                        ]
                    ];
                } elseif ($order->status == "processing" && $transaction_id !== $saved_transaction_id) { // handle new transaction with the same order
                    $response = $this->error_has_another_transaction($payload);
                } else {
                    $response = $this->error_unknown($payload);
                }
            }

            return $response;
        }

        private function PerformTransaction($payload)
        {
            $perform_time = $this->current_timestamp();
            $order = $this->get_order_by_transaction($payload);

            if ($order->status == "processing") { // handle new Perform request
                // Save perform time
                add_post_meta($order->get_id(), '_linkpay_perform_time', $perform_time, true);

                $response = [
                    "id" => $payload['id'],
                    "result" => [
                        "transaction" => "000" . $order->get_id(),
                        "perform_time" => $this->get_perform_time($order),
                        "state" => 2
                    ]
                ];

                // Mark order as completed
                $order->update_status('completed');
                $order->payment_complete($payload['params']['id']);
            } elseif ($order->status == "completed") { // handle existing Perform request
                $response = [
                    "id" => $payload['id'],
                    "result" => [
                        "transaction" => "000" . $order->get_id(),
                        "perform_time" => $this->get_perform_time($order),
                        "state" => 2
                    ]
                ];
            } elseif ($order->status == "cancelled" || $order->status == "refunded") { // handle cancelled order
                $response = $this->error_cancelled_transaction($payload);
            } else {
                $response = $this->error_unknown($payload);
            }

            return $response;
        }

        private function CheckTransaction($payload)
        {
            $transaction_id = $payload['params']['id'];
            $order = $this->get_order_by_transaction($payload);

            // Get transaction id from the order
            $saved_transaction_id = $this->get_transaction_id($order);

            $response = [
                "id" => $payload['id'],
                "result" => [
                    "create_time" => $this->get_create_time($order),
                    "perform_time" => 0,
                    "cancel_time" => 0,
                    "transaction" => "000" . $order->get_id(),
                    "state" => null,
                    "reason" => null
                ],
                "error" => null
            ];

            if ($transaction_id == $saved_transaction_id) {
                switch ($order->status) {
                    case 'processing':
                        $response['result']['state'] = 1;
                        break;

                    case 'completed':
                        $response['result']['state'] = 2;
                        break;

                    case 'cancelled':
                        $response['result']['state'] = -1;
                        $response['result']['reason'] = 2;
                        $response['result']['cancel_time'] = $this->get_cancel_time($order);
                        break;

                    case 'refunded':
                        $response['result']['state'] = -2;
                        $response['result']['reason'] = 5;
                        $response['result']['perform_time'] = $this->get_perform_time($order);
                        $response['result']['cancel_time'] = $this->get_cancel_time($order);
                        break;

                    default:
                        $response = $this->error_transaction($payload);
                        break;
                }
            } else {
                $response = $this->error_transaction($payload);
            }

            return $response;
        }

        private function CancelTransaction($payload)
        {
            $order = $this->get_order_by_transaction($payload);

            $transaction_id = $payload['params']['id'];
            $saved_transaction_id = $this->get_transaction_id($order);

            if ($transaction_id == $saved_transaction_id) {

                $cancel_time = $this->current_timestamp();

                $response = [
                    "id" => $payload['id'],
                    "result" => [
                        "transaction" => "000" . $order->get_id(),
                        "cancel_time" => $cancel_time,
                        "state" => null
                    ]
                ];

                switch ($order->status) {
                    case 'pending':
                        add_post_meta($order->get_id(), '_linkpay_cancel_time', $cancel_time, true); // Save cancel time
                        $order->update_status('cancelled'); // Change status to Cancelled
                        $response['result']['state'] = -1;
                        break;

                    case 'completed':
                        add_post_meta($order->get_id(), '_linkpay_cancel_time', $cancel_time, true); // Save cancel time
                        $order->update_status('refunded'); // Change status to Refunded
                        $response['result']['state'] = -2;
                        break;

                    case 'cancelled':
                        $response['result']['cancel_time'] = $this->get_cancel_time($order);
                        $response['result']['state'] = -1;
                        break;

                    case 'refunded':
                        $response['result']['cancel_time'] = $this->get_cancel_time($order);
                        $response['result']['state'] = -2;
                        break;

                    default:
                        $response = $this->error_cancel($payload);
                        break;
                }
            } else {
                $response = $this->error_transaction($payload);
            }

            return $response;
        }

        private function ChangePassword($payload)
        {
            if ($payload['params']['password'] != $this->merchant_key) {
                $woo_options = get_option('woocommerce_linkpay_settings');

                if (!$woo_options) { // No options found
                    return $this->error_password($payload);
                }

                // Save new password
                $woo_options['merchant_key'] = $payload['params']['password'];
                $is_success = update_option('woocommerce_linkpay_settings', $woo_options);

                if (!$is_success) { // Couldn't save new password
                    return $this->error_password($payload);
                }

                return [
                    "id" => $payload['id'],
                    "result" => ["success" => true],
                    "error" => null
                ];
            }

            // Same password or something wrong
            return $this->error_password($payload);
        }

        private function error_password($payload)
        {
            $response = [
                "error" => [
                    "code" => -32400,
                    "message" => [
                        "ru" => __('Cannot change the password', 'linkpay'),
                        "uz" => __('Cannot change the password', 'linkpay'),
                        "en" => __('Cannot change the password', 'linkpay')
                    ],
                    "data" => "password"
                ],
                "result" => null,
                "id" => $payload['id']
            ];

            return $response;
        }

        private function error_invalid_json()
        {
            $response = [
                "error" => [
                    "code" => -32700,
                    "message" => [
                        "ru" => __('Could not parse JSON', 'linkpay'),
                        "uz" => __('Could not parse JSON', 'linkpay'),
                        "en" => __('Could not parse JSON', 'linkpay')
                    ],
                    "data" => null
                ],
                "result" => null,
                "id" => 0
            ];

            return $response;
        }

        private function error_order_id($payload)
        {
            $response = [
                "error" => [
                    "code" => -31099,
                    "message" => [
                        "ru" => __('Order number cannot be found', 'linkpay'),
                        "uz" => __('Order number cannot be found', 'linkpay'),
                        "en" => __('Order number cannot be found', 'linkpay')
                    ],
                    "data" => "order"
                ],
                "result" => null,
                "id" => $payload['id']
            ];

            return $response;
        }

        private function error_has_another_transaction($payload)
        {
            $response = [
                "error" => [
                    "code" => -31099,
                    "message" => [
                        "ru" => __('Other transaction for this order is in progress', 'linkpay'),
                        "uz" => __('Other transaction for this order is in progress', 'linkpay'),
                        "en" => __('Other transaction for this order is in progress', 'linkpay')
                    ],
                    "data" => "order"
                ],
                "result" => null,
                "id" => $payload['id']
            ];

            return $response;
        }

        private function error_amount($payload)
        {
            $response = [
                "error" => [
                    "code" => -31001,
                    "message" => [
                        "ru" => __('Order amount is incorrect', 'linkpay'),
                        "uz" => __('Order amount is incorrect', 'linkpay'),
                        "en" => __('Order amount is incorrect', 'linkpay')
                    ],
                    "data" => "amount"
                ],
                "result" => null,
                "id" => $payload['id']
            ];

            return $response;
        }

        private function error_unknown($payload)
        {
            $response = [
                "error" => [
                    "code" => -31008,
                    "message" => [
                        "ru" => __('Unknown error', 'linkpay'),
                        "uz" => __('Unknown error', 'linkpay'),
                        "en" => __('Unknown error', 'linkpay')
                    ],
                    "data" => null
                ],
                "result" => null,
                "id" => $payload['id']
            ];

            return $response;
        }

        private function error_unknown_method($payload)
        {
            $response = [
                "error" => [
                    "code" => -32601,
                    "message" => [
                        "ru" => __('Unknown method', 'linkpay'),
                        "uz" => __('Unknown method', 'linkpay'),
                        "en" => __('Unknown method', 'linkpay')
                    ],
                    "data" => $payload['method']
                ],
                "result" => null,
                "id" => $payload['id']
            ];

            return $response;
        }

        private function error_transaction($payload)
        {
            $response = [
                "error" => [
                    "code" => -31003,
                    "message" => [
                        "ru" => __('Transaction number is wrong', 'linkpay'),
                        "uz" => __('Transaction number is wrong', 'linkpay'),
                        "en" => __('Transaction number is wrong', 'linkpay')
                    ],
                    "data" => "id"
                ],
                "result" => null,
                "id" => $payload['id']
            ];

            return $response;
        }

        private function error_cancelled_transaction($payload)
        {
            $response = [
                "error" => [
                    "code" => -31008,
                    "message" => [
                        "ru" => __('Transaction was cancelled or refunded', 'linkpay'),
                        "uz" => __('Transaction was cancelled or refunded', 'linkpay'),
                        "en" => __('Transaction was cancelled or refunded', 'linkpay')
                    ],
                    "data" => "order"
                ],
                "result" => null,
                "id" => $payload['id']
            ];

            return $response;
        }

        private function error_cancel($payload)
        {
            $response = [
                "error" => [
                    "code" => -31007,
                    "message" => [
                        "ru" => __('It is impossible to cancel. The order is completed', 'linkpay'),
                        "uz" => __('It is impossible to cancel. The order is completed', 'linkpay'),
                        "en" => __('It is impossible to cancel. The order is completed', 'linkpay')
                    ],
                    "data" => "order"
                ],
                "result" => null,
                "id" => $payload['id']
            ];

            return $response;
        }

        private function error_authorization($payload)
        {
            $response = [
                "error" =>
                    [
                        "code" => -32504,
                        "message" => [
                            "ru" => __('Error during authorization', 'linkpay'),
                            "uz" => __('Error during authorization', 'linkpay'),
                            "en" => __('Error during authorization', 'linkpay')
                        ],
                        "data" => null
                    ],
                "result" => null,
                "id" => $payload['id']
            ];

            return $response;
        }
    }

    // Register new Gateway

    function add_linkpay_gateway($methods)
    {
        $methods[] = 'WC_LINKPAY';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_linkpay_gateway');
}

?>