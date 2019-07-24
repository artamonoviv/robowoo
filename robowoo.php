<?php 
/*
  Plugin Name: RoboWoo — Robokassa payment gateway for WooCommerce
  Description: Provides a <a href="https://www.robokassa.ru" target="_blank">Robokassa</a> gateway for WooCommerce. Supports russian law 54-FZ
  Version: 1.0.6
  Author: Ivan Artamonov
  Author URI: https://artamonoviv.ru
  Plugin URI: https://github.com/artamonoviv/robowoo
  License: GPLv2 or later
  License URI: http://www.gnu.org/licenses/gpl-2.0.html  
*/

if ( ! defined( 'ABSPATH' ) ) exit;

add_action('plugins_loaded', 'init_woocommerce_robokassa');

function init_woocommerce_robokassa()
{
	if ( !class_exists('WC_Payment_Gateway') ) {
		return;
	}
		
	if( class_exists('WC_ROBOKASSA') ) {
		return;
	}

	function add_robokassa_gateway( $methods ) {
		array_push($methods, 'WC_ROBOKASSA');
		return $methods;
	}

	add_filter('woocommerce_payment_gateways', 'add_robokassa_gateway');	
	
	class WC_ROBOKASSA extends WC_Payment_Gateway {
		public function __construct() {
			global $woocommerce;

			$this->id = 'robokassa';
			$this->icon = plugin_dir_url( __FILE__ ).'robokassa.png';
			$this->method_title = "Робокасса";
			$this->method_description = "Позволяет принимать платежы через систему Робокасса";
			$this->has_fields = false;
			$this->robokassa_url = 'https://auth.robokassa.ru/Merchant/Index.aspx';
			$this->robokassa_second_url = 'https://ws.roboxchange.com/RoboFiscal/Receipt/Attach';
			
			$this->init_form_fields();
			$this->init_settings();
			
			$this->title =               ( isset( $this->settings['title'] ) ) ? $this->settings['title'] : '';
			$this->robokassa_merchant =  ( isset( $this->settings['robokassa_merchant'] ) ) ? $this->settings['robokassa_merchant'] : '';
			$this->robokassa_key1 =      ( isset( $this->settings['robokassa_key1'] ) ) ? $this->settings['robokassa_key1'] : '';
			$this->robokassa_key2 =      ( isset( $this->settings['robokassa_key2'] ) ) ? $this->settings['robokassa_key2'] : '';
			$this->debug =               ( isset( $this->settings['debug'] ) ) ? $this->settings['debug'] : '';
			$this->hashcode =            ( isset( $this->settings['hashcode'] ) ) ? $this->settings['hashcode'] : '';
			$this->testmode =            ( isset( $this->settings['testmode'] ) ) ? $this->settings['testmode'] : '';
			$this->receipt =             ( isset( $this->settings['receipt'] ) ) ? $this->settings['receipt'] : '';
			$this->sno_enabled =         ( isset( $this->settings['sno_enabled'] ) ) ? $this->settings['sno_enabled'] : '';
			$this->include_shipping =    ( isset( $this->settings['include_shipping'] ) ) ? $this->settings['include_shipping'] : '';
			$this->sno =                 ( isset( $this->settings['sno'] ) ) ? $this->settings['sno'] : '';
			$this->tax =                 ( isset( $this->settings['tax'] ) ) ? $this->settings['tax'] : 'none';
			$this->payment_method =      ( isset( $this->settings['payment_method'] ) ) ? $this->settings['payment_method'] : 'full_payment';
			$this->payment_object =      ( isset( $this->settings['payment_object'] ) ) ? $this->settings['payment_object'] : 'commodity';
			$this->if_fail =             ( isset( $this->settings['if_fail'] ) ) ? $this->settings['if_fail'] : 'retry';
			$this->lang =                ( isset( $this->settings['lang'] ) ) ? $this->settings['lang'] : 'ru';		
			$this->description =         ( isset( $this->settings['description'] ) ) ? $this->settings['description'] : '';
			$this->submit_button_class = ( isset( $this->settings['submit_button_class'] ) ) ? $this->settings['submit_button_class'] : '';
			$this->cancel_button_class = ( isset( $this->settings['submit_button_class'] ) ) ? $this->settings['cancel_button_class'] : '';
						
			if ( $this->debug == 'yes' ){
				$this->log = new WC_Logger();
			}			

			$woocommerce_currency = get_option('woocommerce_currency');
			if( in_array( $woocommerce_currency, array( 'EUR' , 'USD' )) ) {
				$this->outsumcurrency = $woocommerce_currency;
			}
			
			add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

			add_action( 'woocommerce_api_wc_' . $this->id, array( $this, 'check_ipn_response' ) );

			if ( !$this->is_valid_for_use() ){
				$this->enabled = false;
			}
		}
		
		function is_valid_for_use() {
			if ( !in_array(get_option('woocommerce_currency'), array('RUB', 'EUR', 'USD') ) ) {
				return false;
			}
			return true;
		}
		
		public function admin_options() {
			echo "<h3>Настройки оплаты через Робокассу</h3>";
			if ( $this->is_valid_for_use() ){
				echo '<table class="form-table">';
				$this->generate_settings_html();
				echo '</table>';
			} else {
				echo '<p><strong>Этот способ оплаты отключен, так как Робокасса не поддерживает валюту вашего магазина</strong></p>';
			}
		}

		function init_form_fields() {
		
			$this->form_fields = array(
					'enabled' => array(
						'title' => __( 'Enable/Disable', 'woocommerce' ),
						'type' => 'checkbox',
						'label' => 'Включен',
						'default' => 'yes'
					),
					'title' => array(
						'title' => __( 'Title', 'woocommerce' ),
						'type' => 'text', 
						'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ), 
						'default' => 'Робокасса'
					),
					'description' => array(
						'title' =>  'Описание',
						'type' => 'textarea',
						'description' =>  'Описание метода оплаты, которое клиент будет видеть на сайте.',
						'default' => 'Оплата с помощью robokassa.'
					),				
					'testmode' => array(
						'title' => 'Тестовый режим',
						'type' => 'checkbox', 
						'label' => 'Включен',
						'description' => 'В этом режиме плата за товар не снимается. Для этого режима существуют отдельные пароли в разделе технических настроек Робокассы',
						'default' => 'no'
					),
					'robokassa_merchant' => array(
						'title' => 'Идентификатор магазина',
						'type' => 'text',
						'description' => 'Идентификатор магазина из раздела технических настроек Робокассы',
						'default' => 'demo'
					),
					'robokassa_key1' => array(
						'title' => 'Пароль #1',
						'type' => 'password',
						'description' => 'Пароль #1 из раздела технических настроек Робокассы',
						'default' => ''
					),
					'robokassa_key2' => array(
						'title' => 'Пароль #2',
						'type' => 'password',
						'description' => 'Пароль #2 из раздела технических настроек Робокассы',
						'default' => ''
					),				
					'hashcode' => array(
						'title' => 'Алгоритм расчёта хэша',
						'type' => 'select', 
						'default' => 'MD5',
						'description' => 'Указан в разделе технических настроек Робокассы',
						'options' => array('MD5'=>'md5', 'SHA1'=>'sha1', 'SHA256'=>'sha256', 'SHA384'=>'sha384', 'SHA512'=>'sha512')
					),
					'receipt' => array(
						'title' => 'Передавать информацию о корзине',
						'type' => 'checkbox', 
						'label' => 'Включен',
						'default' => 'yes',
						'description' => 'Передает Робокассе информацию о составе заказа клиента, чтобы сформировать чек. Эта информация обязательна для клиентов Робокассы, выбравших для себя Облачное решение, Кассовое решение или решение Робочеки.'.((version_compare(PHP_VERSION,'5.4.0','<'))?'<br><strong style="color:red">Внимание! Ваша версия PHP - '.phpversion().'. А необходима как минимум 5.4.0 для корректной работы этой функции!</strong>':'')
						
					),
					'if_fail' => array(
						'title' => 'В случае ошибки платежа:',
						'type' => 'select', 
						'description' => 'Если платеж клиента не будет произведен (неправильный номер карты, нет средства и пр.), куда клиента нужно перенаправить?',
						'default' => 'retry',
						'options' => array(
							'retry' => 'Вывести окно с запросом повторной попытки оплаты',
							'cancel' => 'Отменить заказ, и сказать клиенту об этом'
						)
					),
					'include_shipping' => array(
						'title' => 'Доставка в чеке',
						'type' => 'checkbox', 
						'label' => 'Включена',
						'description' => 'Включать доставку как отдельную позицию в чек? (Работает только в том случае, если стоимость доставки в заказе клиента ненулевая. Информация берется из раздела "Доставка" WooCommerce)',
						'default' => 'no'
					),	
					'payment_method' => array(
						'title' => 'Признак способа расчёта',
						'type' => 'select', 
						'description' => 'Способ расчета, который будет передан в чек. Обычно достаточно указать "Полный расчет". Полное описание полей находится на сайте Робокассы: <a href="https://docs.robokassa.ru/#7508">https://docs.robokassa.ru/#7508</a>',
						'default' => 'full_payment',
						'options' => array(
							'full_prepayment' => 'Предоплата 100%',
							'prepayment' => 'Частичная предоплата',
							'advance' => 'Аванс',
							'full_payment' => 'Полный расчет',
							'partial_payment' => 'Частичный расчёт и кредит',
							'credit' => 'Передача в кредит',
							'credit_payment' => 'Оплата кредита'
						)
					),	
					'payment_object' => array(
						'title' => 'Признак предмета расчёта',
						'type' => 'select', 
						'description' => 'О предмете какого типа был расчет. Обычно это "Товар". Полное описание полей находится на сайте Робокассы: <a href="https://docs.robokassa.ru/#7509">https://docs.robokassa.ru/#7509</a>',
						'default' => 'commodity',
						'options' => array(
							'commodity' => 'Товар',
							'excise' => 'Подакцизный товар',
							'job' => 'Работа',
							'service' => 'Услуга',
							'gambling_bet' => 'Ставка азартной игры',
							'gambling_prize' => 'Выигрыш азартной игры',
							'lottery' => 'Лотерейный билет',
							'lottery_prize' => 'Выигрыш лотереи',
							'intellectual_activity' => 'Результаты интеллектуальной деятельности',
							'payment' => 'Платеж',
							'agent_commission' => 'Агентское вознаграждение',
							'composite' => 'Составной предмет расчета',
							'another' => 'Иной предмет расчета',
							'property_right' => 'Имущественное право',
							'non-operating_gain' => 'Внереализационный доход',
							'insurance_premium' => 'Страховые взносы',
							'sales_tax' => 'Торговый сбор',
							'resort_fee' => 'Курортный сбор'
						)
					),					
					'tax' => array(
						'title' => 'Налог для чека',
						'type' => 'select', 
						'description' => 'Этот налог будет написан в чеке. Эту информацию обязательно указывать для клиентов Робокассы, выбравших для себя Облачное решение, Кассовое решение или решение Робочеки.',
						'default' => 'none',
						'options' => array(
							'none' => 'без НДС',
							'vat0' => 'НДС по ставке 0%',
							'vat10' => 'НДС чека по ставке 10%',
							'vat18' => 'НДС чека по ставке 18%',
							'vat110' => 'НДС чека по расчетной ставке 10/110',
							'vat118' => 'НДС чека по расчетной ставке 18/118'
						)
					),	
					'sno_enabled' => array(
						'title' => 'Передавать информацию о системе налогообложения',
						'type' => 'checkbox', 
						'label' => 'Включен',
						'description' => 'Не отмечайте это поле, если у организации имеется только один тип налогообложения. В ином случае эта информация обязательна для клиентов Робокассы, выбравших для себя Облачное решение, Кассовое решение или решение Робочеки.',
						'default' => 'no'
					),				
					'sno' => array(
						'title' => 'Система налогообложения',
						'type' => 'select', 
						'label' => 'Включен',
						'description' => 'Необязательное поле, если у организации имеется только один тип налогообложения. В ином случае эта информация обязательна для клиентов Робокассы, выбравших для себя Облачное решение, Кассовое решение или решение Робочеки.',
						'default' => 'usn_income',
						'options' => array(
							'osn' => 'общая СН',
							'usn_income' => 'упрощенная СН (доходы)',
							'usn_income_outcome' => 'упрощенная СН (доходы минус расходы)',
							'envd' => 'единый налог на вмененный доход',
							'esn' => 'единый сельскохозяйственный налог',
							'patent' => 'патент'
						)
					),			
					'debug' => array(
						'title' => 'Записывать все действия в журнал',
						'type' => 'checkbox',
						'label' => 'Включить (просмотр журнала: <code><a href="/wp-admin/admin.php?page=wc-status&tab=logs&log_file='.basename(wc_get_log_file_path( $this->id )).'" target="_blank">'. wc_get_log_file_path( $this->id ). '</a></code>)',
						'default' => 'no'
					),
					'lang' => array(
						'title' =>  'Язык Робокассы с клиентом',
						'type' => 'select',
						'options' => array(
							"ru" => "Русский",
							"en" => "English"
						),
						'description' =>  'Определите язык, на котором Робокасса работает с клиентом',
						'default' => 'ru'
					),
					'submit_button_class' => array(
						'title' => 'CSS-классы для кнопки оплаты',
						'type' => 'text', 
						'description' =>  'Перечислите классы через пробел', 
						'default' => 'button'
					),
					'cancel_button_class' => array(
						'title' => 'CSS-классы для кнопки отмены оплаты',
						'type' => 'text', 
						'description' =>  'Перечислите классы через пробел', 
						'default' => 'button'
					)
				);
		}
		
		function gl($message)
		{
			if ( $this->debug == 'yes' )
			{
				$this->log->add( $this->id, $message );
			}
		}
		
		function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );
			return array (
				'result' => 'success',
				'redirect'	=> add_query_arg('order-pay', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
			);
		}
	
		function receipt_page( $order_id ) {

			$order = wc_get_order( $order_id );
					
			$args = $this->receipt_params( $order ); 
			
			$action_adr = $this->robokassa_url;	
			
			$args_array = array();
			
			foreach ( $args as $key => $value ) {
				array_push ($args_array, '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'" />');
			}
			
			echo '<form action="'.esc_url($action_adr).'" method="post" id="robokassa_form">';
			echo implode('', $args_array);
			echo '<input type="submit" class="'.$this->submit_button_class.'" id="robokassa_form_submit" value="Оплатить" /> <a class="'.$this->cancel_button_class.'" id="robokassa_form_cancel" href="'.$order->get_cancel_order_url().'">Отмена</a></form>';
			
			$this->gl('Сгенерирована форма для оплаты заказа №'.$order_id );
		}
		
		function receipt_params( $order )
		{	
			global $woocommerce;
			
			$order_id = $order->get_id();
			
			$out_summ = number_format($order->get_total(), 2, '.', '');
			
			$crc=array( $this->robokassa_merchant, $out_summ, $order_id );
			
			if( $this->receipt == 'yes' ) {
				$receipt=urlencode(json_encode($this->generate_receipt( $order ), JSON_UNESCAPED_UNICODE));
				array_push ( $crc, $receipt );
			}
			
			if( !empty( $this->outsumcurrency ) ) {
				array_push ( $crc, $this->outsumcurrency );
			}
			
			array_push ( $crc, $this->robokassa_key1 );

			$args = array (
					'MrchLogin' =>       $this->robokassa_merchant,
					'OutSum' =>          $out_summ,
					'InvId' =>           $order_id,
					'SignatureValue' =>  hash($this->hashcode,implode(":",$crc)),
					'Culture' =>         $this->lang,
					'Encoding' =>        'utf-8'
				);
			
			if( $this->receipt == 'yes' ) {
				$args['Receipt'] = $receipt;
			}
			
			if ( $this->testmode == 'yes' ) {
				$args['IsTest'] = 1;
			}
						
			if( $order->get_billing_email() ) {
				$args['Email'] = $order->get_billing_email();
			}

			if( !empty( $this->outsumcurrency ) ) {
				$args['OutSumCurrency'] = $this->outsumcurrency;
			}
			
			$args = apply_filters('woocommerce_robokassa_args', $args);
			
			return $args;
		}
	
		function generate_receipt( $order ) {
						
			$items=array();
			foreach ( $order->get_items('line_item') as $item_id => $item_data )
			{
				$product = $item_data->get_product();
				array_push (
					$items, 
					array(
						'name'=>     $product->get_name(),
						'quantity'=> $item_data->get_quantity(),
						'sum' =>     $item_data->get_total(),
						'payment_method'=>$this->payment_method,
						'payment_object'=>$this->payment_object,
						'tax'=>      $this->tax
					)
				);
			};
			
			if( $this->include_shipping == 'yes' ) {				
				foreach ( $order->get_items( 'shipping' ) as $item_id => $item_data )
				{
					if ($item_data->get_total() != 0)
					{
						array_push (
							$items, 
							array(
								'name'=>     $item_data->get_name(),
								'quantity'=> 1,
								'sum' =>     $item_data->get_total(),
								'tax'=>      $this->tax
							)
						);
					}
				}
			}
			
			$arr = array( 'items' => $items );
			
			if( $this->sno_enabled == 'yes' ) {
				$arr['sno'] = $this->sno;
			}
						
			return $arr;
		}

		
		function check_ipn_response(){
			
			global $woocommerce;
			
			$_POST = stripslashes_deep($_POST);
			$inv_id = $_POST['InvId'];
			
			if ( isset($_GET['robokassa']) AND $_GET['robokassa'] == 'result' ) {
				ob_clean();

				if ( $this->check_ipn_response_is_valid($_POST) ) {
					
					$out_summ = $_POST['OutSum'];
					$order = wc_get_order($inv_id);

					if ( !is_object($order) || $order->status == 'completed' ) {
						exit;
					}

					$order->add_order_note('Платеж успешно завершен.');
					$order->payment_complete();
					$woocommerce->cart->empty_cart();
					
					$this->gl('Платеж успешно завершен для заказа №'.$inv_id );
					
					if($this->payment_method == 'prepayment' || $this->payment_method == 'advance')
					{
						add_post_meta($order->get_id(), 'robokassa_total', $order->get_total());
						$order->add_order_note('Для правильного формирования итогового чека по заказу заполните поле robokassa_total итоговой стоимостью.');
					}
					
				} else {
					$order->add_order_note('Платеж не прошел: ошибочный ответ от платежной системы!');

					$this->gl('Проверка ответа от Робокассы не удалась для заказа №'.$inv_id );

					wp_die('IPN Request Failure');
				}
			} elseif ( isset($_GET['robokassa']) AND $_GET['robokassa'] == 'success' ) {
				
				$order = wc_get_order($inv_id);
				
				if ( !is_object($order) ) {
					
					$this->gl('Робокасса вернула заказ №'.$inv_id.', но WooCommerce не нашел заказ с таким номером!');
					
					$url = wc_get_account_endpoint_url( 'orders' );
					wp_redirect( str_replace( '&amp;', '&', $url ) );
					exit;
				}
				
				WC()->cart->empty_cart();				

				$url = $order->get_checkout_order_received_url();
				
				$this->gl('Клиент пришел с Робокассы по заказу №'.$inv_id.' и перенаправлен на адрес '.$url );
				
				wp_redirect( str_replace('&amp;', '&', $url ) );
			}
			elseif ( isset($_GET['robokassa']) AND $_GET['robokassa'] == 'fail' ) {
				
				$order = wc_get_order($inv_id);
				
				if (!is_object($order)) {
					
					$this->gl('Робокасса вернула заказ №'.$inv_id.', но WooCommerce не нашел заказ с таким номером!');
					
					$url = wc_get_account_endpoint_url( 'orders' );
					wp_redirect( str_replace( '&amp;', '&', $url ) );
					exit;
					
				}
				
				$order->add_order_note('Платеж не прошел: Робокасса сообщает об ошибке!');
				
				$this->gl('Клиент пришел с Робокассы по заказу №'.$inv_id.', который НЕ был успешно оплачен' );
				
				if( $this->if_fail == 'retry' ) {
					wp_redirect( str_replace( '&amp;', '&', $order->get_checkout_payment_url() ) );
				}
				else{	
					$order->update_status('failed', 'Платеж не прошел');				
					wp_redirect( str_replace( '&amp;', '&', $order->get_cancel_order_url() ) );
				}			
			}
			exit;
		}
		
		private function check_ipn_response_is_valid( $post ) {
			
			$out_summ = $post['OutSum'];
			$inv_id = $post['InvId'];
			
			$crc=array( $out_summ, $inv_id);
			
			if( !empty( $this->outsumcurrency ) ) {
				array_push ( $crc, $this->outsumcurrency);
			}
			
			array_push ( $crc, $this->robokassa_key2 );			
			
			$sign = strtoupper(hash($this->hashcode,implode(":",$crc)));
			
			if ( strtoupper($post['SignatureValue']) == $sign )
			{
				echo 'OK'.$inv_id;
				return true;
			}
			return false;
		}
	}

	add_action( 'woocommerce_order_actions', 'robowoo_second_receipt_add_action' );

	add_action( 'woocommerce_order_action_robowoo_second_receipt', 'robowoo_second_receipt_action' );
	
	add_action('admin_notices', 'robowoo_second_receipt_notice');
	
}

function robowoo_second_receipt_add_action( $actions ) {
	
	global $theorder;
	
	if ( ! is_object( $theorder ) ) {
		$theorder = wc_get_order( $post->ID );
	}
	
	$payment_gateways = get_payment_gateways();
	
	$payment_method = $theorder->get_payment_method();
			
	if (!isset( $payment_gateways[ $payment_method ] )) return $actions;
	if ($payment_gateways[ $payment_method ]->id != 'robokassa') return $actions;
	if (!need_second_receipt($payment_gateways)) return $actions;
	if (get_post_meta( $theorder->get_id(), 'robokassa_disable_second_receipt', true ))  return $actions;
	if (get_post_meta( $theorder->get_id(), 'robokassa_OpKey', true ))  return $actions;
	
	$actions['robowoo_second_receipt'] = 'Сформировать второй чек (тестовый режим)';
	
	return $actions;
}

function get_payment_gateways() {
	if ( WC()->payment_gateways() ) {
		return WC()->payment_gateways->payment_gateways();
	} else {
		return array();
	}		
}

function robowoo_second_receipt_action( $order ) {
	
	$data =  generate_request_data($order);
	
	$robowoo = get_robowoo($order);
	
	$url = $robowoo->robokassa_second_url;
			
	$ch = curl_init();

	curl_setopt($ch, CURLOPT_URL,            $url );
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1 );
	curl_setopt($ch, CURLOPT_POST,           1 );
	curl_setopt($ch, CURLOPT_POSTFIELDS,     $data ); 
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_HTTPHEADER,     array('Content-Type: text/plain')); 

	$result=curl_exec ($ch);
	
	$error = '';
	
	if (curl_errno($ch)) {
		$msg = curl_error($ch);
		
		$error = sprintf('По заказу %s произошла ошибка отправки POST-запроса для второго чека %s', $order->get_id(), $msg);
	}
	
	if (!$result) {
		$error = sprintf('По заказу %s произошла ошибка отправки POST-запроса для второго чека', $order->get_id());
	}
	
	curl_close ($ch);
	
	$answer = json_decode($result, TRUE);
	
	if(isset($answer['ResultCode'])) {
		if ($answer['ResultCode'] == '0') {
			$order->add_order_note('Успешно отправлен второй чек');
			add_post_meta($order->get_id(), 'robokassa_disable_second_receipt', 1);
			add_post_meta($order->get_id(), 'robokassa_OpKey', $answer['OpKey']);
			wp_trash_post( $fake_order->get_id() );
		}
		else {
			$error = sprintf('По заказу %s получен неверный ответ от Робокассы при отправке второго чека. ResultCode: %s, ResultDescription: %s', $order->get_id(), $answer['ResultCode'], $answer['ResultDescription'] );
		}
		
	}
	else {
		$error = sprintf('По заказу %s получен неверный ответ от Робокассы при отправке второго чека: %s', $order->get_id(), $result);
	}
	
	if ($error) {
		$order->add_order_note($error);
		$robowoo->gl($error);	
	}
}

function get_robowoo($order) {
	
	$payment_gateways = get_payment_gateways();
	
	$payment_method = $order->get_payment_method();
	
	$robowoo = $payment_gateways[ $payment_method ];
	
	return $robowoo;
}

function generate_request_data($order) {
	
	$fake_order = create_second_order($order);

	$robowoo = get_robowoo($order);
	
	$arr = generate_second_receipt($robowoo, $order, $fake_order);
	
	$receipt = json_encode($arr, JSON_UNESCAPED_UNICODE);
	
	$receipt = stupid_robokassa_rules_1($receipt);
	
	$base64 = base64_encode($receipt);
	
	$base64 = rtrim($base64, '='); 
	
	$hash = hash($robowoo->hashcode, $base64.$robowoo->robokassa_key1);
	
	$base64_sign = base64_encode($hash);
	
	$base64_sign = rtrim($base64_sign, '='); 
	
	$data = $base64.'.'.$base64_sign;

	return $data;
}

function stupid_robokassa_rules_1($receipt) {
	$receipt = str_replace('+','-',$receipt);
	$receipt = str_replace('/','_',$receipt);
	return $receipt;
}

function generate_second_receipt( $robowoo, $order, $fake_order ) {
			
	$arr = $robowoo -> generate_receipt( $order );
	
	$tax_sum = get_post_meta( $order->get_id(), 'robokassa_tax', true );
			
	if($robowoo->tax != 'none' && !$tax_sum){
		
		add_post_meta($fake_order->get_id(), 'robokassa_tax', 0);
		
		$this->gl(sprintf('Попытка сформировать второй чек для заказа %s не удалась, так как нет информации о сумме НДС к заказу.', $order->get_id()));
		
		$order->add_order_note('Не могу сфомировать второй чек, пока не будет выставлена сумма НДС в полях заказа. Внесите сумму налога к заказу в поле robokassa_tax');
										
		$url = add_query_arg(  array('post'=> $order->get_id(), 'action'=>'edit' ) );
				
		wp_redirect(   $url  ); 
		
		exit; 
	}
	
	if (empty($tax_sum)) {
		$tax_sum = 0;
	}
	else {
		$tax_sum = number_format($tax_sum, 2, '.', '');
	}
	
	$total = get_post_meta( $order->get_id(), 'robokassa_total', true );
	
	if (!$total) {
		$total = $order->get_total();
	}

	$arr['merchantId'] = $robowoo->robokassa_merchant;
	$arr['id'] = $fake_order->get_id();
	$arr['originId'] = $order->get_id();
	$arr['operation'] = 'sell';
	//$arr['url'] = get_site_url();
	$arr['total'] = number_format($total, 2, '.', '');
	
	if ($order->get_billing_email()) $arr['client']['email'] = $order->get_billing_email();
	if ($order->get_billing_phone()) $arr['client']['phone'] = $order->get_billing_phone();
	
	$arr['payments'] = array(array('type'=> 2, 'sum'=> $arr['total']));
	
	$arr['vats'] = array(array('type'=> $robowoo->tax, 'sum'=> $tax_sum));
	
	return $arr;
}


function create_second_order( $order ) {
	
	global $woocommerce;
	
	$orders = wc_get_orders( array('parent' => $order->get_id(), 'status' => 'cancelled') );
	
	if (!empty($orders)) {
		 foreach ($orders as $current_order){
			if (get_post_meta( $current_order->get_id(), 'robokassa_fake_order', true)){
				return $current_order;
			}
		 }
	}			
	
	$message = sprintf('Это псевдо-заказ для второго чека по заказу №%s', $order->get_id());
	
	$fake_order = wc_create_order(
		array(
		'status'        => 'wc-cancelled',
		'customer_note' => $message,
		'parent'        => $order->get_id(),
		'created_via'   => 'Robowoo'
		)
	);
	
	$payment_gateways = WC()->payment_gateways->payment_gateways();
	
	$fake_order->set_payment_method($payment_gateways['robokassa']);
		
	$fake_order->add_order_note( $message );
	
	add_post_meta($fake_order->get_id(), 'robokassa_fake_order', 1);
	add_post_meta($fake_order->get_id(), 'robokassa_disable_second_receipt', 1);		
	
	$fake_order->save();
	
	return $fake_order;
}		

function need_second_receipt($payment_gateways) {
	if (isset($payment_gateways['robokassa']) && $payment_gateways['robokassa']->enabled == 'yes' && ($payment_gateways['robokassa']->payment_method == 'full_prepayment' 	|| $payment_gateways['robokassa']->payment_method == 'prepayment' || $payment_gateways['robokassa']->payment_method == 'advance' ) )
	{
		return true;
	}
	return false;
	
}

function robowoo_second_receipt_notice() { 	
	if (need_second_receipt (get_payment_gateways()) ) {
		echo '<div class="notice notice-error"><p>Внимание! Режим работы с итоговыми чеками функционирует нестабильно! Крайне не рекомендуется его пока использовать!</p></div>';		
	}
}

?>