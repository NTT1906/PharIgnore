<?php
if (!function_exists('fnmatch')) {
    define('FNM_PATHNAME', 1);
    define('FNM_NOESCAPE', 2);
    define('FNM_PERIOD', 4);
    define('FNM_CASEFOLD', 16);

    function fnmatch($pattern, $string, $flags = 0) : bool{
        return pcre_fnmatch($pattern, $string, $flags);
    }

    function pcre_fnmatch($pattern, $string, $flags = 0) : bool{
        $modifiers = null;
        $transforms = array(
            '\*' => '.*',
            '\?' => '.',
            '\[\!' => '[^',
            '\[' => '[',
            '\]' => ']',
            '\.' => '\.',
            '\\' => '\\\\'
        );

        // Forward slash in string must be in pattern:
        if ($flags & FNM_PATHNAME) {
            $transforms['\*'] = '[^/]*';
        }

        // Back slash should not be escaped:
        if ($flags & FNM_NOESCAPE) {
            unset($transforms['\\']);
        }

        // Perform case insensitive match:
        if ($flags & FNM_CASEFOLD) {
            $modifiers .= 'i';
        }

        // Period at start must be the same as pattern:
        if ($flags & FNM_PERIOD && str_starts_with($string, '.') && !str_starts_with($pattern, '.')) {
            return false;
        }

        $pattern = '#^' . strtr(preg_quote($pattern, '#'), $transforms) . '\$#' . $modifiers;
        return (bool) preg_match($pattern, $string);
    }
}

function buildPhar(string $pharPath, string $basePath, array $ignoreList = [], string $stub = "", int $signatureAlgo = \Phar::SHA1, ?int $compression = null) : void{
    $basePath = rtrim(str_replace("/", DIRECTORY_SEPARATOR, $basePath), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (file_exists($pharPath)) {
        echo "Phar file already exists, overwriting...";
        echo PHP_EOL;
        try {
            Phar::unlinkArchive($pharPath);
        } catch (PharException $e) {
            unlink($pharPath);
        }
    }
    if (is_file($stub)) {
        $stub = file_get_contents($stub);
    }
    if (empty($stub) && is_file($stubF = $basePath . DIRECTORY_SEPARATOR . "stub.php")) {
        $stub = file_get_contents($stubF);
    }

    $files = [];
    echo "Adding files...\n";

    $start = microtime(true);
    $phar = new \Phar($pharPath);
    if (!empty($stub)) {
        $phar->setStub($stub);
    }
    //$phar->setMetadata($metadata);
    $phar->setSignatureAlgorithm($signatureAlgo);
    $phar->startBuffering();

    if (is_file($basePath . DIRECTORY_SEPARATOR . "phar.ignore")) {
        $ignoreList = explode(PHP_EOL, file_get_contents($basePath . DIRECTORY_SEPARATOR . "phar.ignore"));
//        $ignoreList = array_map(static fn(string $in) => str_replace("/", DIRECTORY_SEPARATOR,  $in), $ignoreList); Why... Why backslash filesystem is even a thing...
    }

    $iterators = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($basePath, FilesystemIterator::KEY_AS_PATHNAME | FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS));

    /** @var SplFileInfo $file */
    foreach ($iterators as $file) {
        $fixed = str_replace($basePath, "", $file->getPathname());
        $temp = str_replace(DIRECTORY_SEPARATOR, "/", $fixed);
        if (in_array($fixed, $ignoreList, true)) {
            echo "Ignored " . $fixed . "\n";
            continue;
        }
//        var_dump($temp);
        foreach ($ignoreList as $i) {
            if (fnmatch($i, $temp)) {
                echo "Ignored " . $temp . "\n";
                continue 2;
            }
        }
        $files[$fixed] = $file->getPathname();
    }
//    var_dump($files);

    $count = count($phar->buildFromIterator(new \ArrayIterator($files)));
    echo "Added " . $count . " files...";
    if($compression !== null){
        echo "Compressing files...";
        $phar->compressFiles($compression);
        echo "Finished compression";
    }
    $phar->stopBuffering();
    echo "Done in " . round(microtime(true) - $start, 3) . "s";
}

const PLUGIN_STUB = '<?php __HALT_COMPILER();';

function getLongOpt(array $opt, array $alias) : array{
    foreach ($alias as $ali) {
        if (is_array($ali)) {
            foreach ($ali as $aali) {
                $opt[] = $aali;
            }
            continue;
        }
        $opt[] = $ali;
    }
    $tempOpt = array_map(static fn(string $in) => $in . "::", $opt);
    $i = 1;
    $opts = getopt("", $tempOpt, $i);
    foreach ($alias as $ori => $ali) {
        if (is_array($ali)) {
            foreach ($ali as $aali) {
                $opts[$ori] ??= $opts[$aali] ?? null;
                unset($opts[$aali]);
            }
            continue;
        }
        $opts[$ori] ??= $opts[$ali] ?? null;
        unset($opts[$ali]);
    }
    return $opts;
}

$opts = getLongOpt(["in", "out", "compress", "stub", "pignore"], [
    "in" => "i",
    "out" => "o",
    "compress" => "c",
    "stub" => "s",
    "pignore" => ["phar-ignore", "pi", "p"]
]);
if (!isset($opts["in"])) {
    echo "Missing input\n";
    exit(0);
}
if (!isset($opts["out"])) {
    echo "Missing output\n";
    exit(0);
}
if (isset($opts["pignore"])) {
    if (!is_file($opts["pignore"])) {
        echo "Invalid phar.ignore file\n";
        exit(0);
    }
    $pharIgnore = explode(PHP_EOL, file_get_contents($opts["[pignore"] . DIRECTORY_SEPARATOR . "phar.ignore"));
}
$stub = $opts["stub"] ?? PLUGIN_STUB;
$compress = null;
if (isset($opts["compress"])) {
    $compress = match (strtolower($opts["compress"])) {
        "bz2" => \Phar::BZ2,
        "gz" => \Phar::GZ,
        "none" => \Phar::NONE,
        default => exit(0)
    };
}
buildPhar($opts["out"], $opts["in"], $pharIgnore ?? [], $stub, Phar::SHA1, $compress);
