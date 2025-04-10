<?php

use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Method;

require_once __DIR__ . '/vendor/autoload.php';

class SimpleCartMolliePaymentGateway extends SimpleCartGateway
{
    /** @var simplecart_mollie $service */
    protected $service;

    /** @var MollieApiClient $mollie */
    public $mollie;

    /** {@inheritDoc} */
    public $overwriteOutput = true;

    public function view()
    {

        try {
            if (!$this->initMollie()) {
                return false;
            }

            // method properties
            $mPhs = $this->method->toArray();
            $mPhs['paymentKey'] = $this->getProperty('paymentKey', 'paymentMethod');
            ;
            $idx = $this->method->getIdx();
            $output = '';

            // properties
            $tpl = $this->getProperty('mollieMethodRowTpl', $this->getProperty('tpl', 'scPaymentMethod'));
            $outerTpl = $this->getProperty('mollieMethodOuterTpl');
            $outputSeparator = $this->getProperty('outputSeparator', "\n");
            $selectedFirst = (bool) $this->getProperty('selectedFirst', 1, 'isset');
            $selected = $this->getProperty('selected', -1);

            // get filtered methods, if any
            $filtered = array();
            $filterMethod = $this->getProperty('filterMethod');
            if (!empty($filterMethod)) {
                $filtered = explode(',', $filterMethod);
                foreach ($filtered as $k => $v) {
                    if (!is_numeric($v) && stristr($v, '~') !== false) {
                        $v = explode('~', $v); // format like '{name}~{id}'
                        if ($v[1] == $this->method->get('id')) {
                            $filtered[$k] = $v[0];
                        } else {
                            unset($filtered[$k]);
                        }
                    }
                }
            }

            $total = $this->method->cartTotal;

            /** @var Method[] $methods */
            $methods = $this->mollie->methods->allActive()->getIterator();
            foreach ($methods as $key => $method) {
                if (!empty($filtered) && !in_array($method->id, $filtered)) {
                    continue;
                }

                // Only show payment methods that allow transactions within the provided range
                if (
                    ($total > (float)$method->maximumAmount->value)
                    || ($total < (float)$method->minimumAmount->value)
                ) {
                    continue;
                }


                $phs = $mPhs;
                $phs['idx'] = $idx;
                $phs['id'] = $method->id . '~' . $mPhs['id'];
                $phs['name'] .= '-' . $method->id;
                $phs['selected'] = (($selected == $phs['id'] || ($selectedFirst && $idx == 1)) ? 'selected' : '');

                $lexiconKey = 'simplecart.methods.payment.mollie.' . $method->id;
                $title = (string) $this->modx->lexicon($lexiconKey);
                if (!empty($title) && $title != $lexiconKey) {
                    $phs['title'] = $title;
                }
                else {
                    $phs['title'] = $method->id;
                }

                $lexiconKeyDesc = $lexiconKey . '.desc';
                $description = (string) $this->modx->lexicon($lexiconKeyDesc);
                if (!empty($description) && $description != $lexiconKeyDesc) {
                    $phs['description'] = $description;
                }
                else {
                    $phs['description'] = '';
                }

                $lexiconKeyOD = $lexiconKey . '.orderdesc';
                $description = (string) $this->modx->lexicon($lexiconKeyOD);
                if (!empty($description) && $description != $lexiconKeyOD) {
                    $phs['orderdesc'] = $description;
                }
                else {
                    $phs['orderdesc'] = '';
                }

                // when the method has it's own "getContent" method, get it and add to the output
                $contentFunction  = 'get' . ucfirst($method->id) . 'Content';
                if (method_exists($this, $contentFunction)) {
                    $addContent = $this->$contentFunction();
                    if (!empty($addContent)) {
                        $phs['addContent'] = $addContent;
                    }
                }

                $phs = $this->simplecart->prefixPlaceholders($phs, 'method.');
                $output .= $this->service->getChunk($tpl, $phs) . $outputSeparator;

                $this->method->setIdx($idx);
                $idx++;
            }

            if (!empty($output) && !empty($outerTpl)) {
                $phs = array('wrapper' => $output);
                $output = $this->service->getChunk($outerTpl, $phs) . $outputSeparator;
            }

            return $output;
        }
        catch (ApiException $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[SimpleCart] Mollie Error: ' . $e->getMessage());
            return false;
        }
    }

    public function submit()
    {
        try {
            if (!$this->initMollie()) {
                return false;
            }
            $this->modx->lexicon->load('simplecart:cart', 'simplecart:methods');

            // figure out the sub-method selected
            $paymentKey = $this->getProperty('paymentKey', 'paymentMethod');
            $params = $this->modx->request->getParameters(array($paymentKey), 'POST');
            if (!array_key_exists($paymentKey, $params) || stristr($params[$paymentKey], '~') === false) {
                throw new ApiException('No method selected! Expecting "' . $paymentKey . '", with format "{id}-{method.id}". This can happen when Mollie cannot be reached.');
            }
            $subMethod = explode('~', $params[$paymentKey]);
            $subMethod = $subMethod[0];
            $method = $this->mollie->methods->get($subMethod);

            if (empty($method) || $method->id != $subMethod) {
                throw new ApiException('The method for "' . $subMethod . '" cannot be found.');
            }

            $webhookUrl = $this->modx->getOption('server_protocol') . '://' . $this->modx->getOption('http_host');
            $webhookUrl .= $this->service->config['connectorUrl'] . '?action=webhook';

            $payment = $this->mollie->payments->create(array(
                'amount' => [
                    'currency' => $this->simplecart->currency->get('name'),
                    'value' => number_format($this->order->get('total'), 2, '.', ''),
                ],
                'description' => $this->modx->lexicon('simplecart.methods.yourorderat', array(
                    'site_name' => $this->modx->getOption('site_name'),
                    '+site_name' => $this->modx->getOption('site_name'),
                    'site_url' => $this->modx->getOption('site_url'),
                    'ordernr' => $this->order->get('ordernr'),
                )),
                'method' => $method->id,
                'issuer' => $this->hasField('mollie_ideal_issuer') ? $this->getField('mollie_ideal_issuer') : null,
                'metadata' => array(
                    'order_id' => $this->order->get('id'),
                    'order_nr' => $this->order->get('ordernr'),
                ),
                'webhookUrl' => $webhookUrl,
                'redirectUrl' => $this->getRedirectUrl(),
            ));

            $this->order->addLog('Mollie Transaction ID', $payment->id);
            $this->order->set('async_payment_confirmation', true);
            $this->order->save();

            $this->modx->sendRedirect($payment->getCheckoutUrl());
            return true;
        }
        catch (ApiException $e) {
            $this->order->addLog('Mollie Exception', $e->getMessage());
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[SimpleCart] Mollie Exception: ' . $e->getMessage());
            return false;
        }
    }

    public function verify()
    {

        try {
            if (!$this->initMollie()) {
                return false;
            }

            $transId = $this->order->getLog('Mollie Transaction ID');
            if (empty($transId)) {
                return false;
            }

            // If previously stored as confirmed, we don't double check the API
            $storedStatus = $this->order->getLog('Mollie Payment');
            if ($storedStatus === 'Confirmed') {
                return true;
            }

            $payment = $this->mollie->payments->get($transId);
            if ($payment->isPaid()) {
                $this->order->addLog('Mollie Payment', 'Confirmed');
                $this->order->setStatus('finished');
                $this->order->save();

                return true;
            }

            // not paid + not pending = some kind of failure
            if (!$payment->isOpen()) {
                $this->order->addLog('Mollie Payment Failed', $payment->status);
                $this->order->setStatus('payment_failed');
                $this->order->save();
            }

            return false;
        }
        catch (ApiException $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[SimpleCart] Mollie Verify Exception: ' . $e->getMessage());
            return false;
        }
    }

    /** CUSTOM METHODS **/

    public function initMollie()
    {

        $apiKey = $this->getProperty('api_key');
        if (empty($apiKey)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, 'Could not instantiate Mollie gateway, missing api_key property.', '', __METHOD__, __FILE__, __LINE__);
            return false;
        }

        $this->mollie = new MollieApiClient();
        try {
            $this->mollie->setApiKey($apiKey);
        } catch (ApiException $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, 'Could not instantiate Mollie gateway, invalid api_key property: ' . $apiKey);
            return false;
        }

        // initialize service too
        $corePath = $this->modx->getOption('simplecart_mollie.core_path', null, $this->modx->getOption('core_path') . 'components/simplecart_mollie/') . 'model/simplecart_mollie/';
        $this->service = $this->modx->getService('simplecart_mollie', 'simplecart_mollie', $corePath, array());
        if (!($this->service instanceof simplecart_mollie)) {
            return false;
        }

        return true;
    }
}
