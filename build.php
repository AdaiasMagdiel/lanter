<?php

// Concatenates + minifies src/ into a single dist/lanter.php; source stays untouched.

$root = __DIR__;

$sourceFiles = [
    'src/Driver/DriverInterface.php',
    'src/Driver/SqliteDriver.php',
    'src/Driver/MysqlDriver.php',
    'src/Config.php',
    'src/Auth.php',
    'src/View/layout.php',
    'src/Actions/login.php',
    'src/Actions/logout.php',
    'src/Actions/dashboard.php',
    'src/Actions/table_structure.php',
    'src/bootstrap.php',
];

function lanter_build_strip_php_tags(string $code): string
{
    $code = preg_replace('/^<\?php\s*/', '', $code, 1);
    $code = preg_replace('/\?>\s*$/', '', rtrim($code));

    return trim($code, "\n") . "\n";
}

$body = '';
foreach ($sourceFiles as $relativePath) {
    $body .= lanter_build_strip_php_tags(file_get_contents($root . '/' . $relativePath)) . "\n";
}

$css = file_get_contents($root . '/src/assets/app.css');
$css = preg_replace('/\s+/', ' ', $css);
$css = trim($css);

$cssLiteral = var_export($css, true);
$body .= "function lanter_css(): string\n{\n    return {$cssLiteral};\n}\n\n";
$body .= "lanter_run();\n";

$output = "<?php\n\n" . $body;

// Token-based (not regex) so strings, heredocs and inline HTML are never touched.
function lanter_minify_php(string $code): string
{
    $wordChar = static fn (string $c): bool => $c !== '' && preg_match('/[A-Za-z0-9_\x80-\xff]/', $c) === 1;
    $sameCharOperators = ['+', '-', '&', '|', '=', '<', '>', ':', '.'];

    $output = '';
    foreach (token_get_all($code) as $token) {
        [$id, $text] = is_array($token) ? $token : [0, $token];

        if ($id === T_COMMENT || $id === T_DOC_COMMENT || $id === T_WHITESPACE) {
            continue;
        }

        $lastChar = $output === '' ? '' : substr($output, -1);
        $firstChar = $text[0];

        $needsSpace = ($wordChar($lastChar) && $wordChar($firstChar))
            || ($lastChar === $firstChar && in_array($lastChar, $sameCharOperators, true))
            || (ctype_digit($lastChar) && $firstChar === '.')
            || ($lastChar === '.' && ctype_digit($firstChar));

        $output .= ($needsSpace ? ' ' : '') . $text;
    }

    return $output;
}

$output = lanter_minify_php($output);

@mkdir($root . '/dist', 0777, true);
file_put_contents($root . '/dist/lanter.php', $output);

echo 'Built dist/lanter.php (' . strlen($output) . " bytes)\n";
