<?php declare(strict_types = 1);

namespace PHPStan\Rules;

use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\BenevolentUnionType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\Generic\TemplateMixedType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectWithoutClassType;
use PHPStan\Type\StaticType;
use PHPStan\Type\StrictMixedType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;
use function array_merge;
use function count;
use function sprintf;
use function strpos;

class RuleLevelHelper
{

	public function __construct(
		private ReflectionProvider $reflectionProvider,
		private bool $checkNullables,
		private bool $checkThisOnly,
		private bool $checkUnionTypes,
		private bool $checkExplicitMixed,
		private bool $checkImplicitMixed,
		private bool $checkListType,
		private bool $checkBenevolentUnionTypes,
	)
	{
	}

	/** @api */
	public function isThis(Expr $expression): bool
	{
		return $expression instanceof Expr\Variable && $expression->name === 'this';
	}

	/** @api */
	public function accepts(Type $acceptingType, Type $acceptedType, bool $strictTypes): bool
	{
		return $this->acceptsWithReason($acceptingType, $acceptedType, $strictTypes)->result;
	}

	public function acceptsWithReason(Type $acceptingType, Type $acceptedType, bool $strictTypes): RuleLevelHelperAcceptsResult
	{
		$checkForUnion = $this->checkUnionTypes;

		if ($this->checkBenevolentUnionTypes) {
			$traverse = static function (Type $type, callable $traverse) use (&$checkForUnion): Type {
				if ($type instanceof BenevolentUnionType) {
					$checkForUnion = true;
					return new UnionType($type->getTypes());
				}

				return $traverse($type);
			};

			$acceptedType = TypeTraverser::map($acceptedType, $traverse);
		}

		if (
			$this->checkExplicitMixed
		) {
			$traverse = static function (Type $type, callable $traverse): Type {
				if ($type instanceof TemplateMixedType) {
					return $type->toStrictMixedType();
				}
				if (
					$type instanceof MixedType
					&& $type->isExplicitMixed()
				) {
					return new StrictMixedType();
				}

				return $traverse($type);
			};
			$acceptingType = TypeTraverser::map($acceptingType, $traverse);
			$acceptedType = TypeTraverser::map($acceptedType, $traverse);
		}

		if (
			$this->checkImplicitMixed
		) {
			$traverse = static function (Type $type, callable $traverse): Type {
				if ($type instanceof TemplateMixedType) {
					return $type->toStrictMixedType();
				}
				if (
					$type instanceof MixedType
					&& !$type->isExplicitMixed()
				) {
					return new StrictMixedType();
				}

				return $traverse($type);
			};
			$acceptingType = TypeTraverser::map($acceptingType, $traverse);
			$acceptedType = TypeTraverser::map($acceptedType, $traverse);
		}

		if (
			!$this->checkNullables
			&& !$acceptingType instanceof NullType
			&& !$acceptedType instanceof NullType
			&& !$acceptedType instanceof BenevolentUnionType
		) {
			$acceptedType = TypeCombinator::removeNull($acceptedType);
		}

		$accepts = $acceptingType->acceptsWithReason($acceptedType, $strictTypes);
		if ($accepts->yes()) {
			return new RuleLevelHelperAcceptsResult(true, $accepts->reasons);
		}
		if ($acceptingType instanceof UnionType) {
			$reasons = [];
			foreach ($acceptingType->getSortedTypes() as $i => $innerType) {
				$accepts = self::acceptsWithReason($innerType, $acceptedType, $strictTypes)->decorateReasons(static fn (string $reason) => sprintf('Type #%d from the union: %s', $i + 1, $reason));
				if ($accepts->result) {
					return $accepts;
				}

				$reasons = array_merge($reasons, $accepts->reasons);
			}

			return new RuleLevelHelperAcceptsResult(false, $reasons);
		}

		if (
			$acceptedType->isArray()->yes()
			&& $acceptingType->isArray()->yes()
			&& (
				$acceptedType->isConstantArray()->no()
				|| !$acceptedType->isIterableAtLeastOnce()->no()
			)
			&& $acceptingType->isConstantArray()->no()
		) {
			if ($acceptingType->isIterableAtLeastOnce()->yes() && !$acceptedType->isIterableAtLeastOnce()->yes()) {
				$verbosity = VerbosityLevel::getRecommendedLevelByType($acceptingType, $acceptedType);
				return new RuleLevelHelperAcceptsResult(false, [
					sprintf(
						'%s %s empty.',
						$acceptedType->describe($verbosity),
						$acceptedType->isIterableAtLeastOnce()->no() ? 'is' : 'might be',
					),
				]);
			}

			if (
				$this->checkListType
				&& $acceptingType->isList()->yes()
				&& !$acceptedType->isList()->yes()
			) {
				$verbosity = VerbosityLevel::getRecommendedLevelByType($acceptingType, $acceptedType);
				return new RuleLevelHelperAcceptsResult(false, [
					sprintf(
						'%s %s a list.',
						$acceptedType->describe($verbosity),
						$acceptedType->isList()->no() ? 'is not' : 'might not be',
					),
				]);
			}

			return self::acceptsWithReason(
				$acceptingType->getIterableKeyType(),
				$acceptedType->getIterableKeyType(),
				$strictTypes,
			)->and(self::acceptsWithReason(
				$acceptingType->getIterableValueType(),
				$acceptedType->getIterableValueType(),
				$strictTypes,
			));
		}

		return new RuleLevelHelperAcceptsResult(
			$checkForUnion ? $accepts->yes() : !$accepts->no(),
			$accepts->reasons,
		);
	}

	/**
	 * @api
	 * @param callable(Type $type): bool $unionTypeCriteriaCallback
	 */
	public function findTypeToCheck(
		Scope $scope,
		Expr $var,
		string $unknownClassErrorPattern,
		callable $unionTypeCriteriaCallback,
	): FoundTypeResult
	{
		if ($this->checkThisOnly && !$this->isThis($var)) {
			return new FoundTypeResult(new ErrorType(), [], [], null);
		}
		$type = $scope->getType($var);
		if (!$this->checkNullables && !$type->isNull()->yes()) {
			$type = TypeCombinator::removeNull($type);
		}

		if (
			$this->checkExplicitMixed
			&& $type instanceof MixedType
			&& !$type instanceof TemplateMixedType
			&& $type->isExplicitMixed()
		) {
			return new FoundTypeResult(new StrictMixedType(), [], [], null);
		}

		if (
			$this->checkImplicitMixed
			&& $type instanceof MixedType
			&& !$type instanceof TemplateMixedType
			&& !$type->isExplicitMixed()
		) {
			return new FoundTypeResult(new StrictMixedType(), [], [], null);
		}

		if ($type instanceof MixedType || $type instanceof NeverType) {
			return new FoundTypeResult(new ErrorType(), [], [], null);
		}
		if ($type instanceof StaticType) {
			$type = $type->getStaticObjectType();
		}

		$errors = [];
		$directClassNames = $type->getObjectClassNames();
		$hasClassExistsClass = false;
		foreach ($directClassNames as $referencedClass) {
			if ($this->reflectionProvider->hasClass($referencedClass)) {
				$classReflection = $this->reflectionProvider->getClass($referencedClass);
				if (!$classReflection->isTrait()) {
					continue;
				}
			}

			if ($scope->isInClassExists($referencedClass)) {
				$hasClassExistsClass = true;
				continue;
			}

			$errors[] = RuleErrorBuilder::message(sprintf($unknownClassErrorPattern, $referencedClass))->line($var->getLine())->discoveringSymbolsTip()->build();
		}

		if (count($errors) > 0 || $hasClassExistsClass) {
			return new FoundTypeResult(new ErrorType(), [], $errors, null);
		}

		if (!$this->checkUnionTypes && $type instanceof ObjectWithoutClassType) {
			return new FoundTypeResult(new ErrorType(), [], [], null);
		}

		if (
			(
				!$this->checkUnionTypes
				&& $type instanceof UnionType
				&& !$type instanceof BenevolentUnionType
			) || (
				!$this->checkBenevolentUnionTypes
				&& $type instanceof BenevolentUnionType
			)
		) {
			$newTypes = [];

			foreach ($type->getTypes() as $innerType) {
				if (!$unionTypeCriteriaCallback($innerType)) {
					continue;
				}

				$newTypes[] = $innerType;
			}

			if (count($newTypes) > 0) {
				return new FoundTypeResult(TypeCombinator::union(...$newTypes), $directClassNames, [], null);
			}
		}

		$tip = null;
		if (strpos($type->describe(VerbosityLevel::typeOnly()), 'PhpParser\\Node\\Arg|PhpParser\\Node\\VariadicPlaceholder') !== false && !$unionTypeCriteriaCallback($type)) {
			$tip = 'Use <fg=cyan>->getArgs()</> instead of <fg=cyan>->args</>.';
		}

		return new FoundTypeResult($type, $directClassNames, [], $tip);
	}

}
