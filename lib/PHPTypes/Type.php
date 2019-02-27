<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Types, a Type Resolver implementation for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPTypes;

class Type
{
    const TYPE_UNKNOWN = -1;

    const TYPE_NULL = 1;

    const TYPE_BOOLEAN = 2;

    const TYPE_LONG = 3;

    const TYPE_DOUBLE = 4;

    const TYPE_STRING = 5;

    const TYPE_OBJECT = 6;

    const TYPE_ARRAY = 7;

    const TYPE_CALLABLE = 8;

    const TYPE_UNION = 10;

    const TYPE_INTERSECTION = 11;

    /**
     * @var int
     */
    public $type = 0;

    /**
     * @var Type[]
     */
    public $subTypes = [];

    /**
     * @var string
     */
    public $userType = '';

    /**
     * @var int[]
     */
    protected static $hasSubtypes = [
        self::TYPE_ARRAY => self::TYPE_ARRAY,
        self::TYPE_UNION => self::TYPE_UNION,
        self::TYPE_INTERSECTION => self::TYPE_INTERSECTION,
    ];

    /**
     * @var Type[]
     */
    private static $typeCache = [];

    /**
     * @param int     $type
     * @param Type[]  $subTypes
     * @param ?string $userType
     */
    public function __construct($type, array $subTypes = [], $userType = null)
    {
        $this->type = $type;
        if ($type === self::TYPE_OBJECT) {
            $this->userType = (string) $userType;
        } elseif (isset(self::$hasSubtypes[$type])) {
            $this->subTypes = $subTypes;
            foreach ($subTypes as $sub) {
                if (! $sub instanceof self) {
                    throw new \RuntimeException('Sub types must implement Type');
                }
            }
        }
    }

    public function __toString(): string
    {
        static $ctr = 0;
        ++$ctr;
        if ($this->type === self::TYPE_UNKNOWN) {
            --$ctr;

            return 'unknown';
        }
        $primitives = self::getPrimitives();
        if (isset($primitives[$this->type])) {
            --$ctr;
            if ($this->type === self::TYPE_OBJECT && $this->userType) {
                return $this->userType;
            }
            if ($this->type === self::TYPE_ARRAY && $this->subTypes) {
                return $this->subTypes[0].'[]';
            }

            return $primitives[$this->type];
        }
        $value = '';
        if ($this->type === self::TYPE_UNION) {
            $value = implode('|', $this->subTypes);
        } elseif ($this->type === self::TYPE_INTERSECTION) {
            $value = implode('&', $this->subTypes);
        } else {
            throw new \RuntimeException("Assertion failure: unknown type {$this->type}");
        }
        --$ctr;
        if ($ctr > 0) {
            return '('.$value.')';
        }

        return $value;
    }

    public static function unknown(): self
    {
        return self::makeCachedType(self::TYPE_UNKNOWN);
    }

    public static function int(): self
    {
        return self::makeCachedType(self::TYPE_LONG);
    }

    public static function float(): self
    {
        return self::makeCachedType(self::TYPE_DOUBLE);
    }

    public static function string(): self
    {
        return self::makeCachedType(self::TYPE_STRING);
    }

    public static function bool(): self
    {
        return self::makeCachedType(self::TYPE_BOOLEAN);
    }

    public static function null(): self
    {
        return self::makeCachedType(self::TYPE_NULL);
    }

    public static function object(): self
    {
        return self::makeCachedType(self::TYPE_OBJECT);
    }

    public static function numeric(): self
    {
        if (! isset(self::$typeCache['numeric'])) {
            self::$typeCache['numeric'] = new self(self::TYPE_UNION, [self::int(), self::float()]);
        }

        return self::$typeCache['numeric'];
    }

    public static function mixed(): self
    {
        if (! isset(self::$typeCache['mixed'])) {
            $subs = [];
            foreach (self::getPrimitives() as $key => $name) {
                $subs[] = self::makeCachedType($key);
            }
            self::$typeCache['mixed'] = new self(self::TYPE_UNION, $subs);
        }

        return self::$typeCache['mixed'];
    }

    /**
     * Get the primitives
     *
     * @return string[]
     */
    public static function getPrimitives(): array
    {
        return [
            self::TYPE_NULL => 'null',
            self::TYPE_BOOLEAN => 'bool',
            self::TYPE_LONG => 'int',
            self::TYPE_DOUBLE => 'float',
            self::TYPE_STRING => 'string',
            self::TYPE_OBJECT => 'object',
            self::TYPE_ARRAY => 'array',
            self::TYPE_CALLABLE => 'callable',
        ];
    }

    public function toString(): string
    {
        return $this->__toString();
    }

    public function hasSubtypes()
    {
        return in_array($this->type, self::$hasSubtypes, true);
    }

    public function allowsNull()
    {
        if ($this->type === self::TYPE_NULL) {
            return true;
        }
        if ($this->type === self::TYPE_UNION) {
            foreach ($this->subTypes as $subType) {
                if ($subType->allowsNull()) {
                    return true;
                }
            }
        }
        if ($this->type === self::TYPE_INTERSECTION) {
            foreach ($this->subTypes as $subType) {
                if (! $subType->allowsNull()) {
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * @param string $kind
     * @param string $comment
     * @param string $name    The name of the parameter
     *
     * @return Type The type
     */
    public static function extractTypeFromComment($kind, $comment, $name = ''): self
    {
        $match = [];
        if (null === $comment) {
            return self::mixed();
        }
        switch ($kind) {
            case 'var':
                if (preg_match('(@var\s+(\S+))', $comment, $match)) {
                    return self::fromDecl($match[1]);
                }

                break;
            case 'return':
                if (preg_match('(@return\s+(\S+))', $comment, $match)) {
                    return self::fromDecl($match[1]);
                }

                break;
            case 'param':
                if (preg_match("(@param\\s+(\\S+)\\s+\\\${$name})i", $comment, $match)) {
                    return self::fromDecl($match[1]);
                }

                break;
        }

        return self::mixed();
    }

    public function simplify(): self
    {
        if ($this->type !== self::TYPE_UNION && $this->type !== self::TYPE_INTERSECTION) {
            return $this;
        }
        $new = [];
        foreach ($this->subTypes as $subType) {
            $subType = $subType->simplify();
            if ($this->type === $subType->type) {
                $new = array_merge($new, $subType->subTypes);
            } else {
                $new[] = $subType->simplify();
            }
        }
        // TODO: compute redundant unions
        return new self($this->type, $new);
    }

    /**
     * @param string $decl
     *
     * @return Type The type
     */
    public static function fromDecl($decl): self
    {
        if ($decl instanceof self) {
            return $decl;
        }
        if (! is_string($decl)) {
            throw new \LogicException('Should never happen');
        }
        if (empty($decl)) {
            throw new \RuntimeException('Empty declaration found');
        }
        if ($decl[0] === '\\') {
            $decl = substr($decl, 1);
        } elseif ($decl[0] === '?') {
            $decl = substr($decl, 1);
            $type = self::fromDecl($decl);

            return (new self(self::TYPE_UNION, [
                $type,
                new self(self::TYPE_NULL),
            ]))->simplify();
        }
        switch (strtolower($decl)) {
            case 'boolean':
            case 'bool':
            case 'false':
            case 'true':
                return new self(self::TYPE_BOOLEAN);
            case 'integer':
            case 'int':
                return new self(self::TYPE_LONG);
            case 'double':
            case 'real':
            case 'float':
                return new self(self::TYPE_DOUBLE);
            case 'string':
                return new self(self::TYPE_STRING);
            case 'array':
                return new self(self::TYPE_ARRAY);
            case 'callable':
                return new self(self::TYPE_CALLABLE);
            case 'null':
            case 'void':
                return new self(self::TYPE_NULL);
            case 'numeric':
                return self::fromDecl('int|float');
        }
        // TODO: parse | and & and ()
        if (strpos($decl, '|') !== false || strpos($decl, '&') !== false || strpos($decl, '(') !== false) {
            return self::parseCompexDecl($decl)->simplify();
        }
        if (substr($decl, -2) === '[]') {
            $type = self::fromDecl(substr($decl, 0, -2));

            return new self(self::TYPE_ARRAY, [$type]);
        }
        $regex = '(^([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*\\\\)*[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$)';
        if (! preg_match($regex, $decl)) {
            throw new \RuntimeException("Unknown type declaration found: ${decl}");
        }

        return new self(self::TYPE_OBJECT, [], $decl);
    }

    /**
     * @return Type The type
     */
    public static function fromValue($value): self
    {
        if (is_int($value)) {
            return new self(self::TYPE_LONG);
        }
        if (is_bool($value)) {
            return new self(self::TYPE_BOOLEAN);
        }
        if (is_double($value)) {
            return new self(self::TYPE_DOUBLE);
        }
        if (is_string($value)) {
            return new self(self::TYPE_STRING);
        }

        throw new \RuntimeException('Unknown value type found: '.gettype($value));
    }

    /**
     * @return bool The status
     */
    public function equals(self $type): bool
    {
        if ($type === $this) {
            return true;
        }
        if ($type->type !== $this->type) {
            return false;
        }
        if ($type->type === self::TYPE_OBJECT) {
            return strtolower($type->userType) === strtolower($this->userType);
        }
        // TODO: handle sub types
        if (isset(self::$hasSubtypes[$this->type]) && isset(self::$hasSubtypes[$type->type])) {
            if (count($this->subTypes) !== count($type->subTypes)) {
                return false;
            }
            // compare
            $other = $type->subTypes;
            foreach ($this->subTypes as $st1) {
                foreach ($other as $key => $st2) {
                    if ($st1->equals($st2)) {
                        unset($other[$key]);

                        continue 2;
                    }
                    // We have a subtype that's not equal
                    return false;
                }
            }

            return empty($other);
        }

        return true;
    }

    /**
     * @return Type the removed type
     */
    public function removeType(self $type): self
    {
        if (! isset(self::$hasSubtypes[$this->type])) {
            if ($this->equals($type)) {
                // left with an unknown type
                return self::null();
            }

            return $this;
        }
        $new = [];
        foreach ($this->subTypes as $key => $st) {
            if (! $st->equals($type)) {
                $new[] = $st;
            }
        }
        if (empty($new)) {
            throw new \LogicException('Unknown type encountered');
        }
        if (count($new) === 1) {
            return $new[0];
        }

        return new self($this->type, $new);
    }

    /**
     * @param int $key
     */
    private static function makeCachedType($key): self
    {
        if (! isset(self::$typeCache[$key])) {
            self::$typeCache[$key] = new self($key);
        }

        return self::$typeCache[$key];
    }

    /**
     * @param string $decl
     */
    private static function parseCompexDecl($decl): self
    {
        $left = null;
        $right = null;
        $combinator = '';
        if (substr($decl, 0, 1) === '(') {
            $regex = '(^(\(((?>[^()]+)|(?1))*\)))';
            $match = [];
            if (preg_match($regex, $decl, $match)) {
                $sub = (string) $match[0];
                $left = self::fromDecl(substr($sub, 1, -1));
                if ($sub === $decl) {
                    return $left;
                }
                $decl = substr($decl, strlen($sub));
            } else {
                throw new \RuntimeException('Unmatched braces?');
            }
            if (! in_array(substr($decl, 0, 1), ['|', '&'], true)) {
                throw new \RuntimeException("Unknown position of combinator: ${decl}");
            }
            $right = self::fromDecl(substr($decl, 1));
            $combinator = substr($decl, 0, 1);
        } else {
            $orPos = strpos($decl, '|');
            $andPos = strpos($decl, '&');
            $pos = 0;
            if ($orPos === false && $andPos !== false) {
                $pos = $andPos;
            } elseif ($orPos !== false && $andPos === false) {
                $pos = $orPos;
            } elseif ($orPos !== false && $andPos !== false) {
                $pos = min($orPos, $andPos);
            } else {
                throw new \RuntimeException("No combinator found: ${decl}");
            }
            if ($pos === 0) {
                throw new \RuntimeException("Unknown position of combinator: ${decl}");
            }
            $left = self::fromDecl(substr($decl, 0, $pos));
            $right = self::fromDecl(substr($decl, $pos + 1));
            $combinator = substr($decl, $pos, 1);
        }
        if ($combinator === '|') {
            return new self(self::TYPE_UNION, [$left, $right]);
        }
        if ($combinator === '&') {
            return new self(self::TYPE_INTERSECTION, [$left, $right]);
        }

        throw new \RuntimeException("Unknown combinator ${combinator}");
    }
}
