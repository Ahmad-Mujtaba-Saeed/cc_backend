<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class R2UploadService
{
    private S3Client $s3Client;
    private string $bucket;
    private string $baseUrl;

    public function __construct()
    {
        $region = config('services.r2.region', 'auto');
        $endpoint = config('services.r2.endpoint');
        $key = config('services.r2.key');
        $secret = config('services.r2.secret');
        $token = config('services.r2.token');

        $this->bucket = (string) config('services.r2.bucket');
        $this->baseUrl = (string) config('services.r2.url');

        if (empty($endpoint) || empty($this->bucket) || empty($this->baseUrl)) {
            Log::error('R2 is not configured properly (missing endpoint/bucket/url).', [
                'endpoint_set' => !empty($endpoint),
                'bucket_set' => !empty($this->bucket),
                'url_set' => !empty($this->baseUrl),
            ]);
            throw new \RuntimeException('R2 configuration missing. Please set services.r2.endpoint, bucket, and url.');
        }

        if (empty($key) || empty($secret)) {
            Log::error('R2 credentials are missing. Configure filesystems.disks.r2.key and filesystems.disks.r2.secret (or env vars).', [
                'key_set' => !empty($key),
                'secret_set' => !empty($secret),
            ]);
            throw new \RuntimeException('R2 credentials missing. Set R2 key/secret in filesystem disk config/env.');
        }

        $clientConfig = [
            'version' => 'latest',
            'region' => $region,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => array_filter([
                'key' => $key,
                'secret' => $secret,
                'token' => $token,
            ], static fn ($v) => !is_null($v) && $v !== ''),
        ];

        $this->s3Client = new S3Client($clientConfig);
    }

    /**
     * Upload file to Cloudflare R2.
     */
    public function upload(string $localPath, string $remotePath = null, array $options = []): ?string
    {
        try {
            Log::info('Starting R2 upload', [
                'local_path' => $localPath,
                'remote_path' => $remotePath
            ]);

            if (!file_exists($localPath)) {
                throw new \Exception('Local file does not exist: ' . $localPath);
            }

            // Generate remote path if not provided
            if (!$remotePath) {
                $remotePath = $this->generateRemotePath($localPath);
            }

            // Prepare upload options
            $uploadOptions = [
                'Bucket' => $this->bucket,
                'Key' => $remotePath,
                'SourceFile' => $localPath,
                'ContentType' => $options['content_type'] ?? $this->getMimeType($localPath),
                'Metadata' => $options['metadata'] ?? [],
            ];

            // Add ACL if specified
            if (isset($options['acl'])) {
                $uploadOptions['ACL'] = $options['acl'];
            }

            // Add cache control if specified
            if (isset($options['cache_control'])) {
                $uploadOptions['CacheControl'] = $options['cache_control'];
            }

            // Perform multipart upload for large files
            $fileSize = filesize($localPath);
            if ($fileSize > 100 * 1024 * 1024) { // 100MB threshold
                $result = $this->multipartUpload($uploadOptions);
            } else {
                $result = $this->simpleUpload($uploadOptions);
            }

            $publicUrl = $this->baseUrl . '/' . $remotePath;

            Log::info('R2 upload completed', [
                'remote_path' => $remotePath,
                'public_url' => $publicUrl,
                'file_size' => $fileSize
            ]);

            return $publicUrl;

        } catch (\Exception $e) {
            Log::error('R2 upload failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Simple upload for smaller files.
     */
    private function simpleUpload(array $options): array
    {
        $result = $this->s3Client->putObject($options);
        return method_exists($result, 'toArray') ? $result->toArray() : (array)$result;
    }

    /**
     * Multipart upload for larger files.
     */
    private function multipartUpload(array $options): array
    {
        // Initiate multipart upload
        $result = $this->s3Client->createMultipartUpload([
            'Bucket' => $options['Bucket'],
            'Key' => $options['Key'],
            'ContentType' => $options['ContentType'],
            'Metadata' => $options['Metadata'],
        ]);

        $uploadId = $result['UploadId'];
        $parts = [];
        $partNumber = 1;
        $fileSize = filesize($options['SourceFile']);
        $partSize = 50 * 1024 * 1024; // 50MB parts

        // Upload parts
        $file = fopen($options['SourceFile'], 'rb');
        while (!feof($file) && ftell($file) < $fileSize) {
            $chunk = fread($file, $partSize);
            
            $result = $this->s3Client->uploadPart([
                'Bucket' => $options['Bucket'],
                'Key' => $options['Key'],
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber,
                'Body' => $chunk,
            ]);

            $parts[] = [
                'PartNumber' => $partNumber,
                'ETag' => $result['ETag'],
            ];

            $partNumber++;
        }
        fclose($file);

        // Complete multipart upload
        $result = $this->s3Client->completeMultipartUpload([
            'Bucket' => $options['Bucket'],
            'Key' => $options['Key'],
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => $parts],
        ]);

        return method_exists($result, 'toArray') ? $result->toArray() : (array)$result;
    }

    /**
     * Delete file from R2.
     */
    public function delete(string $remotePath): bool
    {
        try {
            Log::info('Deleting file from R2', ['remote_path' => $remotePath]);

            $this->s3Client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $remotePath,
            ]);

            Log::info('File deleted from R2', ['remote_path' => $remotePath]);
            return true;

        } catch (\Exception $e) {
            Log::error('R2 delete failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if file exists in R2.
     */
    public function exists(string $remotePath): bool
    {
        try {
            $this->s3Client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $remotePath,
            ]);

            return true;

        } catch (AwsException $e) {
            if ($e->getStatusCode() === 404) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Get file metadata from R2.
     */
    public function getMetadata(string $remotePath): ?array
    {
        try {
            $result = $this->s3Client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $remotePath,
            ]);

            return [
                'size' => $result['ContentLength'] ?? null,
                'content_type' => $result['ContentType'] ?? null,
                'last_modified' => $result['LastModified'] ?? null,
                'metadata' => $result['Metadata'] ?? [],
                'etag' => $result['ETag'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to get R2 metadata: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate remote path for upload.
     */
    private function generateRemotePath(string $localPath): string
    {
        $extension = pathinfo($localPath, PATHINFO_EXTENSION);
        $filename = uniqid() . '_' . time() . '.' . $extension;
        
        // Organize by date and file type
        $dateFolder = date('Y/m/d');
        $typeFolder = $this->getTypeFolder($extension);
        
        return "{$typeFolder}/{$dateFolder}/{$filename}";
    }

    /**
     * Get folder based on file type.
     */
    private function getTypeFolder(string $extension): string
    {
        $videoExtensions = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm'];
        $audioExtensions = ['mp3', 'wav', 'aac', 'ogg', 'flac'];
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];

        $extension = strtolower($extension);

        if (in_array($extension, $videoExtensions)) {
            return 'videos';
        } elseif (in_array($extension, $audioExtensions)) {
            return 'audio';
        } elseif (in_array($extension, $imageExtensions)) {
            return 'images';
        } else {
            return 'files';
        }
    }

    /**
     * Get MIME type for file.
     */
    private function getMimeType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        $mimeTypes = [
            'mp4' => 'video/mp4',
            'avi' => 'video/x-msvideo',
            'mov' => 'video/quicktime',
            'wmv' => 'video/x-ms-wmv',
            'flv' => 'video/x-flv',
            'webm' => 'video/webm',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'aac' => 'audio/aac',
            'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'webp' => 'image/webp',
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    /**
     * Create signed URL for temporary access.
     */
    public function createSignedUrl(string $remotePath, int $expiresIn = 3600): ?string
    {
        try {
            $command = $this->s3Client->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key' => $remotePath,
            ]);

            $request = $this->s3Client->createPresignedRequest($command, "+{$expiresIn} seconds");
            
            return (string) $request->getUri();

        } catch (\Exception $e) {
            Log::error('Failed to create signed URL: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * List files in R2 bucket.
     */
    public function listFiles(string $prefix = '', int $maxKeys = 1000): array
    {
        try {
            $result = $this->s3Client->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
                'MaxKeys' => $maxKeys,
            ]);

            $files = [];
            foreach ($result['Contents'] ?? [] as $object) {
                $files[] = [
                    'key' => $object['Key'],
                    'size' => $object['Size'],
                    'last_modified' => $object['LastModified'],
                    'url' => $this->baseUrl . '/' . $object['Key'],
                ];
            }

            return $files;

        } catch (\Exception $e) {
            Log::error('Failed to list R2 files: ' . $e->getMessage());
            return [];
        }
    }
}
