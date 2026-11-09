<?php
// php extract.php <zip> <folder>: unzips with PHP's zip extension (the image has no unzip command).
$zip = new ZipArchive();
if ($zip->open($argv[1]) !== true) {
    fwrite(STDERR, "cannot open {$argv[1]}\n");
    exit(1);
}
if (!$zip->extractTo($argv[2])) {
    fwrite(STDERR, "cannot extract to {$argv[2]}\n");
    exit(1);
}
echo $zip->numFiles, " files\n";
$zip->close();
