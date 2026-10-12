<?php

declare(strict_types=1);

// Every type, method and constant an OCP stub declares has to exist in the
// OCP package given, inherited members resolved:
//
//   php check-ocp-stubs.php [<ocp package dir>] [<stubs dir>]
//
// Parameter and return types are not compared: the stubs are looser than
// upstream on purpose. What this finds is a member that was renamed or
// moved, which a test against the stub would never notice. A method only
// tests need carries `@stub-only` in its docblock, and must then not exist
// upstream: a stub's own method would hide the real one. Prints one line
// per finding and exits 1 when there is one. OcpStubsExistUpstreamTest runs
// it against the locked OCP, the Psalm job against each OCP it analyses.
//
// Upstream is read rather than loaded: its classes carry the stubs' names,
// and the two cannot share a process.

use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

$ocpDir = $argv[1] ?? $root . '/vendor/nextcloud/ocp';
$stubsDir = $argv[2] ?? $root . '/tests/phpunit/stubs/OCP';

/**
 * The class-likes the PHP files under $dir declare, by lowercase name.
 *
 * @return array<string, array{name: string, methods: array<string, array{name: string, stubOnly: bool}>, constants: array<string, true>, parents: list<string>}>
 */
function typesIn(string $dir): array {
	$parser = (new ParserFactory())->createForNewestSupportedVersion();
	$finder = new NodeFinder();
	$types = [];
	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($files as $file) {
		if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
			continue;
		}
		$statements = $parser->parse((string)file_get_contents($file->getPathname())) ?? [];
		$traverser = new NodeTraverser(new NameResolver());
		$statements = $traverser->traverse($statements);
		foreach ($finder->findInstanceOf($statements, ClassLike::class) as $type) {
			if ($type->namespacedName === null) {
				continue;
			}
			$types[strtolower($type->namespacedName->toString())] = describe($type);
		}
	}
	return $types;
}

/** @return array{name: string, methods: array<string, array{name: string, stubOnly: bool}>, constants: array<string, true>, parents: list<string>} */
function describe(ClassLike $type): array {
	$parents = [];
	if ($type instanceof Class_) {
		if ($type->extends !== null) {
			$parents[] = $type->extends->toString();
		}
		foreach ($type->implements as $interface) {
			$parents[] = $interface->toString();
		}
	} elseif ($type instanceof Interface_) {
		foreach ($type->extends as $interface) {
			$parents[] = $interface->toString();
		}
	} elseif ($type instanceof Enum_) {
		foreach ($type->implements as $interface) {
			$parents[] = $interface->toString();
		}
		$parents[] = $type->scalarType === null ? 'UnitEnum' : 'BackedEnum';
	}
	$methods = [];
	$constants = [];
	foreach ($type->stmts as $statement) {
		if ($statement instanceof TraitUse) {
			foreach ($statement->traits as $trait) {
				$parents[] = $trait->toString();
			}
		} elseif ($statement instanceof ClassMethod) {
			$methods[strtolower($statement->name->toString())] = [
				'name' => $statement->name->toString(),
				'stubOnly' => str_contains((string)$statement->getDocComment()?->getText(), '@stub-only'),
			];
		} elseif ($statement instanceof ClassConst) {
			foreach ($statement->consts as $constant) {
				$constants[$constant->name->toString()] = true;
			}
		} elseif ($statement instanceof EnumCase) {
			$constants[$statement->name->toString()] = true;
		}
	}
	return [
		'name' => (string)$type->namespacedName?->toString(),
		'methods' => $methods,
		'constants' => $constants,
		'parents' => $parents,
	];
}

/**
 * The methods and constants $name has, its own and inherited, and the
 * ancestors that could be found neither upstream nor among PHP's own.
 *
 * @param array<string, array{name: string, methods: array<string, array{name: string, stubOnly: bool}>, constants: array<string, true>, parents: list<string>}> $upstream
 * @param array<string, true> $seen
 * @return array{methods: array<string, true>, constants: array<string, true>, unread: list<string>}
 */
function membersOf(string $name, array $upstream, array $seen = []): array {
	$key = strtolower(ltrim($name, '\\'));
	if (isset($upstream[$key])) {
		$type = $upstream[$key];
		$members = ['methods' => array_fill_keys(array_keys($type['methods']), true), 'constants' => $type['constants'], 'unread' => []];
		foreach ($type['parents'] as $parent) {
			if (isset($seen[strtolower($parent)])) {
				continue;
			}
			$inherited = membersOf($parent, $upstream, $seen + [$key => true]);
			$members['methods'] += $inherited['methods'];
			$members['constants'] += $inherited['constants'];
			$members['unread'] = [...$members['unread'], ...$inherited['unread']];
		}
		return $members;
	}
	$builtIn = class_exists($name, false) || interface_exists($name, false) || trait_exists($name, false) || enum_exists($name, false);
	if ($builtIn && (new ReflectionClass($name))->isInternal()) {
		$reflection = new ReflectionClass($name);
		return [
			'methods' => array_fill_keys(array_map(static fn (ReflectionMethod $method): string => strtolower($method->getName()), $reflection->getMethods()), true),
			'constants' => array_fill_keys(array_keys($reflection->getConstants()), true),
			'unread' => [],
		];
	}
	return ['methods' => [], 'constants' => [], 'unread' => [$name]];
}

$upstream = typesIn($ocpDir) + (is_dir($root . '/vendor/psr') ? typesIn($root . '/vendor/psr') : []);
$findings = [];
foreach (typesIn($stubsDir) as $key => $stub) {
	if (!isset($upstream[$key])) {
		$findings[] = $stub['name'] . ': no such type upstream';
		continue;
	}
	$members = membersOf($stub['name'], $upstream);
	$unread = $members['unread'] === [] ? '' : ' (' . implode(', ', array_unique($members['unread'])) . ' could not be read)';
	foreach ($stub['methods'] as $key => $method) {
		if ($method['stubOnly'] && isset($members['methods'][$key])) {
			$findings[] = $stub['name'] . '::' . $method['name'] . '(): marked @stub-only, but upstream has it';
		} elseif (!$method['stubOnly'] && !isset($members['methods'][$key])) {
			$findings[] = $stub['name'] . '::' . $method['name'] . '(): no such method upstream' . $unread;
		}
	}
	foreach (array_keys($stub['constants']) as $constant) {
		if (!isset($members['constants'][$constant])) {
			$findings[] = $stub['name'] . '::' . $constant . ': no such constant upstream' . $unread;
		}
	}
}

sort($findings);
foreach ($findings as $finding) {
	echo $finding, "\n";
}
exit($findings === [] ? 0 : 1);
