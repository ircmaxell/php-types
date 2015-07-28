<?php

namespace PHPTypes;

use PHPCfg\Block;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Traverser;
use PHPCfg\Visitor;
use SplObjectStorage;

class State {
    
    /**
     * @var Block[]
     */
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

    public $classResolves = [];

    public $classResolvedBy = [];

    public function __construct(array $blocks) {
        $this->blocks = $blocks;
        $this->resolver = new TypeResolver($this);
        $this->internalTypeInfo = new InternalArgInfo;
        $this->load();
    }


    private function load() {
        $traverser = new Traverser;
        $declarations = new Visitor\DeclarationFinder;
        $calls = new Visitor\CallFinder;
        $variables = new Visitor\VariableFinder;
        $traverser->addVisitor($declarations);
        $traverser->addVisitor($calls);
        $traverser->addVisitor($variables);
        foreach ($this->blocks as $block) {
            $traverser->traverse($block);
        }
        $this->variables = $variables->getVariables();
        $this->constants = $declarations->getConstants();
        $this->traits = $declarations->getTraits();
        $this->classes = $declarations->getClasses();
        $this->interfaces = $declarations->getInterfaces();
        $this->methods = $declarations->getMethods();
        $this->functions = $declarations->getFunctions();
        $this->functionLookup = $this->buildFunctionLookup($declarations->getFunctions());
        $this->computeTypeMatrix();
    }

    private function buildFunctionLookup(array $functions) {
        $lookup = [];
        foreach ($functions as $function) {
            assert($function->name instanceof Operand\Literal);
            $name = strtolower($function->name->value);
            if (!isset($lookup[$name])) {
                $lookup[$name] = [];
            }
            $lookup[$name][] = $function;
        }
        return $lookup;
    }

    private function computeTypeMatrix() {
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
                echo "Could not find parent $extends\n";
            }
        }
        $this->classResolves = $map;
        $this->classResolvedBy = [];
        foreach ($map as $child => $parent) {
            foreach ($parent as $name => $_) {
                if (!isset($this->classResolvedBy[$name])) {
                    $this->classResolvedBy[$name] = [];
                }
                //allows iterating and looking udm_cat_path(agent, category)
                $this->classResolvedBy[$name][$child] = $child;
            }
        }
    }
}