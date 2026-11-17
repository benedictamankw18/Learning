<?php

declare(strict_types=1);

/**
 * Repository hygiene checks that do not need a database.
 *
 * These catch regressions that are easy to miss in review: syntax errors,
 * whitespace errors, committed environment files, credential-looking strings,
 * and controllers sliding back to raw role comparisons.
 */

/**
 * @param array<int,string> $command
 * @return array{code:int,stdout:string,stderr:string}
 */
function runRepositoryCommand(array $command, ?string $cwd = null): array
{
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, $cwd, null);
    if (!is_resource($process)) {
        fail('Could not start command: ' . implode(' ', $command));
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    return [
        'code' => (int) $code,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

function repositoryRoot(): string
{
    return dirname(__DIR__, 2);
}

function apiRoot(): string
{
    return dirname(__DIR__);
}

/**
 * @return array<int,string>
 */
function trackedFiles(): array
{
    static $files = null;

    if ($files !== null) {
        return $files;
    }

    $result = runRepositoryCommand(['git', '-C', repositoryRoot(), 'ls-files']);
    assertSame(0, $result['code'], 'git ls-files failed: ' . $result['stderr']);

    $files = [];
    foreach (preg_split('/\r?\n/', trim($result['stdout'])) as $file) {
        if ($file !== '') {
            $files[] = $file;
        }
    }

    return $files;
}

/**
 * @return array<int,string>
 */
function phpFilesUnder(string $directory): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

function isBinaryPath(string $path): bool
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    return in_array($extension, [
        'png', 'jpg', 'jpeg', 'gif', 'ico', 'webp', 'bmp', 'pdf',
        'zip', 'gz', 'tar', 'rar', '7z', 'jar', 'phar',
        'woff', 'woff2', 'ttf', 'eot', 'otf',
        'mp3', 'mp4', 'wav', 'ogg', 'webm', 'mov',
        'exe', 'dll', 'so', 'dylib', 'bin',
    ], true);
}

test('Every PHP file in src, scripts, and tests passes php -l', function () {
    $roots = [
        apiRoot() . '/src',
        apiRoot() . '/scripts',
        apiRoot() . '/tests',
    ];

    $files = [];
    foreach ($roots as $root) {
        $files = array_merge($files, phpFilesUnder($root));
    }

    assertTrue(count($files) > 0, 'No PHP files were found to lint');

    $failures = [];
    foreach ($files as $file) {
        $result = runRepositoryCommand([PHP_BINARY, '-l', $file]);
        if ($result['code'] !== 0) {
            $failures[] = $file . ': ' . trim($result['stdout'] . ' ' . $result['stderr']);
        }
    }

    assertSame([], $failures, "PHP lint failures:\n" . implode("\n", $failures));
});

test('git diff --check reports no whitespace errors', function () {
    // Compare against HEAD so both staged and unstaged changes are checked.
    $result = runRepositoryCommand(['git', '-C', repositoryRoot(), 'diff', 'HEAD', '--check']);

    assertSame(
        0,
        $result['code'],
        "git diff HEAD --check failed:\n" . $result['stdout'] . $result['stderr']
    );
});

test('No real .env files are tracked', function () {
    $offenders = [];

    foreach (trackedFiles() as $file) {
        $base = basename($file);

        if ($base === '.env') {
            $offenders[] = $file;
            continue;
        }

        if (str_starts_with($base, '.env.') && !str_ends_with($base, '.example')) {
            $offenders[] = $file;
        }
    }

    assertSame([], $offenders, 'Tracked environment files: ' . implode(', ', $offenders));
});

test('Tracked files do not contain credential-looking values', function () {
    $knownValueMarkers = [
        'change-this', 'your-', 'replace', 'min-32', 'min_32', 'changeme',
        'example', 'placeholder', 'redacted', 'todo', 'dummy', 'sample',
        'xxxx', '***', '...', '${', '<', '$',
    ];

    $secretKeyPattern = '/^[ \t]*('
        . 'JWT_SECRET|DB_PASS|DB_PASSWORD|DB_ROOT_PASSWORD|MYSQL_PASSWORD|MYSQL_ROOT_PASSWORD'
        . '|SMTP_PASS|SMTP_PASSWORD|MAIL_PASSWORD|AWS_SECRET_ACCESS_KEY'
        . '|API_KEY|SECRET_KEY|PRIVATE_KEY|CLIENT_SECRET|OAUTH_CLIENT_SECRET'
        . ')[ \t]*=[ \t]*(.*)$/mi';

    $credentialPatterns = [
        'private key block' => '/-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP )?PRIVATE KEY-----/',
        'AWS access key' => '/\bAKIA[0-9A-Z]{16}\b/',
        'GitHub token' => '/\bgh[pousr]_[A-Za-z0-9]{36,}\b/',
        'Slack token' => '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/',
        'Stripe secret key' => '/\bsk-(?:live|test)-[A-Za-z0-9]{16,}\b/',
        'Google API key' => '/\bAIza[0-9A-Za-z_-]{35}\b/',
    ];

    $offenders = [];

    foreach (trackedFiles() as $file) {
        $normalized = str_replace('\\', '/', $file);
        if (str_contains($normalized, '/vendor/') || str_contains($normalized, '/node_modules/')) {
            continue;
        }
        if (isBinaryPath($file)) {
            continue;
        }

        $absolute = repositoryRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
        if (!is_file($absolute)) {
            continue;
        }

        $contents = file_get_contents($absolute);
        if (!is_string($contents) || str_contains($contents, "\0")) {
            continue;
        }

        foreach ($credentialPatterns as $label => $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $offenders[] = "{$file}: {$label}";
            }
        }

        if (preg_match_all($secretKeyPattern, $contents, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $key = strtoupper($match[1]);
                $value = trim(trim($match[2]), "\"'");

                if ($value === '') {
                    continue;
                }

                $looksLikePlaceholder = false;
                foreach ($knownValueMarkers as $marker) {
                    if (str_contains(strtolower($value), $marker)) {
                        $looksLikePlaceholder = true;
                        break;
                    }
                }

                if ($looksLikePlaceholder) {
                    continue;
                }

                // Short values for these keys are usually feature flags or
                // local defaults rather than credentials.
                if (in_array($key, ['JWT_SECRET', 'API_KEY', 'SECRET_KEY', 'PRIVATE_KEY', 'CLIENT_SECRET'], true)
                    && strlen($value) < 32) {
                    continue;
                }

                $offenders[] = "{$file}: {$key} looks like a committed credential";
            }
        }
    }

    assertSame([], $offenders, "Credential-looking values found:\n" . implode("\n", $offenders));
});

test('B7a controllers do not use raw role comparisons for authorization', function () {
    $controllers = [
        'ClassSubjectController.php',
        'AssignmentController.php',
        'QuizController.php',
        'CourseSectionController.php',
        'GradeCategoryController.php',
        'GradeScaleController.php',
        'InstitutionController.php',
        'AnnouncementController.php',
    ];

    $roleNames = 'super[_-]?admin|super admin|admin|teacher|student|parent';
    $forwardPattern = '/\$user\s*\[\s*[\'"]role[\'"]\s*\]\s*(?:===|!==|==|!=)\s*[\'"](' . $roleNames . ')[\'"]/i';
    $reversePattern = '/[\'"](' . $roleNames . ')[\'"]\s*(?:===|!==|==|!=)\s*\$user\s*\[\s*[\'"]role[\'"]\s*\]/i';

    $offenders = [];
    foreach ($controllers as $controller) {
        $path = apiRoot() . '/src/Controllers/' . $controller;
        assertTrue(is_file($path), "Missing controller: {$controller}");

        $contents = (string) file_get_contents($path);

        if (preg_match($forwardPattern, $contents, $match) === 1) {
            $offenders[] = "{$controller}: raw comparison with {$match[1]}";
        }
        if (preg_match($reversePattern, $contents, $match) === 1) {
            $offenders[] = "{$controller}: raw reverse comparison with {$match[1]}";
        }

        assertContains('AccessControl::', $contents, "{$controller} should use the shared AccessControl policy");
    }

    assertSame([], $offenders, "Raw role checks found:\n" . implode("\n", $offenders));
});

test('Upload serving refuses path traversal and directory listing', function () {
    $routerPath = apiRoot() . '/public/router.php';
    assertTrue(is_file($routerPath), 'Missing public/router.php');
    $router = (string) file_get_contents($routerPath);

    // Uploaded bytes must be resolved through realpath and confined to the
    // uploads directory, otherwise "/uploads/../../.env" reads arbitrary files.
    assertContains('resolveUploadFile', $router, 'router.php should resolve uploads through a containment helper');
    assertContains('realpath(', $router, 'router.php should canonicalize upload paths');
    assertContains('str_starts_with($candidate, $basePrefix)', $router, 'router.php should reject paths outside uploads/');
    assertContains('X-Content-Type-Options: nosniff', $router, 'router.php should send nosniff for uploads');

    // SVG served inline is a stored-XSS vector; it must not be in the mime map.
    assertFalse(
        str_contains($router, "'svg' => 'image/svg+xml'"),
        'router.php must not serve SVG as image/svg+xml'
    );

    $dockerfilePath = apiRoot() . '/Dockerfile';
    assertTrue(is_file($dockerfilePath), 'Missing lms-api/Dockerfile');
    $dockerfile = (string) file_get_contents($dockerfilePath);

    assertContains('Options -Indexes', $dockerfile, 'The uploads directory must not allow directory listing');
    assertFalse(
        str_contains($dockerfile, 'Options Indexes'),
        'The uploads directory must not enable Options Indexes'
    );
    assertContains('Require all denied', $dockerfile, 'Script extensions under uploads/ must be denied');
    assertContains('X-Content-Type-Options "nosniff"', $dockerfile, 'Apache should send nosniff for uploads');
});
