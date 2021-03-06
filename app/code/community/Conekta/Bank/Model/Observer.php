<?php
include_once(Mage::getBaseDir('lib') . DS . 'Conekta' . DS . 'lib' . DS . 'Conekta.php');
class Conekta_Bank_Model_Observer{
  public function processPayment($event){
    if (!class_exists('Conekta')) {
      error_log("Plugin miss Conekta PHP lib dependency. Clone the repository using 'git clone --recursive git@github.com:conekta/conekta-magento.git'", 0);
      throw new Mage_Payment_Model_Info_Exception("Payment module unavailable. Please contact system administrator.");
    }
    if($event->payment->getMethod() == Mage::getModel('Conekta_Bank_Model_Bank')->getCode()){
      Conekta::setApiKey(Mage::getStoreConfig('payment/bank/privatekey'));
      Conekta::setLocale(Mage::app()->getLocale()->getLocaleCode());
      
      $order = $event->payment->getOrder();
      $customer = $order->getCustomer();
      $shipping_address = $order->getShippingAddress();

      $billing = $order->getBillingAddress()->getData();
      $email = $order->getCustomerEmail();
      if ($shipping_address) {
        $shipping_data = $shipping_address->getData();
      }
      $items = $order->getAllVisibleItems();
      $line_items = array();
      $i = 0;
      foreach ($items as $itemId => $item){
        $name = $item->getName();
        $sku = $item->getSku();
        $price = $item->getPrice();
        $description = $item->getDescription();
        $product_type = $item->getProductType();
        $line_items = array_merge($line_items, array(array(
          'name' => $name,
          'sku' => $sku,
          'unit_price' => $price,
          'description' =>$description,
          'quantity' => 1,
          'type' => $product_type
          ))
        );
        $i = $i + 1;
      }
      $shipp = array();
      if (empty($shipping_data) != true) {
        $shipp = array(
          'price' => intval(((float) $order->getShippingAmount()) * 100),
          'service' => $order->getShippingMethod(),
          'carrier' => $order->getShippingDescription(),
          'address' => array(
            'street1' => $shipping_data['street'],
            'city' => $shipping_data['city'],
            'state' => $shipping_data['region'],
            'country' => $shipping_data['country_id'],
            'zip' => $shipping_data['postcode'],
            'phone' =>$shipping_data['telephone'],
            'email' =>$email
            )
          );
      }
      $days = $event->payment->getMethodInstance()->getConfigData('my_date');
      $expiry_date=Date('Y-m-d', strtotime("+".$days." days"));
      try {
        $charge = Conekta_Charge::create(array(
          'bank'=>array(
            'type'=>'banorte',
            'expires_at'=>$expiry_date
            ),
          'currency' => Mage::app()->getStore()->getCurrentCurrencyCode(),
          'amount' => intval(((float) $event->payment->getOrder()->grandTotal) * 100),
          'description' => 'Compra en Magento',
          'reference_id' => $event->payment->getOrder()->getIncrementId(),
          'details' => array(
            'name' => preg_replace('!\s+!', ' ', $billing['firstname'] . ' ' . $billing['middlename'] . ' ' . $billing['firstname']),
            'email' => $email,
            'phone' => $billing['telephone'],
            'billing_address' => array(
              'company_name' => $billing['company'],
              'street1' => $billing['street'],
              'city' =>$billing['city'],
              'state' =>$billing['region'],
              'country' =>$billing['country_id'],
              'zip' =>$billing['postcode'],
              'phone' =>$billing['telephone'],
              'email' =>$email
              ),
            'line_items' => $line_items,
            'shipment' => $shipp
            ),
            'coupon_code' => $order->getCouponCode(),
            'custom_fields' => array(
              'customer' => array(
                'website_id' => $customer->getWebsiteId(),
                'entity_id' => $customer->getEntityId(),
                'entity_type_id' => $customer->getEntityTypeId(),
                'attribute_set_id' => $customer->getAttributeSetId(),
                'email' => $customer->getEmail(),
                'group_id' => $customer->getGroupId(),
                'store_id' => $customer->getStoreId(),
                'created_at' => $customer->getCreatedAt(),
                'updated_at' => $customer->getUpdatedAt(),
                'is_active' => $customer->getIsActive(),
                'disable_auto_group_change' => $customer->getDisableAutoGroupChange(),
                'get_tax_vat' => $customer->getTaxvat(),
                'created_in' => $customer->getCreatedIn(),
                'gender' => $customer->getGender(),
                'default_billing' => $customer->getDefaultBilling(),
                'default_shipping' => $customer->getDefaultShipping(),
                'dob' => $customer->getDob(),
                'tax_class_id' => $customer->getTaxClassId()
              ),
              'discount_description' => $order->getDiscountDescription(),
              'discount_amount' => $order->getDiscountAmount(),
              'shipping_amount' => $shipping_address->getShippingAmount(),
              'shipping_description' => $shipping_address->getShippingDescription(),
              'shipping_method' => $shipping_address->getShippingMethod()
            )
          )
        );
      } catch (Conekta_Error $e){
        throw new Mage_Payment_Model_Info_Exception($e->message_to_purchaser);
      }
      $event->payment->setBankExpiryDate($expiry_date);
      $event->payment->setBankServiceName($charge->payment_method->service_name);
      $event->payment->setBankServiceNumber($charge->payment_method->service_number);
      $event->payment->setBankName($charge->payment_method->type);
      $event->payment->setBankReference($charge->payment_method->reference);
      $event->payment->setChargeId($charge->id);

      //Update Quote
      $order = $event->payment->getOrder();
      $quote = $order->getQuote();
      $payment = $quote->getPayment();
      $payment->setBankExpiryDate($expiry_date);
      $payment->setBankServiceName($charge->payment_method->service_name);
      $payment->setBankServiceNumber($charge->payment_method->service_number);
      $payment->setBankName($charge->payment_method->type);
      $payment->setBankReference($charge->payment_method->reference);
      $payment->setChargeId($charge->id);
      $quote->collectTotals();
      $quote->save();
      $order->setQuote($quote);
      $order->save();
    }
    return $event;
  }
}
