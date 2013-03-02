<?php
/**
* @author Igor Gariev <gariev@hotmail.com>
* @copyright Copyright (c) 2013 Igor Gariev
* @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
*/


//
// Strict vs. relaxed mode:
//  <?php
//      include "foo.php";          // relaxed mode - no one knows what's inside foo.php
//      if (isset($x)) ...          // ok, we know now that there may be variable named $x
//      function bar($a, $b) {      // strict mode - new scope
//          if (isset($y)) ...;     // error - there's no way $y can be here
//          extract($a);            // relaxed mode from here till end of the scope
//          $$b = 1;                // also turn relaxed mode
//          if (empty($z))          // ok, we are in relaxed mode
//      }
//
//  In short:
//      - Global scope starts in relaxed mode, functions scope starts with strict.
//      - Use of extract() or $$var or include/require triggers relaxed mode.
//      - Use of isset($unknown_var) or empty($unknown_var) is error in strict mode
//        and "declaration" of $unknown_var in relaxed mode.
//
//      - TODO: make any conditional expression with a variable "declare" this
//        variable in relaxed mode (?), like
//          if (trim($unknownVar) != '') // now $unknownVar is known in this scope
//

// function that can assign values to variables passed by reference:
// list of known functions both that can and cannot set variable's value
//
//  strict mode:
//      known_function_that_assign_variable($unknown_var);          // ok
//      known_function_that_doesnt_assign_variable($unknown_var);   // error
//      unknown_function($unknown_var);                             // warning
//      unknown_function($unknown_var_in_expression*2);             // error
// relaxed mode:
//      known_function_that_assign_variable($unknown_var);          // ok
//      known_function_that_doesnt_assign_variable($unknown_var);   // warning
//      unknown_function($unknown_var);                             // warning
//      unknown_function($unknown_var_in_expression*2);             // warning
//
// list-of-known-function =
//      explicit list of functions +
//      config file defined funcions +
//      result of get_defined_functions() +     // don't overwrite functions from above
//      parsing of current file                 // overwrite or not?
//


class XRef_Lint_UninitializedVars extends XRef_APlugin implements XRef_ILintPlugin {
    protected $reportId             = "lint-uninitialized-vars";
    protected $reportName           = "Lint (use of uninitialized vars)";
    protected $supportedFileType    = XRef::FILETYPE_PHP;

    /** known superglobals: array('$_SEREVR' => true, ...); */
    protected static $knownSuperglobals = array();

    /** known globals: array('$argv' => true, ...) */
    protected static $knownGlobals = array();

    /**
     * known internal php functions:
     * array( "function_name" => null|array with list of init-by-ref argument positions),
     * e.g. array( 'preg_match' => array(2), 'printf' => null, ... )
     */
    protected static $internalFunctions = array();

    /**
     * array similar to $internalFunctions above but with user functions, file dependent
     */
    private $userFunctions = array();

    public function __construct() {
        // super global variables
        $superGlobals = array(
            '$GLOBALS', '$_REQUEST', '$_GET', '$_POST',
            '$_FILES', '$_ENV', '$_SERVER', '$_COOKIE', '$_SESSION',
            '$HTTP_RAW_POST_DATA',
            '$http_response_header', '$php_errormsg',
            // let's pretend here that $this is always defined,
            // the other lint plugin checks context of $this usage
            '$this',
        );
        self::$knownSuperglobals = array_fill_keys($superGlobals, true);

        // global variables
        $globals = array_merge(
            array('$argv', '$argc'),
            XRef::getConfigValue("lint.globals-vars", array())
        );
        self::$knownGlobals  = array_fill_keys($globals, true);

        if (!self::$internalFunctions) {
            self::$internalFunctions = & self::get_internal_php_functions();
        }
    }

    public function getName() {
        return $this->reportName;
    }
    public function getId() {
        return $this->reportId;
    }

    const VAR_ASSIGNED = 1;
    const VAR_USED = 2;
    const VAR_UNKNOWN = 0;

    const MODE_STRICT = 1;
    const MODE_RELAXED = 2;

    protected $reportLevel = XRef::WARNING;
    public function setReportLevel($reportLevel) {
        $this->reportLevel = $reportLevel;
    }

    // array of XRef_CodeDefect objects
    protected $report = array();

    protected function addDefect($token, $defectLevel, $message) {
        if ($defectLevel >= $this->reportLevel) {
            $this->report[] = new XRef_CodeDefect($token, $defectLevel, $message);
        }
    }

    // for each scope, there is an array with list of declared variables
    // $foo = 1;                        // global scope
    // function bar() { $baz = 1; }     // function scope
    //                                  // nested functions ...
    protected $stackOfScopes = array(); // array of stdObjects

    protected function addScope($prevScope, $mode) {
        $this->stackOfScopes[] = (object) array(
            "vars"      => array(),
            "prevScope" => $prevScope,
            "mode"      => $mode,
        );
    }
    protected function getCurrentScope() {
        if (count($this->stackOfScopes)>0) {
            return $this->stackOfScopes[ count($this->stackOfScopes)-1 ];
        } else {
            return null;
        }
    }
    protected function removeScope() {
        return array_pop($this->stackOfScopes);
    }


    protected function getOrCreateVar($token) {
        $varName = $token->text;
        $currentScope = $this->stackOfScopes[ count($this->stackOfScopes)-1 ];
        if (!isset($currentScope->vars[$varName])) {
            $currentScope->vars[$varName] = (object) array(
                "status"     => self::VAR_UNKNOWN,
                "token"      => $token,
                "isRefParam" => false, // paramenter of fuction passed by reference: function foo(&$bar)
                "isCatchVar" => false, // variable of try {} catch (E $foo) block
                "isGlobal"   => false,
            );
        } else {
            $currentScope->vars[$varName]->token = $token;
        }
        return $currentScope->vars[$varName];
    }

    protected function checkVar($token) {
        $varName = $token->text;
        if (isset(self::$knownSuperglobals[$varName])) {
            return true;
        } else if (count($this->stackOfScopes)==1 && isset(self::$knownGlobals[$varName])) {
            return true;
        } else {
            $currentScope = $this->getCurrentScope();
            return isset($currentScope->vars[$varName]);
        }
    }

    public function getReport(XRef_IParsedFile $pf) {
        if ($pf->getFileType() != $this->supportedFileType) {
            return;
        }

        // initialization/clean-up after previous parsed file, if any
        $this->stackOfScopes = array();
        $this->report = array();
        $dropScopeAt = -1;  // index of the token where current scope ends
        $this->addScope(-1, self::MODE_RELAXED);
        $this->userFunctions = &self::get_user_functions($pf);

        // to check or not to check variables in the global scope
        // variables in local scope (inside functions) will always be checked
        $checkGlobalScope = XRef::getConfigValue("lint.check-global-scope", true);

        $switch_to_relaxed_scope_at = -1;   // token position, after which there is relaxed mode
        $token_caused_mode_switch = null;   // token that caused the switch, e.g. "extract"

        $tokens = $pf->getTokens();
        // hate PHP 5.2: no loop labels allowed
        // TOKEN:
        for ($i=0; $i<count($tokens); ++$i) {
            $t = $tokens[$i];

            //
            // Switch from strict mode to relaxed?
            //
            // use of extract() or $$foo notation
            // trick is: the mode should be switched AFTER the statement, e.g.
            //  function foo() { extract($foo); echo $bar; }
            // $foo must be declared (still in strict scope); $bar - not (in relaxed mode)

            // use of extract()
            if ($t->kind == T_STRING && $t->text == 'extract') {
                $n = $t->nextNS(); // next non-space token
                if ($n->text == '(') {
                    $token_caused_mode_switch = $t;
                    $switch_to_relaxed_scope_at = $pf->getIndexOfPairedBracket( $n->index );
                    continue;
                }
            }
            // $$var notation in assignement.
            // Non-assignement (read) operations doesn't cause mode switch
            //      $$foo =
            //      $$bar["baz"] =
            // TODO: other forms of assignement? $$foo++; ?
            if ($t->text == '$') {
                $n = $t->nextNS(); // next non-space token
                if ($n->kind==T_VARIABLE) {
                    $nn = $n->nextNS();
                    while ($nn->text == '[') {
                        // quick forward to closing ']'
                        $nn = $pf->getTokenAt( $pf->getIndexOfPairedBracket($nn->index) );
                    }
                    if ($nn->text == '=') {
                        $token_caused_mode_switch = $n;
                        $s = self::skipTillText($n, ';');           // find the end of the statement
                        $switch_to_relaxed_scope_at = $s->index;    // and switch to relaxed mode from there
                    }
                }
            }
            // include/require statements
            // if you use them inside functions, well, it's impossible to make any assertions about your code.
            if ($t->kind==T_INCLUDE || $t->kind==T_REQUIRE || $t->kind==T_INCLUDE_ONCE || $t->kind==T_REQUIRE_ONCE) {
                $s = self::skipTillText($t, ';');               // find the end of the statement
                if ($s) {
                    $token_caused_mode_switch = $t;
                    $switch_to_relaxed_scope_at = $s->index;    // and switch to relaxed mode from there
                }
            }
            // switch the mode, actually
            if (isset($token_caused_mode_switch) && $i >= $switch_to_relaxed_scope_at) {
                $scope = $this->getCurrentScope();
                if ($scope->mode != self::MODE_RELAXED) {
                    $scope->mode = self::MODE_RELAXED;
                    $this->addDefect($token_caused_mode_switch, XRef::NOTICE, "Can't reliable detect var usage from here");
                }
                $switch_to_relaxed_scope_at = -1;
                unset($token_caused_mode_switch);
            }

            //
            // Part 1.
            //
            // Find "declared" or "known" variables.
            // Variable is "known" in following cases:
            //  1. value is assigned to the variable: $foo = expr
            //  2. loop var:    foreach (array() as $foo)
            //  3. parameter of a function:  function bar($foo)
            //  4. catch(Exception $err)
            //  5. Array autovivification: $foo['index']
            //  6. Scalar autovivification: $count++, $text .=
            //  7. superglobals
            //  8. list($foo) = array();
            //  9. globals: global $foo;
            // 10. functions that modify arguments:
            //      int preg_match ( string $pattern , string $subject [, array &$matches ...])
            // 11. test for existence of var in "relaxed" mode: isset($foo), empty($bar)

            // $foo =
            // $foo[...] =
            // $foo[...][...] =
            // $foo++
            // $foo .=
            // exclude class variables: public $foo = 1;
            // special case: allow declarations of variables with undefined value: $foo;
            if ($t->kind==T_VARIABLE) {

                // skip variables declaration in classes
                //      public $foo;
                //      private static $bar;
                //      var $baz;
                if ($pf->getClassAt($t->index)!=null && $pf->getMethodAt($t->index)==null) {
                    continue; //TOKEN
                }

                $n = $t->nextNS(); // next non-space token
                $p = $t->prevNS(); // prev non-space token

                // skip static class variables:
                // Foo::$bar, self::$foo
                if ($p->kind==T_DOUBLE_COLON) {
                    continue;
                }

                $isArray = false;
                while ($n->text == '[') {
                    // quick forward to closing ']'
                    $n = $pf->getTokenAt( $pf->getIndexOfPairedBracket($n->index) );
                    $n = $n->nextNS();
                    $isArray = true;
                }

                if ($n->text == '=') {
                    if (!$this->checkVar($t) && $isArray) {
                        // array autovivification;
                        $this->addDefect($t, XRef::WARNING, "Array autovivification");
                    }
                    $var = $this->getOrCreateVar($t);
                    $var->status = self::VAR_ASSIGNED;
                    continue;
                }

                if ($n->kind==T_INC || $n->kind==T_DEC || $p->kind==T_INC || $p->kind==T_DEC || $n->kind==T_CONCAT_EQUAL) {
                    if (!$this->checkVar($t)) {
                        if ($isArray) {
                            // $foo["bar"]++
                            // array autovivification;
                            $this->addDefect($t, XRef::WARNING, "Array autovivification");
                        } else {
                            // $foo++
                            // $text .=
                            $this->addDefect($t, XRef::WARNING, "Scalar autovivification");
                        }

                        $var = $this->getOrCreateVar($t);
                        $var->status = self::VAR_ASSIGNED;
                        continue;
                    }
                }

                if ($n->text == ';' && !$isArray) {
                    if ($p && ($p->text==';' || $p->text=='{')) {
                        $this->addDefect($t, XRef::NOTICE, "Empty declaration-like statement");
                        $var = $this->getOrCreateVar($t);
                        $var->status = self::VAR_ASSIGNED;
                        continue;
                    }
                }
            }

            // foreach (expr as $foo)
            // foreach (expr as $foo => & $var)
            if ($t->kind==T_FOREACH) {
                $n = $t->nextNS();
                while ($n->kind != T_AS) {
                    $n = $n->nextNS();
                }
                $nn = $n->nextNS();
                if ($nn->text == '&') {
                    $nn = $nn->nextNS();
                }
                $var = $this->getOrCreateVar($nn);
                $var->status = self::VAR_ASSIGNED;

                $n = $nn->nextNS();
                if ($n->kind == T_DOUBLE_ARROW) {
                    $nn = $n->nextNS();
                    if ($nn->text == '&') {
                        $nn = $nn->nextNS();
                    }
                    $var = $this->getOrCreateVar($nn);
                    $var->status = self::VAR_ASSIGNED;
                    $n = $nn->nextNS();
                }

                if ($n->text == ")") {
                    // ok
                } else {
                    // PHP code generated by smarty:
                    // foreach ($_from as $this->_tpl_vars['event']):
                }

                // TODO: can't skip to ")" of foreach(expr as ...), because expr will be unparsed
                // TODO: loop vars will be scanned again and counted as used even if they are not
            }

            // function &asdf($foo, $bar = array())
            // function asdf(&$foo)
            // here a new scope frame is created
            if ($t->kind==T_FUNCTION) {
                $this->addScope($dropScopeAt, self::MODE_STRICT);
                $n = self::skipTillText($t->nextNS(), '(');
                $closingBraketIndex = $pf->getIndexOfPairedBracket($n->index);
                while ($n->index != $closingBraketIndex) {
                    $n = $n->nextNS();
                    if ($n->kind == T_VARIABLE) {
                        $var = $this->getOrCreateVar($n);
                        $var->status = self::VAR_ASSIGNED;
                        $var->isRefParam = ($n->prevNS()->text == '&'); // parameter passed by reference
                    }
                }

                $n = $n->nextNS();
                if ($n->text == ';') {
                    // declaration only or absctract function: function foo();
                    $this->removeScope(); // empty scope with parameters names at most
                } elseif ($n->text == '{') {
                    $dropScopeAt = $pf->getIndexOfPairedBracket( $n->index );
                } else {
                    throw new Exception("$n found instead of { or ;");
                }
                $i = $n->index;
                continue;
            }
            if ($i==$dropScopeAt) {
                $currentScope = $this->removeScope();
                $dropScopeAt = $currentScope->prevScope;

                //
                // the notice below is unreliable:
                //  if a variable is inside loop, it can be used on next iterations of the loop
                // another TODO: report about vars ouside of loops only
                /*
                foreach ($currentScope->vars as $varName => $var) {
                    if ($var->status != self::VAR_USED && !$var->isRefParam && !$var->isCatchVar && !in_array($varName, self::$knownSuperglobals) && !$var->isGlobal) {
                        $this->addDefect($var->token, XRef::NOTICE, "Value of variable is not used");
                    }
                }
                */
            }

            // catch (Exception $foo)
            if ($t->kind == T_CATCH) {
                $n = $t->nextNS();
                if ($n->text != '(') {
                    throw new Exception("$n found instead of '('");
                }
                $n = $n->nextNS(); // class name?
                $n = $n->nextNS(); //
                if ($n->kind == T_VARIABLE) {
                    $var = $this->getOrCreateVar($n);
                    $var->status = self::VAR_ASSIGNED;
                    $var->isCatchVar = true;
                } else {
                    throw new Exception("$n found instead of variable");
                }
                $n = $n->nextNS(); //
                if ($n->text != ')') {
                    throw new Exception("$n found instead of ')'");
                }

                $i = $n->index;
                continue;
            }

            // list($a, $b) = ...
            // TODO: check that the list is used in the left side of the assinment operator
            // TODO: example from PHP documentation: list($a, list($b, $c)) = array(1, array(2, 3));
            if ($t->kind==T_LIST) {
                $n = $t->nextNS();
                if (!$n->text=="(") {
                    throw new Exception("Invalid list declaration found: $t");
                }

                $closingBraketIndex = $pf->getIndexOfPairedBracket($n->index);
                while ($n->index != $closingBraketIndex) {
                    if ($n->kind==T_VARIABLE) {
                        $var = $this->getOrCreateVar($n);
                        $var->status = self::VAR_ASSIGNED;
                    }
                    $n = $n->nextNS();
                }
                $i = $n->index;
                continue;
            }

            // globals:
            //      global $foo;     // makes the variable $foo known
            //      global $$bar;    // uh-oh, the var $bar must be known and relaxed mode afterwards
            //
            // TODO: check that the variable does exist at global level
            // TODO: link this var to the var at global level
            if ($t->kind == T_GLOBAL) {
                $n = $t->nextNS();
                while (true) {
                    if ($n->kind==T_VARIABLE) {
                        $var = $this->getOrCreateVar($n);
                        $var->isGlobal = true;
                        $var->status = self::VAR_ASSIGNED;
                        $n = $n->nextNS();
                    } elseif ($n->text=='$') {
                        $n = $n->nextNS();
                        if ($n->kind==T_VARIABLE) {
                            // check that this var is declared
                            if (!$this->checkVar($n)) {
                                $this->addDefect($n, XRef::ERROR, "Use of non-defined variable");
                            }
                            // turn the relaxed mode on starting from the next statement
                            $s = self::skipTillText($n, ';');
                            $token_caused_mode_switch = $n;
                            $switch_to_relaxed_scope_at = $s->index;
                        } else {
                            throw new Exception("Invalid 'global' decalaraion found: $nn");
                        }
                        $n = $n->nextNS();
                    } else {
                        throw new Exception("Invalid 'global' decalaraion found: $n");
                    }

                    if ($n->text==',') {
                        $n = $n->nextNS();
                        continue; // next variable in list
                    } elseif ($n->text==';') {
                        break; // end of list
                    } else {
                        throw new Exception("Invalid 'global' declaration found: $n");
                    }
                }
                $i = $n->index;
                continue;
            }

            // static function variables
            //  function foo() {
            //      static $foo;                // <-- well, strictly speaking this variable is not intilialized,
            //      static $bar = 10, $baz;     //  but it's declared so let's hope that author knows what's going on
            //  }
            // other usage of "static" keyword:
            //  $foo = new static();
            //  $foo = new static;
            //  $foo = static::methodName();
            if ($t->kind == T_STATIC && $pf->getMethodAt($t->index)!=null) {
                $n = $t->nextNS();
                $p = $t->prevNS();
                if ($n->kind != T_DOUBLE_COLON && $p->kind != T_NEW) {
                    $list = $pf->extractList($n, ',', ';');
                    foreach ($list as $n) {
                        if ($n->kind == T_VARIABLE) {
                            $var = $this->getOrCreateVar($n);
                            $var->status = self::VAR_ASSIGNED;
                            $i = $n->index;
                        } else {
                            // oops?
                            throw new Exception("Invalid 'static' decalaraion found: $n");
                        }
                    }
                    continue;
                }
            }

            //
            // Functions that return values into passed-by-reference-arguments,
            //  e.g. preg_match, preg_match_all etc.
            //
            // Unknown (user-defined) functions can accept vars by reference too,
            // but we don't know about them, so just produce a varning
            //
            // Summary:
            //      known_function_that_assign_variable($unknown_var);          // ok               (processed here)
            //      known_function_that_doesnt_assign_variable($unknown_var);   // error/warning    (processed later)
            //      unknown_function($unknown_var);                             // warning          (here)
            //      unknown_function($unknown_var_in_expression*2);             // error/warning    (later)
            //
            if ($t->kind == T_STRING) {
                $n = $t->nextNS();
                if ($n->text == '(') {
                    $arguments = $pf->extractList($n->nextNS());
                    $function_name = $t->text;  // TODO: differentiate between function and method names
                    $is_known_function = false;

                    if (array_key_exists($function_name, $this->userFunctions)) {
                        $args = $this->userFunctions[$function_name];
                        $is_known_function = true;
                    } elseif (array_key_exists($function_name, self::$internalFunctions)) {
                        $args = self::$internalFunctions[$function_name];
                        $is_known_function = true;
                    }

                    if ($is_known_function) {
                        // For known funcitons:
                        //  - mark variables that are used as passed-by-reference return arguments as known
                        //  - do nothing with variables that are not returned by function - they will be checked later
                        if ($args) {
                            foreach ($args as $argPos) {
                                if (count($arguments) > $argPos) {
                                    $n = $arguments[$argPos];
                                    if ($n->text == '&') {
                                        $n = $n->nextNS();
                                    }
                                    if ($n->kind != T_VARIABLE) {
                                        // TODO: add other valid lvalues here, like $array["index"] or $object->field
                                        $this->addDefect($n, XRef::ERROR, "Possible attemps to pass non-variable by reference");
                                    }
                                    $var = $this->getOrCreateVar($n);
                                    $var->status = self::VAR_ASSIGNED;
                                }
                            }
                        }
                    } else {
                        // For unknown functions:
                        // If argument look like a single variable (not a part of a complex expression),
                        // it too can be passed/returned/initialized by function.
                        // Issue a warning if this variable is not known
                        foreach ($arguments as $n) {
                            if ($n->text == '&') {
                                $n = $n->nextNS();
                            }
                            if ($n->kind == T_VARIABLE && !$this->checkVar($n)) {
                                $nn = $n->nextNS();
                                if ($nn->text==',' || $nn->text==')') {
                                    $this->addDefect($n, XRef::WARNING, "Possible use of non-defined variable");
                                    $var = $this->getOrCreateVar($n);
                                    $var->status = self::VAR_ASSIGNED;
                                }
                            }
                        }
                    }
                }
            }

            // test for variable in relaxed mode only:
            //      if (isset($variable)) ...   // this makes $variable "known" in relaxed mode
            //      if (!empty($variable)) ...
            // No expressions as function argument:
            //      isset( $foo["bar"] ); // doesn't make $foo "declared", it must exist or this is an error
            if ($t->kind==T_ISSET || $t->kind==T_EMPTY) {
                $n = $t->nextNS();
                if ($n && $n->text=='(') {
                    $nn = $n->nextNS();
                    if ($nn && $nn->kind==T_VARIABLE) {
                        $nnn = $nn->nextNS();
                        if ($nnn && $nnn->text==')') {
                            // ok, this is a simple expression with a variable inside function call
                            $scope = $this->getCurrentScope();
                            if ($scope->mode==self::MODE_RELAXED) {
                                // mark this variable as "known" in relaxed mode
                                $var = $this->getOrCreateVar($nn);
                                $var->status = self::VAR_ASSIGNED;
                            } else {
                                // skip till the end of statement in strict mode
                                $i = $nnn->index;
                                continue;
                            }
                        }
                    }
                }
            }

            // Part 2.
            // Check if a variable is defined
            //
            if ($t->kind==T_VARIABLE) {
                $skipVariable = false;

                // skip class static variables:
                // Foo::$foo
                // TODO: check that this class variable is really declared
                $p = $t->prevNS();
                if ($p->kind == T_DOUBLE_COLON) {
                    $skipVariable = true;
                }

                // skip varibales in the global scope, because it's often polluted by vars
                // included from inlcuded/required files
                if ($checkGlobalScope==false && count($this->stackOfScopes)==1) {
                    $skipVariable = true;
                }

                if (!$skipVariable && !$this->checkVar($t)) {
                    $scope = $this->getCurrentScope();
                    if ($scope->mode == self::MODE_STRICT) {
                        $this->addDefect($t, XRef::ERROR, "Use of non-defined variable");
                    } else {
                        $this->addDefect($t, XRef::WARNING, "Possible use of non-defined variable");
                    }
                    $var = $this->getOrCreateVar($t);
                    $var->status = self::VAR_USED; // mark it as used to report every var only once
                }
            }
        } // end of "for each token" loop

        if (count($this->stackOfScopes)!=1) {
            throw new Exception("internal error: size of stack = " . count($this->stackOfScopes) . ", " . $pf->getFileName());
        }

        $currentScope = $this->removeScope();
        foreach ($currentScope->vars as $varName => $var) {
            if ($var->status != self::VAR_USED && !in_array($varName, self::$knownSuperglobals)) {
                $this->addDefect($var->token, XRef::NOTICE, "Value of variable is not used");
            }
        }

        return $this->report;
    }

    // input: start token, text value
    // output: first token that follows the start token and equals to the given text
    // Helpful to find end-of-the statement (terminated by ';') of the start token
    private static function skipTillText($token, $text) {
        while ($token) {
            if ($token->text == $text) {
                return $token;
            }
            $token = $token->nextNS();
        }
        return null;
    }

    // returns the list of internal php function with their arguments that they can initialize
    // see self::$internalFunctions
    private static function &get_internal_php_functions() {
        $functions = array();

        //
        // use PHP introspection to get all known functions
        //
        $defined_functions = get_defined_functions();
        $internal_functions = $defined_functions["internal"];
        foreach ($internal_functions as $function_name) {
            $r = new ReflectionFunction($function_name);
            $params = $r->getParameters();
            $ref_params_list = array();
            foreach ($params as $p) {
                if ($p->isPassedByReference()) {
                    $pos = $p->getPosition();
                    if ($p->getName() == "...") {
                        // functions like sscanf takes unlimited number of args
                        // TODO: create a better way to work with unlimited lists
                        for ($i = $pos; $i<10; ++$i) {
                            $ref_params_list[] = $i;
                        }
                    } else {
                        $ref_params_list[] = $pos;
                    }
                }
            }
            $functions[$function_name] = (count($ref_params_list)) ? $ref_params_list : null;
        }

        // then, override some of them:
        //  1. some functions take pass-by-reference params but they don't initialize them,
        //     the params must already exist, e.g. bool sort ( array &$array [, int $sort_flags] )
        //  2. some functions are defined in extensions that the given PHP runtime may miss
        $remove_list = array(
            'array_multisort', 'array_pop', 'array_push', 'array_shift', 'array_splice', 'array_unshift',
            'array_walk', 'array_walk_recursive', 'arsort', 'asort', 'call_user_method',
            'call_user_method_array', 'current', 'each', 'end', 'extract', 'key', 'krsort', 'ksort',
            'mb_convert_variables', 'natcasesort', 'natsort', 'next', 'openssl_csr_new', 'pos', 'prev',
            'reset', 'rsort', 'settype', 'shuffle', 'sort', 'uasort', 'uksort', 'usort', 'xml_set_object',
        );

        $override_list = array(
            "apc_fetch"               => array(1),
            'apc_dec'                 => array(2),
            'apc_inc'                 => array(2),
            'grapheme_extract'        => array(4),
            'ncurses_color_content'   => array(1, 2, 3),
            'ncurses_getmaxyx'        => array(1, 2),
            'ncurses_getmouse'        => array(0),
            'ncurses_getyx'           => array(1, 2),
            'ncurses_instr'           => array(0),
            'ncurses_mouse_trafo'     => array(0, 1),
            'ncurses_mousemask'       => array(1),
            'ncurses_pair_content'    => array(1, 2),
            'ncurses_wmouse_trafo'    => array(1, 2),
            'numfmt_parse'            => array(3),
            'numfmt_parse_currency'   => array(2, 3),
            'pcntl_waitpid'           => array(1),
            "pcntl_wait"              => array(0),
        );

        foreach ($remove_list as $function_name) {
            $functions[$function_name] = null;
        }
        foreach ($override_list as $function_name => $args) {
            $functions[$function_name] = $args;
        }
        return $functions;
    }

    private static function &get_user_functions($pf) {
        $functions = array();

        // add functions/methods defined in this file
        $pf_methods = $pf->getMethods();
        foreach ($pf_methods as $m) {
            $function_name = $m->name;
            if ($function_name=='__construct') {
                // constructors are too different from regular functions/methods
                // in decl/usage syntax
                continue;
            }
            $t = $pf->getTokenAt( $m->nameStartIndex );
            $n = $t->nextNS();
            if ($n->text == '(') {
                $ref_params_list = array();
                $tokenList = $pf->extractList($n->nextNS());
                for ($i=0; $i<count($tokenList); ++$i) {
                    $t = $tokenList[$i];
                    if ($t->text=='&') {
                        $ref_params_list[] = $i;
                    }
                }
                $functions[$function_name] = (count($ref_params_list)) ? $ref_params_list : null;
            }
        }

        // add functions/methods from config file
        // format of each config entry (arguments positions starts with 1):
        // init-by-reference[]  =   <function-name>,<position-of-param1>,<position-of-param2...>
        foreach (XRef::getConfigValue("lint.init-by-reference", array()) as $str) {
            $params = split(",", $str);
            $function_name = array_shift($params);
            $functions[$function_name] = $params;
        }
        return $functions;
    }
}

// vim: tabstop=4 expandtab
