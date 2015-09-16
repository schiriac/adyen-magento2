<?php

namespace Adyen\Payment\Model;

use Magento\Sales\Model\Order\Email\Sender\OrderSender;

class Cron
{

    /**
     * Logging instance
     * @var \Adyen\Payment\Logger\AdyenLogger
     */
    protected $_logger;

    protected $_notificationFactory;

    protected $_datetime;

    protected $_localeDate;

    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $_orderFactory;

    /**
     * @var \Magento\Sales\Model\Order
     */
    protected $_order;

    /**
     * Core store config
     *
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $_scopeConfig;

    protected $_adyenHelper;

    /**
     * @var OrderSender
     */
    protected $_orderSender;


    // notification attributes
    protected $_pspReference;
    protected $_merchantReference;
    protected $_eventCode;
    protected $_success;
    protected $_paymentMethod;
    protected $_reason;
    protected $_value;
    protected $_boletoOriginalAmount;
    protected $_boletoPaidAmount;
    protected $_modificationResult;
    protected $_klarnaReservationNumber;
    protected $_fraudManualReview;

    /**
     * Collected debug information
     *
     * @var array
     */
    protected $_debugData = array();


    /**
     * Constructor
     * @param \Adyen\Payment\Logger\Logger $logger
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Adyen\Payment\Logger\AdyenLogger $adyenLogger,
        \Adyen\Payment\Model\Resource\Notification\CollectionFactory $notificationFactory,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Framework\Stdlib\DateTime $dateTime,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        \Adyen\Payment\Helper\Data $adyenHelper,
        OrderSender $orderSender
    )
    {
        $this->_scopeConfig = $scopeConfig;
        $this->_logger = $adyenLogger;
        $this->_notificationFactory = $notificationFactory;
        $this->_orderFactory = $orderFactory;
        $this->_datetime = $dateTime;
        $this->_localeDate = $localeDate;
        $this->_adyenHelper = $adyenHelper;
        $this->_orderSender = $orderSender;
    }


    public function processNotification()
    {

        $this->_order = null;

        $this->_logger->info("START OF THE CRONJOB");

        //fixme somehow the created_at is saved in my timzone


        // loop over notifications that are not processed and from 1 minute ago

        $dateStart = new \DateTime();
        $dateStart->modify('-1 day');

        // excecute notifications from 2 minute or earlier because order could not yet been created by mangento
        $dateEnd = new \DateTime();
        $dateEnd->modify('-2 minute');

        // TODO: format to right timezones db is now having my local time

        $dateRange = ['from' => $dateStart, 'to' => $dateEnd, 'datetime' => true];


        $notifications = $this->_notificationFactory->create();
        $notifications->addFieldToFilter('done', 0);
        $notifications->addFieldToFilter('created_at', $dateRange);

        foreach($notifications as $notification) {


            // get order
            $incrementId = $notification->getMerchantReference();

            $this->_order = $this->_orderFactory->create()->loadByIncrementId($incrementId);
            if (!$this->_order->getId()) {
                throw new Exception(sprintf('Wrong order ID: "%1".', $incrementId));
            }

            // declare all variables that are needed
            $this->_declareVariables($notification);

            // add notification to comment history status is current status
            $this->_addStatusHistoryComment();

//            $previousAdyenEventCode = $this->order->getAdyenNotificationEventCode();
            $previousAdyenEventCode = $this->_order->getData('adyen_notification_event_code');


            // check if success is true of false
            if (strcmp($this->_success, 'false') == 0 || strcmp($this->_success, '0') == 0) {
                // Only cancel the order when it is in state pending, payment review or if the ORDER_CLOSED is failed (means split payment has not be successful)
                if($this->_order->getState() === \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT || $this->_order->getState() === \Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW || $this->_eventCode == \Magento\Sales\Model\Order::ADYEN_EVENT_ORDER_CLOSED) {
                    $this->_debugData['_updateOrder info'] = 'Going to cancel the order';

                    // if payment is API check, check if API result pspreference is the same as reference
                    if($this->_eventCode == Adyen_Payment_Model_Event::ADYEN_EVENT_AUTHORISATION && $this->_getPaymentMethodType($this->_order) == 'api') {
                        if($this->_pspReference == $this->_order->getPayment()->getAdyenPspReference()) {
                            // don't cancel the order if previous state is authorisation with success=true
                            if($previousAdyenEventCode != "AUTHORISATION : TRUE") {
                                $this->_holdCancelOrder(false);
                            } else {
                                //$this->_order->setAdyenEventCode($previousAdyenEventCode); // do not update the adyenEventCode
                                $this->_order->setData('adyen_notification_event_code', $previousAdyenEventCode);
                                $this->_debugData['_updateOrder warning'] = 'order is not cancelled because previous notification was a authorisation that succeeded';
                            }
                        } else {
                            $this->_debugData['_updateOrder warning'] = 'order is not cancelled because pspReference does not match with the order';
                        }
                    } else {
                        // don't cancel the order if previous state is authorisation with success=true
                        if($previousAdyenEventCode != "AUTHORISATION : TRUE") {
                            $this->_holdCancelOrder(false);
                        } else {
//                            $this->_order->setAdyenEventCode($previousAdyenEventCode); // do not update the adyenEventCode
                            $this->_order->setData('adyen_notification_event_code', $previousAdyenEventCode);
                            $this->_debugData['_updateOrder warning'] = 'order is not cancelled because previous notification was a authorisation that succeeded';
                        }
                    }
                } else {
                    $this->_debugData['_updateOrder info'] = 'Order is already processed so ignore this notification state is:' . $this->_order->getState();
                }
            } else {
                // Notification is successful
                $this->_processNotification();
            }


            $id = $notification->getId();
//            echo $id;



//            $comment = "THIS IS A TEST";
//            $status = \Magento\Sales\Model\Order::STATE_PROCESSING;

//            $this->_order->setState($status);
//            $this->_order->addStatusHistoryComment($comment, $status);
//
            $this->_order->save();



            foreach($this->_debugData as $debug) {
                $this->_logger->info($debug);
            }



            print_R($this->_debugData);


            echo $this->_order->getId();die();





            $eventCode = $notification->getEventCode();



            // TODO: set done to true!!







        }

        echo 'end';
        // get currenttime
//        $date = new date();






        $this->_logger->info("END OF THE CRONJOB");




    }

    protected function _declareVariables($notification)
    {
        //  declare the common parameters
        $this->_pspReference = $notification->getPspreference();
        $this->_merchantReference = $notification->getMerchantReference();
        $this->_eventCode = $notification->getEventCode();
        $this->_success = $notification->getSuccess();
        $this->_paymentMethod = $notification->getPaymentMethod();
        $this->_reason = $notification->getPaymentMethod();
        $this->_value = $notification->getAmountValue();


        $additionalData = unserialize($notification->getAdditionalData());

        // boleto data
        if($this->_paymentMethodCode() == "adyen_boleto") {
            if($additionalData && is_array($additionalData)) {
                $boletobancario = isset($additionalData['boletobancario']) ? $additionalData['boletobancario'] : null;
                if($boletobancario && is_array($boletobancario)) {
                    $this->_boletoOriginalAmount = isset($boletobancario['originalAmount']) ? trim($boletobancario['originalAmount']) : "";
                    $this->_boletoPaidAmount = isset($boletobancario['paidAmount']) ? trim($boletobancario['paidAmount']) : "";
                }
            }
        }

        if($additionalData && is_array($additionalData)) {

            // check if the payment is in status manual review
            $fraudManualReview = isset($additionalData['fraudManualReview']) ? $additionalData['fraudManualReview'] : "";
            if($fraudManualReview == "true") {
                $this->_fraudManualReview = true;
            } else {
                $this->_fraudManualReview = false;
            }

            $modification = isset($additionalData['modification']) ? $additionalData['modification'] : null;
            if($modification && is_array($modification)) {
                $this->_modificationResult = isset($valueArray['action']) ? trim($modification['action']) : "";
            }
            $additionalData2 = isset($additionalData['additionalData']) ? $additionalData['additionalData'] : null;
            if($additionalData2 && is_array($additionalData2)) {
                $this->_klarnaReservationNumber = isset($additionalData2['acquirerReference']) ? trim($additionalData2['acquirerReference']) : "";
            }
        }
    }

    /**
     * @return mixed
     */
    protected function _paymentMethodCode()
    {
        return $this->_order->getPayment()->getMethod();
    }

    /**
     * @desc order comments or history
     * @param type $order
     */
    protected function _addStatusHistoryComment()
    {
        $success_result = (strcmp($this->_success, 'true') == 0 || strcmp($this->_success, '1') == 0) ? 'true' : 'false';
        $success = (!empty($this->_reason)) ? "$success_result <br />reason:$this->_reason" : $success_result;

        if($this->_eventCode == Notification::REFUND || $this->_eventCode == Notification::CAPTURE) {

            $currency = $this->_order->getOrderCurrencyCode();

            // check if it is a full or partial refund
            $amount = $this->_value;
            $orderAmount = (int) $this->_adyenHelper->formatAmount($this->_order->getGrandTotal(), $currency);

            $this->_debugData['_addStatusHistoryComment amount'] = 'amount notification:'.$amount . ' amount order:'.$orderAmount;

            if($amount == $orderAmount) {
//                $this->_order->setAdyenEventCode($this->_eventCode . " : " . strtoupper($success_result));
                $this->_order->setData('adyen_notification_event_code', $this->_eventCode . " : " . strtoupper($success_result));
            } else {
//                $this->_order->setAdyenEventCode("(PARTIAL) " . $this->_eventCode . " : " . strtoupper($success_result));
                $this->_order->setData('adyen_notification_event_code', "(PARTIAL) " . $this->_eventCode . " : " . strtoupper($success_result));
            }
        } else {
//            $this->_order->setAdyenEventCode($this->_eventCode . " : " . strtoupper($success_result));
            $this->_order->setData('adyen_notification_event_code', $this->_eventCode . " : " . strtoupper($success_result));
        }

        // if payment method is klarna or openinvoice/afterpay show the reservartion number
        if(($this->_paymentMethod == "klarna" || $this->_paymentMethod == "afterpay_default" || $this->_paymentMethod == "openinvoice") && ($this->_klarnaReservationNumber != null && $this->_klarnaReservationNumber != "")) {
            $klarnaReservationNumberText = "<br /> reservationNumber: " . $this->_klarnaReservationNumber;
        } else {
            $klarnaReservationNumberText = "";
        }

        if($this->_boletoPaidAmount != null && $this->_boletoPaidAmount != "") {
            $boletoPaidAmountText = "<br /> Paid amount: " . $this->_boletoPaidAmount;
        } else {
            $boletoPaidAmountText = "";
        }

        $type = 'Adyen HTTP Notification(s):';
        $comment = __('%1 <br /> eventCode: %2 <br /> pspReference: %3 <br /> paymentMethod: %4 <br /> success: %5 %6 %7', $type, $this->_eventCode, $this->_pspReference, $this->_paymentMethod, $success, $klarnaReservationNumberText, $boletoPaidAmountText);

        // If notification is pending status and pending status is set add the status change to the comment history
        if($this->_eventCode == Notification::PENDING)
        {
            $pendingStatus = $this->_getConfigData('pending_status', 'adyen_abstract', $this->_order->getStoreId());
            if($pendingStatus != "") {
                $this->_order->addStatusHistoryComment($comment, $pendingStatus);
                $this->_debugData['_addStatusHistoryComment'] = 'Created comment history for this notification with status change to: ' . $pendingStatus;
                return;
            }
        }

        // if manual review is accepted and a status is selected. Change the status through this comment history item
        if($this->_eventCode == Notification::MANUAL_REVIEW_ACCEPT
            && $this->_getFraudManualReviewAcceptStatus() != "")
        {
            $manualReviewAcceptStatus = $this->_getFraudManualReviewAcceptStatus();
            $this->_order->addStatusHistoryComment($comment, $manualReviewAcceptStatus);
            $this->_debugData['_addStatusHistoryComment'] = 'Created comment history for this notification with status change to: ' . $manualReviewAcceptStatus;
            return;
        }

        $this->_order->addStatusHistoryComment($comment);
        $this->_debugData['_addStatusHistoryComment'] = 'Created comment history for this notification';
    }

    /**
     * @param $order
     * @return bool
     * @deprecate not needed already cancelled in ProcessController
     */
    protected function _holdCancelOrder($ignoreHasInvoice)
    {
        $orderStatus = $this->_getConfigData('payment_cancelled', 'adyen_abstract', $this->_order->getStoreId());

        $helper = Mage::helper('adyen');

        // check if order has in invoice only cancel/hold if this is not the case
        if ($ignoreHasInvoice || !$this->_order->hasInvoices()) {
            $this->_order->setActionFlag($orderStatus, true);

            if($orderStatus == Mage_Sales_Model_Order::STATE_HOLDED) {
                if ($this->_order->canHold()) {
                    $this->_order->hold();
                } else {
                    $this->_debugData['warning'] = 'Order can not hold or is already on Hold';
                    return;
                }
            } else {
                if ($this->_order->canCancel()) {
                    $this->_order->cancel();
                } else {
                    $this->_debugData['warning'] = 'Order can not be canceled';
                    return;
                }
            }
        } else {
            $this->_debugData['warning'] = 'Order has already an invoice so cannot be canceled';
        }
    }

    /**
     * @param $params
     */
    protected function _processNotification()
    {
        $this->_debugData['_processNotification'] = 'Processing the notification';
        $_paymentCode = $this->_paymentMethodCode();

        switch ($this->_eventCode) {
            case Notification::REFUND_FAILED:
                // do nothing only inform the merchant with order comment history
                break;
            case Notification::REFUND:
                $ignoreRefundNotification = $this->_getConfigData('ignore_refund_notification', 'adyen_abstract', $this->_order->getStoreId());
                if($ignoreRefundNotification != true) {
                    $this->_refundOrder($this->_order);
                    //refund completed
                    $this->_setRefundAuthorized($this->_order);
                } else {
                    $this->_debugData['_processNotification info'] = 'Setting to ignore refund notification is enabled so ignore this notification';
                }
                break;
            case Notification::PENDING:
                if($this->_getConfigData('send_email_bank_sepa_on_pending', 'adyen_abstract', $this->_order->getStoreId())) {
                    // Check if payment is banktransfer or sepa if true then send out order confirmation email
                    $isBankTransfer = $this->_isBankTransfer($this->_paymentMethod);
                    if($isBankTransfer || $this->_paymentMethod == 'sepadirectdebit') {
//                        $this->_order->sendNewOrderEmail(); // send order email
                        $this->_orderSender->send($this->_order);

                        $this->_debugData['_processNotification send email'] = 'Send orderconfirmation email to shopper';
                    }
                }
                break;
            case Notification::HANDLED_EXTERNALLY:
            case Notification::AUTHORISATION:
                // for POS don't do anything on the AUTHORIZATION
                if($_paymentCode != "adyen_pos") {
                    $this->_authorizePayment();
                }
                break;
            case Notification::MANUAL_REVIEW_REJECT:
                // don't do anything it will send a CANCEL_OR_REFUND notification when this payment is captured
                break;
            case Notification::MANUAL_REVIEW_ACCEPT:
                // only process this if you are on auto capture. On manual capture you will always get Capture or CancelOrRefund notification
                if ($this->_isAutoCapture()) {
                    $this->_setPaymentAuthorized($this->_order, false);
                }
                break;
            case Notification::CAPTURE:
                if($_paymentCode != "adyen_pos") {
                    // ignore capture if you are on auto capture (this could be called if manual review is enabled and you have a capture delay)
                    if (!$this->_isAutoCapture()) {
                        $this->_setPaymentAuthorized($this->_order, false, true);
                    }
                } else {

                    // uncancel the order just to be sure that order is going trough
                    $this->_uncancelOrder($this->_order);

                    // FOR POS authorize the payment on the CAPTURE notification
                    $this->_authorizePayment($this->_order, $this->_paymentMethod);
                }
                break;
            case Notification::CAPTURE_FAILED:
            case Notification::CANCELLATION:
            case Notification::CANCELLED:
                $this->_holdCancelOrder(true);
                break;
            case Notification::CANCEL_OR_REFUND:
                if(isset($this->_modificationResult) && $this->_modificationResult != "") {
                    if($this->_modificationResult == "cancel") {
                        $this->_holdCancelOrder(true);
                    } elseif($this->_modificationResult == "refund") {
                        $this->_refundOrder($this->_order);
                        //refund completed
                        $this->_setRefundAuthorized($this->_order);
                    }
                } else {
                    $orderStatus = $this->_getConfigData('order_status', 'adyen_abstract', $this->_order->getStoreId());
                    if(($orderStatus != Mage_Sales_Model_Order::STATE_HOLDED && $this->_order->canCancel()) || ($orderStatus == Mage_Sales_Model_Order::STATE_HOLDED && $this->_order->canHold())) {
                        // cancel order
                        $this->_debugData['_processNotification info'] = 'try to cancel the order';
                        $this->_holdCancelOrder(true);
                    } else {
                        $this->_debugData['_processNotification info'] = 'try to refund the order';
                        // refund
                        $this->_refundOrder($this->_order);
                        //refund completed
                        $this->_setRefundAuthorized($this->_order);
                    }
                }
                break;
            case Notification::RECURRING_CONTRACT:

                // get payment object
                $payment = $this->_order->getPayment();

                // storedReferenceCode
                $recurringDetailReference = $this->_pspReference;

                // check if there is already a BillingAgreement
                $agreement = Mage::getModel('adyen/billing_agreement')->load($recurringDetailReference, 'reference_id');

                if ($agreement && $agreement->getAgreementId() > 0 && $agreement->isValid()) {

                    $agreement->addOrderRelation($this->_order);
                    $agreement->setStatus($agreement::STATUS_ACTIVE);
                    $agreement->setIsObjectChanged(true);
                    $this->_order->addRelatedObject($agreement);
                    $message = $this->_adyenHelper->__('Used existing billing agreement #%s.', $agreement->getReferenceId());

                } else {
                    // set billing agreement data
                    $payment->setBillingAgreementData(array(
                        'billing_agreement_id'  => $recurringDetailReference,
                        'method_code'           => $payment->getMethodCode()
                    ));

                    // create billing agreement for this order
                    $agreement = Mage::getModel('adyen/billing_agreement');
                    $agreement->setStoreId($this->_order->getStoreId());
                    $agreement->importOrderPayment($payment);

                    $listRecurringContracts = Mage::getSingleton('adyen/api')->listRecurringContracts($agreement->getCustomerReference(), $agreement->getStoreId());

                    $contractDetail = null;
                    // get currenct Contract details and get list of all current ones
                    $recurringReferencesList = array();
                    foreach ($listRecurringContracts as $rc) {
                        $recurringReferencesList[] = $rc['recurringDetailReference'];
                        if (isset($rc['recurringDetailReference']) && $rc['recurringDetailReference'] == $recurringDetailReference) {
                            $contractDetail = $rc;
                        }
                    }

                    if($contractDetail != null) {
                        // update status of the agreements in magento
                        $billingAgreements = Mage::getResourceModel('adyen/billing_agreement_collection')
                            ->addFieldToFilter('customer_id', $agreement->getCustomerReference());

                        foreach($billingAgreements as $billingAgreement) {
                            if(!in_array($billingAgreement->getReferenceId(), $recurringReferencesList)) {
                                $billingAgreement->setStatus(Adyen_Payment_Model_Billing_Agreement::STATUS_CANCELED);
                                $billingAgreement->save();
                            } else {
                                $billingAgreement->setStatus(Adyen_Payment_Model_Billing_Agreement::STATUS_ACTIVE);
                                $billingAgreement->save();
                            }
                        }

                        $agreement->parseRecurringContractData($contractDetail);

                        if ($agreement->isValid()) {
                            $message = __('Created billing agreement #%s.', $agreement->getReferenceId());

                            // save into sales_billing_agreement_order
                            $agreement->addOrderRelation($this->_order);

                            // add to order to save agreement
                            $this->_order->addRelatedObject($agreement);
                        } else {
                            $message = __('Failed to create billing agreement for this order.');
                        }
                    } else {
                        $this->_debugData['_processNotification error'] = 'Failed to create billing agreement for this order (listRecurringCall did not contain contract)';
                        $this->_debugData['_processNotification ref'] = printf('recurringDetailReference in notification is %s', $recurringDetailReference) ;
                        $this->_debugData['_processNotification customer ref'] = printf('CustomerReference is: %s and storeId is %s', $agreement->getCustomerReference(), $agreement->getStoreId());
                        $this->_debugData['_processNotification customer result'] = $listRecurringContracts;
                        $message = __('Failed to create billing agreement for this order (listRecurringCall did not contain contract)');
                    }
                }
                $comment = $this->_order->addStatusHistoryComment($message);
                $this->_order->addRelatedObject($comment);
                break;
            default:
                $this->_order->getPayment()->getMethodInstance()->writeLog('notification event not supported!');
                break;
        }
    }

    /**
     *
     */
    protected function _authorizePayment()
    {
        $this->_debugData['_authorizePayment'] = 'Authorisation of the order';

//        $this->_uncancelOrder($order); // not implemented in magento v2.0

        $fraudManualReviewStatus = $this->_getFraudManualReviewStatus();


        // If manual review is active and a seperate status is used then ignore the pre authorized status
        if($this->_fraudManualReview != true || $fraudManualReviewStatus == "") {
            $this->_setPrePaymentAuthorized();
        } else {
            $this->_debugData['_authorizePayment info'] = 'Ignore the pre authorized status because the order is under manual review and use the Manual review status';
        }

        $this->_prepareInvoice();

        $_paymentCode = $this->_paymentMethodCode();

        // for boleto confirmation mail is send on order creation
        if($this->_paymentMethod != "adyen_boleto") {
            // send order confirmation mail after invoice creation so merchant can add invoicePDF to this mail
//            $this->_order->sendNewOrderEmail(); // send order email
            $this->_orderSender->send($this->_order);
        }

        if(($this->_paymentMethod == "c_cash" && $this->_getConfigData('create_shipment', 'adyen_cash', $this->_order->getStoreId())) || ($this->_getConfigData('create_shipment', 'adyen_pos', $this->_order->getStoreId()) && $_paymentCode == "adyen_pos"))
        {
            $this->_createShipment($this->_order);
        }
    }

    private function _setPrePaymentAuthorized()
    {
        $status = $this->_getConfigData('payment_pre_authorized', 'adyen_abstract', $this->_order->getStoreId());

        // only do this if status in configuration is set
        if(!empty($status)) {
            $this->_order->addStatusHistoryComment(__('Payment is pre authorised waiting for capture'), $status);
            $this->_debugData['_setPrePaymentAuthorized'] = 'Order status is changed to Pre-authorised status, status is ' . $status;
        } else {
            $this->_debugData['_setPrePaymentAuthorized'] = 'No pre-authorised status is used so ignore';
        }
    }

    /**
     * @param $order
     */
    protected function _prepareInvoice()
    {
        $this->_debugData['_prepareInvoice'] = 'Prepare invoice for order';
        $payment = $this->_order->getPayment()->getMethodInstance();


        //Set order state to new because with order state payment_review it is not possible to create an invoice
        if (strcmp($this->_order->getState(), \Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW) == 0) {
            $this->_order->setState(\Magento\Sales\Model\Order::STATE_NEW);
        }

        //capture mode
        if (!$this->_isAutoCapture()) {
            $this->_order->addStatusHistoryComment(__('Capture Mode set to Manual'));
            $this->_debugData['_prepareInvoice capture mode'] = 'Capture mode is set to Manual';

            // show message if order is in manual review
            if($this->_fraudManualReview) {
                // check if different status is selected
                $fraudManualReviewStatus = $this->_getFraudManualReviewStatus();
                if($fraudManualReviewStatus != "") {
                    $status = $fraudManualReviewStatus;
                    $comment = "Adyen Payment is in Manual Review check the Adyen platform";
                    $this->_order->addStatusHistoryComment(__($comment), $status);
                }
            }

            $createPendingInvoice = (bool) $this->_getConfigData('create_pending_invoice', 'adyen_abstract', $this->_order->getStoreId());
            if(!$createPendingInvoice) {
                $this->_debugData['_prepareInvoice done'] = 'Setting pending invoice is off so don\'t create an invoice wait for the capture notification';
                return;
            }
        }

        // validate if amount is total amount
        $orderCurrencyCode = $this->_order->getOrderCurrencyCode();
        $orderAmount = (int) $this->_adyenHelper->formatAmount($this->_order->getGrandTotal(), $orderCurrencyCode);

        if($this->_isTotalAmount($orderAmount)) {
            $this->_createInvoice($this->_order);
        } else {
            $this->_debugData['_prepareInvoice partial authorisation step1'] = 'This is a partial AUTHORISATION';

            // Check if this is the first partial authorisation or if there is already been an authorisation
            $paymentObj = $this->_order->getPayment();
            $authorisationAmount = $paymentObj->getAdyenAuthorisationAmount();
            if($authorisationAmount != "") {
                $this->_debugData['_prepareInvoice partial authorisation step2'] = 'There is already a partial AUTHORISATION received check if this combined with the previous amounts match the total amount of the order';
                $authorisationAmount = (int) $authorisationAmount;
                $currentValue = (int) $this->_value;
                $totalAuthorisationAmount = $authorisationAmount + $currentValue;

                // update amount in column
                $paymentObj->setAdyenAuthorisationAmount($totalAuthorisationAmount);

                if($totalAuthorisationAmount == $orderAmount) {
                    $this->_debugData['_prepareInvoice partial authorisation step3'] = 'The full amount is paid. This is the latest AUTHORISATION notification. Create the invoice';
                    $this->_createInvoice($this->_order);
                } else {
                    // this can be multiple times so use envenData as unique key
                    $this->_debugData['_prepareInvoice partial authorisation step3'] = 'The full amount is not reached. Wait for the next AUTHORISATION notification. The current amount that is authorized is:' . $totalAuthorisationAmount;
                }
            } else {
                $this->_debugData['_prepareInvoice partial authorisation step2'] = 'This is the first partial AUTHORISATION save this into the adyen_authorisation_amount field';
                $paymentObj->setAdyenAuthorisationAmount($this->_value);
            }
        }
    }

    /**
     * @param $order
     * @return bool
     */
    protected function _isAutoCapture()
    {
        $captureMode = trim($this->_getConfigData('capture_mode', 'adyen_abstract', $this->_order->getStoreId()));
        $sepaFlow = trim($this->_getConfigData('flow', 'adyen_sepa', $this->_order->getStoreId()));
        $_paymentCode = $this->_paymentMethodCode();
        $captureModeOpenInvoice = $this->_getConfigData('auto_capture_openinvoice', 'adyen_abstract', $this->_order->getStoreId());
        $captureModePayPal = trim($this->_getConfigData('paypal_capture_mode', 'adyen_abstract', $this->_order->getStoreId()));

        //check if it is a banktransfer. Banktransfer only a Authorize notification is send.
        $isBankTransfer = $this->_isBankTransfer();

        // if you are using authcap the payment method is manual. There will be a capture send to indicate if payment is succesfull
        if($_paymentCode == "adyen_sepa" && $sepaFlow == "authcap") {
            return false;
        }

        // payment method ideal, cash adyen_boleto or adyen_pos has direct capture
        if (strcmp($this->_paymentMethod, 'ideal') === 0 || strcmp($this->_paymentMethod, 'c_cash' ) === 0 || $_paymentCode == "adyen_pos" || $isBankTransfer == true || ($_paymentCode == "adyen_sepa" && $sepaFlow != "authcap") || $_paymentCode == "adyen_boleto") {
            return true;
        }
        // if auto capture mode for openinvoice is turned on then use auto capture
        if ($captureModeOpenInvoice == true && (strcmp($this->_paymentMethod, 'openinvoice') === 0 || strcmp($this->_paymentMethod, 'afterpay_default') === 0 || strcmp($this->_paymentMethod, 'klarna') === 0)) {
            return true;
        }
        // if PayPal capture modues is different from the default use this one
        if(strcmp($this->_paymentMethod, 'paypal' ) === 0 && $captureModePayPal != "") {
            if(strcmp($captureModePayPal, 'auto') === 0 ) {
                return true;
            } elseif(strcmp($captureModePayPal, 'manual') === 0 ) {
                return false;
            }
        }
        if (strcmp($captureMode, 'manual') === 0) {
            return false;
        }
        //online capture after delivery, use Magento backend to online invoice (if the option auto capture mode for openinvoice is not set)
        if (strcmp($this->_paymentMethod, 'openinvoice') === 0 || strcmp($this->_paymentMethod, 'afterpay_default') === 0 || strcmp($this->_paymentMethod, 'klarna') === 0) {
            return false;
        }
        return true;
    }

    /**
     * @param $paymentMethod
     * @return bool
     */
    protected function _isBankTransfer() {
        if(strlen($this->_paymentMethod) >= 12 &&  substr($this->_paymentMethod, 0, 12) == "bankTransfer") {
            $isBankTransfer = true;
        } else {
            $isBankTransfer = false;
        }
        return $isBankTransfer;
    }


    protected function _getFraudManualReviewStatus()
    {
        return $this->_getConfigData('fraud_manual_review_status', 'adyen_abstract', $this->_order->getStoreId());
    }

    protected function _getFraudManualReviewAcceptStatus()
    {
        return $this->_getConfigData('fraud_manual_review_accept_status', 'adyen_abstract', $this->_order->getStoreId());
    }

    protected function _isTotalAmount($orderAmount) {

        $this->_debugData['_isTotalAmount'] = 'Validate if AUTHORISATION notification has the total amount of the order';
        $value = (int)$this->_value;

        if($value == $orderAmount) {
            $this->_debugData['_isTotalAmount result'] = 'AUTHORISATION has the full amount';
            return true;
        } else {
            $this->_debugData['_isTotalAmount result'] = 'This is a partial AUTHORISATION, the amount is ' . $this->_value;
            return false;
        }

    }


    /**
     * Retrieve information from payment configuration
     *
     * @param string $field
     * @param int|string|null|\Magento\Store\Model\Store $storeId
     *
     * @return mixed
     */
    protected function _getConfigData($field, $paymentMethodCode = 'adyen_cc', $storeId)
    {
        // replace for now settings should moved from adyen_cc to adyen_abstract
        if($paymentMethodCode == 'adyen_abstract') {
            $paymentMethodCode = "adyen_cc";
        }
        $path = 'payment/' . $paymentMethodCode . '/' . $field;
        return $this->_scopeConfig->getValue($path, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
    }


}