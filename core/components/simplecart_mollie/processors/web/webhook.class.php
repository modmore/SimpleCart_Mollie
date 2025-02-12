<?php

use Mollie\Api\Exceptions\ApiException;

class SimpleCartMollieWebHookProcessor extends modProcessor
{
    public function process()
    {

        /** @var simpleCartMethod $method */
        $method = $this->modx->getObject('simpleCartMethod', array('name' => 'mollie'));
        if (!empty($method) && is_object($method)) {
            $method->getGateway();
            if (!($method->gateway instanceof SimpleCartMolliePaymentGateway)) {
                return $this->failure('Failed to load Mollie Payment Gateway');
            }

            /** @var SimpleCartMolliePaymentGateway gateway */
            $method->gateway->setProperties($method->getProperties());
            $method->gateway->initMollie();

            $transId = $this->modx->getOption('id', $_REQUEST, '');
            if (empty($transId)) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, '[SimpleCart.Mollie] Webhook triggered, but no `id` present in request.');
                return $this->failure('Failed to get the Transaction ID');
            }

            try {
                $payment = $method->gateway->mollie->payments->get($transId);
            } catch (ApiException $e) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, '[SimpleCart.Mollie] Webhook triggered for payment ' . $transId . ', received exception  ' . get_class($e) . ': ' . $e->getMessage());
                return $this->failure('Failed to load the order');
            }

            // get metadata to get the order
            $orderId = $payment->metadata->order_id;
            $orderNr = $payment->metadata->order_nr;

            /** @var simpleCartOrder $order */
            $order = $this->modx->getObject('simpleCartOrder', array('id' => $orderId, 'ordernr' => $orderNr));
            if (empty($order) || !is_object($order)) {
                http_response_code(400);
                $this->modx->log(modX::LOG_LEVEL_ERROR, '[SimpleCart.Mollie] Webhook triggered for payment ' . $transId . ', but order not found with id = ' . $orderId . ' and ordernr = ' . $orderNr);
                return $this->failure('Failed to load the order');
            }

            // prevent double processing of the webhook logic
            $value = $order->getLog('Mollie Payment Source');
            if (strtolower($value) === 'Webhook') {
                return $this->failure('Payment already confirmed');
            }

            if ($payment->isPaid()) {
                $order->addLog('Mollie Payment', 'Confirmed');
                $order->addLog('Mollie Payment Source', 'Webhook');
                $order->setStatus('finished');
//                We no longer unset this value. It is used in the Order finish handling to prevent duplicate sends,
//                which means that the gateway is responsible for sending the confirmation at all times.
//                $order->set('async_payment_confirmation', false);
                $order->save();
                if (!$order->get('confirmation_sent')) {
                    // This triggers a request to the checkout finish page that handles hooks and email sending
                    $order->processOrder();
                }

                return $this->success('Order ' . $order->get('ordernr') . ' confirmed');
            }
            if (!$payment->isOpen()) {
                $order->addLog('Mollie Payment Failed', $payment->status);
                $order->addLog('Mollie Payment Source', 'Webhook');
                $order->setStatus('payment_failed');
                $order->set('async_payment_confirmation', false);
                $order->save();
            }
        }

        return $this->failure('Payment failed');
    }
}

return 'SimpleCartMollieWebHookProcessor';
