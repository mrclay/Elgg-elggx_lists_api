<?php

spl_autoload_register(function ($class) {
	$pieces = explode('\\', ltrim($class, '\\'));
	$pieces[count($pieces) - 1] = strtr($pieces[count($pieces) - 1], '_', '/');
	$file = __DIR__ . '/classes/' . implode('/', $pieces) . '.php';
	is_readable($file) && (require $file);
});
