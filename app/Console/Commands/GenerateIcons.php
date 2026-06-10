<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateIcons extends Command
{
    protected $signature   = 'icons:generate';
    protected $description = 'Generate PWA and favicon PNG icons from public/images/logo.jpeg';

    public function handle(): int
    {
        if (!extension_loaded('gd')) {
            $this->error('PHP GD extension is not enabled. Enable it in php.ini and try again.');
            return 1;
        }

        $source = public_path('images/logo.jpeg');

        if (!file_exists($source)) {
            $this->error('Source image not found: ' . $source);
            return 1;
        }

        $src = imagecreatefromjpeg($source);
        if (!$src) {
            $this->error('Could not read logo.jpeg. Check the file is a valid JPEG.');
            return 1;
        }

        $sizes = [
            'images/icon-16.png'          => 16,
            'images/icon-32.png'          => 32,
            'images/apple-touch-icon.png' => 180,
            'images/icon-192.png'         => 192,
            'images/icon-512.png'         => 512,
        ];

        foreach ($sizes as $path => $size) {
            $dst = imagecreatetruecolor($size, $size);

            // White background
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefill($dst, 0, 0, $white);

            imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));
            imagepng($dst, public_path($path));
            imagedestroy($dst);

            $this->line('  <info>✓</info> ' . $path . ' (' . $size . 'x' . $size . ')');
        }

        imagedestroy($src);
        $this->info('All icons generated successfully.');
        return 0;
    }
}
