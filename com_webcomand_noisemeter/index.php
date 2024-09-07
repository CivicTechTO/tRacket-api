<?php
namespace com_webcomand_noisemeter_api;

/**
 * Route requests to https://api.tracket.com/ to the controllers.
 *
 * Default to the 'v1' controller because that is the current and only API version.
 */
\io_comand_mvc\router::route([
    'namespace' => __NAMESPACE__,
    'default' => 'v1'
]);
