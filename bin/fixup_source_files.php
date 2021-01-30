#!/usr/bin/env php
<?php
/**
 * Generic source code linter
 */

define("VERSION", "0.3.3");

$WITH_DEBUG = (getenv("WITH_LINTER_DEBUG") != "");


/* ------------------------------------------------------------------------
 *    Common functions definitions
 * ------------------------------------------------------------------------ */

function bail($message)
{
  fprintf(STDERR, "%s\n\n", $message);
  exit(1);
}

function dbgf($message, $file)
{
  global $WITH_DEBUG;
  if ($WITH_DEBUG)
    printf("[d] %-30s %s\n", $message, $file);
}

function is_prefix($data, $prefix)
{
  return (substr(str_replace("\\", "/", $data), 0, strlen($prefix)) == $prefix);
}

function fixup_file($path, array $settings, $check_only = true)
{
  /* read the text file content */
  $data = $orig_data = file_get_contents($path);

  /* fixup eols */
  if (isset($settings['eol'])) {
    switch ($settings['eol']) {
    case 'lf':
      $data = str_replace(array("\r\n", "\r"), array("\n", "\n"), $data);
      break;
    case 'crlf':
      $data = str_replace(array("\r\n", "\r"), array("\n", "\n"), $data);
      $data = str_replace("\n", "\r\n", $data);
      break;
    default:
      throw new \Exception("Invalid 'eol' format setting");
    }
  }

  /* fixup trailing whitespace */
  if (isset($settings['ws'])) {
    switch ($settings['ws']) {
    case 'rtrim':
      $data = preg_replace('/[ \t]+(\n|$)/', "\n", $data);
      break;
    default:
      throw new \Exception("Invalid 'ws' format setting");
    }
  }

  /* manage encodings */
  if (isset($settings['charset']) && ($settings['charset'] != "")) {
    switch ($settings['charset']) {
    case 'ascii':
      $data = preg_replace_callback('/[\x80-\xff]/', function($m) {
          return sprintf("((?char:%02x))", ord($m[0]));
        }, $data);
      break;
    case 'utf8':
      mb_convert_variables('UTF-8', 'UTF-8', $data);
      break;
    default:
      throw new \Exception("Invalid 'charset' format setting");
    }
  }

  /* handle tabs behavior */
  if (isset($settings['tabs'])) {
    $tabs = $settings['tabs'];
    if ($tabs[0] == 'convert') {
      $data = str_replace("\t", str_repeat(" ", $tabs['width']), $data);
    }
    elseif ($tabs[0] == 'check') {
      /* i cannot have tabs after anything that's not a tab */
      $_tab_width = $tabs['width'];
      $_repl = str_repeat(" ", $tabs['width']);
      while (true) {
        $_datafix = preg_replace_callback('{^(\t*[^\t\n]+)(\t+)}m',
          function($m) use ($_tab_width) {
            if (substr($m[1], 0, 2) == "//")
              return $m[0];
            $_left_side = strlen(str_replace("\t", str_repeat(" ", $_tab_width), $m[1]));
            return $m[1] . str_repeat(" ", $_tab_width - ($_left_side % $_tab_width)) .
                str_repeat(" ", $_tab_width * (strlen($m[2]) - 1));
          }, $data);
        if ($_datafix == $data)
          break;
        $data = $_datafix;
      }
    }
  }

  /* finally, do the silly stuff. apply decorations */
  $settings['decors'] = (array) (isset($settings['decors']) ?
                                       $settings['decors'] : null);
  if (in_array("c-centered-dashes", $settings['decors'])) {
    $_re = '{^(/\*| \*)\s+-{4,}(?:\s+(.+?)\s+-{3,})?(\s+\*/)?$}m';
    $data = preg_replace_callback($_re,
      function($m) {
        if (!isset($m[2]) || ($m[2] == ""))
          return $m[1] . " " . /* ----------- */
              str_repeat("-", 72) .
              (!empty($m[3]) ? " */" : "");
        else
          return $m[1] . " " . /* --- xyz --- */
              str_repeat("-", (int) floor((70 - strlen($m[2])) / 2)) .
              " " . $m[2] . " " .
              str_repeat("-", (int) ceil((70 - strlen($m[2])) / 2)) .
              (!empty($m[3]) ? " */" : "");
      }, $data);
  }

  /* make sure there is exactly one EOL at the end */
  $data = rtrim($data, "\n") . "\n";

  if ($data != $orig_data) {
    if ($check_only)
      file_put_contents(sys_get_temp_dir() . DIRECTORY_SEPARATOR . "fixup-test-file", $data);
    else
      file_put_contents($path, $data);
    return true;
  }

  return false;
}


/* ------------------------------------------------------------------------
 *    Standard definitions
 * ------------------------------------------------------------------------ */

$StyleSettings = array(
    'source-c' => array(
        'eol' => 'lf',
        'ws' => 'rtrim',
        'charset' => 'ascii',
        'tabs' => array('check', 'width' => 8),
        'decors' => array('c-centered-dashes'),
    ),
    'source-php' => array(
        'eol' => 'lf',
        'ws' => 'rtrim',
        'tabs' => array('convert', 'width' => 2),
    ),
    'source-lua' => array(
        'eol' => 'lf',
        'ws' => 'rtrim',
    ),
    'source' => array(
        'eol' => 'lf',
        'ws' => 'rtrim',
        'charset' => '',
    ),
    'text' => array(
        'eol' => 'lf',
        'ws' => 'rtrim',
    ),
    'text-md' => array(
        'eol' => 'lf',
    ),
);

$FileExts = array(
    '.c'        => 'source-c',    // c (source)
    '.h'        => 'source-c',    // c (headers)
    '.php'      => 'source-php',  // php
    '.lua'      => 'source-lua',  // lua
    '.tpl'      => 'source',      // html (templates)
    '.html'     => 'source',      // html (plain)
    '.less'     => 'source',      // stylesheets
    '.css'      => 'source',      // stylesheets
    '.js'       => 'source',      // javascript
    '.sh'       => 'source',      // shell scripts
    '.sql'      => 'source',      // sql data
    '.htaccess' => 'text',        // .htaccess files
    '.txt'      => 'text',        // text (generic)
    '.md'       => 'text-md',     // text (markdown)
);

$IgnorePaths = array(
  ".git/",
  "_build/",
  "_build-",
  "_tests_coverage_output/",
  "vendor/",
  "vendor-deploy/",
  "vendor-debug/",
  "vendor-dev/",
);

$IgnoreFiles = array(
);


/* ------------------------------------------------------------------------
 *    Directory traversal
 * ------------------------------------------------------------------------ */

$local_args = $argv;
$progname = array_shift($local_args);

function print_usage()
{
  global $StyleSettings, $progname;

  fprintf(STDERR, "ThGnet source code fixer v%s\n", VERSION);
  fprintf(STDERR, "\n");
  fprintf(STDERR, "Usage: %s [-d] [-style <style> <value>] [-ext <ext> <style>] <check|apply>\n",
      $progname);
  fprintf(STDERR, "\n");
  fprintf(STDERR, "Builtin preconfigured styles:\n");
  fprintf(STDERR, "%s\n", json_encode($StyleSettings,
      JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  fprintf(STDERR, "\n");
  exit(1);
}

function parse_command_args_arg($opt_name, &$local_args, $type)
{
  switch ($type) {
  case 'string':
    $value = array_shift($local_args);
    if ($value == "")
      bail("Error: Invalid command line switch \"$opt_name\", expecting one value");
    $retval = array($value);
    break;

  case 'string-pair':
    $key = array_shift($local_args);
    if (($p = strpos($key, "=")) !== false) {
      $value = substr($key, $p + 1);
      $key = substr($key, 0, $p);
    }
    else {
      $value = array_shift($local_args);
    }
    if (($key == "") || ($value == ""))
      bail("Error: Invalid command line switch \"$opt_name\", expecting key/value pair");
    $retval = array($key, $value);
    break;
  }

  return $retval;
}

function parse_command_args(&$local_args)
{
  global $StyleSettings, $FileExts, $IgnorePaths, $IgnoreFiles, $WITH_DEBUG;

  while ($local_args && (substr($local_args[0], 0, 1) == "-")) {
    $opt_name = substr(array_shift($local_args), 1);
    if (substr($opt_name, 0, 1) == "-")
      $opt_name = substr($opt_name, 1);

    switch ($opt_name) {
    case "d":
      $WITH_DEBUG = true;
      break;

    case "ignore-path":
      list($_path) =
          parse_command_args_arg($opt_name, $local_args, 'string');
      $IgnorePaths[] = $_path;
      break;

    case "ignore-file":
      list($_file) =
          parse_command_args_arg($opt_name, $local_args, 'string');
      $IgnoreFiles[] = $_file;
      break;

    case "style":
      list($_style, $_value) =
          parse_command_args_arg($opt_name, $local_args, 'string-pair');
      $_context = &$StyleSettings;
      foreach (explode(".", $_style) as $_key) {
        if (!isset($_context[$_key]))
          bail("Error: Invalid style \"$_style\"");
        $_context = &$_context[$_key];
      }
      switch (gettype($_context)) {
      case 'string':
        $_context = $_value;
        break;
      case 'integer':
        if (!preg_match('/^-?\d+$/', $_value))
          bail("Error: Invalid value for style \"$_style\"");
        $_context = (int) $_value;
        break;
      default:
        bail("Error: Invalid style path \"$_style\"");
      }
      unset($_context);
      break;

    case "ext":
      list($_ext, $_style) =
          parse_command_args_arg($opt_name, $local_args, 'string-pair');
      if (!isset($StyleSettings[$_style]))
        bail("Error: Invalid style \"$_style\"");
      $FileExts[".$_ext"] = $_style;
      break;

    default:
      bail("Error: Invalid command line switch \"$opt_name\"");
    }
  }
}

function parse_conf_file()
{
  $entries = @file(".fixup-source-files.conf");
  if (!$entries)
    return;
  foreach ($entries as $idx => $entry) {
    $line = $idx + 1;
    $toks = preg_split('/\s+/', $entry);
    array_pop($toks);
    $toks[0] = "-" . $toks[0];
    parse_command_args($toks);
    if (count($toks))
      bail("Error: Invalid configuration file at line $line");
  }
}

parse_conf_file();

parse_command_args($local_args);

$action = array_shift($local_args);
switch ($action) {
case "check":
  $check_only = true;
  break;
case "apply":
  $check_only = false;
  break;
default:
  print_usage();
  exit(1);
}

$basepath = rtrim(array_shift($local_args), "/");
if ($basepath == "")
  $basepath = ".";

$dd = new \RecursiveDirectoryIterator($basepath);
$it = new \RecursiveIteratorIterator($dd, \RecursiveIteratorIterator::SELF_FIRST);

$prog_retval = 0;
$stats_count = 0;

foreach ($it as $ff) {
  /* disregard directories, we are looking for files */
  if ($ff->isDir())
    continue;

  $p = $ff->getPathname();

  $_ignore = "";
  foreach ($IgnorePaths as $ignore) {
    if (is_prefix($p, "$basepath/$ignore")) {
      $_ignore = "paths";
      break;
    }
  }
  foreach ($IgnoreFiles as $ignore) {
    if (fnmatch($ignore, $ff->getFilename()))
      $_ignore = "files";
  }
  if ($_ignore) {
    dbgf("ignoring ($_ignore)", $p);
    continue;
  }

  $ext = $ff->getExtension();

  /* determine the style to use */
  $style = (isset($FileExts[".$ext"]) ? $FileExts[".$ext"] : null);
  if (!$style) {
    dbgf("ignoring (no style)", $p);
    continue;
  }

  /* perform the processing */
  dbgf("processing as '$style'", $p);
  $retval = fixup_file($p, $StyleSettings[$style], $check_only);
  $stats_count++;

  if ($retval) {
    if ($check_only) {
      /* in check-only mode we output the differences found */
      print "\n\n!!! Bad text/source file $p\n\n";
      system("diff -u -L $p.orig -L $p.fix $p /tmp/fixup-test-file | head -n 50");
      $prog_retval = 1;
    }
    else {
      /* in normal mode we just warn the user that we modified it */
      print "FIXUP $p\n";
    }
  }

  /* finally, if the file is PHP, check for syntax */
  if ($style == "source-php") {
    $syntax_check_output = array();

    $PHP_WIN_OS_LIST = array(
      "WIN32",
      "WINNT",
      "Windows");

    if (in_array(PHP_OS, $PHP_WIN_OS_LIST)) {
      /* windows does not support output redirection */
      $command = "php -d log_errors=off -l $p";
    }
    else {
      /* use a generic POSIX command line */
      $command = "php -d log_errors=off -l $p 2>/dev/null";
    }

    exec($command, $syntax_check_output, $syntax_check_retval);
    if ($syntax_check_retval) {
      print "\n\n!!! Bad PHP syntax file $p\n";
      print trim(implode("\n", $syntax_check_output)) . "\n\n";
      $prog_retval = 1;
    }
  }
}

print "\n\nTotally processed for fixup $stats_count text/source files\n\n";

print ($prog_retval ? "Failure ($prog_retval)" : "Success") . "\n";

exit($prog_retval);
