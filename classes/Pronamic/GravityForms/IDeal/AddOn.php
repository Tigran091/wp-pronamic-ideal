<?php 

/**
 * Title: Gravity Forms iDEAL Add-On
 * Description: 
 * Copyright: Copyright (c) 2005 - 2011
 * Company: Pronamic
 * @author Remco Tolsma
 * @version 1.0
 */
class Pronamic_GravityForms_IDeal_AddOn {
	/**
	 * Slug
	 * 
	 * @var string
	 */
	const SLUG = 'gravityformsideal';

	/**
	 * Gravity Forms minimum required version
	 * 
	 * @var string
	 */
	const GRAVITY_FORMS_MINIMUM_VERSION = '1.0';

	//////////////////////////////////////////////////

	/**
	 * Option version
	 * 
	 * @var string
	 */
	const OPTION_VERSION = 'gf_ideal_version';

	/**
	 * The current version of this plugin
	 * 
	 * @var string
	 */
	const VERSION = '1.1';

	//////////////////////////////////////////////////

	/**
	 * Bootstrap
	 */
	public static function bootstrap() {
		if(self::isGravityFormsSupported()) {
			add_action('init',  array(__CLASS__, 'initialize'));
			add_action('plugins_loaded', array(__CLASS__, 'setup'));
		}
	}

	//////////////////////////////////////////////////

	/**
	 * Initialize
	 */
	public static function initialize() {
		// Activation hook
		register_activation_hook(Pronamic_WordPress_IDeal_Plugin::$file, array(__CLASS__, 'activate'));

		// Admin
		if(is_admin()) {
			add_filter('gform_addon_navigation', array(__CLASS__, 'createMenu'));
			
			add_filter('gform_entry_info', array(__CLASS__, 'entryInfo'), 10, 3);

			RGForms::add_settings_page(
				__('iDEAL', Pronamic_WordPress_IDeal_Plugin::TEXT_DOMAIN), 
				array(__CLASS__, 'pageSettings') , 
				plugins_url('/images/icon-32x32.png', Pronamic_WordPress_IDeal_Plugin::$file)
			);

			// AJAX
			add_action('wp_ajax_gf_get_form_data', array(__CLASS__, 'ajaxGetFormData'));

			// Scripts
			wp_register_script(
				'gf_ideal_admin' , 
				plugins_url('js/admin.js', Pronamic_WordPress_IDeal_Plugin::$file) ,
				array('jquery')
			);

			add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueueAdminScripts'));
		} else {
			// @see http://www.gravityhelp.com/documentation/page/Gform_confirmation
			add_filter('gform_confirmation', array(__CLASS__, 'handleIDeal'), 10, 4);
		}
		
		add_action('pronamic_ideal_return', array(__CLASS__, 'updateStatus'));

		// iDEAL fields
		Pronamic_GravityForms_IDeal_Fields::bootstrap();
	}

	//////////////////////////////////////////////////

	/**
	 * Activate the plugin
	 */
	public static function activate() {
		// Add some new capabilities
		global $wp_roles;

		$wp_roles->add_cap('administrator', 'gravityforms_ideal');
		$wp_roles->add_cap('administrator', 'gravityforms_ideal_uninstall');
	}

	//////////////////////////////////////////////////

	/**
	 * Setup, creates or updates database tables. Will only run when version changes
	 */
	public static function setup() {
		if(get_option(self::OPTION_VERSION) != self::VERSION) {
			Pronamic_GravityForms_IDeal_FeedsRepository::updateTable();

			update_option(self::OPTION_VERSION, self::VERSION);
		}
	}

	//////////////////////////////////////////////////

	/**
	 * Uninstall
	 */
	public static function uninstall() {
		// Drop tables
		Pronamic_GravityForms_IDeal_FeedsRepository::dropTables();

		// Delete options
		delete_option(self::OPTION_VERSION);
	}

	//////////////////////////////////////////////////

	/**
	 * Maybe update user role of the specified lead and feed
	 * 
	 * @param array $lead
	 * @param Feed $feed
	 */
	private static function maybeUpdateUserRole($lead, $feed) {
		// Gravity Forms User Registration Add-On 
		if(class_exists('GFUserData')) {
			$user = GFUserData::get_user_by_entry_id($lead['id']);
		} 
		
		if($user == false) {
			$createdBy = $lead[Pronamic_GravityForms_GravityForms::LEAD_PROPERY_CREATED_BY];
				
			$user = new WP_User($createdBy);
		}

		if($user && !empty($feed->userRoleFieldId) && isset($lead[$feed->userRoleFieldId])) {
			$value = $lead[$feed->userRoleFieldId];
			$value = GFCommon::get_selection_value($value);

			$user->set_role($value);
		}
	}

	//////////////////////////////////////////////////
	
	/**
	 * Update lead status of the specified payment
	 * 
	 * @param string $payment
	 */
	public static function updateStatus($payment) {
		if($payment->getSource() == self::SLUG) {
			$leadId = $payment->getSourceId();
			$transaction = $payment->transaction;

			$lead = RGFormsModel::get_lead($leadId);

			if($lead) {
				$status = $transaction->getStatus();

				RGFormsModel::update_lead_property($leadId, Pronamic_GravityForms_GravityForms::LEAD_PROPERTY_PAYMENT_STATUS, $status);

				$formId = $lead['form_id'];
				
				$feed = Pronamic_GravityForms_IDeal_FeedsRepository::getFeedByFormId($formId);

				if($feed) {
					$url = null;

					switch($status) {
						case Pronamic_IDeal_Transaction::STATUS_CANCELLED:
							$url = $feed->getUrl(Pronamic_GravityForms_IDeal_Feed::LINK_CANCEL);
							break;
						case Pronamic_IDeal_Transaction::STATUS_EXPIRED:
							$url = $feed->getUrl(Pronamic_GravityForms_IDeal_Feed::LINK_EXPIRED);
							break;
						case Pronamic_IDeal_Transaction::STATUS_FAILURE:
							$url = $feed->getUrl(Pronamic_GravityForms_IDeal_Feed::LINK_ERROR);
							break;
						case Pronamic_IDeal_Transaction::STATUS_SUCCESS:
							self::maybeUpdateUserRole($lead, $feed);
							$url = $feed->getUrl(Pronamic_GravityForms_IDeal_Feed::LINK_SUCCESS);
							break;
						case Pronamic_IDeal_Transaction::STATUS_OPEN:
						default:
							$url = $feed->getUrl(Pronamic_GravityForms_IDeal_Feed::LINK_OPEN);
							break;
					}
					
					if($url) {
						wp_redirect($url, 303);

						exit;
					}
				}
			}
		}
	}

	//////////////////////////////////////////////////

	/**
	 * Render entry info of the specified form and lead
	 * 
	 * @param string $formId
	 * @param array $lead
	 */
	public static function entryInfo($formId, $lead) {
		_e('iDEAL', Pronamic_WordPress_IDeal_Plugin::TEXT_DOMAIN); ?>: 
		<a href="#" target="_blank">transaction 1</a>
		<br /><br /><?php
	}

	//////////////////////////////////////////////////

	/**
	 * Create the admin menu
	 */
	public static function createMenu($menus) {
		$permission = 'gravityforms_ideal';

		$menus[] = array(
			'name' => 'gf_ideal' , 
			'label' => __('iDEAL', Pronamic_WordPress_IDeal_Plugin::TEXT_DOMAIN) , 
			'callback' =>  array(__CLASS__, 'page') , 
			'permission' => $permission
		);

        return $menus;
	}

	//////////////////////////////////////////////////

	/**
	 * Enqueue admin scripts
	 */
	public static function enqueueAdminScripts() {
		wp_enqueue_script('gf_ideal_admin');
	}
	
	/**
	 * Handle AJAX request get form data
	 */
	public static function ajaxGetFormData() {
		$formId = filter_input(INPUT_GET, 'formId', FILTER_SANITIZE_STRING);
		
		$result = new stdClass();
		$result->success = true;
		$result->data = RGFormsModel::get_form_meta($formId);

		// Output
		header('Content-Type: application/json');

		echo json_encode($result);

		die();
	}

	//////////////////////////////////////////////////

	/**
	 * Page
	 */
	public static function page() {
		$view = filter_input(INPUT_GET, 'view', FILTER_SANITIZE_STRING);

		switch($view) {
			case 'edit':
				return self::pageFeedEdit();
			default:
				return self::pageFeeds();
		}
	}
	
	/**
	 * Page list
	 */
	public static function pageFeeds() {
		return Pronamic_WordPress_IDeal_Admin::renderView('gravityforms/feeds');
	}
	
	/**
	 * Page edit
	 */
	public static function pageFeedEdit() {
		return Pronamic_WordPress_IDeal_Admin::renderView('gravityforms/feed-edit');
	}
	
	/**
	 * Page settings
	 */
	public static function pageSettings() {
		return Pronamic_WordPress_IDeal_Admin::renderView('configurations-form');
	}

	/**
	 * Get the edit link for the specfied feed id
	 * 
	 * @param string $id
	 * @return string url
	 */
	public static function getEditFeedLink($id = null) {
		$link = 'admin.php';
		$link = add_query_arg('page', 'gf_ideal', $link);
		$link = add_query_arg('view', 'edit', $link);

		if($id != null) {
			$link = add_query_arg('id', $id, $link);
		}

		return $link;
	}

	/**
	 * Get the delete link for the specfied feed id
	 * 
	 * @param string $id
	 * @return string url
	 */
	public static function getDeleteFeedLink($id = null) {
		$link = 'admin.php';
		$link = add_query_arg('page', 'gf_ideal', $link);
		$link = add_query_arg('action', 'delete', $link);

		if($id != null) {
			$link = add_query_arg('id', $id, $link);
		}

		return $link;
	}

	//////////////////////////////////////////////////

	/**
	 * Checks if Gravity Forms is supporter
	 * 
	 * @return true if Gravity Forms is supported, false otherwise
	 */
	public static function isGravityFormsSupported() {
		if(class_exists('GFCommon')) {
			return version_compare(GFCommon::$version, self::GRAVITY_FORMS_MINIMUM_VERSION, '>=');
        } else {
			return false;
        }
	}
	
	//////////////////////////////////////////////////

	/**
	 * Check if the iDEAL condition is true
	 * 
	 * @param mixed $form
	 * @param mixed $feed
	 */
	public static function isConditionTrue($form, $feed) {
		$result = true;

        if($feed->conditionEnabled) {
			$field = RGFormsModel::get_field($form, $feed->conditionFieldId);

			if(empty($field)) {
				// unknown field
				$result = true;
			} else {
				$isHidden = RGFormsModel::is_field_hidden($form, $field, array());

				if($isHidden) {
					// hidden field
					$result = true;
				} else {
					$value = RGFormsModel::get_field_value($field, array());

					$isMatch = RGFormsModel::is_value_match($value, $feed->conditionValue);
					
					switch($feed->conditionOperator) {
						case Pronamic_GravityForms_GravityForms::OPERATOR_IS:
							$result = $isMatch;
							break;
						case Pronamic_GravityForms_GravityForms::OPERATOR_IS_NOT:
							$result = !$isMatch;
							break;
						default: // unknown operator
							$result = true;
							break;
					}
				}
			}
        } else {
        	// condition is disabled, result is true
        	$result = true;
        }

        return $result;
	}

	//////////////////////////////////////////////////

	/**
	 * Handle iDEAL
	 * 
	 * @see http://www.gravityhelp.com/documentation/page/Gform_confirmation
	 */
	public static function handleIDeal($confirmation, $form, $lead, $ajax) {
		$feed = Pronamic_GravityForms_IDeal_FeedsRepository::getFeedByFormId($form['id']);

		if($feed !== null) {
			$configuration = $feed->getIDealConfiguration();

			if($configuration !== null) {
				$variant = $configuration->getVariant();

				if($variant !== null) {
					switch($variant->getMethod()) {
						case Pronamic_IDeal_IDeal::METHOD_BASIC:
							$confirmation = self::handleIDealBasic($confirmation, $form, $lead, $ajax);
							break;
						case Pronamic_IDeal_IDeal::METHOD_ADVANCED:
							$confirmation = self::handleIDealAdvanced($confirmation, $form, $lead, $ajax);
							break;
					}
				}
			}
		}
		
		if((headers_sent() || $ajax) && is_array($confirmation) && isset($confirmation['redirect'])) {
			$url = $confirmation['redirect'];

			$confirmation = sprintf('<script>function gformRedirect() { document.location.href = "%s"; }', $url);
			if(!$ajax) {
				$confirmation .= 'gformRedirect();';
			}

			$confirmation .= '</script>';
		}
		
		return $confirmation;
	}

	/**
	 * Handle iDEAL advanced
	 * 
	 * @see http://www.gravityhelp.com/documentation/page/Gform_confirmation
	 */
	public static function handleIDealAdvanced($confirmation, $form, $lead, $ajax) {
		$feed = Pronamic_GravityForms_IDeal_FeedsRepository::getFeedByFormId($form['id']);

		// Check if there is an iDEAL feed mapped to the specified form
		if($feed == null) {
			return $confirmation;
		}

		// Check if the iDEAL condition is specified in the feed is true
		if(!self::isConditionTrue($form, $feed)) {
			return $confirmation;
		}

		$configuration = $feed->getIDealConfiguration();
		$variant = $configuration->getVariant();

		$issuerDropDowns = GFCommon::get_fields_by_type($form, array(Pronamic_GravityForms_IDeal_IssuerDropDown::TYPE));
		$issuerDropDown = array_shift($issuerDropDowns);

		if($issuerDropDown != null) {
			$issuerId =  RGFormsModel::get_field_value($issuerDropDown);

			$transaction = new Pronamic_IDeal_Transaction();
			$transaction->setAmount(GFCommon::get_order_total($form, $lead)); 
			$transaction->setCurrency(GFCommon::get_currency());
			$transaction->setExpirationPeriod('PT1H');
			$transaction->setLanguage('nl');
			$transaction->setEntranceCode(uniqid());

			$description = GFCommon::replace_variables($feed->transactionDescription, $form, $lead);
			$transaction->setDescription($description);

			$payment = new Pronamic_WordPress_IDeal_Payment();
			$payment->configuration = $configuration;
			$payment->transaction = $transaction;
			$payment->setSource(self::SLUG, $lead['id']);

			$updated = Pronamic_WordPress_IDeal_PaymentsRepository::updatePayment($payment);

			// Updating lead's payment_status to Processing
	        $lead[Pronamic_GravityForms_GravityForms::LEAD_PROPERTY_PAYMENT_STATUS] = Pronamic_GravityForms_GravityForms::PAYMENT_STATUS_PROCESSING;
	        $lead[Pronamic_GravityForms_GravityForms::LEAD_PROPERTY_PAYMENT_AMOUNT] = $transaction->getAmount();
	        $lead[Pronamic_GravityForms_GravityForms::LEAD_PROPERTY_PAYMENT_DATE] = $payment->getDate()->format('y-m-d H:i:s');
	        $lead[Pronamic_GravityForms_GravityForms::LEAD_PROPERTY_TRANSACTION_TYPE] = Pronamic_GravityForms_GravityForms::TRANSACTION_TYPE_PAYMENT;
	        $lead[Pronamic_GravityForms_GravityForms::LEAD_PROPERTY_TRANSACTION_ID] = $payment->getId();
	
	        RGFormsModel::update_lead($lead);

			$url = Pronamic_WordPress_IDeal_IDeal::handleTransaction($issuerId, $payment, $variant);

			$confirmation = array('redirect' => $url);
		}

		return $confirmation;
	}

	/**
	 * Handle iDEAL basic
	 * 
	 * @see http://www.gravityhelp.com/documentation/page/Gform_confirmation
	 */
	public static function handleIDealBasic($confirmation, $form, $lead, $ajax) {
		$feed = Pronamic_GravityForms_IDeal_FeedsRepository::getFeedByFormId($form['id']);

		// Check if there is an iDEAL feed mapped to the specified form
		if($feed == null) {
			return $confirmation;
		}

		// Check if the iDEAL condition is specified in the feed is true
		if(!self::isConditionTrue($form, $feed)) {
			return $confirmation;
		}
		
		$configuration = $feed->getIDealConfiguration();
		$variant = $configuration->getVariant();

		$iDeal = new Pronamic_IDeal_Basic();
		
		// Payment server URL
		$iDeal->setPaymentServerUrl($configuration->getPaymentServerUrl());
		
		// Merchant ID
		$iDeal->setMerchantId($configuration->merchantId);
		
		// Sub ID
		$iDeal->setSubId($configuration->subId);
		
		// Language
		$iDeal->setLanguage('nl');
		
		// Hash key
		$iDeal->setHashKey($configuration->hashKey);
		
		// Currency
		$currency = GFCommon::get_currency();
		$iDeal->setCurrency($currency);

        // Purchae ID, we use the Gravity Forms lead id
        $id = $lead['id'];

        $iDeal->setPurchaseId($id);

        // Success URL
        $url = $feed->getUrl(Pronamic_GravityForms_IDeal_Feed::LINK_SUCCESS);
        if($url != null) {
        	$url = add_query_arg('transaction', $id, $url);
        	$url = add_query_arg('status', 'success', $url);
        	$iDeal->setSuccessUrl($url);
        }

        // Cancel URL
        $url = $feed->getUrl(Pronamic_GravityForms_IDeal_Feed::LINK_CANCEL);
        if($url != null) {
        	$url = add_query_arg('transaction', $id, $url);
        	$url = add_query_arg('status', 'cancel', $url);
        	$iDeal->setCancelUrl($url);
        }

        // Error URL
        $url = $feed->getUrl(Pronamic_GravityForms_IDeal_Feed::LINK_ERROR);
        if($url != null) {
        	$url = add_query_arg('transaction', $id, $url);
        	$url = add_query_arg('status', 'error', $url);
        	$iDeal->setErrorUrl($url);
        }

		// Products
        $products = GFCommon::get_product_fields($form, $lead);
        foreach($products['products'] as $i => $product) {
        	$item = new Pronamic_IDeal_Basic_Item();
        	$item->setNumber($i);
        	$item->setDescription($product['name']);
        	$item->setQuantity($product['quantity']);
        	$item->setPrice(GFCommon::to_number($product['price']));

        	$iDeal->addItem($item);

			if(isset($product['options']) && is_array($product['options'])) {
				foreach($product['options'] as $j => $option) {
		        	$item = new Pronamic_IDeal_Basic_Item();
		        	$item->setNumber($j);
		        	$item->setDescription($option['option_name']);
		        	$item->setQuantity(1);
		        	$item->setPrice($option['price']);

        			$iDeal->addItem($item);
				}
            }
        }

		// Updating lead's payment_status to Processing
        $lead[Pronamic_GravityForms_GravityForms::LEAD_PROPERTY_PAYMENT_STATUS] = Pronamic_GravityForms_GravityForms::PAYMENT_STATUS_PROCESSING;
        $lead[Pronamic_GravityForms_GravityForms::LEAD_PROPERTY_PAYMENT_AMOUNT] = $iDeal->getAmount();
        $lead[Pronamic_GravityForms_GravityForms::LEAD_PROPERTY_PAYMENT_DATE] = gmdate('y-m-d H:i:s');
        $lead[Pronamic_GravityForms_GravityForms::LEAD_PROPERTY_TRANSACTION_TYPE] = Pronamic_GravityForms_GravityForms::TRANSACTION_TYPE_PAYMENT;
        $lead[Pronamic_GravityForms_GravityForms::LEAD_PROPERTY_TRANSACTION_ID] = $lead['id'];

        RGFormsModel::update_lead($lead);

        // Update payment
		$transaction = new Pronamic_IDeal_Transaction();
		$transaction->setAmount(GFCommon::get_order_total($form, $lead)); 
		$transaction->setCurrency(GFCommon::get_currency());
		$transaction->setLanguage('nl');
		$transaction->setEntranceCode(uniqid());

		$description = GFCommon::replace_variables($feed->transactionDescription, $form, $lead);
		$transaction->setDescription($description);
        $iDeal->setDescription($description);

		$payment = new Pronamic_WordPress_IDeal_Payment();
		$payment->configuration = $configuration;
		$payment->transaction = $transaction;
		$payment->setSource(self::SLUG, $lead['id']);

		$updated = Pronamic_WordPress_IDeal_PaymentsRepository::updatePayment($payment);

        // HTML
        $html  = '';
        $html .= '<div id="gforms_confirmation_message">';
        $html .= 	GFCommon::replace_variables($form['confirmation']['message'], $form, $lead, false, true, $nl2br);
        $html .= 	sprintf('<form method="post" action="%s">', esc_attr($iDeal->getPaymentServerUrl()));
        $html .= 	$iDeal->getHtmlFields();
        $html .= '		<input type="submit" name="ideal" value="Betaal via iDEAL" />';
        $html .= '	</form>';
		$html .= '</div>';

        // Extend the confirmation with the iDEAL form
        $confirmation = $html;

        // Return
        return $confirmation;
	}
}
