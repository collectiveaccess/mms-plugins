<?php

if (!defined("__CA_LOCAL_CONFIG_DIRECTORY__")) {
	define("__CA_LOCAL_CONFIG_DIRECTORY__", __DIR__."/conf");
}

$_SERVER['HTTP_HOST'] = 'mmsd001.stm.kul.muenchen.de';

// Define config overrides for unit tests above, the following line will use remaining settings from main config.
require_once('../../setup.php');
