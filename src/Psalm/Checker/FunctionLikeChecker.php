<?php

namespace Psalm\Checker;

ini_set('xdebug.max_nesting_level', 512);

use PhpParser;
use Psalm\Issue\InvalidDocblock;
use Psalm\Issue\InvalidReturnType;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Config;
use Psalm\Context;
use Psalm\IssueBuffer;

abstract class FunctionLikeChecker implements StatementsSource
{
    protected $function;
    protected $namespace;
    protected $file_name;
    protected $is_static = false;
    protected $absolute_class;
    protected $statements_checker;
    protected $source;
    protected $return_vars_in_scope = [];
    protected $return_vars_possibly_in_scope = [];
    protected $class_name;
    protected $class_extends;

    /**
     * @var array
     */
    protected $suppressed_issues;

    protected static $no_effects_hashes = [];

    public function __construct(PhpParser\Node\FunctionLike $function, StatementsSource $source)
    {
        $this->function = $function;
        $this->namespace = $source->getNamespace();
        $this->class_name = $source->getClassName();
        $this->class_extends = $source->getParentClass();
        $this->file_name = $source->getFileName();
        $this->absolute_class = $source->getAbsoluteClass();
        $this->source = $source;
        $this->suppressed_issues = $source->getSuppressedIssues();
    }

    public function check(Context $context, $check_methods = true)
    {
        if ($this->function->stmts) {
            $has_context = (bool) count($context->vars_in_scope);
            if ($this instanceof MethodChecker) {
                if (ClassLikeChecker::getThisClass()) {
                    $hash = $this->getMethodId() . json_encode([$context->vars_in_scope, $context->vars_possibly_in_scope]);

                    // if we know that the function has no effects on vars, we don't bother rechecking
                    if (isset(self::$no_effects_hashes[$hash])) {
                        list($context->vars_in_scope, $context->vars_possibly_in_scope) = self::$no_effects_hashes[$hash];
                        return;
                    }
                }
                elseif ($context->self) {
                    $context->vars_in_scope['this'] = new Type\Union([new Type\Atomic($context->self)]);
                }
            }

            $statements_checker = new StatementsChecker($this, $has_context, $check_methods);

            if ($this->function instanceof PhpParser\Node\Stmt\ClassMethod) {
                $method_params = MethodChecker::getMethodParams($this->getMethodId());

                foreach ($method_params as $method_param) {
                    $context->vars_in_scope[$method_param['name']] = StatementsChecker::fleshOutTypes(
                        clone $method_param['type'],
                        [],
                        $context->self,
                        $this->getMethodId()
                    );

                    $statements_checker->registerVariable($method_param['name'], $this->function->getLine());
                }
            }
            else {
                // @todo deprecate this code
                foreach ($this->function->params as $param) {
                    if ($param->type) {
                        if ($param->type instanceof PhpParser\Node\Name) {
                            if (!in_array($param->type->parts[0], ['self', 'parent'])) {
                                ClassLikeChecker::checkClassName(
                                    $param->type,
                                    $this->namespace,
                                    $this->getAliasedClasses(),
                                    $this->file_name,
                                    $this->suppressed_issues
                                );
                            }
                        }
                    }

                    $is_nullable = $param->default !== null &&
                                    $param->default instanceof \PhpParser\Node\Expr\ConstFetch &&
                                    $param->default->name instanceof PhpParser\Node\Name &&
                                    $param->default->name->parts = ['null'];

                    if ($param->type) {
                        if ($param->type instanceof Type) {
                            $context->vars_in_scope[$param->name] = clone $param->type;
                        }
                        else {
                            if (is_string($param->type)) {
                                $param_type_string = $param->type;
                            }
                            elseif ($param->type instanceof PhpParser\Node\Name) {
                                $param_type_string = $param->type->parts === ['self']
                                                        ? $this->absolute_class
                                                        : ClassLikeChecker::getAbsoluteClassFromName(
                                                            $param->type,
                                                            $this->namespace,
                                                            $this->getAliasedClasses()
                                                        );
                            }

                            if ($is_nullable) {
                                $param_type_string .= '|null';
                            }

                            $context->vars_in_scope[$param->name] = Type::parseString($param_type_string);
                        }
                    }
                    else {
                        $context->vars_in_scope[$param->name] = Type::getMixed();
                    }

                    $context->vars_possibly_in_scope[$param->name] = true;
                    $statements_checker->registerVariable($param->name, $param->getLine());
                }
            }

            $statements_checker->check($this->function->stmts, $context);

            if (isset($this->return_vars_in_scope[''])) {
                $context->vars_in_scope = TypeChecker::combineKeyedTypes($context->vars_in_scope, $this->return_vars_in_scope['']);
            }

            if (isset($this->return_vars_possibly_in_scope[''])) {
                $context->vars_possibly_in_scope = array_merge($context->vars_possibly_in_scope, $this->return_vars_possibly_in_scope['']);
            }

            foreach ($context->vars_in_scope as $var => $type) {
                if (strpos($var, 'this->') !== 0) {
                    unset($context->vars_in_scope[$var]);
                }
            }

            foreach ($context->vars_possibly_in_scope as $var => $type) {
                if (strpos($var, 'this->') !== 0) {
                    unset($context->vars_possibly_in_scope[$var]);
                }
            }

            if (ClassLikeChecker::getThisClass() && $this instanceof MethodChecker) {
                self::$no_effects_hashes[$hash] = [$context->vars_in_scope, $context->vars_possibly_in_scope];
            }
        }
    }

    /**
     * Adds return types for the given function
     * @param string        $return_type
     * @param array<Type>   $context->vars_in_scope
     * @param array<bool>   $context->vars_possibly_in_scope
     */
    public function addReturnTypes($return_type, Context $context)
    {
        if (isset($this->return_vars_in_scope[$return_type])) {
            $this->return_vars_in_scope[$return_type] = TypeChecker::combineKeyedTypes($context->vars_in_scope, $this->return_vars_in_scope[$return_type]);
        }
        else {
            $this->return_vars_in_scope[$return_type] = $context->vars_in_scope;
        }

        if (isset($this->return_vars_possibly_in_scope[$return_type])) {
            $this->return_vars_possibly_in_scope[$return_type] = array_merge($context->vars_possibly_in_scope, $this->return_vars_possibly_in_scope[$return_type]);
        }
        else {
            $this->return_vars_possibly_in_scope[$return_type] = $context->vars_possibly_in_scope;
        }
    }

    /**
     * @return null|string
     */
    public function getMethodId()
    {
        if ($this->function instanceof PhpParser\Node\Expr\Closure) {
            return null;
        }

        return ($this->absolute_class ? $this->absolute_class . '::' : '') . strtolower($this->function->name);
    }

    public function getNamespace()
    {
        return $this->namespace;
    }

    public function getAliasedClasses()
    {
        return $this->source->getAliasedClasses();
    }

    public function getAbsoluteClass()
    {
        return $this->absolute_class;
    }

    public function getClassName()
    {
        return $this->class_name;
    }

    public function getClassLikeChecker()
    {
        return $this->source->getClassLikeChecker();
    }

    public function getParentClass()
    {
        return $this->class_extends;
    }

    public function getFileName()
    {
        return $this->file_name;
    }

    public function isStatic()
    {
        return $this->is_static;
    }

    public function getSource()
    {
        return $this->source;
    }

    public function getSuppressedIssues()
    {
        return $this->suppressed_issues;
    }

    /**
     * @return false|null
     */
    public function checkReturnTypes($update_doc_comment = false)
    {
        if (!$this->function->stmts) {
            return;
        }

        if ($this->function->name === '__construct') {
            // we know that constructors always return this
            return;
        }

        $method_id = $this->getMethodId();

        if ($this instanceof MethodChecker) {
            $method_return_types = MethodChecker::getMethodReturnTypes($method_id);
        }
        else {
            $method_return_types = FunctionChecker::getFunctionReturnTypes($method_id, $this->file_name);
        }

        if (!$method_return_types) {
            return;
        }

        // passing it through fleshOutTypes eradicates errant $ vars
        $declared_return_type = StatementsChecker::fleshOutTypes(
            $method_return_types,
            [],
            $this->absolute_class,
            $method_id
        );

        if ($declared_return_type) {
            $inferred_return_types = \Psalm\EffectsAnalyser::getReturnTypes($this->function->stmts, true);

            if (!$inferred_return_types) {
                if ($declared_return_type->isVoid()) {
                    return;
                }

                if (ScopeChecker::onlyThrows($this->function->stmts)) {
                    // if there's a single throw statement, it's presumably an exception saying this method is not to be used
                    return;
                }

                if (IssueBuffer::accepts(
                    new InvalidReturnType(
                        'No return type was found for method ' . MethodChecker::getCasedMethodId($method_id) . ' but return type \'' . $declared_return_type . '\' was expected',
                        $this->file_name,
                        $this->function->getLine()
                    )
                )) {
                    return false;
                }

                return;
            }

            $inferred_return_type = Type::combineTypes($inferred_return_types);

            if ($inferred_return_type && !$inferred_return_type->isMixed() && !$declared_return_type->isMixed()) {
                if ($inferred_return_type->isNull() && $declared_return_type->isVoid()) {
                    return;
                }

                if (!TypeChecker::hasIdenticalTypes($declared_return_type, $inferred_return_type, $this->absolute_class)) {
                    if (IssueBuffer::accepts(
                        new InvalidReturnType(
                            'The given return type \'' . $declared_return_type . '\' for ' . MethodChecker::getCasedMethodId($method_id) . ' is incorrect, got \'' . $inferred_return_type . '\'',
                            $this->file_name,
                            $this->function->getLine()
                        ),
                        $this->getSuppressedIssues()
                    )) {
                        return false;
                    }
                }
            }

            return;
        }
    }

    protected function improveParamsFromDocblock(array $docblock_params, array $function_param_names, array &$function_signature, $method_line_number)
    {
        foreach ($docblock_params as $docblock_param) {
            $param_name = $docblock_param['name'];

            if (!array_key_exists($param_name, $function_param_names)) {
                if (IssueBuffer::accepts(
                    new InvalidDocblock(
                        'Parameter $' . $param_name .' does not appear in the argument list for ' . $this->getMethodId(),
                        $this->file_name,
                        $method_line_number
                    )
                )) {
                    return false;
                }

                continue;
            }

            $new_param_type =
                Type::parseString(
                    self::fixUpLocalType(
                        $docblock_param['type'],
                        null,
                        $this->namespace,
                        $this->getAliasedClasses()
                    )
                );

            if ($function_param_names[$param_name] && !$function_param_names[$param_name]->isMixed()) {
                if (!$new_param_type->isIn($function_param_names[$param_name])) {
                    if (IssueBuffer::accepts(
                        new InvalidDocblock(
                            'Parameter $' . $param_name .' has wrong type \'' . $new_param_type . '\', should be \'' . $function_param_names[$param_name] . '\'',
                            $this->file_name,
                            $method_line_number
                        )
                    )) {
                        return false;
                    }

                    continue;
                }
            }

            foreach ($function_signature as &$function_signature_param) {
                if ($function_signature_param['name'] === $param_name) {
                    $existing_param_type_nullable = $function_signature_param['is_nullable'];

                    if ($existing_param_type_nullable && !$new_param_type->isNullable()) {
                        $new_param_type->types['null'] = Type::getNull(false);
                    }

                    $function_signature_param['type'] = $new_param_type;
                    break;
                }
            }
        }
    }

    protected function getParamArray(PhpParser\Node\Param $param)
    {
        $param_type = null;

        $is_nullable = $param->default !== null &&
                        $param->default instanceof \PhpParser\Node\Expr\ConstFetch &&
                        $param->default->name instanceof PhpParser\Node\Name &&
                        $param->default->name->parts === ['null'];

        if ($param->type) {
            if (is_string($param->type)) {
                $param_type_string = $param->type;
            }
            elseif ($param->type instanceof PhpParser\Node\Name\FullyQualified) {
                $param_type_string = implode('\\', $param->type->parts);
            }
            elseif ($param->type->parts === ['self']) {
                $param_type_string = $this->absolute_class;
            }
            else {
                $param_type_string = ClassLikeChecker::getAbsoluteClassFromString(
                    implode('\\', $param->type->parts),
                    $this->namespace,
                    $this->getAliasedClasses()
                );
            }

            if ($param_type_string) {
                if ($is_nullable) {
                    $param_type_string .= '|null';
                }

                $param_type = Type::parseString($param_type_string);
            }
        }

        $is_optional = $param->default !== null;

        return [
            'name' => $param->name,
            'by_ref' => $param->byRef,
            'type' => $param_type ?: Type::getMixed(),
            'is_optional' => $is_optional,
            'is_nullable' => $is_nullable,
        ];
    }

    protected static function getReflectionParamArray(\ReflectionParameter $param)
    {
        $param_type_string = null;

        if ($param->isArray()) {
            $param_type_string = 'array';

        }
        else {
            $param_class = null;

            try {
                $param_class = $param->getClass();
            }
            catch (\ReflectionException $e) {
                // do nothing
            }

            if ($param_class) {
                $param_type_string = $param->getClass()->getName();
            }
        }

        $is_nullable = false;

        $is_optional = $param->isOptional();

        try {
            $is_nullable = $param->getDefaultValue() === null;

            if ($param_type_string && $is_nullable) {
                $param_type_string .= '|null';
            }
        }
        catch (\ReflectionException $e) {
            // do nothing
        }

        $param_name = $param->getName();
        $param_type = $param_type_string ? Type::parseString($param_type_string) : Type::getMixed();

        return [
            'name' => $param_name,
            'by_ref' => $param->isPassedByReference(),
            'type' => $param_type,
            'is_nullable' => $is_nullable,
            'is_optional' => $is_optional,
        ];
    }

    public static function fixUpLocalType($return_type, $absolute_class, $namespace, $aliased_classes)
    {
        if (strpos($return_type, '[') !== false) {
            $return_type = Type::convertSquareBrackets($return_type);
        }

        $return_type_tokens = Type::tokenize($return_type);

        foreach ($return_type_tokens as &$return_type_token) {
            if ($return_type_token[0] === '\\') {
                $return_type_token = substr($return_type_token, 1);
                continue;
            }

            if (in_array($return_type_token, ['<', '>', '|', '?'])) {
                continue;
            }

            $return_type_token = Type::fixScalarTerms($return_type_token);

            if ($return_type_token[0] === strtoupper($return_type_token[0])) {
                if ($return_type === '$this') {
                    $return_type_token = 'static';
                    continue;
                }

                $return_type_token = ClassLikeChecker::getAbsoluteClassFromString($return_type_token, $namespace, $aliased_classes);
            }
        }

        return implode('', $return_type_tokens);
    }

    /**
     * Does the input param type match the given param type
     * @param  Type\Union $input_type
     * @param  Type\Union $param_type
     * @param  bool       &$has_scalar_match
     * @return bool
     */
    public static function doesParamMatch(Type\Union $input_type, Type\Union $param_type, &$has_scalar_match = null)
    {
        $has_scalar_match = true;

        if ($param_type->isMixed()) {
            return true;
        }

        if ($input_type->isMixed()) {
            // @todo make this a config
            return true;
        }

        $type_match_found = false;
        $has_type_mismatch = false;

        foreach ($input_type->types as $input_type_part) {
            if ($input_type_part->isNull()) {
                continue;
            }

            $type_match_found = false;
            $scalar_type_match_found = false;

            foreach ($param_type->types as $param_type_part) {
                if ($param_type_part->isNull()) {
                    continue;
                }

                if ($input_type_part->value === $param_type_part->value ||
                    ClassChecker::classExtendsOrImplements($input_type_part->value, $param_type_part->value) ||
                    StatementsChecker::isMock($input_type_part->value)
                ) {
                    $type_match_found = true;
                    break;
                }

                if ($input_type_part->value === 'false' && $param_type_part->value === 'bool') {
                    $type_match_found = true;
                }

                if ($input_type_part->value === 'int' && $param_type_part->value === 'float') {
                    $type_match_found = true;
                }

                if ($param_type_part->isNumeric() && $input_type_part->isNumericType()) {
                    $type_match_found = true;
                }

                if ($param_type_part->isCallable() && ($input_type_part->value === 'string' || $input_type_part->value === 'array')) {
                    // @todo add value checks if possible here
                    $type_match_found = true;
                }

                if ($input_type_part->isScalarType()) {
                    if ($param_type_part->isScalarType()) {
                        $scalar_type_match_found = true;
                    }
                }
                else if ($param_type_part->isObject()) {
                    $type_match_found = true;
                }

                if (ClassChecker::classExtendsOrImplements($param_type_part->value, $input_type_part->value)) {
                    // @todo handle coercion
                    $type_match_found = true;
                    break;
                }

            }

            if (!$type_match_found) {
                if (!$scalar_type_match_found) {
                    $has_scalar_match = false;
                }

                return false;
            }
        }

        return true;
    }

    public function getParamsById($method_id, array $args, $file_name)
    {
        if (strpos($method_id, '::')) {
            return MethodChecker::getMethodParams($method_id);
        }

        $function_param_options = FunctionChecker::getParamsFromCallMap($method_id, $args);

        if ($function_param_options === null) {
            return FunctionChecker::getParams(strtolower($method_id), $file_name);
        }

        $function_params = null;

        if (count($function_param_options) === 1) {
            return $function_param_options[0];
        }

        foreach ($function_param_options as $possible_function_params) {
            $all_args_match = true;

            foreach ($args as $argument_offset => $arg) {
                if (count($possible_function_params) <= $argument_offset) {
                    break;
                }

                $param_type = $possible_function_params[$argument_offset]['type'];

                if (!isset($arg->value->inferredType)) {
                    continue;
                }

                if (FunctionLikeChecker::doesParamMatch($arg->value->inferredType, $param_type)) {
                    continue;
                }

                $all_args_match = false;
                break;
            }

            if ($all_args_match) {
                return $possible_function_params;
            }
        }

        // if we don't succeed in finding a match, set to the first possible and wait for issues below
        return $function_param_options[0];
    }
}