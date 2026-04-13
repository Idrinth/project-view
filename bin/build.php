<?php
/**
 * Build script for Project View.
 *
 * Reads unminified sources from ./resources and writes a minified copy
 * into ./public so that the web root only contains deliverable assets.
 *
 * Usage:
 *   php bin/build.php
 */

declare(strict_types=1);

$root      = dirname(__DIR__);
$resources = $root . '/resources';
$public    = $root . '/public';

if (!is_dir($resources)) {
    fwrite(STDERR, "resources directory not found: {$resources}\n");
    exit(1);
}

if (!is_dir($public) && !mkdir($public, 0755, true) && !is_dir($public)) {
    fwrite(STDERR, "could not create public directory: {$public}\n");
    exit(1);
}

/**
 * Compute a short content hash suitable for use as a cache-busting query
 * parameter. Derived from the built (minified) contents, so any change
 * to the served file produces a new value.
 */
function asset_hash(string $contents): string
{
    return substr(hash('sha256', $contents), 0, 10);
}

/**
 * Rewrite <link href="..."> and <script src="..."> references to built
 * CSS/JS assets so they include a ?v=<hash> cache-busting parameter.
 * The lookup is keyed by the asset's basename, which matches how the
 * flat public/ layout is referenced from the HTML templates.
 *
 * Runs on raw HTML before minify_html() stashes <script> blocks, so
 * that the rewritten `src` attribute is preserved verbatim.
 */
function rewrite_asset_references(string $html, array $hashes): string
{
    $pattern = '#(<(?:link|script)\b[^>]*?\b(?:href|src)\s*=\s*")([^"]+\.(?:css|js))(")#i';
    return preg_replace_callback(
        $pattern,
        static function (array $match) use ($hashes): string {
            $url = $match[2];
            $basename = basename($url);
            if (!isset($hashes[$basename])) {
                return $match[0];
            }
            $separator = strpos($url, '?') === false ? '?' : '&';
            return $match[1] . $url . $separator . 'v=' . $hashes[$basename] . $match[3];
        },
        $html
    ) ?? $html;
}

/**
 * Minify HTML by stripping comments and collapsing whitespace between tags.
 * Content inside <pre>, <textarea> and <script> blocks is preserved.
 */
function minify_html(string $input): string
{
    $placeholders = [];
    $protect = static function (string $pattern) use (&$input, &$placeholders): void {
        $input = preg_replace_callback(
            $pattern,
            static function (array $match) use (&$placeholders): string {
                $token = '@@PV_PLACEHOLDER_' . count($placeholders) . '@@';
                $placeholders[$token] = $match[0];
                return $token;
            },
            $input
        ) ?? $input;
    };

    $protect('#<pre\b[^>]*>.*?</pre>#si');
    $protect('#<textarea\b[^>]*>.*?</textarea>#si');
    $protect('#<script\b[^>]*>.*?</script>#si');

    $input = preg_replace('/<!--(?!\[if).*?-->/s', '', $input) ?? $input;
    $input = preg_replace('/>\s+</', '><', $input) ?? $input;
    $input = preg_replace('/\s{2,}/', ' ', $input) ?? $input;
    $input = trim($input);

    foreach ($placeholders as $token => $original) {
        $input = str_replace($token, $original, $input);
    }

    return $input;
}

/**
 * Minify CSS by stripping comments and unnecessary whitespace.
 */
function minify_css(string $input): string
{
    $input = preg_replace('#/\*.*?\*/#s', '', $input) ?? $input;
    $input = preg_replace('/\s+/', ' ', $input) ?? $input;
    $input = preg_replace('/\s*([{};:,>+~])\s*/', '$1', $input) ?? $input;
    $input = str_replace(';}', '}', $input);
    return trim($input);
}

/**
 * Minify JavaScript. This is intentionally conservative: it strips line
 * comments, block comments and collapses runs of whitespace. It is not
 * a full JavaScript minifier and is fine for the simple scripts in
 * this project.
 */
function minify_js(string $input): string
{
    $input = preg_replace('#/\*.*?\*/#s', '', $input) ?? $input;
    $input = preg_replace('#(^|[^:])//[^\n\r]*#', '$1', $input) ?? $input;
    $input = preg_replace('/[ \t]+/', ' ', $input) ?? $input;
    $input = preg_replace('/\s*\n\s*/', "\n", $input) ?? $input;
    return trim($input);
}

/**
 * Remove everything in a directory without removing the directory itself.
 * Hidden files (starting with a dot) are preserved so that markers like
 * .gitkeep stay in place.
 */
function clear_directory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if (str_starts_with($entry->getFilename(), '.')) {
            continue;
        }
        if ($entry->isDir()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
}

echo "Cleaning {$public} ...\n";
clear_directory($public);

// Collect every source file up front so we can process non-HTML assets
// first (to compute their content hashes) and then rewrite the HTML in
// a second pass with cache-busting ?v=<hash> parameters baked in.
$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($resources, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $relative = substr($file->getPathname(), strlen($resources) + 1);
    // Flatten the top-level resources/{html,css,js,php} folders so that
    // public/ contains a flat, directly servable set of files.
    $relative = preg_replace('#^(html|css|js|php)[\\\\/]#', '', $relative) ?? $relative;

    $files[] = [
        'source'    => $file->getPathname(),
        'relative'  => $relative,
        'basename'  => $file->getBasename(),
        'extension' => strtolower($file->getExtension()),
    ];
}

// Order so that assets referenced from HTML (CSS, JS) are built before
// the HTML files that link to them. Everything that isn't HTML goes in
// the first pass; HTML files go in the second pass.
usort($files, static function (array $a, array $b): int {
    $aIsHtml = in_array($a['extension'], ['html', 'htm'], true) ? 1 : 0;
    $bIsHtml = in_array($b['extension'], ['html', 'htm'], true) ? 1 : 0;
    return $aIsHtml <=> $bIsHtml;
});

$processed    = 0;
$assetHashes  = [];

foreach ($files as $entry) {
    $target = $public . '/' . $entry['relative'];
    $dir    = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        fwrite(STDERR, "could not create directory: {$dir}\n");
        exit(1);
    }

    $contents = file_get_contents($entry['source']);
    if ($contents === false) {
        fwrite(STDERR, "could not read {$entry['source']}\n");
        exit(1);
    }

    switch ($entry['extension']) {
        case 'html':
        case 'htm':
            $contents = rewrite_asset_references($contents, $assetHashes);
            $contents = minify_html($contents);
            break;
        case 'css':
            $contents = minify_css($contents);
            $assetHashes[basename($entry['relative'])] = asset_hash($contents);
            break;
        case 'js':
            $contents = minify_js($contents);
            $assetHashes[basename($entry['relative'])] = asset_hash($contents);
            break;
    }

    if (file_put_contents($target, $contents) === false) {
        fwrite(STDERR, "could not write {$target}\n");
        exit(1);
    }

    echo "  {$entry['basename']} -> public/{$entry['relative']}\n";
    $processed++;
}

echo "Build complete: {$processed} file(s) written to {$public}.\n";
