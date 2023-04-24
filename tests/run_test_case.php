#!/usr/bin/env php
<?php
/**
 * ...
 */

$local_args = $argv;
$progname = array_shift($local_args);

$file = array_shift($local_args);

if ($file == "") {
  fprintf(STDERR, "Usage: %s <case-dir|case-file>\n", $progname);
  exit(1);
}

/**
 * ...
 *
 * ...
 */
function bail($message)
{
  fprintf(STDERR, "%s\n", $message);
  exit(1);
}

/**
 * ...
 *
 * ...
 */
function parse_test_data($data)
{
  $output = "";

  $lines = explode("\n", $data);
  foreach ($lines as $line) {
    $output .= str_replace(
        array("<.>", "<lf>"),
        array(" ", "\n"), $line);
  }

  return $output;
}

/**
 * ...
 *
 * @param string $file ...
 * @return array{
 *    name: string,
 *    ext: string,
 *    input: string,
 *    output: string} ...
 */
function parse_test_case_file($file)
{
  if (!preg_match('{^(.*/)?(.*)\.txt$}', $file, $regp))
    bail("Bad case data file name: $file");
  $name = $regp[2];

  $data = (string) @file_get_contents($file);

  $regexp ='{^' .
      ':ext=(txt|php)\n' .
      ':input\n' .
      '(.*)\n' .
      ':output\n' .
      '(.*)\n$}Us';
  if (!preg_match($regexp, $data, $regp))
    bail("Bad case data file contents: $file");

  return array(
    'name' => $name,
    'ext' => $regp[1],
    'input' => parse_test_data($regp[2]),
    'output' => parse_test_data($regp[3]));
}

function run_test_case_file($file)
{
  $case = parse_test_case_file($file);

  /* prepare the input data */
  $dir = "testrun-111";
  @mkdir($dir);
  $fname = sprintf("%s/%s.%s", $dir, $case['name'], $case['ext']);
  file_put_contents($fname, $case['input']);

  /* run the script */
  system("cd $dir; ../../bin/fixup_source_files.php apply");

  /* check the output */
  $actual = file_get_contents($fname);
  if ($actual !== $case['output']) {
    print "CASE FAILED\n";
  }
  else {
    print "CASE SUCCESS\n";
  }
}

function run_test_case_dir($dir)
{
  $cases = array();
  foreach (scandir($dir) as $file) {
    if (($file == ".") || ($file == ".."))
      continue;

    $pathfile = $dir . "/" . $file;
    $cases[] = parse_test_case_file($pathfile);
  }

  $rundir = "testrun-222";
  @mkdir($rundir);

  foreach ($cases as &$case) {
    /* prepare the input data */
    $fname = sprintf("%s/%s.%s", $rundir, $case['name'], $case['ext']);
    $case['_fname'] = $fname;
    if (!file_put_contents($fname, $case['input']))
      bail("Failed to create '$fname'");
  }
  unset($case);

  /* run the script */
  system("cd $rundir; ../../bin/fixup_source_files.php apply");

  /* check the output */
  $errors = 0;
  foreach ($cases as $case) {
    $actual = file_get_contents($case['_fname']);
    if ($actual !== $case['output']) {
      print "CASE " . $case['name'] . " FAILED\n";
      $errors++;
    }
    else {
      print "CASE " . $case['name'] . " SUCCESS\n";
    }
  }

  system("rm -rf $rundir");

  exit($errors ? 1 : 0);
}

echo "Running test case '$file' on php " . PHP_VERSION . "\n\n";
system("../bin/fixup_source_files.php -v");

run_test_case_dir($file);
