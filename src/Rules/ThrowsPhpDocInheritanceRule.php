<?php declare(strict_types = 1);

namespace Pepakriz\PHPStanExceptionRules\Rules;

use Pepakriz\PHPStanExceptionRules\CheckedExceptionService;
use Pepakriz\PHPStanExceptionRules\DefaultThrowTypeService;
use Pepakriz\PHPStanExceptionRules\UnsupportedClassException;
use Pepakriz\PHPStanExceptionRules\UnsupportedFunctionException;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Broker\ClassNotFoundException;
use PHPStan\Reflection\MissingMethodFromReflectionException;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\FileTypeMapper;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;
use PHPStan\Type\VoidType;
use function array_filter;
use function array_merge;
use function count;
use function sprintf;

/**
 * @implements Rule<ClassMethod>
 */
class ThrowsPhpDocInheritanceRule implements Rule
{

	/**
	 * @var CheckedExceptionService
	 */
	private $checkedExceptionService;

	/**
	 * @var DefaultThrowTypeService
	 */
	private $defaultThrowTypeService;

	/**
	 * @var FileTypeMapper
	 */
	private $fileTypeMapper;

	/**
	 * @var ReflectionProvider
	 */
	private $reflectionProvider;

	public function __construct(
		CheckedExceptionService $checkedExceptionService,
		DefaultThrowTypeService $defaultThrowTypeService,
		FileTypeMapper $fileTypeMapper,
		ReflectionProvider $reflectionProvider
	)
	{
		$this->checkedExceptionService = $checkedExceptionService;
		$this->defaultThrowTypeService = $defaultThrowTypeService;
		$this->fileTypeMapper = $fileTypeMapper;
		$this->reflectionProvider = $reflectionProvider;
	}

	public function getNodeType(): string
	{
		return ClassMethod::class;
	}

	/**
	 * @return RuleError[]
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		/** @var ClassMethod $node */
		$node = $node;

		$classReflection = $scope->getClassReflection();
		if ($classReflection === null) {
			return [];
		}

		$docComment = $node->getDocComment();
		if ($docComment === null) {
			return [];
		}

		$methodName = $node->name->toString();
		if ($methodName === '__construct') {
			return [];
		}

		$traitReflection = $scope->getTraitReflection();
		$traitName = $traitReflection !== null ? $traitReflection->getName() : null;

		$resolvedPhpDoc = $this->fileTypeMapper->getResolvedPhpDoc(
			$scope->getFile(),
			$classReflection->getName(),
			$traitName,
			$methodName,
			$docComment->getText()
		);

		$throwsTag = $resolvedPhpDoc->getThrowsTag();
		if ($throwsTag === null || $throwsTag->getType() instanceof VoidType) {
			return [];
		}

		$throwType = $throwsTag->getType();
		$parentClasses = array_filter(
			array_merge($classReflection->getInterfaces(), [$classReflection->getParentClass()])
		);

		$messages = [];
		foreach ($parentClasses as $parentClass) {
			try {
				$parentClassReflection = $this->reflectionProvider->getClass($parentClass->getName());
			} catch (ClassNotFoundException $e) {
				return [$e->getMessage()];
			}

			try {
				$methodReflection = $parentClassReflection->getMethod($methodName, $scope);
			} catch (MissingMethodFromReflectionException $e) {
				continue;
			}

			try {
				$parentThrowType = $this->defaultThrowTypeService->getMethodThrowType($methodReflection);
			} catch (UnsupportedClassException | UnsupportedFunctionException $e) {
				$parentThrowType = $methodReflection->getThrowType();
			}

			if ($parentThrowType === null || $parentThrowType instanceof VoidType) {
				$messages[] = RuleErrorBuilder::message(sprintf('PHPDoc tag @throws with type %s is not compatible with parent', $throwType->describe(VerbosityLevel::typeOnly())))->build();

				continue;
			}

			$parentThrowType = $this->filterUnchecked($parentThrowType);
			if ($parentThrowType === null) {
				continue;
			}

			if ($parentThrowType->isSuperTypeOf($throwType)->yes()) {
				continue;
			}

			$messages[] = RuleErrorBuilder::message(sprintf('PHPDoc tag @throws with type %s is not compatible with parent %s', $throwType->describe(VerbosityLevel::typeOnly()), $parentThrowType->describe(VerbosityLevel::typeOnly())))->build();
		}

		return $messages;
	}

	private function filterUnchecked(Type $type): ?Type
	{
		$exceptionClasses = $this->extractDirectClassNames($type);
		$exceptionClasses = $this->checkedExceptionService->filterCheckedExceptions($exceptionClasses);

		if (count($exceptionClasses) === 0) {
			return null;
		}

		$types = [];
		foreach ($exceptionClasses as $exceptionClass) {
			$types[] = new ObjectType($exceptionClass);
		}

		return TypeCombinator::union(...$types);
	}

	private function extractDirectClassNames(Type $type): array
	{
		$classNames = [];

		foreach ($type->getObjectClassNames() as $name) {
			$classNames[] = $name;
		}

		return $classNames;
	}

}
