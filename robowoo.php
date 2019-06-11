<?php 
/*
  Plugin Name: RoboWoo — Robokassa payment gateway for WooCommerce
  Description: Provides a <a href="https://www.robokassa.ru" target="_blank">Robokassa</a> gateway for WooCommerce. Supports russian law 54-FZ
  Version: 1.0.4
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
			$this->payment_method =      ( isset( $this->settings['payment_method'] ) ) ? $this->settings['payment_method'] : 'full_prepayment';
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
						'description' => 'Способ расчета, который будет передан в чек. Обычно достаточно указать "Предоплата 100%". Полное описание полей находится на сайте Робокассы: <a href="https://docs.robokassa.ru/#7508">https://docs.robokassa.ru/#7508</a>',
						'default' => 'full_prepayment',
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
		
		function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );
			return array (
				'result' => 'success',
				'redirect'	=> add_query_arg('order-pay', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
			);
		}
		
		private function generate_receipt( $order_id ) {
			
			$order = wc_get_order( $order_id ); 
			
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
			}		;
			
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
						
			return urlencode(json_encode($arr, JSON_UNESCAPED_UNICODE));
		}	


		function receipt_page( $order_id ) {
			
			global $woocommerce;

			$order = wc_get_order( $order_id );
			$action_adr = $this->robokassa_url;

			$out_summ = number_format($order->order_total, 2, '.', '');
			
			$crc=array( $this->robokassa_merchant, $out_summ, $order_id );
			
			if( $this->receipt == 'yes' ) {
				$receipt=$this->generate_receipt( $order_id );
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
						
			if( !empty( $order->billing_email ) ) {
				$args['Email'] = $order->billing_email;
			}

			if( !empty( $this->outsumcurrency ) ) {
				$args['OutSumCurrency'] = $this->outsumcurrency;
			}
			
			$args = apply_filters('woocommerce_robokassa_args', $args);

			$args_array = array();
			
			foreach ( $args as $key => $value ) {
				array_push ($args_array, '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'" />');
			}
			
			echo '<form action="'.esc_url($action_adr).'" method="post" id="robokassa_form">';
			echo implode('', $args_array);
			echo '<input type="submit" class="'.$this->submit_button_class.'" id="robokassa_form_submit" value="Оплатить" /> <a class="'.$this->cancel_button_class.'" id="robokassa_form_cancel" href="'.$order->get_cancel_order_url().'">Отмена</a></form>';
			
			if ( $this->debug == 'yes' ) {
				$this->log->add( $this->id,'Сгенерирована форма для оплаты заказа №'.$order_id );
			}
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
					
					if ( $this->debug == 'yes' ) {
						$this->log->add( $this->id,'Платеж успешно завершен для заказа №'.$inv_id );
					}
					
				} else {
					$order->add_order_note('Платеж не прошел: ошибочный ответ от платежной системы!');
					if ( $this->debug == 'yes' ) {
						$this->log->add( $this->id,'Проверка ответа от Робокассы не удалась для заказа №'.$inv_id );
					}					
					wp_die('IPN Request Failure');
				}
			} elseif ( isset($_GET['robokassa']) AND $_GET['robokassa'] == 'success' ) {
				
				$order = wc_get_order($inv_id);
				
				if ( !is_object($order) ) {
					
					if ( $this->debug == 'yes' ) {
						$this->log->add( $this->id,'Робокасса вернула заказ №'.$inv_id.', но WooCommerce не нашел заказ с таким номером!');
					}
					
					$url = wc_get_account_endpoint_url( 'orders' );
					wp_redirect( str_replace( '&amp;', '&', $url ) );
					exit;
				}
				
				WC()->cart->empty_cart();				

				$url = $order->get_checkout_order_received_url();
				
				if ( $this->debug == 'yes' ) {
					$this->log->add( $this->id,'Клиент пришел с Робокассы по заказу №'.$inv_id.' и перенаправлен на адрес '.$url );
				}	
				
				wp_redirect( str_replace('&amp;', '&', $url ) );
			}
			elseif ( isset($_GET['robokassa']) AND $_GET['robokassa'] == 'fail' ) {
				
				$order = wc_get_order($inv_id);
				
				if (!is_object($order)) {
					
					if ( $this->debug == 'yes' ) {
						$this->log->add( $this->id,'Робокасса вернула заказ №'.$inv_id.', но WooCommerce не нашел заказ с таким номером!');
					}
					
					$url = wc_get_account_endpoint_url( 'orders' );
					wp_redirect( str_replace( '&amp;', '&', $url ) );
					exit;
					
				}
				
				$order->add_order_note('Платеж не прошел: Робокасса сообщает об ошибке!');
				
				if ( $this->debug == 'yes' ) {
					$this->log->add( $this->id,'Клиент пришел с Робокассы по заказу №'.$inv_id.', который НЕ был успешно оплачен' );
				}
				
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
}
?>