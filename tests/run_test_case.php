#!/usr/bin/env php
<?php
/**
 * Executor for tests template files
 */

$local_args = $argv;
$progname = array_shift($local_args);

$opt_debug = false;
if (($k = array_search("-d", $local_args)) !== false) {
  $opt_debug = true;
  putenv("WITH_LINTER_DEBUG=true");
  array_splice($local_args, $k, 1);
}

$targets = $local_args;

if (!$targets) {
  fprintf(STDERR, "Usage: %s <case-dir|case-file>\n", $progname);
  exit(1);
}

/**
 * Prints a message to the standard error and exits
 *
 * @param string $message Error message
 * @return never
 */
function bail($message)
{
  fprintf(STDERR, "%s\n", $message);
  exit(1);
}

/**
 * ...
 *
 * @param string $message ...
 * @return void
 */
function dbgx($message)
{
  global $opt_debug;

  if (!$opt_debug)
    return;

  fprintf(STDERR, "[d] %s\n", $message);
}

/**
 * Parses a block of test data resolving tags
 *
 * @param string $data Test markup text data
 * @return string Parsed test text data
 */
function unwrap_test_data($data)
{
  $output = "";

  $lines = explode("\n", $data);
  foreach ($lines as $line) {
    $output .= (string) preg_replace_callback(
        '/<(\.|cr|crlf|lf|tab|[0-9a-f]{2})>/',
        function($m) {
          if ($m[1] == ".")
            return " ";
          if ($m[1] == "cr")
            return "\r";
          if ($m[1] == "crlf")
            return "\r\n";
          if ($m[1] == "lf")
            return "\n";
          if ($m[1] == "tab")
            return "\t";
          return (string) pack("H*", $m[1]);
        }, $line);
  }

  return $output;
}

/**
 * Parses a test case template file
 *
 * @param string $file Path to the file
 * @return list<array{
 *    name: string,
 *    ext: string,
 *    input: string,
 *    output: string}> Test case data
 */
function parse_test_case_file($file)
{
  if (!preg_match('{^(.*/)?(.*)\.txt$}', $file, $regp))
    bail("Bad case data file name: $file");
  $name = $regp[2];

  $data = (string) @file_get_contents($file);
  if ($data == "")
    return array();

  $data_chunks = explode("\n\n", $data);
  $retval = array();

  foreach ($data_chunks as $idx => $data) {
    $data = rtrim($data, "\n") . "\n";
    $regexp ='{^' .
        '(?:#.*\n)?' .
        ':ext=([0-9a-z-]+)\n' .
        ':input\n' .
        '(.*)\n' .
        ':output\n' .
        '(.*)\n$}Us';
    if (!preg_match($regexp, $data, $regp))
      bail("Bad case data file contents: $file (part $idx)");

    $retval[] = array(
      'name' => $name . "-" . ($idx + 1),
      'ext' => $regp[1],
      'input' => unwrap_test_data($regp[2]),
      'output' => unwrap_test_data($regp[3]));
  }

  return $retval;
}

/**
 * ...
 *
 * @param array<string> $targets ...
 * @return void
 */
function run_test_cases($targets)
{
  $cases = array();
  foreach ($targets as $target) {
    if (is_dir($target) && ($files = scandir($target))) {
      foreach ($files as $file) {
        if (($file == ".") || ($file == ".."))
          continue;

        $pathfile = $target . "/" . $file;
        foreach (parse_test_case_file($pathfile) as $case) {
          $cases[] = $case;
        }
      }
    }
    elseif (is_file($target)) {
      foreach (parse_test_case_file($target) as $case) {
        $cases[] = $case;
      }
    }
    else
      bail("Invalid target: " . $target);
  }

  printf("Running %d test case%s on php %s using:\n", count($cases),
      (count($cases) != 1 ? "s" : ""), PHP_VERSION);
  system("../bin/fixup_source_files.php -v");
  printf("\n");

  $rundir = "testrun-222";
  @mkdir($rundir);

  file_put_contents($rundir . "/.fixup-source-files.conf",
"
ext txt-eol-nil=txt-eol-nil
ext txt-eol-crlf=txt-eol-crlf
ext txt-eol-cr=txt-eol-cr
style txt-eol-crlf.eol=crlf
style txt-eol-cr.eol=cr

ext txt-eof-0=txt-eof-0
ext txt-eof-1=txt-eof-1
ext txt-eof-2=txt-eof-2
ext txt-eof-3=txt-eof-3
ext txt-eof-4=txt-eof-4
style txt-eof-0.eol=crlf
style txt-eof-1.eol=crlf
style txt-eof-2.eol=crlf
style txt-eof-3.eol=crlf
style txt-eof-4.eol=crlf
style txt-eof-1.eof=none
style txt-eof-2.eof=at-most-one
style txt-eof-3.eof=exactly-one
style txt-eof-4.eof=at-least-one

ext c-tabs-check=c-tabs-check
ext c-tabs-check-2=c-tabs-check-2
style c-tabs-check.tabs-mode=check
style c-tabs-check-2.tabs.0=check
style c-tabs-check-2.tabs.width=2

ext c-tabs-convert=c-tabs-convert
ext c-tabs-convert-2=c-tabs-convert-2
style c-tabs-convert.tabs-mode=convert
style c-tabs-convert-2.tabs.0=convert
style c-tabs-convert-2.tabs.width=2

ext c-tabs-indent=c-tabs-indent
style c-tabs-indent.tabs-mode=indent

ext txt-cs-ascii=txt-cs-ascii
ext txt-cs-iso-8859-1=txt-cs-iso-8859-1
ext txt-cs-windows-1252=txt-cs-windows-1252
ext txt-cs-utf-8=txt-cs-utf-8
style txt-cs-ascii.charset=ascii
style txt-cs-iso-8859-1.charset=iso-8859-1
style txt-cs-windows-1252.charset=windows-1252
style txt-cs-utf-8.charset=utf-8

ext json-style-1=json-style-1
ext json-style-2=json-style-2
ext json-style-3=json-style-3
ext json-style-4=json-style-4
style json-style-1.syntax=json
style json-style-2.syntax=json
style json-style-3.syntax=json
style json-style-4.syntax=json
style json-style-1.indent-style=json-minimal
style json-style-2.indent-style=json-inline
style json-style-3.indent-style=json-php-pretty-print
style json-style-4.indent-style=json-proper
style json-style-4.indent-width=2
style json-style-4.eof=exactly-one
");

  foreach ($cases as &$case) {
    /* prepare the input data */
    $fname = sprintf("%s/%s.%s", $rundir, $case['name'], $case['ext']);
    dbgx("producing case '$fname'");
    $case['_fname'] = $fname;
    if (!file_put_contents($fname, $case['input']))
      bail("Failed to create '$fname'");
  }
  unset($case);
  /**
   * @var array<array{
   *    name: string,
   *    ext: string,
   *    input: string,
   *    output: string,
   *    _fname: string}> $cases
   */

  /* run the script */
  print "------------------ [ EXEC START ] ------------------\n";
  system("cd $rundir; ../../bin/fixup_source_files.php --force apply", $execresult);
  print "------------------ [ EXEC END $execresult ] ------------------\n\n";

  $expectresult = 0;
  if ($execresult != $expectresult)
    bail("FAILED: Exit code is " . $execresult . " expected " . $expectresult);

  /* check the results and generate output */
  $ctl_s_r = "\x1b[31m";
  $ctl_s_r1 = "\x1b[31;1m";
  $ctl_s_g = "\x1b[32m";
  $ctl_s_g1 = "\x1b[32;1m";
  $ctl_s_b = "\x1b[33m";
  $ctl_s_y1 = "\x1b[33;1m";
  $ctl_e = "\x1b[m";
  $dump = function($str) {
    return rtrim(preg_replace_callback('/\r\n|\r|\n|\t|[\x00-\x1f]/', function($m) {
      if ($m[0] == "\r\n")
        return "<crlf>\n";
      if ($m[0] == "\n")
        return "<lf>\n";
      if ($m[0] == "\r")
        return "<cr>\n";
      if ($m[0] == "\t")
        return "<tab>";
      return "<" . bin2hex($m[0]) . ">";
    }, $str));
  };
  $errors = 0;
  foreach ($cases as $case) {
    $actual = file_get_contents($case['_fname']);
    if (($case['name'] == "charset-utf-8-1") && (PHP_VERSION_ID < 70000)) {
      print "*** CASE " . $case['name'] . " *** " . $ctl_s_y1 . "SKIPPED" . $ctl_e . "\n";
    }
    elseif ($actual !== $case['output']) {
      print "*** CASE " . $case['name'] . " *** " . $ctl_s_r1 . "FAILED" . $ctl_e . "\n";
      print $ctl_s_b . "--- INPUT ---\n" .
          $ctl_s_g . $dump($case['input']) . $ctl_e . "\n";
      print $ctl_s_b . "--- EXPECTED ---\n" .
          $ctl_s_r . $dump($case['output']) . $ctl_e . "\n";
      print $ctl_s_b . "--- ACTUAL ---\n" .
          $ctl_s_r . $dump($actual) . $ctl_e . "\n";
      $errors++;
    }
    else {
      print "*** CASE " . $case['name'] . " *** " . $ctl_s_g1 . "SUCCESS" . $ctl_e . "\n";
    }
  }

  system("rm -rf $rundir");

  exit($errors ? 1 : 0);
}

run_test_cases($targets);
