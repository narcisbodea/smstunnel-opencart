<?php
/**
 * Builds the two packages OpenCart installs (Extensions > Installer > Upload):
 *
 *   build/smstunnel-oc3.ocmod.zip            OpenCart 3.0.x: upload/ (copied into the store) + LICENSE
 *   build/smstunnel-oc4/smstunnel.ocmod.zip  OpenCart 4.0.2+ and 4.1: install.json + the extension's files
 *
 * OpenCart 4 names the extension's folder after the zip's file name, so the OpenCart 4 package must keep
 * the name smstunnel.ocmod.zip (another name breaks its PHP namespaces).
 *
 * Both packages carry the same core (src/core/smstunnel) and the same language files (src/language).
 * Needs PHP with the zip extension: tools/build.sh runs it in the composer:2 Docker image.
 *
 * @author    Narcis Bodea <contact@narboweb.ro>
 * @copyright 2026 NARBOWEB SRL
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 */

$root = dirname(__DIR__);
$build = $root . '/build';

// A fixed date inside the zips, so the same sources give the same packages.
const ZIP_TIME = 1793836800; // 2026-11-04 00:00:00 UTC

function fail($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function removeTree($path)
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);

        return;
    }
    foreach (scandir($path) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            removeTree($path . '/' . $entry);
        }
    }
    rmdir($path);
}

/** Every file under $dir, relative, sorted. */
function filesOf($dir, $prefix = '')
{
    $files = array();
    $entries = scandir($dir);
    sort($entries);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.DS_Store' || $entry === 'Thumbs.db') {
            continue;
        }
        if (is_dir($dir . '/' . $entry)) {
            $files = array_merge($files, filesOf($dir . '/' . $entry, $prefix . $entry . '/'));
        } else {
            $files[] = $prefix . $entry;
        }
    }

    return $files;
}

/** Copies $from (a file or a folder) to $to. */
function copyInto($from, $to)
{
    if (is_file($from)) {
        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0755, true);
        }
        copy($from, $to) || fail("cannot copy $from");

        return;
    }
    foreach (filesOf($from) as $file) {
        copyInto($from . '/' . $file, $to . '/' . $file);
    }
}

/** Zips the folder's contents (paths relative to it), folders included, in a fixed order and date. */
function zipFolder($folder, $zipFile)
{
    if (file_exists($zipFile)) {
        unlink($zipFile);
    }
    if (!is_dir(dirname($zipFile))) {
        mkdir(dirname($zipFile), 0755, true);
    }
    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fail("cannot create $zipFile");
    }
    $files = filesOf($folder);
    $dirs = array();
    foreach ($files as $file) {
        $parts = explode('/', $file);
        array_pop($parts);
        $path = '';
        foreach ($parts as $part) {
            $path .= $part . '/';
            $dirs[$path] = true;
        }
    }
    ksort($dirs);
    foreach (array_keys($dirs) as $dir) {
        $zip->addEmptyDir(rtrim($dir, '/'));
    }
    foreach ($files as $file) {
        $zip->addFile($folder . '/' . $file, $file);
    }
    if (method_exists($zip, 'setMtimeName')) {
        foreach (array_keys($dirs) as $dir) {
            $zip->setMtimeName(rtrim($dir, '/') . '/', ZIP_TIME);
        }
        foreach ($files as $file) {
            $zip->setMtimeName($file, ZIP_TIME);
        }
    }
    $zip->close() || fail("cannot write $zipFile");

    return count($files);
}

// The version must be the same everywhere.
$version = null;
if (preg_match("/const NUMBER = '([0-9.]+)'/", (string) file_get_contents($root . '/src/core/smstunnel/Version.php'), $match)) {
    $version = $match[1];
}
$installJson = json_decode((string) file_get_contents($root . '/src/oc4/install.json'), true);
if (!$version || !is_array($installJson) || $installJson['version'] !== $version) {
    fail('The version in src/core/smstunnel/Version.php and src/oc4/install.json differ.');
}
if (strpos((string) file_get_contents($root . '/CHANGELOG.md'), '## [' . $version . ']') === false) {
    fail("CHANGELOG.md has no entry for $version.");
}

removeTree($build);
mkdir($build, 0755, true);

$languages = array();
foreach (filesOf($root . '/src/language') as $file) {
    $languages[basename($file, '.php')] = $root . '/src/language/' . $file;
}

// OpenCart 3.0.
$oc3 = $build . '/oc3';
copyInto($root . '/src/oc3/upload', $oc3 . '/upload');
copyInto($root . '/src/core/smstunnel', $oc3 . '/upload/system/library/smstunnel');
foreach ($languages as $code => $file) {
    copyInto($file, $oc3 . '/upload/admin/language/' . $code . '/extension/module/smstunnel.php');
}
copyInto($root . '/LICENSE', $oc3 . '/LICENSE.txt');
$count3 = zipFolder($oc3, $build . '/smstunnel-oc3.ocmod.zip');

// OpenCart 4.
$oc4 = $build . '/oc4';
copyInto($root . '/src/oc4', $oc4);
copyInto($root . '/src/core/smstunnel', $oc4 . '/system/library/smstunnel');
foreach ($languages as $code => $file) {
    copyInto($file, $oc4 . '/admin/language/' . $code . '/module/smstunnel.php');
}
copyInto($root . '/LICENSE', $oc4 . '/LICENSE.txt');
$count4 = zipFolder($oc4, $build . '/smstunnel-oc4/smstunnel.ocmod.zip');

printf("SMSTunnel for OpenCart %s\n", $version);
printf("  build/smstunnel-oc3.ocmod.zip            %3d files, %6d bytes  (OpenCart 3.0.x)\n", $count3, filesize($build . '/smstunnel-oc3.ocmod.zip'));
printf("  build/smstunnel-oc4/smstunnel.ocmod.zip  %3d files, %6d bytes  (OpenCart 4.0.2.x and 4.1.x; keep this file name)\n", $count4, filesize($build . '/smstunnel-oc4/smstunnel.ocmod.zip'));
