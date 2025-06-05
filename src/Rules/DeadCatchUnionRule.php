<?php declare(strict_types = 1);

namespace Pepakriz\PHPStanExceptionRules\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Catch_;
use PHPStan\Analyser\Scope;
use PHPStan\Broker\ClassNotFoundException;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use function array_unique;
use function count;
use function sprintf;

/**
 * @implements Rule<Catch_>
 */
class DeadCatchUnionRule implements Rule
{

	/** @var ReflectionProvider */
	private $reflectionProvider;

	public function __construct(ReflectionProvider $reflectionProvider)
	{
		$this->reflectionProvider = $reflectionProvider;
	}

	public function getNodeType(): string
	{
		return Catch_::class;
	}

	/**
	 * @return string[]
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		/** @var Catch_ $node */
		$node = $node;

		if (count($node->types) <= 1) {
			return [];
		}

		/** @var ClassReflection[] $types */
		$types = [];
		foreach ($node->types as $type) {
			try {
				$types[] = $this->reflectionProvider->getClass($type->toString());
			} catch (ClassNotFoundException $exception) {
				// ignore, already spotted by built-in rules
			}
		}

		/** @var string[] $errors */
		$errors = [];
		foreach ($types as $index => $type) {
			foreach ($types as $otherIndex => $otherType) {
				if ($index === $otherIndex) {
					continue;
				}

				if ($type === $otherType) {
					$errors[] = sprintf('Type %s is redundant', $type->getName());

					continue 2;
				}

				if ($type->isSubclassOf($otherType->getName())) {
					$errors[] = sprintf('Type %s is already caught by %s', $type->getName(), $otherType->getName());
					continue 2;
				}
			}
		}

		return array_unique($errors);
	}

}
