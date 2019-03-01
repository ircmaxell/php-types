<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Types, a Type Resolver implementation for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPTypes;

use PHPCfg\Assertion;
use PHPCfg\Op;
use PHPCfg\Operand;
use SplObjectStorage;

class TypeReconstructor
{
    protected $state;

    public function resolve(State $state)
    {
        $this->state = $state;
        // First resolve properties
        $this->resolveAllProperties();
        $resolved = new SplObjectStorage();
        $unresolved = new SplObjectStorage();
        foreach ($state->variables as $op) {
            if (! empty($op->type) && $op->type->type !== Type::TYPE_UNKNOWN) {
                $resolved[$op] = $op->type;
            } elseif ($op instanceof Operand\BoundVariable && $op->scope === Operand\BoundVariable::SCOPE_OBJECT) {
                $resolved[$op] = $op->type = Type::fromDecl($op->extra->value);
            } elseif ($op instanceof Operand\Literal) {
                $resolved[$op] = $op->type = Type::fromValue($op->value);
            } else {
                $unresolved[$op] = Type::unknown();
            }
        }

        if (count($unresolved) === 0) {
            // short-circuit
            return;
        }

        $round = 1;
        do {
            $start = count($resolved);
            $toRemove = [];
            foreach ($unresolved as $k => $var) {
                $type = $this->resolveVar($var, $resolved);
                if ($type) {
                    $toRemove[] = $var;
                    $resolved[$var] = $type;
                }
            }
            foreach ($toRemove as $remove) {
                $unresolved->detach($remove);
            }
        } while (count($unresolved) > 0 && $start < count($resolved));
        foreach ($resolved as $var) {
            $var->type = $resolved[$var];
        }
        foreach ($unresolved as $var) {
            $var->type = $unresolved[$var];
        }
    }

    /**
     * @param Type[] $types
     */
    protected function computeMergedType(array $types): Type
    {
        if (count($types) === 1) {
            return $types[0];
        }
        $same = null;
        foreach ($types as $key => $type) {
            if (! $type instanceof Type) {
                var_dump($types);

                throw new \RuntimeException('Invalid type found');
            }
            if (null === $same) {
                $same = $type;
            } elseif ($same && ! $same->equals($type)) {
                $same = false;
            }
            if ($type->type === Type::TYPE_UNKNOWN) {
                return false;
            }
        }
        if ($same) {
            return $same;
        }

        return (new Type(Type::TYPE_UNION, $types))->simplify();
    }

    protected function resolveVar(Operand $var, SplObjectStorage $resolved)
    {
        $types = [];
        foreach ($var->ops as $prev) {
            $type = $this->resolveVarOp($var, $prev, $resolved);
            if ($type) {
                if (! is_array($type)) {
                    throw new \LogicException('Handler for '.get_class($prev).' returned a non-array');
                }
                foreach ($type as $t) {
                    assert($t instanceof Type);
                    $types[] = $t;
                }
            } else {
                return false;
            }
        }
        if (empty($types)) {
            return false;
        }

        return $this->computeMergedType($types);
    }

    protected function resolveVarOp(Operand $var, Op $op, SplObjectStorage $resolved)
    {
        $method = 'resolveOp_'.$op->getType();
        if (method_exists($this, $method)) {
            return call_user_func([$this, $method], $var, $op, $resolved);
        }
        switch ($op->getType()) {
            case 'Expr_InstanceOf':
            case 'Expr_BinaryOp_Equal':
            case 'Expr_BinaryOp_NotEqual':
            case 'Expr_BinaryOp_Greater':
            case 'Expr_BinaryOp_GreaterOrEqual':
            case 'Expr_BinaryOp_Identical':
            case 'Expr_BinaryOp_NotIdentical':
            case 'Expr_BinaryOp_Smaller':
            case 'Expr_BinaryOp_SmallerOrEqual':
            case 'Expr_BinaryOp_LogicalAnd':
            case 'Expr_BinaryOp_LogicalOr':
            case 'Expr_BinaryOp_LogicalXor':
            case 'Expr_BooleanNot':
            case 'Expr_Cast_Bool':
            case 'Expr_Empty':
            case 'Expr_Isset':
                return [Type::bool()];
            case 'Expr_BinaryOp_BitwiseAnd':
            case 'Expr_BinaryOp_BitwiseOr':
            case 'Expr_BinaryOp_BitwiseXor':
                if ($resolved->contains($op->left) && $resolved->contains($op->right)) {
                    switch ([$resolved[$op->left]->type, $resolved[$op->right]->type]) {
                        case [Type::TYPE_STRING, Type::TYPE_STRING]:
                            return [Type::string()];
                        default:
                            return [Type::int()];
                    }
                }

                return false;
            case 'Expr_BitwiseNot':
                if ($resolved->contains($op->expr)) {
                    switch ($resolved[$op->expr]->type) {
                        case Type::TYPE_STRING:
                            return [Type::string()];
                        default:
                            return [Type::int()];
                    }
                }

                return false;
            case 'Expr_BinaryOp_Div':
            case 'Expr_BinaryOp_Plus':
            case 'Expr_BinaryOp_Minus':
            case 'Expr_BinaryOp_Mul':
                if ($resolved->contains($op->left) && $resolved->contains($op->right)) {
                    switch ([$resolved[$op->left]->type, $resolved[$op->right]->type]) {
                        case [Type::TYPE_LONG, Type::TYPE_LONG]:
                            return [Type::int()];
                        case [Type::TYPE_DOUBLE, TYPE::TYPE_LONG]:
                        case [Type::TYPE_LONG, TYPE::TYPE_DOUBLE]:
                        case [Type::TYPE_DOUBLE, TYPE::TYPE_DOUBLE]:
                            return [Type::float()];
                        case [Type::TYPE_ARRAY, Type::TYPE_ARRAY]:
                            $sub = $this->computeMergedType(array_merge($resolved[$op->left]->subTypes, $resolved[$op->right]->subTypes));
                            if ($sub) {
                                return [new Type(Type::TYPE_ARRAY, [$sub])];
                            }

                            return [new Type(Type::TYPE_ARRAY)];
                        default:
                            return [Type::mixed()];

                            throw new \RuntimeException("Math op on unknown types {$resolved[$op->left]} + {$resolved[$op->right]}");
                    }
                }

                return false;
            case 'Expr_BinaryOp_Concat':
            case 'Expr_Cast_String':
            case 'Expr_ConcatList':
                return [Type::string()];
            case 'Expr_BinaryOp_Mod':
            case 'Expr_BinaryOp_ShiftLeft':
            case 'Expr_BinaryOp_ShiftRight':
            case 'Expr_Cast_Int':
            case 'Expr_Print':
                return [Type::int()];
            case 'Expr_Cast_Double':
                return [Type::float()];
            case 'Expr_UnaryMinus':
            case 'Expr_UnaryPlus':
                if ($resolved->contains($op->expr)) {
                    switch ($resolved[$op->expr]->type) {
                        case Type::TYPE_LONG:
                        case Type::TYPE_DOUBLE:
                            return [$resolved[$op->expr]];
                    }

                    return [Type::numeric()];
                }

                return false;
            case 'Expr_Eval':
                return false;
            case 'Iterator_Key':
                if ($resolved->contains($op->var)) {
                    // TODO: implement this as well
                    return false;
                }

                return false;
            case 'Expr_Exit':
            case 'Iterator_Reset':
                return [Type::null()];
            case 'Iterator_Valid':
                return [Type::bool()];
            case 'Iterator_Value':
                if ($resolved->contains($op->var)) {
                    if ($resolved[$op->var]->subTypes) {
                        return $resolved[$op->var]->subTypes;
                    }

                    return false;
                }

                return false;
            case 'Expr_StaticCall':
                return $this->resolveMethodCall($op->class, $op->name, $op, $resolved);
            case 'Expr_MethodCall':
                return $this->resolveMethodCall($op->var, $op->name, $op, $resolved);
            case 'Expr_Yield':
            case 'Expr_Include':
                // TODO: we may be able to determine these...
                return false;
        }

        throw new \LogicException('Unknown variable op found: '.$op->getType());
    }

    protected function resolveOp_Expr_Array(Operand $var, Op\Expr\Array_ $op, SplObjectStorage $resolved)
    {
        $types = [];
        foreach ($op->values as $value) {
            if (! isset($resolved[$value])) {
                return false;
            }
            $types[] = $resolved[$value];
        }
        if (empty($types)) {
            return [new Type(Type::TYPE_ARRAY)];
        }
        $r = $this->computeMergedType($types);
        if ($r) {
            return [new Type(Type::TYPE_ARRAY, [$r])];
        }

        return [new Type(Type::TYPE_ARRAY)];
    }

    protected function resolveOp_Expr_Cast_Array(Operand $var, Op\Expr\Cast\Array_ $op, SplObjectStorage $resolved)
    {
        // Todo: determine subtypes better
        return [new Type(Type::TYPE_ARRAY)];
    }

    protected function resolveOp_Expr_ArrayDimFetch(Operand $var, Op\Expr\ArrayDimFetch $op, SplObjectStorage $resolved)
    {
        if ($resolved->contains($op->var)) {
            // Todo: determine subtypes better
            $type = $resolved[$op->var];
            if ($type->subTypes) {
                return $type->subTypes;
            }
            if ($type->type === Type::TYPE_STRING) {
                return [$type];
            }

            return [Type::mixed()];
        }

        return false;
    }

    protected function resolveOp_Expr_Assign(Operand $var, Op\Expr\Assign $op, SplObjectStorage $resolved)
    {
        if ($resolved->contains($op->expr)) {
            return [$resolved[$op->expr]];
        }

        return false;
    }

    protected function resolveOp_Expr_AssignRef(Operand $var, Op\Expr\AssignRef $op, SplObjectStorage $resolved)
    {
        if ($resolved->contains($op->expr)) {
            return [$resolved[$op->expr]];
        }

        return false;
    }

    protected function resolveOp_Expr_Cast_Object(Operand $var, Op\Expr\Cast\Object_ $op, SplObjectStorage $resolved)
    {
        if ($resolved->contains($op->expr)) {
            if ($resolved[$op->expr]->type->resolves(Type::object())) {
                return [$resolved[$op->expr]];
            }

            return [new Type(Type::TYPE_OBJECT, [], 'stdClass')];
        }

        return false;
    }

    protected function resolveOp_Expr_Clone(Operand $var, Op\Expr\Clone_ $op, SplObjectStorage $resolved)
    {
        if ($resolved->contains($op->expr)) {
            return [$resolved[$op->expr]];
        }

        return false;
    }

    protected function resolveOp_Expr_Closure(Operand $var, Op\Expr\Closure $op, SplObjectStorage $resolved)
    {
        return [new Type(Type::TYPE_OBJECT, [], 'Closure')];
    }

    protected function resolveOp_Expr_FuncCall(Operand $var, Op\Expr\FuncCall $op, SplObjectStorage $resolved)
    {
        if ($op->name instanceof Operand\Literal) {
            $name = strtolower($op->name->value);
            if (isset($this->state->functionLookup[$name])) {
                $result = [];
                foreach ($this->state->functionLookup[$name] as $func) {
                    if ($func->returnType) {
                        $result[] = Type::fromTypeDecl($func->returnType);
                    } else {
                        // Check doc comment
                        $result[] = Type::extractTypeFromComment('return', $func->getAttribute('doccomment'));
                    }
                }

                return $result;
            }
            if (isset($this->state->internalTypeInfo->functions[$name])) {
                $type = $this->state->internalTypeInfo->functions[$name];
                if (empty($type['return'])) {
                    return false;
                }

                return [Type::fromDecl($type['return'])];
            }
        }
        // we can't resolve the function
        return false;
    }

    protected function resolveOp_Expr_New(Operand $var, Op\Expr\New_ $op, SplObjectStorage $resolved)
    {
        $type = $this->getClassType($op->class, $resolved);
        if ($type) {
            return [$type];
        }

        return [Type::object()];
    }

    protected function resolveDeclaredType(Op\Type $type): Type
    {
        if ($type instanceof Op\Type\Literal) {
            return Type::fromDecl($type->name);
        }
    }

    protected function resolveOp_Expr_Param(Operand $var, Op\Expr\Param $op, SplObjectStorage $resolved)
    {
        $type = $this->resolveDeclaredType($op->declaredType);
        if ($op->defaultVar) {
            if ($op->defaultBlock->children[0]->getType() === 'Expr_ConstFetch' && strtolower($op->defaultBlock->children[0]->name->value) === 'null') {
                $type = (new Type(Type::TYPE_UNION, [$type, Type::null()]))->simplify();
            }
        }

        return [$type];
    }

    protected function resolveOp_Expr_StaticPropertyFetch(Operand $var, Op $op, SplObjectStorage $resolved)
    {
        return $this->resolveOp_Expr_PropertyFetch($var, $op, $resolved);
    }

    protected function resolveOp_Expr_PropertyFetch(Operand $var, Op $op, SplObjectStorage $resolved)
    {
        if (! $op->name instanceof Operand\Literal) {
            // variable property fetch
            return [Type::mixed()];
        }
        $propName = $op->name->value;
        if ($op instanceof Op\Expr\StaticPropertyFetch) {
            $objType = $this->getClassType($op->class, $resolved);
        } else {
            $objType = $this->getClassType($op->var, $resolved);
        }
        if ($objType) {
            return $this->resolveProperty($objType, $propName);
        }

        return false;
    }

    protected function resolveOp_Expr_Assertion(Operand $var, Op $op, SplObjectStorage $resolved)
    {
        $tmp = $this->processAssertion($op->assertion, $op->expr, $resolved);
        if ($tmp) {
            return [$tmp];
        }

        return false;
    }

    protected function resolveOp_Expr_ConstFetch(Operand $var, Op\Expr\ConstFetch $op, SplObjectStorage $resolved)
    {
        if ($op->name instanceof Operand\Literal) {
            $constant = strtolower($op->name->value);
            switch ($constant) {
                case 'true':
                case 'false':
                    return [Type::bool()];
                case 'null':
                    return [Type::null()];
                default:
                    if (isset($this->state->constants[$op->name->value])) {
                        $return = [];
                        foreach ($this->state->constants[$op->name->value] as $value) {
                            if (! $resolved->contains($value->value)) {
                                return false;
                            }
                            $return[] = $resolved[$value->value];
                        }

                        return $return;
                    }
            }
        }

        return false;
    }

    protected function resolveOp_Expr_ClassConstFetch(Operand $var, Op\Expr\ClassConstFetch $op, SplObjectStorage $resolved)
    {
        $classes = [];
        if ($op->class instanceof Operand\Literal) {
            $class = strtolower($op->class->value);

            return $this->resolveClassConstant($class, $op, $resolved);
        }
        if ($resolved->contains($op->class)) {
            $type = $resolved[$op->class];
            if ($type->type !== Type::TYPE_OBJECT || empty($type->userType)) {
                // give up
                return false;
            }

            return $this->resolveClassConstant(strtolower($type->userType), $op, $resolved);
        }

        return false;
    }

    protected function resolveOp_Phi(Operand $var, Op\Phi $op, SplObjectStorage $resolved)
    {
        $types = [];
        $resolveFully = true;
        foreach ($op->vars as $v) {
            if ($resolved->contains($v)) {
                $types[] = $resolved[$v];
            } else {
                $resolveFully = false;
            }
        }
        if (empty($types)) {
            return false;
        }
        $type = $this->computeMergedType($types);
        if ($type) {
            if ($resolveFully) {
                return [$type];
            }
            // leave on unresolved list to try again next round
            $resolved[$var] = $type;
        }

        return false;
    }

    protected function findMethod($class, $name)
    {
        foreach ($class->stmts->children as $stmt) {
            if ($stmt instanceof Op\Stmt\ClassMethod) {
                if (strtolower($stmt->func->name) === $name) {
                    return $stmt;
                }
            }
        }
        if ($name !== '__call') {
            return $this->findMethod($class, '__call');
        }
    }

    protected function findProperty($class, $name)
    {
        foreach ($class->stmts->children as $stmt) {
            if ($stmt instanceof Op\Stmt\Property) {
                if ($stmt->name->value === $name) {
                    return $stmt;
                }
            }
        }
    }

    protected function resolveAllProperties()
    {
        foreach ($this->state->classes as $class) {
            foreach ($class->stmts->children as $stmt) {
                if ($stmt instanceof Op\Stmt\Property) {
                    $stmt->type = Type::extractTypeFromComment('var', $stmt->getAttribute('doccomment'));
                }
            }
        }
    }

    protected function resolveClassConstant($class, $op, SplObjectStorage $resolved)
    {
        $try = $class.'::'.$op->name->value;
        if (isset($this->state->constants[$try])) {
            $types = [];
            foreach ($this->state->constants[$try] as $const) {
                if ($resolved->contains($const->value)) {
                    $types[] = $resolved[$const->value];
                } else {
                    // Not every
                    return false;
                }
            }

            return $types;
        }
        if (! isset($this->state->classResolvedBy[$class])) {
            // can't find classes
            return false;
        }
        $types = [];
        foreach ($this->state->classResolves[$class] as $name => $_) {
            $try = $name.'::'.$op->name->value;
            if (isset($this->state->constants[$try])) {
                foreach ($this->state->constants[$try] as $const) {
                    if ($resolved->contains($const->value)) {
                        $types[] = $resolved[$const->value];
                    } else {
                        // Not every is resolved yet
                        return false;
                    }
                }
            }
        }
        if (empty($types)) {
            return false;
        }

        return $types;
    }

    protected function getClassType(Operand $var, SplObjectStorage $resolved)
    {
        if ($var instanceof Operand\Literal) {
            return new Type(Type::TYPE_OBJECT, [], $var->value);
        }
        if ($var instanceof Operand\BoundVariable && $var->scope === Operand\BoundVariable::SCOPE_OBJECT) {
            assert($var->extra instanceof Operand\Literal);

            return Type::fromDecl($var->extra->value);
        }
        if ($resolved->contains($var)) {
            $type = $resolved[$var];
            if ($type->type === Type::TYPE_OBJECT) {
                return $type;
            }
        }
        // We don't know the type
        return false;
    }

    protected function processAssertion(Assertion $assertion, Operand $source, SplObjectStorage $resolved)
    {
        if ($assertion instanceof Assertion\TypeAssertion) {
            $tmp = $this->processTypeAssertion($assertion, $source, $resolved);
            if ($tmp) {
                return $tmp;
            }
        } elseif ($assertion instanceof Assertion\NegatedAssertion) {
            $op = $this->processAssertion($assertion->value[0], $source, $resolved);
            if ($op instanceof Type) {
                // negated type assertion
                if (isset($resolved[$source])) {
                    return $resolved[$source]->removeType($op);
                }
                // Todo, figure out how to wait for resolving
                return Type::mixed()->removeType($op);
            }
        }

        return false;
    }

    protected function processTypeAssertion(Assertion\TypeAssertion $assertion, Operand $source, SplObjectStorage $resolved)
    {
        if ($assertion->value instanceof Operand) {
            if ($assertion->value instanceof Operand\Literal) {
                return Type::fromDecl($assertion->value->value);
            }
            if (isset($resolved[$assertion->value])) {
                return $resolved[$assertion->value];
            }

            return false;
        }
        $subTypes = [];
        foreach ($assertion->value as $sub) {
            $subTypes[] = $subType = $this->processTypeAssertion($sub, $source, $resolved);
            if (! $subType) {
                // Not fully resolved yet
                return false;
            }
        }
        $type = $assertion->mode === Assertion::MODE_UNION ? Type::TYPE_UNION : Type::TYPE_INTERSECTION;

        return new Type($type, $subTypes);
    }

    /**
     * @param string $propName
     *
     * @return Type[]|false
     */
    private function resolveProperty(Type $objType, $propName)
    {
        if ($objType->type === Type::TYPE_OBJECT) {
            $types = [];
            $ut = strtolower($objType->userType);
            if (! isset($this->state->classResolves[$ut])) {
                // unknown type
                return false;
            }
            foreach ($this->state->classResolves[$ut] as $class) {
                // Lookup property on class
                $property = $this->findProperty($class, $propName);
                if ($property) {
                    if ($property->type) {
                        $types[] = $property->type;
                    } else {
                        echo "Property found to be untyped: ${propName}\n";
                        // untyped property
                        return false;
                    }
                }
            }
            if ($types) {
                return $types;
            }
        }

        return false;
    }

    private function resolveMethodCall($class, $name, Op $op, SplObjectStorage $resolved)
    {
        if (! $name instanceof Operand\Literal) {
            // Variable Method Call
            return false;
        }
        $name = strtolower($name->value);
        if ($resolved->contains($class)) {
            $userType = '';
            if ($resolved[$class]->type === Type::TYPE_STRING) {
                if (! $class instanceof Operand\Literal) {
                    // variable class name, for now just return object
                    return [Type::mixed()];
                }
                $userType = $class->value;
            } elseif ($resolved[$class]->type !== Type::TYPE_OBJECT) {
                return false;
            } else {
                $userType = $resolved[$class]->userType;
            }
            $types = [];
            $className = strtolower($userType);
            if (! isset($this->state->classResolves[$className])) {
                if (isset($this->state->internalTypeInfo->methods[$className])) {
                    $types = [];
                    foreach ($this->state->internalTypeInfo->methods[$className]['extends'] as $child) {
                        if (isset($this->state->internalTypeInfo->methods[$child]['methods'][$name])) {
                            $method = $this->state->internalTypeInfo->methods[$child]['methods'][$name];
                            if ($method['return']) {
                                $types[] = Type::fromDecl($method['return']);
                            }
                        }
                    }
                    if (! empty($types)) {
                        return $types;
                    }
                }

                return false;
            }
            foreach ($this->state->classResolves[$className] as $class) {
                $method = $this->findMethod($class, $name);
                if (! $method) {
                    continue;
                }
                $doc = Type::extractTypeFromComment('return', $method->getAttribute('doccomment'));

                $decl = $this->resolveDeclaredType($method->func->returnType);
                if ($this->state->resolver->resolves($doc, $decl)) {
                    // doc is a subset
                    $types[] = $doc;
                } else {
                    $types[] = $decl;
                }
            }
            if (! empty($types)) {
                return $types;
            }

            return false;
        }

        return false;
    }
}
