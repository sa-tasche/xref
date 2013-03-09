<?php

require_once dirname(__FILE__) . "/BaseLintTest.php";

class UndefinedVarsLintTest extends BaseLintTest {

    public function testSuperGlobals() {
        $testPhpCode = '
        <?php
            // globals cant triggers at all, neither in global scope or in funcitons
            echo $GLOBALS["i"];                                         // ok
            $var = $_REQUEST["foo"] + $_GET["bar"] + $_POST["baz"]      // ok + ok + ok
            if ($_FILES["filename"]) {                                  // ok
                $var++;                                                 //
            }
            echo $_ENV["PATH"];                                         // ok
            call_unknown_function($_SERVER, $_COOKIE, $_SESSION);       // ok, ok, ok
            // less-commonly used vars:
            echo $HTTP_RAW_POST_DATA;                                   // ok
            echo $http_response_header;                                 // ok
            echo $php_errormsg;                                         // ok

            echo $explicit_defect1;                                     // warning

            // the same checks in local scope
            function foo() {
                echo $GLOBALS["i"];                                     // ok
                $var = $_REQUEST["foo"] + $_GET["bar"] + $_POST["baz"]; // ok
                if ($_FILES["filename"]) {                              // ok
                    $var++;                                             //
                }
                echo $_ENV["PATH"];                                     // ok
                call_unknown_function($_SERVER, $_COOKIE, $_SESSION);   // ok, ok, ok
                // less-commonly used vars:
                echo $HTTP_RAW_POST_DATA;                               // ok
                echo $http_response_header;                             // ok
                echo $php_errormsg;                                     // ok

                echo $explicit_defect2;                                 // error
            }
        ';
        $expectedDefects = array(
            array('$explicit_defect1', 16, XRef::WARNING),
            array('$explicit_defect2', 32, XRef::ERROR),
        );
        $this->checkPhpCode($testPhpCode, $expectedDefects);
    }

    public function testGlobals() {
        $testPhpCode = '
        <?php
            // globals can be used in global scope only and triggers error in local scope (unless explicitly imported)
            echo $argc;     // ok
            echo $argv;     // ok

            function foo () {
                echo $argc;     // error
                echo $argv;     // error
            }

            function bar ($bar) {
                $$bar = 1;      // switch to relaxed mode
                echo $argc;     // warning
                echo $argv;     // warning
            }

            function baz () {
                global $argc, $argv;
                echo $argc;     // ok
                echo $argv;     // ok
            }
        ';

        $exceptedDefects = array(
            array('$argc', 8, XRef::ERROR),
            array('$argv', 9, XRef::ERROR),
            array('$argc', 14, XRef::WARNING),
            array('$argv', 15, XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $exceptedDefects);
    }

    public function testVariablesAssignedByFunctions () {

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

        $testPhpCode = '
        <?php
            function foo () {
                // internal functions
                preg_match("#pattern#", "string-to-be-mateched", $matches);     // ok
                preg_match("#pattern#", "string-to-be-mateched", null);         // error (non-var pass by ref)
                preg_grep("pattern", $input);                                   // error
                sort($array);                                                   // error
                sort( array(1,2,3) );                                           // error (non-var pass by ref)

                // locally-defined functions
                local_function_with_pass_by_reference_argument2(1, $var2);      // ok
                local_function_with_pass_by_reference_argument2($var3, $var4);  // error in $var3 only

                // unknown functions
                unknown_function($unknown_var);                                 // warning
                unknown_function($unknown_var_in_expression*2);                 // error
            }

            function bar ($args) {
                extract($args);                                                 // relaxed mode from here

                // internal functions
                preg_match("#pattern#", "string-to-be-mateched", $matches);     // ok
                preg_match("#pattern#", "string-to-be-mateched", null);         // error (non-var pass by ref)
                preg_grep("pattern", $input);                                   // warning
                sort($array);                                                   // warning
                sort( array(1,2,3) );                                           // error (non-var pass by ref)

                // locally-defined functions
                local_function_with_pass_by_reference_argument2(1, $var2);      // ok
                local_function_with_pass_by_reference_argument2($var3, $var4);  // warning in $var3 only

                // unknown functions
                unknown_function($unknown_var);                                 // warning
                unknown_function($unknown_var_in_expression*2);                 // warning
            }

            function local_function_with_pass_by_reference_argument2($arg1, &$arg2) {
                $arg2 = $arg1;
            }

        ';

        $expectedDefects = array(
            array('null',                   6,  XRef::ERROR),
            array('$input',                 7,  XRef::ERROR),
            array('$array',                 8,  XRef::ERROR),
            array('array',                  9,  XRef::ERROR),
            array('$var3',                  13, XRef::ERROR),
            array('$unknown_var',           16, XRef::WARNING),
            array('$unknown_var_in_expression', 17, XRef::ERROR),

            array('null',                   25,  XRef::ERROR),
            array('$input',                 26,  XRef::WARNING),
            array('$array',                 27,  XRef::WARNING),
            array('array',                  28,  XRef::ERROR),
            array('$var3',                  32, XRef::WARNING),
            array('$unknown_var',           35, XRef::WARNING),
            array('$unknown_var_in_expression', 36, XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $expectedDefects);
    }

    // test to check constructs like
    //  function foo () {
    //      static $foo, $bar = 1, $baz;
    //  }
    // as well as other static constructs
    public function testStaticDecl () {
        $testPhpCode = '
            <?php

            function foo () {
                static $foo;                    // ok
                static $bar, $baz;              // ok
                static $qux = 10, $qaz, $qix=1; // ok

                echo $foo, $bar + $baz;         // ok
                echo $qux, $qaz + $qix;         // ok
                echo $i;                        // error
            }

            class Bar {
                public function bar () {
                    if (is_subclass_of(static::$instance, "Foo")) { // ok
                        return static::baz();                       // ok
                    } else {
                        return new static(25);                      // ok
                    }
                    return new static;
                }
            }
            echo $foo;                          // error
        '
        ;
        $expectedDefects = array(
            array('$i',     11,  XRef::ERROR),
            array('$foo',   24,  XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $expectedDefects);
     }

    public function testNestedFunctions() {
        $testPhpCode = '
        <?php
            function foo($x) {
                $y = 10;                                // ok
                $f = function ($z) use ($x, & $y) {     // ok
                    return $z * ($x + $y);              // ok
                    echo $i;                            // error
                };
                echo $z;                                // error
                echo $i;                                // error
                echo $x, $y;                            // ok

                $g = function ($p) use (&$q) {          // error - no $q in outer scope
                    return $p * $q;                     // ok
                };
                echo $q;                                // ok, dont report $q twice
            }
        ';
        $expectedDefects = array(
            array('$i', 7,  XRef::ERROR),
            array('$z', 9,  XRef::ERROR),
            array('$i', 10,  XRef::ERROR),
            array('$q', 13,  XRef::ERROR),
        );
        $this->checkPhpCode($testPhpCode, $expectedDefects);
    }

    public function testRelaxedMode() {
        $testPhpCode = '
        <?php
            function foo($x) {
                echo $y;            // error
                $$x = "foo";        // relaxed mode starts here
                echo $z;            // warning
            }
            function bar() {
                global $i;
                echo $i;            // ok
                echo $j;            // error
                $$i["key"] = "foo"; // relaxed mode starts here
                echo $z;            // warning
            }
         ';
        $expectedDefects = array(
            array('$y', 4,  XRef::ERROR),
            array('$z', 6,  XRef::WARNING),
            array('$j', 11,  XRef::ERROR),
            array('$z', 13,  XRef::WARNING),
        );
        $this->checkPhpCode($testPhpCode, $expectedDefects);

    }

}
