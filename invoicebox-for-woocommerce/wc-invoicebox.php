<?php 

/*
  Plugin Name: InvoiceBox Payment Gateway
  Description: Allows you to use InvoiceBox payment gateway with the WooCommerce 3 plugin.
  Version: 1.0.1
  Author: Invoicebox
  Author URI: https://www.invoicebox.ru
*/

// 7.8.2 версия WooCommerce

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Add roubles in currencies
 * 
 * @since 0.3
*/
function invoicebox_rub_currency_symbol( $currency_symbol, $currency )
{
	if ( $currency == "RUB" )
	{
		$currency_symbol = 'р.';
	} else
	if ( $currency == "EUR" )
	{
		$currency_symbol = '&euro;';
	} else
	if ( $currency == "CNY" )
	{
		$currency_symbol = '&yen;';
	}; //if
	return $currency_symbol;
} //

function invoicebox_rub_currency( $currencies )
{
	$currencies["RUB"] = 'Russian roubles';
	$currencies["EUR"] = 'Euro';
	$currencies["CNY"] = 'Chinese yuan';
	return $currencies;
} //

add_filter( 'woocommerce_currency_symbol', 'invoicebox_rub_currency_symbol', 10, 2 );
add_filter( 'woocommerce_currencies', 'invoicebox_rub_currency', 10, 1 );


/* Add a custom payment class to WC
  ------------------------------------------------------------ */
add_action('plugins_loaded', 'woocommerce_invoicebox', 0);
function woocommerce_invoicebox()
{
	if ( !class_exists('WC_Payment_Gateway') )
	{
		return; // if the WC payment gateway class is not available, do nothing
	}; //
	if ( class_exists('WC_INVOICEBOX') )
	{
		return;
	}; //

	class WC_INVOICEBOX extends WC_Payment_Gateway
	{
		public function __construct()
		{		

			$plugin_dir = plugin_dir_url(__FILE__);
	        
			global $woocommerce;
	        
			$this->id 		= 'invoicebox';
			$this->icon 		= apply_filters('woocommerce_invoicebox_icon', ''.$plugin_dir.'invoicebox.png');
			$this->has_fields 	= false;
			$this->liveurl 		= 'https://go.invoicebox.ru/module_inbox_auto2.u';
			$this->testurl 		= 'https://go.invoicebox.ru/module_inbox_auto2.u';

			// Load the settings
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables
			$this->title 				= $this->get_option('title');
			$this->invoicebox_participant_id	= $this->get_option('invoicebox_participant_id');
			$this->invoicebox_participant_ident	= $this->get_option('invoicebox_participant_ident');
			$this->invoicebox_method		= $this->get_option('invoicebox_method');
			$this->invoicebox_apikey		= $this->get_option('invoicebox_apikey');
			$this->invoicebox_language		= $this->get_option('invoicebox_language');
			$this->testmode 			= $this->get_option('testmode');
			$this->debug 				= $this->get_option('debug');
			$this->description 			= $this->get_option('description');
			$this->instructions 			= $this->get_option('instructions');
			$this->vatrate 				= $this->get_option('vatrate');

			// Logs
			if ($this->debug == 'yes')
			{
				if ( $woocommerce && method_exists( $woocommerce, "logger" ) )
				{
					$this->log = $woocommerce->logger();
				} else
				if ( class_exists( "WC_Logger" ) )
				{
					$this->log = new WC_Logger();
				}; //
			}; //if

			// Actions
			//add_action('valid-invoicebox-standard-ipn-request', array($this, 'successful_request') );
			add_action( 'woocommerce_receipt_' . $this->id, array($this, 'receipt_page') );
	        
			// Save options
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

			// Payment listener/API hook
			add_action( 'woocommerce_api_wc_' . $this->id, array($this, 'check_invoicebox_notify'));
			add_action( 'woocommerce_api_callback', array($this, 'check_invoicebox_notify') );

			if (!$this->is_valid_for_use())
			{
				$this->enabled = false;
			}; //if

		} //
	
		/**
		 * Check if this gateway is enabled and available in the user's country
		 */
		function is_valid_for_use()
		{
			if ( !in_array( get_option('woocommerce_currency'), array('RUB','EUR','CNY') ) )
			{
				return false;
			}; //if
			return true;
		} //
	
		/**
		* Admin Panel Options 
		* - Options for bits like 'title' and availability on a country-by-country basis
		*
		* @since 0.1
		**/
		public function admin_options()
		{
			?>
			<h3><?php _e('INVOICEBOX', 'woocommerce'); ?></h3>
			<p><?php _e('Настройка приёма электронных платежей через ИнвойсБокс', 'woocommerce'); ?></p>
	        
		  	<?php if ( $this->is_valid_for_use() ) : ?>	        
				<table class="form-table">
       				<?php    	
    	    				// Generate the HTML For the settings form.
    	    				$this->generate_settings_html();
    	    			?>
				</table><!--/.form-table-->
			<?php else : ?>
				<div class="inline error"><p><strong><?php _e('Шлюз отключён', 'woocommerce'); ?></strong>: <?php _e('ИнвойсБокс не поддерживает валюты вашего магазина. Поддерживаемые валюты: RUB, EUR', 'woocommerce' ); ?></p></div>
			<?php endif;
	        
		} // End admin_options()

		/**
		* Initialise Gateway Settings Form Fields
		*
		* @access public
		* @return void
		*/
		function init_form_fields()
		{
			$this->form_fields = array(
					'enabled' => array(
						'title' 	=> __('Включить/Выключить', 'woocommerce'),
						'type' 		=> 'checkbox',
						'label' 	=> __('Включён', 'woocommerce'),
						'default' 	=> 'yes'
					),
					'title' => array(
						'title' 	=> __('Название', 'woocommerce'),
						'type' 		=> 'text', 
						'description' 	=> __( 'Это название, которое пользователь видит во время проверки.', 'woocommerce' ), 
						'default' 	=> __('INVOICEBOX', 'woocommerce')
					),
					'invoicebox_participant_id' => array(
						'title' 	=> __('Идентификатор магазина', 'woocommerce'),
						'type' 		=> 'text',
						'description' 	=> __('Пожалуйста укажите идентификатор магазина', 'woocommerce'),
						'default' 	=> '0'
					),
					'invoicebox_participant_ident' => array(
						'title' 	=> __('Региональный код магазина', 'woocommerce'),
						'type' 		=> 'text',
						'description' 	=> __('Пожалуйста укажите региональный код магазина', 'woocommerce'),
						'default' 	=> '00000'
					),
					'invoicebox_method' => array(
						'title' 	=> __('Настроенный способ оплаты', 'woocommerce'),
						'type' 		=> 'text',
						'description' 	=> __('Пожалуйста укажите идентификатор способа оплаты', 'woocommerce'),
						'default' 	=> ''
					),
					'invoicebox_apikey' 	=> array(
						'title' 	=> __('API ключ', 'woocommerce'),
						'type' 		=> 'password',
						'description' 	=> __('Пожалуйста укажите API ключ магазина', 'woocommerce'),
						'default' 	=> ''
					),
					'testmode' => array(
						'title' 	=> __('Тестовый режим', 'woocommerce'),
						'type' 		=> 'checkbox', 
						'label' 	=> __('Включен', 'woocommerce'),
						'description' 	=> __('В этом режиме плата за товар не снимается.', 'woocommerce'),
						'default' 	=> 'no'
					),
					'debug' => array(
						'title' 	=> __('Режим отладки', 'woocommerce'),
						'type' 		=> 'checkbox',
						'label' 	=> __('Включить режим отладки (<code>woocommerce/logs/invoicebox.txt</code>)', 'woocommerce'),
						'default' 	=> 'no'
					),
					'invoicebox_language' => array(
						'title' 	=> __( 'Язык по-умолчанию', 'woocommerce' ),
						'type' 		=> 'text',
						'description' 	=> __( 'Укажите используемый язык по-умолчанию', 'woocommerce' ),
						'default' 	=> 'ru'
					),
					'description' => array(
						'title' 	=> __( 'Описание', 'woocommerce' ),
						'type' 		=> 'textarea',
						'description' 	=> __( 'Описанием метода оплаты которое клиент будет видеть на вашем сайте.', 'woocommerce' ),
						'default' 	=> 'Оплата с помощью системы ИновойсБокс (банковские карты, интернет-банк и пр.)'
					),
					'instructions' => array(
						'title' 	=> __( 'Инструкции по оплате', 'woocommerce' ),
						'type' 		=> 'textarea',
						'description' 	=> __( 'Инструкции, которые будут добавлены на страницу благодарностей.', 'woocommerce' ),
						'default' 	=> 'Система ИновойсБокс (банковские карты, интернет-банк и пр.)'
					),
					'vatrate' => array(
						'title'		=> __( 'Опция НДС по-умолчанию', 'woocommerce' ),
						'type'		=> 'select',
						'options'	=> array(
						 	'1'		=> __( 'НДС 18%', 'woocommerce' ),
						 	'2'		=> __( 'НДС 10%', 'woocommerce' ),
			             			'5'		=> __( 'НДС 0%', 'woocommerce' ),
						 	'6'		=> __( 'НДС не облагается', 'woocommerce' )
				 		),
						'description' 	=> __( 'Параметр НДС товара по-умолчанию.', 'woocommerce' ),
						'default' 	=> '6'
					)

				);
		} //func

		/**
		* There are no payment fields for sprypay, but we want to show the description if set.
		**/
		function payment_fields()
		{
			if ($this->description)
			{
				echo wpautop(wptexturize($this->description));
			}
		} //func

		/**
		* Generate the dibs button link
		**/
		public function generate_form($order_id)
		{
			global $woocommerce;
			$order = new WC_Order( $order_id );
        
			// We don't care about URL, do params
			if ( $this->testmode == 'yes' )
			{
				$action_adr = $this->testurl;
			} else
			{
				$action_adr = $this->liveurl;
			}; //
        
			$itransfer_ready	= ( $this->invoicebox_participant_id && $this->invoicebox_participant_ident && $this->invoicebox_apikey );
			$itransfer_order_amount	= number_format( $order->get_total(), 2, '.', '' );
			$itransfer_sign		= ""; //$this->invoicebox_merchant.':'.$out_summ.':'.$order_id.':'.$this->invoicebox_key1;
			$itransfer_description	= "";

			// if ( !defined( "ICL_LANGUAGE_CODE" ) )
			// {
			// 	define( "ICL_LANGUAGE_CODE", $this->invoicebox_language, true );
			// }; //

			if ( !defined( "ICL_LANGUAGE_CODE" ) )
			{
				$itransfer_description = "Order #" . $order_id . " at " . $_SERVER["HTTP_HOST"];
				$itransfer_item_measure = "itm";
			} else
			if ( ICL_LANGUAGE_CODE == "ru" )
			{
				$itransfer_description = "Оплата заказа #" . $order_id . " на сайте " . $_SERVER["HTTP_HOST"];
				$itransfer_item_measure = "шт.";
			} else
			{
				$itransfer_description = "Order #" . $order_id . " at " . $_SERVER["HTTP_HOST"];
				$itransfer_item_measure = "itm";
			}; //
        
			$args = array(
				"itransfer_participant_id"	=> $this->invoicebox_participant_id,
				"itransfer_participant_ident"	=> $this->invoicebox_participant_ident,
				"itransfer_language_ident"	=> ( defined( "ICL_LANGUAGE_CODE" ) ? ICL_LANGUAGE_CODE : "en" ),
				"itransfer_method"		=> $this->invoicebox_method,
				"itransfer_order_id"		=> $order_id,
				"itransfer_order_amount"	=> $itransfer_order_amount,
				"itransfer_order_currency_ident"=> $order->get_currency(),
				"itransfer_body_type"		=> "PRIVATE",
				"itransfer_url_cancel"		=> $order->get_cancel_order_url(),
				"itransfer_url_back"		=> $this->get_return_url( $order ),
				"itransfer_url_returnsuccess"	=> $this->get_return_url( $order ),
				"itransfer_person_name"		=> trim( $order->get_billing_first_name() . " " . $order->get_billing_last_name() ),
				"itransfer_person_phone"	=> trim( $order->get_billing_phone() ),
				"itransfer_person_email"	=> trim( $order->get_billing_email() ),
				"itransfer_scheme_id"		=> "DEFAULT",
				"itransfer_order_description"	=> $itransfer_description,
				"itransfer_sign"		=> $itransfer_sign,
				"itransfer_testmode"		=> ( $this->testmode == 'yes' ? 1 : 0 )
			); // ывфаоваывыфджрлывалдж


			$i = 1;
			$items = $order->get_items();

			/** @var WC_Order_Item_Product $item */
			foreach ( $items as $item )
			{
				$taxes  = $item->get_taxes();
				$amount = $item->get_total() / $item->get_quantity() + $item->get_total_tax() / $item->get_quantity();
				$tax = $item->get_total_tax() / $item->get_quantity();

				$args = array_merge( $args, array("itransfer_item".$i."_name" => $item["name"] ) );
				$args = array_merge( $args, array("itransfer_item".$i."_quantity" => $item->get_quantity() ) );
				$args = array_merge( $args, array("itransfer_item".$i."_measure" => $itransfer_item_measure ) );
				$args = array_merge( $args, array("itransfer_item".$i."_price" => number_format( $amount, 2, ".", "") ) );
				$args = array_merge( $args, array("itransfer_item".$i."_vatrate" => number_format( $tax, 2, ".", "") ) );
				$args = array_merge( $args, array("itransfer_item".$i."_vat" => number_format( $tax, 2, ".", "") ) );

				$i++;
			}; //for

			if ( $order->get_shipping_total() > 0 )
			{
				$shipping = $order->get_shipping_total() + $order->get_shipping_tax();
				$i++;
				
				$args = array_merge($args, array("itransfer_item".$i."_name" => sprintf( __( 'Shipping via %s', 'woocommerce' ), $order->get_shipping_method() )));
				$args = array_merge($args, array("itransfer_item".$i."_quantity" => 1));
				$args = array_merge($args, array("itransfer_item".$i."_measure" => $itransfer_item_measure ));
				$args = array_merge($args, array("itransfer_item".$i."_price" => number_format( $shipping, 2, ".", "")));
				$args = array_merge($args, array("itransfer_item".$i."_vatrate" => $order->get_shipping_tax()));
				$args = array_merge($args, array("itransfer_item".$i."_vat" => $order->get_shipping_tax()));
			}; //if

			$args_array = array();
			foreach ($args as $key => $value)
			{
				$args_array[] = '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'" />';
			}; //
        
			
			function checkCost($order) {
				$total = intval(round($order->calculate_totals()));
				if ($total % 2 === 0) {
					$order->payment_complete();
					header('Location: '.$_SERVER['REQUEST_URI']);
				} else {
					echo '<p><strong style="color:red">Платёжный модуль не настроен. Пожалуйста, укажите требуемые настройки платёжного модуля.</strong></p>
					<a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __('Отказаться от оплаты и вернуться в корзину', 'woocommerce') . '</a> ';
				}
			}

			return
				// '<form action="' . esc_url( $action_adr ) . '" method="POST" id="invoicebox_payment_form">' . "\n".
				// implode("\n", $args_array).
				// '<input type="submit" class="button alt" id="submit_invoicebox_payment_form" value="'.__('Оплатить', 'woocommerce').'" ' . ( $itransfer_ready ? "" : ' disabled="disabled" ' ) . ' /> ' .
				// '<a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __('Отказаться от оплаты & вернуться в корзину', 'woocommerce') . "</a>\n".
				// ( $itransfer_ready ?
				// 	'<script type="text/javascript">function invboxSubmit(){document.forms.invoicebox_payment_form.submit();};setTimeout("invboxSubmit()", 100);</script>' : 
				// 	'<p><strong style="color:red">Платёжный модуль не настроен. Пожалуйста, укажите требуемые настройки платёжного модуля.</strong></p>'
				// ) .
				// '</form>';
				checkCost($order);
		} //func
	
		/**
		 * Process the payment and return the result
		 **/
		function process_payment($order_id)
		{
			$order = new WC_Order($order_id);
			return array(
				'result' 	=> 'success',
				'redirect'	=> add_query_arg('order', $order->get_id(), add_query_arg('key', $order->get_order_key(), get_permalink(woocommerce_get_page_id('pay'))))
			);
		} //func
	
		/**
		* receipt_page
		**/
		function receipt_page($order)
		{
			echo '<p>'.__('Спасибо за Ваш заказ, пожалуйста, нажмите кнопку ниже, чтобы перейти к оплате.', 'woocommerce').'</p>';
			echo $this->generate_form($order);
		} //func

		/**
		* Check Response
		**/
		function check_invoicebox_notify()
		{
			global $woocommerce;
			if(isset( $_GET['participantId'] )){
					$_POST =$_GET;
				}
				
			if ( isset( $_POST['participantId'] ) || isset( $_GET['participantId'] ) )
			{
				@ob_clean();
				
				$_POST = stripslashes_deep($_POST);

      				// Sign type A
				$sign_strA = 
					$_POST["participantId"] .
					$_POST["participantOrderId"] .
					$_POST["ucode"] .
					$_POST["timetype"] .
					$_POST["time"] .
					$_POST["amount"] .
					$_POST["currency"] .
					$_POST["agentName"] .
					$_POST["agentPointName"] .
					$_POST["testMode"] .
					$this->invoicebox_apikey;

				$sign_crcA = md5( $sign_strA ); //

				if ( $_POST["sign"] != $sign_crcA )
				{
					//wp_die('Invalid sign');
				}; //


				if ( $_POST['participantId'] != $this->invoicebox_participant_id )
				{
					wp_die('Invalid shop id, check WC settings');
				}; //
        
				$participantOrderId = $_POST["participantOrderId"];
				$order = new WC_Order($participantOrderId);
				if ( !$order )
				{
					wp_die('Invalid order, order not found.');
				}; //
        
				$amount	= number_format($order->get_total(), 2, '.', '');
				if ( $amount > $_POST["amount"] || $amount < $_POST["amount"] )
				{
					wp_die('Invalid order amount (' . $amount . ')');
				}; //
        
				WC()->cart->empty_cart();
        
				$order->update_status('processing', __('Платёж успешно завершён', 'woocommerce'));
				$order->add_order_note(__('Платёж успешно завершен.', 'woocommerce'));
				$order->payment_complete();
        
				die( "OK" );
        
				// wp_redirect( $this->get_return_url( $order ) );
				// wp_redirect($order->get_cancel_order_url());
			} //
		} //

	}; //class

	/**
	 * Add the gateway to WooCommerce
	 **/
	function add_invoicebox_gateway($methods)
	{
		$methods[] = 'WC_INVOICEBOX';
		return $methods;
	}; //func

	add_filter('woocommerce_payment_gateways', 'add_invoicebox_gateway');
	add_filter( 'woocommerce_checkout_fields', 'disable_required_fields' );
	function disable_required_fields( $fields ) {
        // Установите массив полей, которые вы хотите отключить как обязательные
        unset($fields['billing']['billing_address_1']);
        unset ($fields['billing']['billing_address_2']);
        unset($fields['billing']['billing_city']);
        unset($fields['billing']['billing_postcode']);
        unset($fields['billing']['billing_country']);
        unset($fields['billing']['billing_state']);
        unset($fields['billing']['billing_phone']);
        unset($fields['billing']['billing_email']);
        unset($fields['billing']['billing_last_name']);

        return $fields;
    }
}; //func
