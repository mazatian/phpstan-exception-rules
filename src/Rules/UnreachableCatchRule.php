<?php declare(strict_types = 1);

namespace Pepakriz\PHPStanExceptionRules\Rules;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\TryCatch;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use function array_map;
use function is_a;
use function sprintf;

/**
 * @implements Rule<Stmt>
 */
class UnreachableCatchRule implements Rule
{

	private const UNREACHABLE_CATCH_NODE_ATTRIBUTE = '__UNREACHABLE_CATCH_NODE_ATTRIBUTE__';

	/**
	 * @var ReflectionProvider
	 */
	private $reflectionProvider;

	public function __construct(ReflectionProvider $reflectionProvider)
	{
		$this->reflectionProvider = $reflectionProvider;
	}

	public function getNodeType(): string
	{
		return Stmt::class;
	}

	/**
	 * @return RuleError[]
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if ($node instanceof TryCatch) {
			/** @var string[] $caughtClasses */
			$caughtClasses = [];
			foreach ($node->catches as $catch) {
				$catchClasses = array_map(static function (Name $node): string {
					return $node->toString();
				}, $catch->types);

				foreach ($catchClasses as $catchClass) {
					if (!$this->reflectionProvider->hasClass($catchClass)) {
						continue;
					}

					foreach ($caughtClasses as $caughtClass) {
						if (!is_a($catchClass, $caughtClass, true)) {
							continue;
						}

						$catch->setAttribute(
							self::UNREACHABLE_CATCH_NODE_ATTRIBUTE,
							sprintf('Superclass of %s has already been caught', $catchClass)
						);
						break 2;
					}

					$caughtClasses[] = $catchClass;
				}
			}

			return [];
		}

		if ($node instanceof Catch_ && $node->hasAttribute(self::UNREACHABLE_CATCH_NODE_ATTRIBUTE)) {
			return [];
		}

		return [];
	}

}
