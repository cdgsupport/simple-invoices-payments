<?php
// vendor/autoload.php
spl_autoload_register(function ($class) {
	// Stripe namespace autoloading
	if (strpos($class, 'Stripe\\') === 0) {
		$path = str_replace('\\', '/', $class);
		$file = __DIR__ . '/../lib/' . $path . '.php';
		if (file_exists($file)) {
			require_once $file;
		}
	}
});

// Include Stripe init
require_once __DIR__ . '/../init.php';