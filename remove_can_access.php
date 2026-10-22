<?php
$dir = __DIR__ . '/app/Filament/Resources';

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
$files = [];
foreach ($iterator as $file) {
    if ($file->isFile() && str_ends_with($file->getFilename(), 'Resource.php')) {
        $files[] = $file->getPathname();
    }
}

foreach ($files as $file) {
    $content = file_get_contents($file);
    // Find the canAccess method and remove it completely using regex
    // It looks like: public static function canAccess(): bool { ... }
    $pattern = '/\s+public static function canAccess\(\): bool\s*\{\s*return [^}]+\s*\}/m';
    $newContent = preg_replace($pattern, '', $content);
    
    // Also remove any stray public static function canAccess(): bool { ... }
    // that might span multiple lines if the first regex didn't catch it
    $pattern2 = '/\s+public static function canAccess\(\): bool\n\s*\{\n.*?\}\n/ms';
    $newContent = preg_replace($pattern2, "\n", $newContent);

    if ($content !== $newContent) {
        file_put_contents($file, $newContent);
        echo "Removed canAccess from " . basename($file) . "\n";
    }
}
echo "Cleanup complete.\n";
