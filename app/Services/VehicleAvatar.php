<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class VehicleAvatar
{
    public function store(UploadedFile $file, string $vehicle): string
    {
        // Decode and re-encode: uploaded metadata and trailing content are never served.
        $image = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if (! $image) {
            throw ValidationException::withMessages(['avatar' => 'Obrázek nelze přečíst. Zvolte platný JPEG, PNG nebo WebP.']);
        }
        try {
            if ($file->getMimeType() === 'image/jpeg') {
                $exif = @exif_read_data($file->getRealPath());
                $orientation = (int) ($exif['Orientation'] ?? 1);
                if (in_array($orientation, [2, 4, 5, 7], true)) {
                    imageflip($image, IMG_FLIP_HORIZONTAL);
                }
                $angle = match ($orientation) {
                    3, 4 => 180,
                    6, 7 => -90,
                    5, 8 => 90,
                    default => 0,
                };
                if ($angle) {
                    $rotated = imagerotate($image, $angle, 0);
                    imagedestroy($image);
                    $image = $rotated;
                }
            }
            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1, 1280 / max($width, $height));
            $output = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            imagealphablending($output, false);
            imagesavealpha($output, true);
            imagecopyresampled($output, $image, 0, 0, 0, 0, imagesx($output), imagesy($output), $width, $height);
            ob_start();
            try {
                if (! imagewebp($output, null, 85)) {
                    throw new RuntimeException('Převod obrázku selhal.');
                }
                $bytes = ob_get_contents();
            } finally {
                ob_end_clean();
                imagedestroy($output);
            }
            $path = 'vehicle-avatars/'.$vehicle.'/'.Str::uuid().'.webp';
            if (! Storage::disk('local')->put($path, $bytes)) {
                throw new RuntimeException('Obrázek se nepodařilo uložit.');
            }

            return $path;
        } finally {
            imagedestroy($image);
        }
    }
}
