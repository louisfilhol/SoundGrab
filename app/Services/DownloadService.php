<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class DownloadService
{
    protected string $outputPath;
    protected string $ytDlpPath;

    public function __construct()
    {
        // Use user's home Downloads folder or app storage
        $this->outputPath = $_ENV['HOME'] . '/Downloads' ?? storage_path('app/downloads');
        $this->ytDlpPath = $this->findYtDlp();
        
        if (!is_dir($this->outputPath)) {
            mkdir($this->outputPath, 0755, true);
        }
    }

    protected function findYtDlp(): string
    {
        $paths = [
            '/usr/local/bin/yt-dlp',
            '/usr/bin/yt-dlp',
            '/opt/homebrew/bin/yt-dlp',
            $_ENV['HOME'] . '/.local/bin/yt-dlp',
            base_path('bin/yt-dlp'),
        ];

        foreach ($paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        $result = Process::run('which yt-dlp');
        if ($result->successful()) {
            return trim($result->output());
        }

        return 'yt-dlp';
    }

    public function getInfo(string $url): array
    {
        $result = Process::timeout(60)->run([
            $this->ytDlpPath,
            '--dump-json',
            '--no-playlist',
            $url,
        ]);

        if (!$result->successful()) {
            throw new \Exception('Failed to fetch info: ' . $result->errorOutput());
        }

        $info = json_decode($result->output(), true);

        return [
            'id' => $info['id'] ?? Str::random(10),
            'title' => $info['title'] ?? 'Unknown',
            'duration' => $info['duration'] ?? 0,
            'thumbnail' => $info['thumbnail'] ?? null,
            'uploader' => $info['uploader'] ?? 'Unknown',
        ];
    }

    public function downloadAudio(string $url, string $format = 'mp3', string $quality = '320'): array
    {
        $info = $this->getInfo($url);
        
        $sanitizedTitle = $this->sanitizeFilename($info['title']);
        $outputTemplate = $this->outputPath . '/' . $sanitizedTitle . '.%(ext)s';
        
        $command = [
            $this->ytDlpPath,
            '--extract-audio',
            '--audio-format', $format,
            '--audio-quality', $this->mapQuality($quality, $format),
            '--output', $outputTemplate,
            '--no-playlist',
        ];

        if ($format === 'mp3') {
            $command[] = '--embed-thumbnail';
            $command[] = '--add-metadata';
        }

        $command[] = $url;

        $result = Process::timeout(600)->run($command);

        if (!$result->successful()) {
            throw new \Exception('Download failed: ' . $result->errorOutput());
        }

        $outputFile = $this->findOutputFile($this->outputPath, $sanitizedTitle, $format);

        return [
            'success' => true,
            'title' => $info['title'],
            'file_path' => $outputFile,
            'file_name' => basename($outputFile),
            'format' => $format,
            'quality' => $quality,
        ];
    }

    protected function mapQuality(string $quality, string $format): string
    {
        if (in_array($format, ['mp3', 'opus', 'm4a', 'aac'])) {
            return match ($quality) {
                '128' => '128K',
                '192' => '192K',
                '256' => '256K',
                '320' => '320K',
                default => '192K',
            };
        }
        return '0';
    }

    protected function sanitizeFilename(string $filename): string
    {
        $filename = preg_replace('/[<>:"\/\\|?*]/', '', $filename);
        $filename = preg_replace('/\s+/', ' ', $filename);
        $filename = trim($filename);
        
        if (strlen($filename) > 200) {
            $filename = substr($filename, 0, 200);
        }

        return $filename ?: 'download';
    }

    protected function findOutputFile(string $directory, string $baseName, string $expectedExt): string
    {
        $expectedFile = $directory . '/' . $baseName . '.' . $expectedExt;
        
        if (file_exists($expectedFile)) {
            return $expectedFile;
        }

        $files = glob($directory . '/' . $baseName . '.*');
        
        if (!empty($files)) {
            usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
            return $files[0];
        }

        return $expectedFile;
    }

    public function getOutputPath(): string
    {
        return $this->outputPath;
    }

    public function setOutputPath(string $path): self
    {
        $this->outputPath = $path;
        
        if (!is_dir($this->outputPath)) {
            mkdir($this->outputPath, 0755, true);
        }

        return $this;
    }

    public function isAvailable(): bool
    {
        $result = Process::run([$this->ytDlpPath, '--version']);
        return $result->successful();
    }
}
