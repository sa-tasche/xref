<?php

$includeDir = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/.." : "@php_dir@/XRef";
require_once("$includeDir/XRef.class.php");

class TestParsers extends PHPUnit_Framework_TestCase {

    private $xref;

    public function __construct() {
        $this->xref = new XRef();
        $this->xref->addParser( new XRef_Parser_PHP() );
     }

    public function testBasicUsage() {
        $testPhpCode = '
            <?php
            class Foo {
                public function bar() {
                    return 42;
                }
            }
        ';
        $pf = $this->xref->getParsedFile("filename.php", "php", $testPhpCode);
        $classes = $pf->getClasses();
        $methods = $pf->getMethods();
        $this->assertTrue(count($classes)==1);
        $this->assertTrue($classes[0]->name=='Foo');
        $this->assertTrue(count($methods)==1);
        $this->assertTrue($methods[0]->name=='bar');

    }

    public function testCompatMode() {
        // make sure we are able to correctly parse PHP 5.3+ code even in PHP 5.2 run-time.
        // running this test in PHP 5.3 environment is redundant.

        // these constant must be defined by XRef main class if they are not defined in PHP itself.
        $this->assertTrue(defined("T_NAMESPACE"), "T_NAMESPACE");
        $this->assertTrue(defined("T_NS_SEPARATOR"), "T_NS_SEPARATOR");
        $this->assertTrue(defined("T_USE"), "T_USE");

        $testPhpCode =
        '
            <?php
            namespace foo\bar;
            function foo ($x) {
                $bar = function ($y) use ($x) { return $x*$y; }
                $bar(10);
            }
        ';
        $pf = $this->xref->getParsedFile("filename.php", "php", $testPhpCode);
        $tokens = $pf->getTokens();

        // checking "namespace" statement
        $is_namespace_found = false;
        foreach ($tokens as $t) {
            if ($t->kind==T_NAMESPACE) {
                $n = $t->next();
                $this->assertTrue($n->isSpace());
                $n = $n->next();
                $this->assertTrue($n->kind==T_STRING);
                $this->assertTrue($n->text=="foo");
                $n = $n->next();
                $this->assertTrue($n->kind==T_NS_SEPARATOR);
                $this->assertTrue($n->text=="\\");
                $n = $n->next();
                $this->assertTrue($n->kind==T_STRING);
                $this->assertTrue($n->text=="bar");
                $n = $n->next();
                $this->assertTrue($n->kind==XRef::T_ONE_CHAR);
                $this->assertTrue($n->text==";");
                $is_namespace_found = true;
                break;
            }
        }
        $this->assertTrue($is_namespace_found);

        // checking nested function statement
        $is_nested_function_found = false;
        foreach ($tokens as $t) {
            if ($t->kind==T_FUNCTION) {
                $n = $t->nextNS();
                while ($n->kind != T_FUNCTION) {
                    $n = $n->nextNS();
                }
                $n = $n->nextNS();
                $this->assertTrue($n->kind==XRef::T_ONE_CHAR);
                $this->assertTrue($n->text=="(");

                $n = $pf->getTokenAt( $pf->getIndexOfPairedBracket($n->index) );
                $this->assertTrue($n->kind==XRef::T_ONE_CHAR);
                $this->assertTrue($n->text==")");

                $n = $n->nextNS();
                $this->assertTrue($n->kind==T_USE);
                $is_nested_function_found = true;
                break;
            }
        }
        $this->assertTrue($is_nested_function_found);

    }
}