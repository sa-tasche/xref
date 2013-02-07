<?php

/**
 * lib/bin-scripts/xref-lint.php
 *
 * This is a lint (a tool to find potential bugs in source code) for PHP sources.
 * This is a command-line version
 *
 * @author Igor Gariev <gariev@hotmail.com>
 * @copyright Copyright (c) 2013 Igor Gariev
 * @licence http://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
 */

$includeDir = ("@php_dir@" == "@"."php_dir@") ? dirname(__FILE__) . "/.." : "@php_dir@/XRef";
require_once("$includeDir/XRef.class.php");

// command-line arguments
list ($options, $arguments) = XRef::getCmdOptions();
if (XRef::needHelp()) {
    XRef::showHelpScreen(
        "xref-lint - tool to find problems in PHP source code",
        "$argv[0] [options] [path to check]"
    );
    exit(1);
}

$reportLevel = XRef::NOTICE;
$r = XRef::getConfigValue("lint.report-level", "notice");
if ($r == "errors" || $r == "error") {
    $reportLevel = XRef::ERROR;
} elseif ($r == "warnings" || $r == "warning") {
    $reportLevel = XRef::WARNING;
} elseif ($r == "notice") {
    $reportLevel = XRef::NOTICE;
} else {
    die("unknown error reporting level: $r");
}

$color = XRef::getConfigValue("lint.color", '');
if ($color=="auto") {
    $color = function_exists('posix_isatty') && posix_isatty(STDOUT);
}
$colorMap = array(
    "error"     => "\033[0;31m",
    "warning"   => "\033[0;33m",
    "notice"    => "\033[0;32m",
    "_off"      => "\033[0;0m",
);

$xref = new XRef();
$xref->loadPluginGroup("lint");
if (count($arguments)) {
    foreach ($arguments as $path) {
        $xref->addPath($path);
    }
} else {
    $xref->addPath(".");
}

$totalFiles         = 0;
$filesWithDefects   = 0;
$numberOfNotices    = 0;
$numberOfWarnings   = 0;
$numberOfErrors     = 0;

// main loop over all files
foreach ($xref->getFiles() as $filename => $ext) {
    try {
        $pf = $xref->getParsedFile( $filename, $ext );
        $report = $xref->getLintReport($pf);

        $totalFiles++;
        if (count($report)) {
            $filesWithDefects++;
            foreach ($report as $r) {
                if ($r->severity==XRef::NOTICE) {
                    $numberOfNotices++;
                } elseif ($r->severity==XRef::WARNING) {
                    $numberOfWarnings++;
                } elseif ($r->severity==XRef::ERROR) {
                    $numberOfErrors++;
                }
            }
        }

        if (count($report)) {
            echo("File: $filename\n");
            foreach ($report as $r) {
                $lineNumber     = $r->lineNumber;
                $tokenText      = $r->tokenText;
                $severityStr    = XRef::$severityNames[ $r->severity ];
                $line = sprintf("    line %4d: %-8s: %s (%s)", $lineNumber, $severityStr, $r->message, $tokenText);
                if ($color) {
                    $line = $colorMap{$severityStr} . $line . $colorMap{"_off"};
                }
                echo($line . "\n");
            }
        }

        $pf->release();
    } catch (Exception $e) {
        echo "Can't parse file '$filename':" . $e->getMessage() . "\n";
    }
}

// print total report
if (XRef::verbose()) {
    echo("Total files:          $totalFiles\n");
    echo("Files with defects:   $filesWithDefects\n");
    echo("Errors:               $numberOfErrors\n");
    echo("Warnings:             $numberOfWarnings\n");
    echo("Notices:              $numberOfNotices\n");
}

if ($numberOfErrors+$numberOfWarnings > 0) {
    exit(1);
} else {
    exit(0);
}


// vim: tabstop=4 expandtab

