<?php
/**
 * Global search and replace script for Backend PHP files
 */

$dir = dirname(__DIR__); // backend/
$search = 'actualExpenditure';
$replace = 'actual_exp';

$it = new RecursiveDirectoryIterator($dir);
foreach (new RecursiveIteratorIterator($it) as $file) {
    if ($file->getExtension() === 'php') {
        $path = $file->getRealPath();
        
        // Skip this script and some others if needed
        if (strpos($path, 'global_replace.php') !== false) continue;
        if (strpos($path, 'vendor') !== false) continue; // Safety

        $content = file_get_contents($path);
        if (strpos($content, $search) !== false) {
            echo "Refactoring: $path\n";
            $newContent = str_replace($search, $replace, $content);
            file_put_contents($path, $newContent);
        }
    }
}

echo "🏆 Global backend refactor COMPLETE.\n";
