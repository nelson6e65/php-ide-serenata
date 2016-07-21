<?php

namespace PhpIntegrator\Application\Command\DeduceTypes;

use PhpIntegrator\DocParser;
use PhpIntegrator\TypeAnalyzer;

use PhpIntegrator\Application\Command\DeduceTypes;
use PhpIntegrator\Application\Command\ResolveType;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor that queries the nodes for information about an invoked function or method.
 */
class QueryingVisitor extends NodeVisitorAbstract
{
    /**
     * @var int
     */
    const TYPE_CONDITIONALLY_GUARANTEED = 1;

    /**
     * @var int
     */
    const TYPE_CONDITIONALLY_POSSIBLE   = 2;

    /**
     * @var int
     */
    const TYPE_CONDITIONALLY_IMPOSSIBLE = 4;

    /**
     * @var int
     */
    protected $position;

    /**
     * @var int
     */
    protected $line;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $code;

    /**
     * @var string
     */
    protected $file;

    /**
     * @var ResolveType
     */
    protected $resolveTypeCommand;

    /**
     * @var DeduceTypes
     */
    protected $deduceTypesCommand;

    /**
     * @var TypeAnalyzer
     */
    protected $typeAnalyzer;

    /**
     * @var DocParser
     */
    protected $docParser;

    /**
     * @var Node\FunctionLike|null
     */
    protected $lastFunctionLikeNode;

    /**
     * @var string|null
     */
    protected $currentClassName;

    /**
     * @var Node|null
     */
    protected $bestMatch;

    /**
     * @var array
     */
    protected $conditionalTypes = [];

    /**
     * @var string|null
     */
    protected $bestTypeOverrideMatch;

    /**
     * @var int|null
     */
    protected $bestTypeOverrideMatchLine;

    /**
     * Constructor.
     *
     * @param string       $file
     * @param string       $code
     * @param int          $position
     * @param int          $line
     * @param string       $name
     * @param TypeAnalyzer $typeAnalyzer
     * @param ResolveType  $resolveTypeCommand
     * @param DeduceTypes   $deduceTypesCommand
     */
    public function __construct(
        $file,
        $code,
        $position,
        $line,
        $name,
        TypeAnalyzer $typeAnalyzer,
        ResolveType $resolveTypeCommand,
        DeduceTypes $deduceTypesCommand
    ) {
        $this->name = $name;
        $this->line = $line;
        $this->file = $file;
        $this->code = $code;
        $this->position = $position;
        $this->typeAnalyzer = $typeAnalyzer;
        $this->deduceTypesCommand = $deduceTypesCommand;
        $this->resolveTypeCommand = $resolveTypeCommand;
    }

    /**
     * @inheritDoc
     */
    public function enterNode(Node $node)
    {
        $startFilePos = $node->getAttribute('startFilePos');
        $endFilePos = $node->getAttribute('endFilePos');

        if ($startFilePos >= $this->position) {
            if ($startFilePos == $this->position) {
                // We won't analyze this node anymore (it falls outside the position and can cause infinite recursion
                // otherwise), but php-parser matches each docblock with the next node. That docblock might still
                // contain a type override annotation we need to parse.
                $this->parseNodeDocblock($node);
            }

            // We've gone beyond the requested position, there is nothing here that can still be relevant anymore.
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }

        $this->parseNodeDocblock($node);

        if ($node instanceof Node\Stmt\Catch_) {
            if ($node->var === $this->name) {
                $this->setBestMatch($node->type);
            }
        } elseif (
            $node instanceof Node\Stmt\If_ ||
            $node instanceof Node\Stmt\ElseIf_ ||
            $node instanceof Node\Expr\Ternary
        ) {
            // There can be conditional expressions inside the current scope (think variables assigned to a ternary
            // expression). In that case we don't want to actually look at the condition for type deduction unless
            // we're inside the scope of that conditional.
            if (
                $this->position >= $node->getAttribute('startFilePos') &&
                $this->position <= $node->getAttribute('endFilePos')
            ) {
                $typeData = $this->parseCondition($node->cond);

                $this->conditionalTypes = array_merge($this->conditionalTypes, $typeData);
            }
        } elseif ($node instanceof Node\Expr\Assign) {
            if ($node->var instanceof Node\Expr\Variable) {
                $variableName = null;

                if ($node->var->name instanceof Node\Name) {
                    $variableName = (string) $node->var->name;
                } elseif (is_string($node->var->name)) {
                    $variableName = $node->var->name;
                }

                if ($variableName && $variableName === $this->name) {
                    $this->setBestMatch($node);
                }
            }
        } elseif ($node instanceof Node\Stmt\Foreach_) {
            if (!$node->valueVar instanceof Node\Expr\List_ && $node->valueVar->name === $this->name) {
                $this->setBestMatch($node);
            }
        }

        if ($startFilePos <= $this->position && $endFilePos >= $this->position) {
            if ($node instanceof Node\Stmt\ClassLike) {
                $this->currentClassName = (string) $node->name;

                $this->resetStateForNewScope();
            } elseif ($node instanceof Node\FunctionLike) {
                $variableIsOutsideCurrentScope = false;

                // If the variable is in a use() statement of a closure, we can't reset the state as we still need to
                // examine the parent scope of the closure where the variable is defined.
                if ($node instanceof Node\Expr\Closure) {
                    foreach ($node->uses as $closureUse) {
                        if ($closureUse->var === $this->name) {
                            $variableIsOutsideCurrentScope = true;
                            break;
                        }
                    }
                }

                if (!$variableIsOutsideCurrentScope) {
                    $this->resetStateForNewScope();
                    $this->lastFunctionLikeNode = $node;
                }
            }
        }
    }

    /**
     * @param Node\Expr $node
     */
    protected function parseCondition(Node\Expr $node)
    {
        $types = [];

        if (
            $node instanceof Node\Expr\BinaryOp\BitwiseAnd ||
            $node instanceof Node\Expr\BinaryOp\BitwiseOr ||
            $node instanceof Node\Expr\BinaryOp\BitwiseXor ||
            $node instanceof Node\Expr\BinaryOp\BooleanAnd ||
            $node instanceof Node\Expr\BinaryOp\BooleanOr ||
            $node instanceof Node\Expr\BinaryOp\LogicalAnd ||
            $node instanceof Node\Expr\BinaryOp\LogicalOr ||
            $node instanceof Node\Expr\BinaryOp\LogicalXor
        ) {
            $leftTypes = $this->parseCondition($node->left);
            $rightTypes = $this->parseCondition($node->right);

            $types = array_merge($leftTypes, $rightTypes);
        } elseif (
            $node instanceof Node\Expr\BinaryOp\Equal ||
            $node instanceof Node\Expr\BinaryOp\Identical
        ) {
            if ($node->left instanceof Node\Expr\Variable && $node->left->name === $this->name) {
                if ($node->right instanceof Node\Expr\ConstFetch && $node->right->name->toString() === 'null') {
                    $types['null'] = self::TYPE_CONDITIONALLY_GUARANTEED;
                }
            } elseif ($node->right instanceof Node\Expr\Variable && $node->right->name === $this->name) {
                if ($node->left instanceof Node\Expr\ConstFetch && $node->left->name->toString() === 'null') {
                    $types['null'] = self::TYPE_CONDITIONALLY_GUARANTEED;
                }
            }
        } elseif (
            $node instanceof Node\Expr\BinaryOp\NotEqual ||
            $node instanceof Node\Expr\BinaryOp\NotIdentical
        ) {
            if ($node->left instanceof Node\Expr\Variable && $node->left->name === $this->name) {
                if ($node->right instanceof Node\Expr\ConstFetch && $node->right->name->toString() === 'null') {
                    $types['null'] = self::TYPE_CONDITIONALLY_IMPOSSIBLE;
                }
            } elseif ($node->right instanceof Node\Expr\Variable && $node->right->name === $this->name) {
                if ($node->left instanceof Node\Expr\ConstFetch && $node->left->name->toString() === 'null') {
                    $types['null'] = self::TYPE_CONDITIONALLY_IMPOSSIBLE;
                }
            }
        } elseif ($node instanceof Node\Expr\BooleanNot) {
            if ($node->expr instanceof Node\Expr\Variable && $node->expr->name === $this->name) {
                $types['int']    = self::TYPE_CONDITIONALLY_POSSIBLE; // 0
                $types['string'] = self::TYPE_CONDITIONALLY_POSSIBLE; // ''
                $types['float']  = self::TYPE_CONDITIONALLY_POSSIBLE; // 0.0
                $types['array']  = self::TYPE_CONDITIONALLY_POSSIBLE; // []
                $types['null']   = self::TYPE_CONDITIONALLY_POSSIBLE; // null
            } else {
                $subTypes = $this->parseCondition($node->expr);

                // Reverse the possiblity of the types.
                foreach ($subTypes as $subType => $possibility) {
                    if ($possibility === self::TYPE_CONDITIONALLY_GUARANTEED) {
                        $types[$subType] = self::TYPE_CONDITIONALLY_IMPOSSIBLE;
                    } elseif ($possibility === self::TYPE_CONDITIONALLY_IMPOSSIBLE) {
                        $types[$subType] = self::TYPE_CONDITIONALLY_GUARANTEED;
                    } elseif ($possibility === self::TYPE_CONDITIONALLY_POSSIBLE) {
                        // Possible types are effectively negated and disappear.
                    }
                }
            }
        } elseif ($node instanceof Node\Expr\Variable && $node->name === $this->name) {
            $types['null'] = self::TYPE_CONDITIONALLY_IMPOSSIBLE;
        } elseif ($node instanceof Node\Expr\Instanceof_) {
            if ($node->expr instanceof Node\Expr\Variable && $node->expr->name === $this->name) {
                if ($node->class instanceof Node\Name) {
                    $types[$this->fetchClassName($node->class)] = self::TYPE_CONDITIONALLY_GUARANTEED;
                } else {
                    // This is an expression, we could fetch its return type, but that still won't tell us what
                    // the actual class is, so it's useless at the moment.
                }
            }
        } elseif ($node instanceof Node\Expr\FuncCall) {
            if ($node->name instanceof Node\Name) {
                $variableHandlingFunctionTypeMap = [
                    'is_array'    => ['array'],
                    'is_bool'     => ['bool'],
                    'is_callable' => ['callable'],
                    'is_double'   => ['float'],
                    'is_float'    => ['float'],
                    'is_int'      => ['int'],
                    'is_integer'  => ['int'],
                    'is_long'     => ['int'],
                    'is_null'     => ['null'],
                    'is_numeric'  => ['int', 'float', 'string'],
                    'is_object'   => ['object'],
                    'is_real'     => ['float'],
                    'is_resource' => ['resource'],
                    'is_scalar'   => ['int', 'float', 'string', 'bool'],
                    'is_string'   => ['string']
                ];

                if (isset($variableHandlingFunctionTypeMap[$node->name->toString()])) {
                    if (
                        !empty($node->args) &&
                        !$node->args[0]->unpack &&
                        $node->args[0]->value instanceof Node\Expr\Variable &&
                        $node->args[0]->value->name === $this->name
                    ) {
                        $guaranteedTypes = $variableHandlingFunctionTypeMap[$node->name->toString()];

                        foreach ($guaranteedTypes as $guaranteedType) {
                            $types[$guaranteedType] = self::TYPE_CONDITIONALLY_GUARANTEED;
                        }
                    }
                }
            }
        }

        return $types;
    }

    /**
     * @param Node $node
     */
    protected function parseNodeDocblock(Node $node)
    {
        $docblock = $node->getDocComment();

        if (!$docblock) {
            return;
        }

        // Check for a reverse type annotation /** @var $someVar FooType */. These aren't correct in the sense that
        // they aren't consistent with the standard syntax "@var <type> <name>", but they are still used by some IDE's.
        // For this reason we support them, but only their most elementary form.
        $classRegexPart = "?:\\\\?[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*";
        $reverseRegexTypeAnnotation = "/\/\*\*\s*@var\s+\\\${$this->name}\s+(({$classRegexPart}(?:\[\])?))\s*(\s.*)?\*\//";

        if (preg_match($reverseRegexTypeAnnotation, $docblock, $matches) === 1) {
            $this->bestTypeOverrideMatch = $matches[1];
            $this->bestTypeOverrideMatchLine = $node->getLine();
        } else {
            $docblockData = $this->getDocParser()->parse((string) $docblock, [
                DocParser::VAR_TYPE
            ], $this->name);

            if ($docblockData['var']['name'] === $this->name && $docblockData['var']['type']) {
                $this->bestTypeOverrideMatch = $docblockData['var']['type'];
                $this->bestTypeOverrideMatchLine = $node->getLine();
            }
        }
    }

    /**
     * Takes a class name and turns it into a string.
     *
     * @param Node\Name $name
     *
     * @return string
     */
    protected function fetchClassName(Node\Name $name)
    {
        $newName = (string) $name;

        if ($name->isFullyQualified() && $newName[0] !== '\\') {
            $newName = '\\' . $newName;
        }

        return $newName;
    }

    /**
     * @param Node|null $bestMatch
     *
     * @return static
     */
    protected function setBestMatch(Node $bestMatch = null)
    {
        $this->resetConditionalState();

        $this->bestMatch = $bestMatch;

        return $this;
    }

    /**
     *
     */
    protected function resetConditionalState()
    {
        $this->conditionalTypes = [];
    }

    /**
     * @return void
     */
    protected function resetStateForNewScope()
    {
        $this->setBestMatch(null);

        $this->bestTypeOverrideMatch = null;
        $this->bestTypeOverrideMatchLine = null;
    }

    /**
     * @param Node $node
     *
     * @return string[]
     */
    protected function getTypesForNode(Node $node)
    {
        if ($node instanceof Node\Expr\Assign) {
            if ($node->expr instanceof Node\Expr\Ternary) {
                $firstOperandType = $this->deduceTypesCommand->deduceTypesFromNode(
                    $this->file,
                    $this->code,
                    $node->expr->if ?: $node->expr->cond,
                    $node->getAttribute('startFilePos')
                );

                $secondOperandType = $this->deduceTypesCommand->deduceTypesFromNode(
                    $this->file,
                    $this->code,
                    $node->expr->else,
                    $node->getAttribute('startFilePos')
                );

                if ($firstOperandType === $secondOperandType) {
                    return $firstOperandType;
                }
            } else {
                return $this->deduceTypesCommand->deduceTypesFromNode(
                    $this->file,
                    $this->code,
                    $node->expr,
                    $node->getAttribute('startFilePos')
                );
            }
        } elseif ($node instanceof Node\Stmt\Foreach_) {
            $types = $this->deduceTypesCommand->deduceTypesFromNode(
                $this->file,
                $this->code,
                $node->expr,
                $node->getAttribute('startFilePos')
            );

            foreach ($types as $type) {
                if ($type && mb_strpos($type, '[]') !== false) {
                    $type = mb_substr($type, 0, -2);

                    return $type ? [$type] : [];
                }
            }
        } elseif ($node instanceof Node\FunctionLike) {
            foreach ($node->getParams() as $param) {
                if ($param->name === $this->name) {
                    $docBlock = $node->getDocComment();

                    if ($docBlock) {
                        // Analyze the docblock's @param tags.
                        $name = null;

                        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
                            $name = $node->name;
                        }

                        $result = $this->getDocParser()->parse((string) $docBlock, [
                            DocParser::PARAM_TYPE
                        ], $name, true);

                        if (isset($result['params']['$' . $this->name])) {
                            return $this->typeAnalyzer->getTypesForTypeSpecification(
                                $result['params']['$' . $this->name]['type']
                            );
                        }
                    }

                    if ($param->type) {
                        // Found a type hint.
                        if ($param->type instanceof Node\Name) {
                            $type = $this->fetchClassName($param->type);

                            return $type ? [$type] : [];
                        }

                        return $param->type ? [$param->type] : [];
                    }

                    break;
                }
            }
        } elseif ($node instanceof Node\Name) {
            return [$this->fetchClassName($node)];
        }

        return [];
    }

    /**
     * @return string[]
     */
    protected function getTypes()
    {
        if ($this->bestTypeOverrideMatch) {
            return $this->typeAnalyzer->getTypesForTypeSpecification($this->bestTypeOverrideMatch);
        }

        $guaranteedTypes = [];
        $possibleTypeMap = [];

        foreach ($this->conditionalTypes as $type => $possibility) {
            if ($possibility === self::TYPE_CONDITIONALLY_GUARANTEED) {
                $guaranteedTypes[] = $type;
            } elseif ($possibility === self::TYPE_CONDITIONALLY_POSSIBLE) {
                $possibleTypeMap[$type] = true;
            }
        }

        $types = [];

        // Types guaranteed by a conditional statement take precedence (if they didn't apply, the if statement could
        // never have executed in the first place).
        if (!empty($guaranteedTypes)) {
            $types = $guaranteedTypes;
        } elseif ($this->name === 'this') {
            $types = $this->currentClassName ? [$this->currentClassName] : [];
        } elseif ($this->bestMatch) {
            $types = $this->getTypesForNode($this->bestMatch);
        } elseif ($this->lastFunctionLikeNode) {
            $types = $this->getTypesForNode($this->lastFunctionLikeNode);
        }

        $filteredTypes = [];

        foreach ($types as $type) {
            if (isset($this->conditionalTypes[$type])) {
                $possibility = $this->conditionalTypes[$type];

                if ($possibility === self::TYPE_CONDITIONALLY_IMPOSSIBLE) {
                    continue;
                } elseif (isset($possibleTypeMap[$type])) {
                    $filteredTypes[] = $type;
                } elseif ($possibility === self::TYPE_CONDITIONALLY_GUARANTEED) {
                    $filteredTypes[] = $type;
                }
            } elseif (empty($possibleTypeMap)) {
                // If the possibleTypeMap wasn't empty, the types the variable can have are limited to those present
                // in it (it acts as a whitelist).
                $filteredTypes[] = $type;
            }
        }

        return $filteredTypes;
    }

    /**
     * @param string $file
     *
     * @return string[]
     */
    public function getResolvedTypes($file)
    {
        $resolvedTypes = [];

        $types = $this->getTypes();

        foreach ($types as $type) {
            if (in_array($type, ['self', 'static', '$this'], true) && $this->currentClassName) {
                $type = $this->currentClassName;
            }

            if ($this->typeAnalyzer->isClassType($type) && $type[0] !== "\\") {
                $type = $this->resolveTypeCommand->resolveType(
                    $type,
                    $file,
                    $this->bestTypeOverrideMatchLine ?: $this->line
                );
            }

            $resolvedTypes[] = $type;
        }

        return $resolvedTypes;
    }

    /**
     * Retrieves an instance of DocParser. The object will only be created once if needed.
     *
     * @return DocParser
     */
    protected function getDocParser()
    {
        if (!$this->docParser instanceof DocParser) {
            $this->docParser = new DocParser();
        }

        return $this->docParser;
    }
}