<?php

declare(strict_types=1);

// Loads every class under lib/ in a process that has nothing but the unit
// suite's bootstrap, as Infection's has, and prints each one that fails.
// LibClassesLoadTest runs it.

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
