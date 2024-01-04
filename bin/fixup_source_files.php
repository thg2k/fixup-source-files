#!/usr/bin/env php
<?php
/**
 * Generic source code linter
 */

define("VERSION", "0.5.1");

$WITH_DEBUG = (getenv("WITH_LINTER_DEBUG") != "");


/* ------------------------------------------------------------------------
 *    Common functions definitions
 * ------------------------------------------------------------------------ */

/**
 * Terminates the execution with an error
 *
 * @param string $message Error message
 * @param 'USAGE'|'CONFIG'|null $type ...
 * @return never
 */
function bail($message, $type = null)
{
  fprintf(STDERR, "Error: %s\n\n", $message);

  switch ($type) {
  case 'USAGE':
    $code = 64;
    break;

  case 'CONFIG':
    $code = 78;
    break;

  default:
    $code = 1;
  }

  exit($code);
}

/**
 * Outputs a debug message if debugging is globally enabled
 *
 * @param string $message Debug message
 * @return void
 */
function dbg($message)
{
  global $WITH_DEBUG;
  if ($WITH_DEBUG)
    fprintf(STDERR, "[d] %s\n", $message);
}

/**
 * Outputs a formatted debug message if debugging is globally enabled
 *
 * @param string $message Debug message
 * @param string $file File reference
 * @return void
 */
function dbgf($message, $file)
{
  global $WITH_DEBUG;
  if ($WITH_DEBUG)
    fprintf(STDERR, "[d] %-30s %s\n", $message, $file);
}


/* ------------------------------------------------------------------------
 *    Syntax module
 * ------------------------------------------------------------------------ */

/**
 * ...
 *
 * @param string $file ...
 * @return array{
 *    valid: bool,
 *    errors: list<string>} ...
 */
function syntax_php_check_file($file)
{
  $syntax_check_output = array();

  $PHP_WIN_OS_LIST = array(
    "WIN32",
    "WINNT",
    "Windows");

  if (in_array(PHP_OS, $PHP_WIN_OS_LIST)) {
    /* windows does not support output redirection */
    $command = "php -d log_errors=off -l $file";
  }
  else {
    /* use a generic POSIX command line */
    $command = "php -d log_errors=off -l $file 2>/dev/null";
  }

  exec($command, $syntax_check_output, $syntax_check_retval);

  $valid = true;
  if ($syntax_check_retval) {
    // print "\n\n!!! Bad PHP syntax file $file\n";
    // print trim(implode("\n", $syntax_check_output)) . "\n\n";
    $valid = false;
  }
  else {
    $syntax_check_output = array();
  }

  $retval = array(
    'valid' => $valid,
    'errors' => $syntax_check_output);

  return $retval;
}
// syntax_php_check_file("tests/samples-syntax/php-bad-file.xphp"); exit();
// var_dump(syntax_php_check_file("tests/samples-syntax/php-bad-file.xphp")); exit();
// var_dump(syntax_php_check_file("bin/fixup_source_files.php")); exit();

/**
 * ...
 *
 * @param string $data ...
 * @return array{
 *    token: mixed,
 *    error: ?string} ...
 */
function syntax_json_parse($data)
{
  $len = strlen($data);
  $ptr = 0;

  $skipws = function() use ($data, $len, &$ptr) {
    while (($ptr < $len) && (strchr(" \n\r\t", $data[$ptr]) !== false)) {
      $ptr++;
    }
  };

  $json_value = function() use ($data, $len, &$ptr, &$json_value, &$skipws) {
    $skipws();
    if ($data[$ptr] == '"') {
      /* string value */
      $start = $ptr++;
      $escaped = false;
      while ($escaped || ($data[$ptr] != '"')) {
        $escaped = ($escaped ? false : $data[$ptr] == '\\');
        if (++$ptr >= $len)
          throw new \Exception("Unterminated string");
      }
      $value = substr($data, $start, ++$ptr - $start);
    }
    elseif ($data[$ptr] == '[') {
      /* array value */
      $ptr++;
      $value = array('[]', array());
      $skipws();
      if (($ptr < $len) && ($data[$ptr] != ']')) {
        while (true) {
          $value[1][] = $json_value();
          if (($ptr < $len) && ($data[$ptr] == ']'))
            break;
          if (($ptr >= $len) || ($data[$ptr++] != ','))
            throw new \Exception("Expected array ','");
        }
      }
      if (($ptr >= $len) || ($data[$ptr++] != ']'))
        throw new \Exception("Unterminated array");
    }
    elseif ($data[$ptr] == '{') {
      /* object value */
      $ptr++;
      $value = array('{}', array());
      $skipws();
      if (($ptr < $len) && ($data[$ptr] != '}')) {
        while (true) {
          $skipws();
          if (($ptr >= $len) || ($data[$ptr] != '"'))
            throw new \Exception("Expected key '\"'");
          $start = $ptr++;
          $escaped = false;
          while ($escaped || ($data[$ptr] != '"')) {
            $escaped = ($escaped ? false : $data[$ptr] == '\\');
            if (++$ptr >= $len)
              throw new \Exception("Unclosed key string");
          }
          $key = substr($data, $start, ++$ptr - $start);
          $skipws();
          if (($ptr >= $len) || ($data[$ptr++] != ':'))
            throw new \Exception("Expected key ':'");
          $value[1][$key] = $json_value();
          if (($ptr < $len) && ($data[$ptr] == '}'))
            break;
          /* @phpstan-ignore-next-line (bug #10368) */
          if (($ptr >= $len) || ($data[$ptr++] != ','))
            throw new \Exception("Expected object ','");
        }
      }
      if (($ptr >= $len) || ($data[$ptr++] != '}'))
        throw new \Exception("Unterminated object");
    }
    elseif (($size = strspn($data, "+-0123456789Ee", $ptr)) > 0) {
      /* number value */
      $value = substr($data, $ptr, $size);
      $ptr += $size;
    }
    elseif (substr($data, $ptr, 4) == 'null') {
      $value = substr($data, $ptr, 4);
      $ptr += 4;
    }
    elseif (substr($data, $ptr, 4) == 'true') {
      $value = substr($data, $ptr, 4);
      $ptr += 4;
    }
    elseif (substr($data, $ptr, 5) == 'false') {
      $value = substr($data, $ptr, 5);
      $ptr += 5;
    }
    else
      throw new \Exception("Unknown character '" . $data[$ptr] . "'");
    $skipws();
    return $value;
  };

  try {
    $token = $json_value();
    $error = null;
  }
  catch (\Exception $e) {
    $token = false;
    $error = "Syntax error: " . $e->getMessage();
  }

  $result = array(
    'token' => $token,
    'error' => $error);

  return $result;
}
// var_dump(syntax_json_parse('"ciao"')); exit();
// var_dump(syntax_json_parse('"abc\\"123"')); exit();
// var_dump(syntax_json_parse('[]')); exit();
// var_dump(syntax_json_parse('[1, 2, 3]')); exit();
// var_dump(syntax_json_parse('{}')); exit();
// var_dump(syntax_json_parse('{"a":1}')); exit();
// var_dump(syntax_json_parse('{"a":1,"\\"b":2}')); exit();
// var_dump(syntax_json_parse('[ { "a": 1 }, {} ]')); exit();

/**
 * ...
 *
 * @param mixed $token ...
 * @param ?array{
 *    spacer?: ?string,
 *    inline-empty?: ?bool} $options ...
 * @return string ...
 */
function syntax_json_format($token, $options = null)
{
  if ($options === null)
    $options = array();

  if (isset($options['spacer'])) {
    $spacer = (isset($options['spacer']) ? $options['spacer'] : "");
    $nl = "\n";
    $pad = " ";
    $min = empty($options['proper']);
  }
  else {
    $spacer = "";
    if (empty($options['proper'])) {
      $nl = "";
      $pad = "";
      $min = true;
    }
    else {
      $nl = " ";
      $pad = " ";
      $min = true;
    }
  }

  $json_value = function($token, $stack) use ($spacer, $nl, $pad, $min, &$json_value) {
    $ss = ($stack > 0 ? str_repeat($spacer, $stack) : "");
    if (is_string($token)) {
      $retval = $token;
    }
    else {
      $retval = $token[0][0];
      $sep = $nl;
      foreach ($token[1] as $key => $subtoken) {
        $retval .= $sep . $ss . $spacer .
            ($token[0][0] == "{" ? $key . ":" . $pad : "") .
            $json_value($subtoken, $stack + 1);
        $sep = "," . $nl;
      }
      $retval .= ($token[1] || !$min ? $nl . $ss : "") . $token[0][1];
    }
    return $retval;
  };

  $retval = $json_value($token, 0);

  return $retval;
}
// print syntax_json_format('"ciao"') . "\n"; exit();
// print syntax_json_format(array('[]', array('1', '2', '3'))) . "\n"; exit();
// print syntax_json_format(array('[]', array('1', '2', '3')), array('spacer' => "  ")) . "\n"; exit();
// print syntax_json_format(array('{}', array('"a"' => '1'))) . "\n"; exit();
// print syntax_json_format(array('{}', array('"a"' => '1', '"b"' => '2')), array('spacer' => "  ")) . "\n"; exit();
// print syntax_json_format(array('[]', array(array('{}', array('"a"' => '1')), array('{}', array()))), array('spacer' => "  ", 'proper' => true)) . "\n"; exit();


/* ------------------------------------------------------------------------
 *    Main module
 * ------------------------------------------------------------------------ */

/**
 * Checks if a path contains a given prefix
 *
 * @param string $path Path to check
 * @param string $prefix Prefix to check
 * @return bool TRUE if path has the given prefix, FALSE otherwise
 */
function is_prefix($path, $prefix)
{
  return (substr(str_replace("\\", "/", $path), 0, strlen($prefix)) == $prefix);
}

/**
 * ...
 *
 * @param string $file ...
 * @param array{
 *    eol?: ?string,
 *    eof?: ?string,
 *    ws?: ?string,
 *    charset?: ?string,
 *    tabs-mode?: ?string,
 *    tabs-width?: ?string,
 *    indent-style?: ?string,
 *    indent-width?: ?string,
 *    indent-char?: ?string,
 *    decor-comments?: ?string,
 *    syntax?: ?string} $settings ...
 * @return array{
 *    garbled: bool,
 *    fixed: bool,
 *    error: ?string,
 *    data: string} ...
 */
function fixup_file($file, array $settings)
{
  /* read the file data */
  $data = $orig_data = file_get_contents($file);
  if ($data === false)
    throw new \Exception("Failed to read file '$file'");

  /** @var bool */
  $garbled = false;
  $syntax_error = null;

  /* fixup eols */
  $eol = null;
  if (isset($settings['eol'])) {
    switch ($settings['eol']) {
    case 'lf':
      $data = str_replace(array("\r\n", "\r"), array("\n", "\n"), $data);
      $eol = "\n";
      break;
    case 'crlf':
      $data = str_replace(array("\r\n", "\r"), array("\n", "\n"), $data);
      $data = str_replace("\n", "\r\n", $data);
      $eol = "\r\n";
      break;
    case 'cr':
      $data = str_replace(array("\r\n", "\r"), array("\n", "\n"), $data);
      $data = str_replace("\n", "\r", $data);
      $eol = "\r";
      break;
    default:
      throw new \Exception("Invalid 'eol' format setting");
    }
  }

  /* fixup trailing whitespace */
  if (isset($settings['ws'])) {
    switch ($settings['ws']) {
    case 'rtrim':
      $data = (string) preg_replace('/[ \t]+(\n|$)/', "\n", $data);
      break;
    default:
      throw new \Exception("Invalid 'ws' format setting");
    }
  }

  /* manage encodings */
  if (!isset($settings['charset']))
    $settings['charset'] = "";
  if ($settings['charset'] != "") {
    $_charset_error = false;
    switch ($settings['charset']) {
    case 'ascii':
      $data = (string) preg_replace_callback(
          '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f\x80-\xff]/',
          function($m) use (&$_charset_error) {
            $_charset_error = true;
            return sprintf("((?char:%02x))", ord($m[0]));
          }, $data);
      break;
    case 'iso-8859-1':
    case 'iso8859-1':
    case 'iso-88591':
    case 'iso88591':
      $data = (string) preg_replace_callback(
          '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f-\x9f]/',
          function($m) use (&$_charset_error) {
            $_charset_error = true;
            return sprintf("((?char:%02x))", ord($m[0]));
          }, $data);
      break;
    case 'windows-1252':
    case 'windows1252':
      $data = (string) preg_replace_callback(
          '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f\x81\x8d\x8f\x90\x9d]/',
          function($m) use (&$_charset_error) {
            $_charset_error = true;
            return sprintf("((?char:%02x))", ord($m[0]));
          }, $data);
      break;
    case 'utf-8':
    case 'utf8':
      mb_convert_variables('UTF-8', 'UTF-8', $data);
      $data = (string) preg_replace_callback(
          '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',
          function($m) use (&$_charset_error) {
            $_charset_error = true;
            return sprintf("((?char:%02x))", ord($m[0]));
          }, $data);
      break;
    default:
      throw new \Exception("Invalid 'charset' format setting");
    }
    if ($_charset_error && !$garbled) {
      $garbled = true;
      $syntax_error = "Incorrect '" . $settings['charset'] . "' encoding";
    }
  }

  /* handle tabs behavior */
  if (isset($settings['tabs-mode'])) {
    $_tabs_mode = (string) $settings['tabs-mode'];
    $_tabs_width = (int) (isset($settings['tabs-width']) ?
        $settings['tabs-width'] : 8);
    if ($_tabs_width < 1)
      throw new \Exception("Invalid 'tabs-width' format setting");
    if ($_tabs_mode == 'convert') {
      $_data_lines = explode("\n", $data);
      for ($ll = 0; $ll < count($_data_lines); $ll++) {
        /* repeat until there is nothing else to change */
        while (true) {
          /* match the first tab and replaced it with aligned spaces */
          $_data_line_fix = (string) preg_replace_callback('{^([^\t]*)\t}',
            function($m) use ($_tabs_width) {
              return $m[1] . str_repeat(" ", $_tabs_width - (strlen($m[1]) % $_tabs_width));
            }, $_data_lines[$ll]);
          if ($_data_lines[$ll] == $_data_line_fix)
            break;
          $_data_lines[$ll] = $_data_line_fix;
        }
      }
      $data = implode("\n", $_data_lines);
      unset($_data_lines, $_data_line_fix);
    }
    elseif ($_tabs_mode == 'check') {
      /* i cannot have tabs after anything that's not a tab */
      $_repl = str_repeat(" ", $_tabs_width);
      while (true) {
        $_data_line_fix = (string) preg_replace_callback('{^(\t*[^\t\n]+)(\t+)}m',
          function($m) use ($_tabs_width) {
            if (substr($m[1], 0, 2) == "//")
              return $m[0];
            $_left_side = strlen(str_replace("\t", str_repeat(" ", $_tabs_width), $m[1]));
            return $m[1] . str_repeat(" ", $_tabs_width - ($_left_side % $_tabs_width)) .
                str_repeat(" ", $_tabs_width * (strlen($m[2]) - 1));
          }, $data);
        if ($_data_line_fix == $data)
          break;
        $data = $_data_line_fix;
      }
      unset($_data_line_fix);
    }
  }

  /* finally, do the silly stuff. apply decorations */
  if (!isset($settings['decor-comments']))
    $settings['decor-comments'] = "";
  if ($settings['decor-comments'] != "") {
    $_decors = explode(",", $settings['decor-comments']);
    if (in_array("c-centered-dashes", $_decors)) {
      $_re = '{^(/\*| \*)\s+-{4,}(?:\s+(.+?)\s+-{3,})?(\s+\*/)?$}m';
      $data = (string) preg_replace_callback($_re,
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
  }

  $parsed_data = null;

  /* now it's time to verify the syntax */
  if (!isset($settings['syntax']))
    $settings['syntax'] = "";
  if (($settings['syntax'] != "") && !$garbled) {
    switch ($settings['syntax']) {
    case 'php':
      $result = syntax_php_check_file($file);
      if (!$result['valid']) {
        $garbled = true;
        $syntax_error = "Bad PHP syntax file $file\n" .
            trim(implode("\n", $result['errors'])) . "\n";
      }
      break;

    case 'json':
      /* first use the internal php engine, as we do not check for strict
       * validity in our custom parsing facility */
      $_result = json_decode($data);
      if (json_last_error() != JSON_ERROR_NONE) {
        $garbled = true;
        $syntax_error = "Bad JSON syntax file $file\n";
      }
      else {
        /* second, use our own internal facility for double-checking and
         * preparing to optional indent formatting */
        $_result = syntax_json_parse($data);
        if ($_result['token'] === false) {
          $garbled = true;
          $syntax_error = $_result['error'];
        }
        else
          $parsed_data = $_result['token'];
      }
      unset($_result);
      break;

    default:
      $garbled = true;
    }
  }

  /* apply the indent formatting */
  if (!isset($settings['indent-style']))
    $settings['indent-style'] = "";
  if (!isset($settings['indent-width']))
    $settings['indent-width'] = "";
  if (!isset($settings['indent-char']))
    $settings['indent-char'] = "";
  if (($settings['indent-style'] != "") && !$garbled) {
    $spacer = str_repeat(
        ($settings['indent-char'] == 'tab' ? "\t" : " "),
        ($settings['indent-width'] > 0 ? (int) $settings['indent-width'] : 4));
    switch ($settings['syntax'] . ":" . $settings['indent-style']) {
    case 'json:json-minimal':
      $data = syntax_json_format($parsed_data);
      break;
    case 'json:json-inline':
      $data = syntax_json_format($parsed_data, array(
        'proper' => true));
      break;
    case 'json:json-php-pretty-print':
      $data = syntax_json_format($parsed_data, array(
        'spacer' => $spacer));
      break;
    case 'json:json-proper':
      $data = syntax_json_format($parsed_data, array(
        'spacer' => $spacer,
        'proper' => true));
      break;
    default:
      throw new \Exception("Invalid 'syntax' format setting");
    }
  }

  /* make sure there is exactly one EOL at the end */
  if (isset($settings['eof']) && ($settings['eof'] != "")) {
    if ($eol === null)
      $eol = "\n";
    switch ($settings['eof']) {
    case "none":
      $data = rtrim($data, "\r\n");
      break;

    case "at-most-one":
      if (substr($data, -strlen($eol)) == $eol)
        $data = rtrim($data, "\r\n") . $eol;
      break;

    case "exactly-one":
      $data = rtrim($data, "\r\n") . $eol;
      break;

    case "at-least-one":
      if (substr($data, -strlen($eol)) != $eol)
        $data .= $eol;
      break;
    }
  }

  $result = array(
    'garbled' => $garbled,
    'fixed' => ($data != $orig_data),
    'error' => $syntax_error,
    'data' => $data);

  return $result;
}


/* ------------------------------------------------------------------------
 *    Standard definitions
 * ------------------------------------------------------------------------ */

$StyleTypes = array(
  'eol' => 'cr|crlf|lf',
  'eof' => 'at-least-one|at-most-one|exactly-one|none',
  'ws' => 'rtrim',
  'charset' => 'ascii|iso-?8859-?1|windows-?1252|utf-?8',
  'tabs-mode' => 'check|convert',
  'tabs-width' => '\d+',
  'indent-style' => 'json-(inline|minimal|php-pretty-print|proper)',
  'indent-width' => '\d+',
  'indent-char' => 'tab|space',
  'decor-comments' => '((c-centered-dashes)(,|$))*',
  'syntax' => 'json|php',
);

$StyleSettings = array(
  'source' => array(
    'eol' => 'lf',
    'eof' => 'exactly-one',
    'ws' => 'rtrim',
  ),
  'source-c' => array(
    'eol' => 'lf',
    'eof' => 'exactly-one',
    'ws' => 'rtrim',
    'charset' => 'ascii',
    'tabs-mode' => 'check',
    'tabs-width' => '8',
    'decor-comments' => 'c-centered-dashes',
  ),
  'source-php' => array(
    'eol' => 'lf',
    'eof' => 'exactly-one',
    'ws' => 'rtrim',
    'tabs-mode' => 'convert',
    'tabs-width' => '2',
    'syntax' => 'php',
  ),
  'source-lua' => array(
    'eol' => 'lf',
    'eof' => 'exactly-one',
    'ws' => 'rtrim',
  ),
  'text' => array(
    'eol' => 'lf',
    'eof' => 'exactly-one',
    'ws' => 'rtrim',
  ),
  'text-md' => array(
    'eol' => 'lf',
    'eof' => 'at-least-one',
  ),
  'text-json' => array(
    'eol' => 'lf',
    'eof' => 'at-most-one',
    'ws' => 'rtrim',
    'syntax' => 'json',
  ),
  'text-json-composer' => array(
    'eol' => 'lf',
    'eof' => 'exactly-one',
    'ws' => 'rtrim',
    'syntax' => 'json',
    'indent-style' => 'json-proper',
  ),
);

$FileExts = array(
  '.c'        => 'source-c',    // c (source)
  '.h'        => 'source-c',    // c (headers)
  '.cpp'      => 'source-c',    // c++ (source)
  '.hpp'      => 'source-c',    // c++ (headers)
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
  '.json'     => 'text-json',   // text (json)
);

$FileNames = array(
  'composer.json' => 'text-json-composer',
);

$IgnorePaths = array(
  ".svn/",
  ".git/",
  "_build/",
  "_build-",
  "_tests_coverage_output/",
  "node-modules/",
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

$WITH_FORCE = false;

/**
 * Outputs the version header information to the given stream
 *
 * @param resource $fd Output stream
 * @return void
 */
function print_version($fd)
{
  fprintf($fd, "ThGnet source code fixer v%s\n", VERSION);
}

/**
 * Outputs the usage information to the given stream
 *
 * @param resource $fd Output stream
 * @return void
 */
function print_usage($fd)
{
  global $StyleSettings, $FileExts, $FileNames, $progname;

  print_version($fd);

  fprintf($fd, "\n");
  fprintf($fd, "Usage: %s " .
      "[-d] [-style <style> <value>] [-ext <ext> <style>] " .
      "<check|apply> [paths...]\n",
      $progname);

  fprintf($fd, "\n");
  fprintf($fd, "Configured styles:\n");
  $_style_exts = array();
  foreach ($StyleSettings as $style => $settings) {
    $_styles = array();
    foreach ($settings as $k => $v) {
      $_styles[] = "$k:$v";
    }
    $_style_exts[$style] = array();
    fprintf($fd, "  %-24s (%s)\n", $style, ($_styles ? implode(" ", $_styles) : "-"));
  }

  fprintf($fd, "\n");
  fprintf($fd, "Configured file types:\n");
  foreach ($FileExts as $ext => $style) {
    assert(isset($_style_exts[$style]));
    $_style_exts[$style][] = "*" . $ext;
  }
  foreach ($FileNames as $name => $style) {
    assert(isset($_style_exts[$style]));
    $_style_exts[$style][] = $name;
  }
  foreach ($_style_exts as $style => $exts) {
    fprintf($fd, "  %-24s %s\n", $style . ":", implode(" ", $exts));
  }
  fprintf($fd, "\n");
}

/**
 * ...
 *
 * @param list<string> $local_args ...
 * @param bool $is_config ...
 * @return void
 */
function parse_command_args(&$local_args, $is_config = false)
{
  global $StyleTypes, $StyleSettings;
  global $FileExts, $FileNames;
  global $IgnorePaths, $IgnoreFiles;
  global $WITH_DEBUG, $WITH_FORCE;

  $ec = ($is_config ? 'CONFIG' : 'USAGE');

  $parse_arg_string = function($opt_name) use ($ec, &$local_args) {
    $value = (string) array_shift($local_args);
    if ($value == "")
      bail("Invalid command line switch \"$opt_name\", expected one value", $ec);
    return array($value);
  };

  $parse_arg_string_pair = function($opt_name) use ($ec, &$local_args) {
    $key = (string) array_shift($local_args);
    if (($p = strpos($key, "=")) !== false) {
      $value = substr($key, $p + 1);
      $key = substr($key, 0, $p);
    }
    else {
      $value = (string) array_shift($local_args);
    }
    if (($key == "") || ($value == ""))
      bail("Invalid command line switch \"$opt_name\", expected key/value pair", $ec);
    return array($key, $value);
  };

  while ($local_args && (substr($local_args[0], 0, 1) == "-")) {
    $opt_name = substr(array_shift($local_args), 1);

    /* accept both "-<option>" and "--<option>" */
    if (substr($opt_name, 0, 1) == "-")
      $opt_name = substr($opt_name, 1);

    switch ($opt_name) {
    case "v":
      print_version(STDOUT);
      exit(0);

    case "h":
    case "help":
      print_usage(STDOUT);
      exit(0);

    case "d":
      $WITH_DEBUG = true;
      break;

    case "ignore-path":
      list($_path) = $parse_arg_string($opt_name);
      $IgnorePaths[] = $_path;
      break;

    case "ignore-file":
      list($_file) = $parse_arg_string($opt_name);
      $IgnoreFiles[] = $_file;
      break;

    case "minimal":
      // @deprecated Will change behavior on 2.x
      foreach (array_keys($FileExts) as $_ext) {
        if (($_pos = strpos($FileExts[$_ext], "-")) !== false)
          $FileExts[$_ext] = substr($FileExts[$_ext], 0, $_pos);
      }
      $FileNames = array();
      // foreach (array_keys($StyleSettings) as $_style) {
        // foreach (array_keys($StyleSettings[$_style]) as $_style_key) {
          // if (!in_array($_style_key, array('eol', 'eof', 'ws')))
            // unset($StyleSettings[$_style][$_style_key]);
        // }
      // }
      break;

    case "force":
      $WITH_FORCE = true;
      break;

    case "style":
      list($_style, $_value) = $parse_arg_string_pair($opt_name);
      dbg("Applying style '$_style' = '$_value'");

      /* verify the style syntax */
      if (!preg_match('/^([0-9a-z-]+)\.([0-9a-z.-]+)$/', $_style, $regp))
        bail("Invalid style \"$_style\"", $ec);

      /* backward compatibility (will be dropped for next major version) */
      // @deprecated Will emit on 1.x and be removed on 2.x
      if ($regp[2] == "tabs.0")
        $regp[2] = "tabs-mode";
      if ($regp[2] == "tabs.width")
        $regp[2] = "tabs-width";
      if ($regp[2] == "decors.0")
        $regp[2] = "decor-comments";

      if (!isset($StyleTypes[$regp[2]]))
        bail("Invalid style \"$_style\"", $ec);

      /* check that the style value is valid */
      if (!preg_match('/^(' . $StyleTypes[$regp[2]] . ')$/', $_value))
        bail("Invalid value \"$_value\" for style \"$_style\"", $ec);

      if (!isset($StyleSettings[$regp[1]]))
        $StyleSettings[$regp[1]] = array();
      $StyleSettings[$regp[1]][$regp[2]] = $_value;
      break;

    case "ext":
      list($_ext, $_style) = $parse_arg_string_pair($opt_name);
      if (!preg_match('/^([0-9a-z-]+)$/', $_style))
        bail("Invalid style \"$_style\"", $ec);
      if (!isset($StyleSettings[$_style]))
        $StyleSettings[$_style] = array();
      $FileExts[".$_ext"] = $_style;
      break;

    case "name":
      list($_name, $_style) = $parse_arg_string_pair($opt_name);
      if (!preg_match('/^([0-9a-z-]+)$/', $_style))
        bail("Invalid style \"$_style\"", $ec);
      if (!isset($StyleSettings[$_style]))
        $StyleSettings[$_style] = array();
      $FileNames[$_name] = $_style;
      break;

    default:
      bail("Invalid command line switch \"$opt_name\"", $ec);
    }
  }
}

/**
 * ...
 *
 * @return void
 */
function parse_conf_file()
{
  $entries = @file(".fixup-source-files.conf");
  if (!$entries) {
    $entries = @file(".fixup_source_files.conf");
    if (!$entries)
      return;
  }
  foreach ($entries as $idx => $entry) {
    $line = $idx + 1;
    if ($entry == "\n")
      continue;

    $toks = (array) preg_split('/\s+/', $entry);
    array_pop($toks);

    $toks[0] = "--" . $toks[0];
    parse_command_args($toks, true);
    if (count($toks))
      bail("Invalid configuration file at line $line", 'CONFIG');
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
  print_usage(STDERR);
  exit(1);
}

/** @var int<0,255> */
$prog_retval = 0;
$stats_count = 0;

if (count($local_args) == 0)
  $local_args = array(".");

$targets = array();
foreach ($local_args as $rpath) {
  $basepath = ($rpath != "/" ? rtrim($rpath, "/") : $rpath);

  if (!file_exists($basepath))
    bail("Bad path name \"$basepath\"");

  $targets[] = $basepath;
}

foreach ($targets as $basepath) {
  $splfile = new \SplFileInfo($basepath);

  if ($splfile->isDir()) {
    $dd = new \RecursiveDirectoryIterator($basepath);
    $it = new \RecursiveIteratorIterator($dd, \RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $ff) {
      /** @var \SplFileInfo $ff */
      process_file($ff, $basepath);
    }
  }
  else {
    process_file($splfile, "./");
  }
}

/**
 * ...
 *
 * @param string $file ...
 * @param string $data ...
 * @return void
 */
function perform_diff($file, $data)
{
  $fixup = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "fixup-test-file";
  file_put_contents($fixup, $data);

  $cmdline = sprintf("diff -u -a -L %s -L %s %s %s",
      escapeshellarg("$file.orig"),
      escapeshellarg("$file.fix"),
      escapeshellarg("$file"),
      escapeshellarg("$fixup"));
  /** @var resource */
  $pd = popen($cmdline, "r");
  while (!feof($pd)) {
    $buf = (string) stream_get_line($pd, 8192, "\n");
    $diff_char = substr($buf, 0, 1);
    $diff_line = substr($buf, 1);

    // print "[out] $buf\n";
    $ctl_s = "";
    if (substr($buf, 0, 3) == "---")
      $ctl_s = "\x1b[31;1m";
    elseif (substr($buf, 0, 3) == "+++")
      $ctl_s = "\x1b[32;1m";
    elseif (substr($buf, 0, 2) == "@@")
      $ctl_s = "\x1b[36;1m";
    elseif (substr($buf, 0, 1) == "-")
      $ctl_s = "\x1b[31m";
    elseif (substr($buf, 0, 1) == "+")
      $ctl_s = "\x1b[32m";

    $diff_line = rtrim((string) preg_replace_callback(
        '/( +$)|([\x00-\x1f\x7f\x81\x8d\x8f\x90\x9d])/',
        function($m) {
          if ($m[1] != "")
            return str_repeat("\xb7", strlen($m[1]));
          elseif ($m[2] == "\t")
            return " <tab> ";
          elseif ($m[2] == "\r")
            return "\x1b[7m^M\x1b[27m";
          else
            return "\x1b[7m<" . bin2hex($m[2]) . ">\x1b[27m";
        }, $diff_line));

    print $ctl_s . $diff_char . $diff_line . "\x1b[m\n";
  }
  pclose($pd);
}

/**
 * ...
 *
 * @param \SplFileInfo $ff ...
 * @param string $basepath ...
 * @return void
 */
function process_file($ff, $basepath)
{
  global $IgnorePaths, $IgnoreFiles, $FileExts, $FileNames, $StyleSettings;
  global $check_only, $stats_count, $prog_retval, $WITH_FORCE;

  /* disregard directories, we are looking for files */
  if ($ff->isDir())
    return;

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
    return;
  }

  $ext = $ff->getExtension();

  /* determine the style to use */
  $style = null;
  foreach ($FileNames as $xname => $xstyle) {
    if (fnmatch($xname, $ff->getFilename()))
      $style = $xstyle;
  }
  if (($style === null) && isset($FileExts[".$ext"]))
    $style = $FileExts[".$ext"];
  if (!$style) {
    dbgf("ignoring (no style)", $p);
    return;
  }

  /* perform the processing */
  dbgf("processing as '$style'", $p);
  $result = fixup_file($p, $StyleSettings[$style]);

  if ($result['garbled'] || $result['fixed']) {
    if ($result['garbled']) {
      print "\n\n!!! " . $result['error'] . "\n";
      if (!$check_only && $WITH_FORCE) {
        print "FIXUP (garbled) $p\n";
        file_put_contents($p, $result['data']);
      }
      else {
        perform_diff($p, $result['data']);
        $prog_retval = 1;
      }
    }
    elseif ($check_only) {
      perform_diff($p, $result['data']);
      $prog_retval = 1;
    }
    else {
      print "FIXUP $p\n";
      file_put_contents($p, $result['data']);
    }
  }

  $stats_count++;
}

print "\n\nTotally processed for fixup $stats_count text/source files\n\n";

print ($prog_retval ? "Failure ($prog_retval)" : "Success") . "\n";

exit($prog_retval);
