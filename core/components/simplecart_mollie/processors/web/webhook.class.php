<?php

class SimpleCartMollieWebHookProcessor extends modProcessor
{
    public function process() {

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
                return $this->failure('Failed to get the Transaction ID');
            }

            /** @var Mollie_Api_Object_Payment $payment */
            $payment = $method->gateway->mollie->payments->get($transId);

            // get metadata to get the order
            $orderId = $payment->metadata->order_id;
            $orderNr = $payment->metadata->order_nr;

            /** @var simpleCartOrder $order */
            $order = $this->modx->getObject('simpleCartOrder', array('id' => $orderId, 'ordernr' => $orderNr));
            if (empty($order) || !is_object($order)) {
                return $this->failure('Failed to load the order');
            }

            $value = $order->getLog('Mollie Payment');
            if (strtolower($value) == 'confirmed') {
                return $this->failure('Order already confirmed');
            }

            if ($payment->isPaid()) {

                $order->addLog('Mollie Payment', 'Confirmed');
                $order->setStatus('finished');
                $order->save();

                return $this->success('Order ' . $order->get('ordernr') . ' confirmed');
            }
            else if (!$payment->isOpen()) {

                $order->addLog('Mollie Payment Failed', $payment->status);
                $order->setStatus('payment_failed');
                $order->save();
            }
        }

        return $this->failure('Payment failed');
    }
}

return 'SimpleCartMollieWebHookProcessor';