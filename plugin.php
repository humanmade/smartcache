<?php

/**
 * Plugin name: Smartcache
 * Description: Smart cache lifetimes for WordPress
 * Author: Joe Hoyle
 * Version: 0.1.0
 */

namespace Smartcache;

require_once __DIR__ . '/inc/namespace.php';
require_once __DIR__ . '/inc/admin/namespace.php';
require_once __DIR__ . '/inc/log/namespace.php';

bootstrap();
Admin\bootstrap();
Log\bootstrap();
