<?php

$input_path = __DIR__ . '/storage/app/public/settings/01KTEMRP46MC0QFYDF991GCDP5.png';

if (!file_exists($input_path)) {
    die("File not found\n");
}

$img = imagecreatefrompng($input_path);
if (!$img) {
    die("Could not open image\n");
}

$width = imagesx($img);
$height = imagesy($img);

// Create a new true color image with alpha channel
$new_img = imagecreatetruecolor($width, $height);
imagesavealpha($new_img, true);

// Fill with transparent background
$transparent = imagecolorallocatealpha($new_img, 0, 0, 0, 127);
imagefill($new_img, 0, 0, $transparent);

for ($x = 0; $x < $width; $x++) {
    for ($y = 0; $y < $height; $y++) {
        $color = imagecolorat($img, $x, $y);
        $colors = imagecolorsforindex($img, $color);
        
        // Check if the pixel is white or close to white (tolerance 220-255)
        if ($colors['red'] > 220 && $colors['green'] > 220 && $colors['blue'] > 220) {
            imagesetpixel($new_img, $x, $y, $transparent);
        } else {
            // Keep the original color
            $original = imagecolorallocatealpha($new_img, $colors['red'], $colors['green'], $colors['blue'], $colors['alpha']);
            imagesetpixel($new_img, $x, $y, $original);
        }
    }
}

// Save the image
imagepng($new_img, $input_path);
imagedestroy($img);
imagedestroy($new_img);

echo "White background removed successfully.\n";
