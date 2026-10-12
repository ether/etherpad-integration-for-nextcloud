<?php

declare(strict_types=1);

// Loads every class under lib/ in a process that has nothing but the unit
// suite's bootstrap, as Infection's has, and prints each one that throws on
// loading; a fatal error ends the run at the first. AppInfo\Application
// loads as the bootstrap's stub. LibClassesLoadTest runs it.

// A deprecation passes, as in the rest of the suite, whatever php.ini says.
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');

require __DIR__ . '/../bootstrap.php';

$lib = (string)realpath(__DIR__ . '/../../../lib');
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
	if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
		continue;
	}
	$name = 'OCA\\EtherpadNextcloud\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($lib) + 1, -4));
	try {
		if (!class_exists($name) && !interface_exists($name) && !trait_exists($name) && !enum_exists($name)) {
			echo $name, ": the file declares no type of that name\n";
		}
	} catch (\Throwable $e) {
		echo $name, ': ', $e->getMessage(), "\n";
	}
}
