<?php

declare(strict_types=1);

/**
 * Peta kode ringkas untuk agent/dev: kelas, method, dan nomor baris.
 *
 *   php tools/agent-map.php                     # peta src/ + router (default, paling ringkas)
 *   php tools/agent-map.php src/StudyService.php
 *   php tools/agent-map.php tools integrations/codeigniter
 *   php tools/agent-map.php --routes            # daftar endpoint public/index.php
 *
 * Tujuan: agent tidak perlu membaca file 700+ baris hanya untuk menemukan satu method.
 * Output sengaja padat (satu baris per simbol) supaya hemat token.
 */

$root = dirname(__DIR__);

const TARGET_DEFAULT = ['src', 'public/index.php', 'public/router.php'];

/**
 * Kumpulkan file PHP dari target (file atau folder, rekursif).
 *
 * @param  list<string> $targets path relatif terhadap root
 * @return list<string> path absolut
 */
function collectFiles(string $root, array $targets): array
{
    $files = [];
    foreach ($targets as $target) {
        $path = $root . '/' . ltrim(str_replace('\\', '/', $target), '/');
        if (is_file($path)) {
            $files[] = $path;
            continue;
        }
        if (!is_dir($path)) {
            fwrite(STDERR, "lewati (tidak ada): {$target}\n");
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files);

    return $files;
}

/**
 * Ekstrak simbol penting dari satu file PHP.
 *
 * @return list<array{line:int, kind:string, text:string}>
 */
function mapFile(string $file): array
{
    $rows = [];
    $lines = array_map(
        static fn (string $l): string => rtrim($l, "\r"),
        file($file, FILE_IGNORE_NEW_LINES) ?: []
    );

    foreach ($lines as $i => $line) {
        $no = $i + 1;
        $trim = trim($line);

        if ($trim === '' || str_starts_with($trim, '*') || str_starts_with($trim, '//')) {
            continue;
        }

        if (preg_match('/^(?:(?:final|abstract|readonly)\s+)*(class|interface|trait|enum)\s+(\w+)/', $trim, $m)) {
            $rows[] = ['line' => $no, 'kind' => 'type', 'text' => "{$m[1]} {$m[2]}"];
            continue;
        }

        if (preg_match('/^(?:(public|protected|private)\s+)?(?:static\s+)?function\s+&?(\w+)\s*\((.*)$/', $trim, $m)) {
            $visibility = $m[1] === '' ? '' : $m[1] . ' ';
            $signature = $visibility . $m[2] . '(';
            $rows[] = ['line' => $no, 'kind' => 'fn', 'text' => $signature];
            continue;
        }

        if (preg_match('/^(?:(public|protected|private)\s+)?const\s+([A-Z_][A-Z0-9_]*)/', $trim, $m)) {
            $rows[] = ['line' => $no, 'kind' => 'const', 'text' => 'const ' . $m[2]];
        }
    }

    return $rows;
}

/**
 * Daftar endpoint dari router public/index.php.
 *
 * @return list<string>
 */
function mapRoutes(string $indexFile): array
{
    $routes = [];
    $lines = array_map(
        static fn (string $l): string => rtrim($l, "\r"),
        file($indexFile, FILE_IGNORE_NEW_LINES) ?: []
    );

    foreach ($lines as $i => $line) {
        if (!preg_match_all("/\\\$path === '([^']+)'/", $line, $mm)) {
            continue;
        }
        $method = preg_match("/\\\$method === '(\w+)'/", $line, $mMethod) ? $mMethod[1] : 'GET';
        foreach ($mm[1] as $path) {
            $routes[] = sprintf('%5d  %-6s %s', $i + 1, $method, $path);
        }
    }

    return $routes;
}

$args = array_slice($argv, 1);

if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo "php tools/agent-map.php [--routes] [file|folder ...]\n";
    exit(0);
}

if (in_array('--routes', $args, true)) {
    $index = $root . '/public/index.php';
    echo "# Endpoint public/index.php\n";
    foreach (mapRoutes($index) as $row) {
        echo $row, "\n";
    }
    exit(0);
}

$targets = array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '-')));
if ($targets === []) {
    $targets = TARGET_DEFAULT;
}

foreach (collectFiles($root, $targets) as $file) {
    $rel = str_replace('\\', '/', substr($file, strlen($root) + 1));
    $rows = mapFile($file);
    $total = count(file($file) ?: []);
    printf("\n%s (%d baris)\n", $rel, $total);
    if ($rows === []) {
        echo "  (tanpa kelas/fungsi)\n";
        continue;
    }
    foreach ($rows as $row) {
        printf("  L%-4d %s\n", $row['line'], $row['text']);
    }
}
