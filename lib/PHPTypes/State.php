<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Types, a Type Resolver implementation for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPTypes;

use PHPCfg\Block;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPCfg\Traverser;
use PHPCfg\Visitor;
use SplObjectStorage;

class State
{
    public $script;

    public $blocks = [];

    /**
     * @var Op\Stmt\Class[][]
     */
    public $classMap = [];

    /**
     * @var SplObjectStorage
     */
    public $variables;

    /**
     * @var Op\Terminal\Const_[]
     */
    public $constants;

    /**
     * @var Op\Stmt\Trait_[]
     */
    public $traits;

    /**
     * @var Op\Stmt\Class_[]
     */
    public $classes;

    /**
     * @var Op\Stmt\Interface_[]
     */
    public $interfaces;

    /**
     * @var Op\Stmt\Method[]
     */
    public $methods;

    /**
     * @var Op\Stmt\Function_[]
     */
    public $functions;

    /**
     * @var Op\Stmt\Function_[][]
     */
    public $functionLookup;

    public $internalTypeInfo;

    public $resolver;

    public $callFinder;

    public $classResolves = [];

    public $classResolvedBy = [];

    public $methodCalls = [];

    public $newCalls = [];

    public function __construct(Script $script)
    {
        $this->script = $script;
        foreach ($this->script->functions as $func) {
            if (null !== $func->cfg) {
                $this->blocks[] = $func->cfg;
            }
        }
        if (null !== $this->script->main->cfg) {
            $this->blocks[] = $this->script->main->cfg;
        }
        $this->resolver = new TypeResolver($this);
        $this->internalTypeInfo = new InternalArgInfo();
        $this->load();
    }

    protected function findTypedBlock($type, Block $block, $result = [])
    {
        $toProcess = new SplObjectStorage();
        $processed = new SplObjectStorage();
        $toProcess->attach($block);
        while (count($toProcess) > 0) {
            foreach ($toProcess as $block) {
                $toProcess->detach($block);
                $processed->attach($block);
                foreach ($block->children as $op) {
                    if ($op->getType() === $type) {
                        $result[] = $op;
                    }
                    foreach ($op->getSubBlocks() as $name) {
                        $sub = $op->{$name};
                        if (null === $sub) {
                            continue;
                        }
                        if (! is_array($sub)) {
                            $sub = [$sub];
                        }
                        foreach ($sub as $subb) {
                            if (! $processed->contains($subb)) {
                                $toProcess->attach($subb);
                            }
                        }
                    }
                }
            }
        }

        return $result;
    }

    private function load()
    {
        $traverser = new Traverser();
        $declarations = new Visitor\DeclarationFinder();
        $calls = new Visitor\CallFinder();
        $variables = new Visitor\VariableFinder();
        $traverser->addVisitor($declarations);
        $traverser->addVisitor($calls);
        $traverser->addVisitor($variables);

        $this->script = $traverser->traverse($this->script);

        $this->variables = $variables->getVariables();
        $this->constants = $declarations->getConstants();
        $this->traits = $declarations->getTraits();
        $this->classes = $declarations->getClasses();
        $this->interfaces = $declarations->getInterfaces();
        $this->methods = $declarations->getMethods();
        $this->functions = $declarations->getFunctions();
        $this->functionLookup = $this->buildFunctionLookup($declarations->getFunctions());
        $this->callFinder = $calls;
        $this->methodCalls = $this->findMethodCalls();
        $this->newCalls = $this->findNewCalls();
        $this->computeTypeMatrix();
    }

    private function buildFunctionLookup(array $functions)
    {
        $lookup = [];
        foreach ($functions as $function) {
            assert($function->name instanceof Operand\Literal);
            $name = strtolower($function->name->value);
            if (! isset($lookup[$name])) {
                $lookup[$name] = [];
            }
            $lookup[$name][] = $function;
        }

        return $lookup;
    }

    private function computeTypeMatrix()
    {
        // TODO: This is dirty, and needs cleaning
        // A extends B
        $map = []; // a => [a, b], b => [b]
        $interfaceMap = [];
        $classMap = [];
        $toProcess = [];
        foreach ($this->interfaces as $interface) {
            $name = strtolower($interface->name->value);
            $map[$name] = [$name => $interface];
            $interfaceMap[$name] = [];
            if ($interface->extends) {
                foreach ($interface->extends as $extends) {
                    $sub = strtolower($extends->value);
                    $interfaceMap[$name][] = $sub;
                    $map[$sub][$name] = $interface;
                }
            }
        }
        foreach ($this->classes as $class) {
            $name = strtolower($class->name->value);
            $map[$name] = [$name => $class];
            $classMap[$name] = [$name];
            foreach ($class->implements as $interface) {
                $iname = strtolower($interface->value);
                $classMap[$name][] = $iname;
                $map[$iname][$name] = $class;
                if (isset($interfaceMap[$iname])) {
                    foreach ($interfaceMap[$iname] as $sub) {
                        $classMap[$name][] = $sub;
                        $map[$sub][$name] = $class;
                    }
                }
            }
            if ($class->extends) {
                $toProcess[] = [$name, strtolower($class->extends->value), $class];
            }
        }
        foreach ($toProcess as $ext) {
            $name = $ext[0];
            $extends = $ext[1];
            $class = $ext[2];
            if (isset($classMap[$extends])) {
                foreach ($classMap[$extends] as $mapped) {
                    $map[$mapped][$name] = $class;
                }
            } else {
                echo "Could not find parent ${extends}\n";
            }
        }
        $this->classResolves = $map;
        $this->classResolvedBy = [];
        foreach ($map as $child => $parent) {
            foreach ($parent as $name => $_) {
                if (! isset($this->classResolvedBy[$name])) {
                    $this->classResolvedBy[$name] = [];
                }
                //allows iterating and looking udm_cat_path(agent, category)
                $this->classResolvedBy[$name][$child] = $child;
            }
        }
    }

    private function findNewCalls()
    {
        $newCalls = [];
        foreach ($this->blocks as $block) {
            $newCalls = $this->findTypedBlock('Expr_New', $block, $newCalls);
        }

        return $newCalls;
    }

    private function findMethodCalls()
    {
        $methodCalls = [];
        foreach ($this->blocks as $block) {
            $methodCalls = $this->findTypedBlock('Expr_MethodCall', $block, $methodCalls);
        }

        return $methodCalls;
    }
}
