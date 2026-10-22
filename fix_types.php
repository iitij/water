<?php
// Quick script to fix Filament v5 type signatures in all resource files
$basePath = __DIR__ . '/app/Filament/Resources';

function fixFile(string $path): void {
    $content = file_get_contents($path);
    $original = $content;
    
    // Fix form method signature for Resources (static)
    $content = str_replace(
        'public static function form(Form $form): Form',
        'public static function form(\Filament\Schemas\Schema $form): \Filament\Schemas\Schema',
        $content
    );
    
    // Fix form method signature for RelationManagers (instance)  
    $content = str_replace(
        'public function form(Form $form): Form',
        'public function form(\Filament\Schemas\Schema $form): \Filament\Schemas\Schema',
        $content
    );
    
    // Remove unused Form import (keep Forms)
    $content = str_replace("use Filament\\Forms\\Form;\n", '', $content);
    
    if ($content !== $original) {
        file_put_contents($path, $content);
        echo "Fixed: " . basename($path) . "\n";
    }
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($basePath)
);

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        fixFile($file->getPathname());
    }
}

echo "Done!\n";
