<?php

/** @var modX|xPDO $modx */
$modx =& $transport->xpdo;
$success = false;

// load package
$modelPath = $modx->getOption('simplecart.core_path', null, $modx->getOption('core_path') . 'components/simplecart/') . 'model/';
$modx->addPackage('simplecart', $modelPath);

switch($options[xPDOTransport::PACKAGE_ACTION]) {

    case xPDOTransport::ACTION_INSTALL:
    case xPDOTransport::ACTION_UPGRADE:

        $modx->log(modX::LOG_LEVEL_INFO, 'Creating payment gateway\'s needed records...');

        // get count for next sort
        $count = $modx->getCount('simpleCartMethod', array('type' => 'payment'));
        $properties = array();

        $modx->log(modX::LOG_LEVEL_INFO, 'Currently ' . $count .' method(s) installed...');

        // create mollie payment method
		$method = $modx->getObject('simpleCartMethod', array('name' => 'mollie', 'type' => 'payment'));
		if(empty($method) || !is_object($method)) {

            $modx->log(modX::LOG_LEVEL_INFO, '... Creating Mollie records');

			$method = $modx->newObject('simpleCartMethod');
			$method->set('name', 'mollie');
			$method->set('price_add', null);
			$method->set('type', 'payment');
			$method->set('sort_order', ($count+2));
			$method->set('ignorefree', false);
			$method->set('allowremarks', false);
			$method->set('default', false);
			$method->set('active', false);
            $method->save();
		}

        $list = array(
            'api_key' => '',
        );

        foreach ($list as $key => $defaultValue) {

            // add some config records
            $property = $modx->getObject('simpleCartMethodProperty', array('method' => $method->get('id'), 'name' => $key));
            if (empty($property) || !is_object($property)) {

                $modx->log(modX::LOG_LEVEL_INFO, '... Creating "' . $key . '" property for Mollie method');

                $property = $modx->newObject('simpleCartMethodProperty');
                $property->set('method', $method->get('id'));
                $property->set('name', $key);
                $property->set('value', $defaultValue);
                $property->save();
            }
        }

        $success = true;
        break;

    case xPDOTransport::ACTION_UNINSTALL:

        $modx->log(modX::LOG_LEVEL_INFO, 'Remove Mollie method records...');

        /** @var simpleCartMethod $method */
        $method = $modx->getObject('simpleCartMethod', array('name' => 'mollie', 'type' => 'payment'));
		if(!empty($method) || is_object($method)) {
            $method->remove();
        }

        $success = true;
        break;
}

return $success;