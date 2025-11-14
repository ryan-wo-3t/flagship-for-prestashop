<?php
$source = 'flagshipshipping-1.0.270.zip';
$zip = new ZipArchive();
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pszip' . uniqid() . DIRECTORY_SEPARATOR;
mkdir($tmp, 0777, true);
if ($zip->open($source) !== true) {
    echo "open fail\n";
    exit;
}
if (!$zip->extractTo($tmp)) {
    echo "extract fail\n";
}
$zip->close();
$entries = array_filter(scandir($tmp), function ($entry) use ($tmp) {
    if (in_array($entry, ['.', '..', '__MACOSX'])) {
        return false;
    }
    return is_dir($tmp . $entry);
});
print_r($entries);
foreach ($entries as $dir) {
    $moduleName = $dir;
    $files = scandir($tmp . $dir);
    var_dump(in_array($moduleName . '.php', $files));
}
?>
