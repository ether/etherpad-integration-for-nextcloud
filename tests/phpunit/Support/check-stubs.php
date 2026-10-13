<?php

declare(strict_types=1);

// The unit suite runs against hand-written stubs. This checks them against
// the packages they stand in for:
//
//   php check-stubs.php [<stubs dir> <upstream dir>...]
//
// Without arguments it checks tests/phpunit/stubs/OCP against the installed
// OCP package and the PSR packages it builds on, and tests/phpunit/stubs/Psr
// against the installed PSR packages.
//
// The stubs are looser than upstream on purpose: parameter and return types
// are not compared, and a stub may leave out a method, a constant or a
// parent. What is reported is what lets a test pass on code that cannot run
// on Nextcloud: a type, method or constant upstream does not have, another
// kind of type, an ancestor upstream does not have (a catch that holds
// against the stub only), a method the stub makes static or more visible. A
// method only tests need carries the `@stub-only` tag and must not exist
// upstream, where the stub's would hide the real one. Where an ancestor
// upstream cannot be read, a finding says so, as that ancestor may hold
// what is missing. `insteadof` is not evaluated: a method either trait
// brings counts.
//
// Prints one line per finding and exits 1 when there is one.
// StubsExistUpstreamTest runs it against the locked packages, the Psalm job
// against the newer OCP it analyses. Upstream is read rather than loaded:
// its classes carry the stubs' names.

use PhpParser\Error;
use PhpParser\Modifiers;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation\Alias;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

// A deprecation passes, as in the unit suite, whatever php.ini says.
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

const VISIBILITY = ['private' => 1, 'protected' => 2, 'public' => 3];

// A type as read here: its name and kind (class, interface, trait or
// enum), its direct parents and traits, the aliases its `use` statements
// give trait methods, its methods by lowercase name (name, visibility,
// static, stubOnly) and its constants.

/**
 * Every class-like declared under $dirs, a type declared twice once each,
 * and every file that could not be read.
 *
 * @param list<string> $dirs
 * @return array{list<array<string, mixed>>, list<string>}
 */
function typesIn(array $dirs): array {
	$parser = (new ParserFactory())->createForNewestSupportedVersion();
	$finder = new NodeFinder();
	$types = [];
	$unreadable = [];
	foreach ($dirs as $dir) {
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
				continue;
			}
			try {
				$statements = $parser->parse((string)file_get_contents($file->getPathname())) ?? [];
			} catch (Error $e) {
				$unreadable[] = $file->getPathname() . ': cannot be read: ' . $e->getMessage();
				continue;
			}
			$statements = (new NodeTraverser(new NameResolver()))->traverse($statements);
			foreach ($finder->findInstanceOf($statements, ClassLike::class) as $type) {
				if ($type->namespacedName !== null) {
					$types[] = describe($type);
				}
			}
		}
	}
	return [$types, $unreadable];
}

/** @return array<string, mixed> */
function describe(ClassLike $type): array {
	$kind = match (true) {
		$type instanceof Interface_ => 'interface',
		$type instanceof Trait_ => 'trait',
		$type instanceof Enum_ => 'enum',
		default => 'class',
	};
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

	$traits = [];
	$aliases = [];
	$methods = magicMethods((string)$type->getDocComment()?->getText());
	$constants = [];
	foreach ($type->stmts as $statement) {
		if ($statement instanceof TraitUse) {
			foreach ($statement->traits as $trait) {
				$traits[] = $trait->toString();
			}
			foreach ($statement->adaptations as $adaptation) {
				if ($adaptation instanceof Alias) {
					$aliases[] = [
						'method' => strtolower($adaptation->method->toString()),
						'name' => $adaptation->newName?->toString(),
						'visibility' => match (true) {
							$adaptation->newModifier === null => null,
							($adaptation->newModifier & Modifiers::PRIVATE) !== 0 => 'private',
							($adaptation->newModifier & Modifiers::PROTECTED) !== 0 => 'protected',
							default => 'public',
						},
					];
				}
			}
		} elseif ($statement instanceof ClassMethod) {
			$methods[strtolower($statement->name->toString())] = [
				'name' => $statement->name->toString(),
				'visibility' => $statement->isPrivate() ? 'private' : ($statement->isProtected() ? 'protected' : 'public'),
				'static' => $statement->isStatic(),
				'stubOnly' => preg_match('/^\s*(?:\/\*\*|\*)?\s*@stub-only\b/m', (string)$statement->getDocComment()?->getText()) === 1,
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
		'kind' => $kind,
		'parents' => $parents,
		'traits' => $traits,
		'aliases' => $aliases,
		'methods' => $methods,
		'constants' => $constants,
	];
}

/**
 * The methods a class docblock declares with `@method`.
 *
 * @return array<string, array<string, mixed>>
 */
function magicMethods(string $docComment): array {
	preg_match_all('/@method\s+(static\s+)?(?:[^\s(]+\s+)?(\w+)\s*\(/', $docComment, $matches, PREG_SET_ORDER);
	$methods = [];
	foreach ($matches as $match) {
		$methods[strtolower($match[2])] = ['name' => $match[2], 'visibility' => 'public', 'static' => $match[1] !== '', 'stubOnly' => false];
	}
	return $methods;
}

/**
 * Upstream's types by lowercase name. A type declared twice holds what
 * either declaration has.
 *
 * @param list<array<string, mixed>> $types
 * @return array<string, array<string, mixed>>
 */
function indexed(array $types): array {
	$index = [];
	foreach ($types as $type) {
		$key = strtolower($type['name']);
		if (!isset($index[$key])) {
			$index[$key] = $type;
			continue;
		}
		$index[$key]['parents'] = array_values(array_unique([...$index[$key]['parents'], ...$type['parents']]));
		$index[$key]['traits'] = array_values(array_unique([...$index[$key]['traits'], ...$type['traits']]));
		$index[$key]['aliases'] = [...$index[$key]['aliases'], ...$type['aliases']];
		$index[$key]['methods'] += $type['methods'];
		$index[$key]['constants'] += $type['constants'];
	}
	return $index;
}

/**
 * What $name has, its own and inherited: the methods a caller can reach
 * (a parent's private ones are not), the constants, every ancestor by
 * lowercase name, and the ancestors found neither upstream nor among PHP's
 * own.
 *
 * @param array<string, array<string, mixed>> $upstream
 * @param array<string, true> $seen
 * @return array{methods: array<string, array<string, mixed>>, constants: array<string, true>, ancestors: array<string, true>, unread: list<string>}
 */
function membersOf(string $name, array $upstream, array $seen = []): array {
	$key = strtolower(ltrim($name, '\\'));
	$type = $upstream[$key] ?? null;
	if ($type === null) {
		if (class_exists($name, false) || interface_exists($name, false)) {
			$reflection = new ReflectionClass($name);
			if ($reflection->isInternal()) {
				$methods = [];
				foreach ($reflection->getMethods() as $method) {
					if (!$method->isPrivate()) {
						$methods[strtolower($method->getName())] = [
							'name' => $method->getName(),
							'visibility' => $method->isProtected() ? 'protected' : 'public',
							'static' => $method->isStatic(),
							'stubOnly' => false,
						];
					}
				}
				$ancestors = array_map('strtolower', [...$reflection->getInterfaceNames(), ...array_keys(class_parents($name))]);
				return [
					'methods' => $methods,
					'constants' => array_fill_keys(array_keys($reflection->getConstants()), true),
					'ancestors' => array_fill_keys($ancestors, true),
					'unread' => [],
				];
			}
		}
		return ['methods' => [], 'constants' => [], 'ancestors' => [], 'unread' => [ltrim($name, '\\')]];
	}

	$seen[$key] = true;
	$methods = [];
	$constants = $type['constants'];
	$ancestors = [];
	$unread = [];
	foreach ($type['parents'] as $parent) {
		$ancestors[strtolower($parent)] = true;
		if (isset($seen[strtolower($parent)])) {
			continue;
		}
		$inherited = membersOf($parent, $upstream, $seen);
		$methods = array_filter($inherited['methods'], static fn (array $method): bool => $method['visibility'] !== 'private') + $methods;
		$constants += $inherited['constants'];
		$ancestors += $inherited['ancestors'];
		$unread = [...$unread, ...$inherited['unread']];
	}
	$fromTraits = [];
	foreach ($type['traits'] as $trait) {
		if (isset($seen[strtolower($trait)])) {
			continue;
		}
		$used = membersOf($trait, $upstream, $seen);
		$fromTraits = $used['methods'] + $fromTraits;
		$constants += $used['constants'];
		$unread = [...$unread, ...$used['unread']];
	}
	foreach ($type['aliases'] as $alias) {
		$original = $fromTraits[$alias['method']] ?? null;
		if ($original === null) {
			continue;
		}
		$aliased = ['name' => $alias['name'] ?? $original['name'], 'visibility' => $alias['visibility'] ?? $original['visibility']] + $original;
		$fromTraits[strtolower($aliased['name'])] = $aliased;
	}

	return [
		'methods' => $type['methods'] + $fromTraits + $methods,
		'constants' => $constants,
		'ancestors' => $ancestors,
		'unread' => $unread,
	];
}

function withArticle(string $kind): string {
	return (in_array($kind[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'an ' : 'a ') . $kind;
}

/**
 * @param list<string> $upstreamDirs
 * @return list<string>
 */
function findings(string $stubsDir, array $upstreamDirs): array {
	foreach ([$stubsDir, ...$upstreamDirs] as $dir) {
		if (!is_dir($dir)) {
			return [$dir . ': no such directory'];
		}
	}
	[$stubs, $unreadable] = typesIn([$stubsDir]);
	[$upstreamTypes, $unreadableUpstream] = typesIn($upstreamDirs);
	$upstream = indexed($upstreamTypes);
	$findings = [...$unreadable, ...$unreadableUpstream];

	foreach ($stubs as $stub) {
		$type = $upstream[strtolower($stub['name'])] ?? null;
		if ($type === null) {
			$findings[] = $stub['name'] . ': no such type upstream';
			continue;
		}
		if ($stub['kind'] !== $type['kind']) {
			$findings[] = $stub['name'] . ': ' . withArticle($stub['kind']) . ' in the stub, ' . withArticle($type['kind']) . ' upstream';
		}
		$members = membersOf($stub['name'], $upstream);
		$unread = $members['unread'] === [] ? '' : ' (' . implode(', ', array_unique($members['unread'])) . ' could not be read)';
		foreach ($stub['parents'] as $parent) {
			if (!isset($members['ancestors'][strtolower($parent)])) {
				$findings[] = $stub['name'] . ': ' . $parent . ' is no ancestor of it upstream' . $unread;
			}
		}
		foreach ($stub['methods'] as $methodKey => $method) {
			$real = $members['methods'][$methodKey] ?? null;
			$named = $stub['name'] . '::' . $method['name'] . '()';
			if ($method['stubOnly']) {
				if ($real !== null) {
					$findings[] = $named . ': marked @stub-only, but upstream has it';
				} elseif ($unread !== '') {
					$findings[] = $named . ': marked @stub-only, which cannot be told' . $unread;
				}
			} elseif ($real === null) {
				$findings[] = $named . ': no such method upstream' . $unread;
			} elseif ($method['static'] && !$real['static']) {
				$findings[] = $named . ': static in the stub, not upstream';
			} elseif (VISIBILITY[$method['visibility']] > VISIBILITY[$real['visibility']]) {
				$findings[] = $named . ': ' . $method['visibility'] . ' in the stub, ' . $real['visibility'] . ' upstream';
			}
		}
		foreach (array_keys($stub['constants']) as $constant) {
			if (!isset($members['constants'][$constant])) {
				$findings[] = $stub['name'] . '::' . $constant . ': no such constant upstream' . $unread;
			}
		}
	}
	return $findings;
}

if (count($argv) === 2) {
	fwrite(STDERR, "Usage: php check-stubs.php [<stubs dir> <upstream dir>...]\n");
	exit(2);
}
$pairs = count($argv) > 2
	? [[$argv[1], array_slice($argv, 2)]]
	: [
		[$root . '/tests/phpunit/stubs/OCP', [$root . '/vendor/nextcloud/ocp', $root . '/vendor/psr']],
		[$root . '/tests/phpunit/stubs/Psr', [$root . '/vendor/psr']],
	];
$findings = [];
foreach ($pairs as [$stubsDir, $upstreamDirs]) {
	$findings = [...$findings, ...findings($stubsDir, $upstreamDirs)];
}

sort($findings);
foreach ($findings as $finding) {
	echo $finding, "\n";
}
exit($findings === [] ? 0 : 1);
