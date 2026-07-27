<?php

namespace App\Utils;

use Exception;
use Mimey\MimeTypes;
use App\Utils\CloudinaryClient;

class FileUtils
{
    public static function generateName(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    public static function saveFile(array $file, string $folder): string
    {
        $mime = new MimeTypes();
        $type = $file['type'];
        $tmpName = $file['tmp_name'];
        $extension = $mime->getExtension($type);
        $fileName = self::generateName() . '.' . $extension;

        try {
            $client = CloudinaryClient::client();

            $upload = $client->uploadApi()->upload($tmpName, [
                'folder' => $folder,
                'public_id' => pathinfo($fileName, PATHINFO_FILENAME),
                'resource_type' => 'auto'
            ]);

            return $upload['secure_url'] ?? '';
        } catch (Exception $e) {
            throw new Exception("Cloudinary upload error: " . $e->getMessage());
        }
    }

    public static function saveFileFromContent(string $content, string $folder, string $extension = "mp3"): string
    {
        $fileName = self::generateName() . '.' . $extension;
        $tmpPath = sys_get_temp_dir() . '/' . $fileName;
        file_put_contents($tmpPath, $content);

        try {
            $client = CloudinaryClient::client();

            $upload = $client->uploadApi()->upload($tmpPath, [
                'folder' => $folder,
                'public_id' => pathinfo($fileName, PATHINFO_FILENAME),
                'resource_type' => 'auto'
            ]);

            unlink($tmpPath);
            return $upload['secure_url'] ?? '';
        } catch (Exception $e) {
            if (file_exists($tmpPath)) unlink($tmpPath);
            throw new Exception("Cloudinary upload error (content): " . $e->getMessage());
        }
    }

    public static function saveFileFromPath(string $path, string $folder, string $publicId = ''): string
    {
        if (!is_file($path)) {
            throw new Exception('The generated file does not exist.');
        }
        $client = CloudinaryClient::client();
        $options = ['folder' => $folder, 'resource_type' => 'auto'];
        if ($publicId !== '') {
            $options['public_id'] = $publicId;
        }
        $upload = $client->uploadApi()->upload($path, $options);
        return $upload['secure_url'] ?? '';
    }

    public static function hasFile(array $files, string $file): bool
    {
        return !empty($files[$file]['name'] ?? '');
    }

    // Cloudinary no necesita delete si no se desea, pero lo incluimos opcionalmente
    public static function removeFile(string $publicUrl): bool
    {
        try {
            $publicId = self::extractPublicIdFromUrl($publicUrl);
            if (!$publicId) return false;

            $client = CloudinaryClient::client();
            $client->uploadApi()->destroy($publicId, [
                'resource_type' => 'auto'
            ]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public static function getPublicUrl(string $cloudinaryUrl): string
    {
        return $cloudinaryUrl;
    }

    /**
     * Extrae el public_id desde una URL de Cloudinary
     */
    private static function extractPublicIdFromUrl(string $url): ?string
    {
        $parsed = parse_url($url);
        if (!isset($parsed['path'])) return null;

        $path = $parsed['path'];
        $parts = explode('/', ltrim($path, '/'));

        // Última parte debe ser el archivo
        $filename = end($parts);
        $publicId = preg_replace('/\.[^.]+$/', '', $filename); // quitar extensión
        array_pop($parts); // remove filename
        $folder = implode('/', $parts);

        return $folder ? "$folder/$publicId" : $publicId;
    }
}
