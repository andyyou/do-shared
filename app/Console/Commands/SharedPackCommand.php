<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SharedPackCommand extends Command
{
    protected $signature = 'shared:pack 
                            {--type= : 僅處理特定類型的檔案 (model, job, service, etc.)}
                            {--file= : 特定檔案}
                            {--dry-run : 預覽模式，不實際移動檔案}';

    protected $description = 'Move php artisan make files to shared package.';

    private $fileTypeMapping = [
        'model' => [
            'directories' => ['app/Models'],
            'extensions' => ['php'],
            'make_command' => 'make:model',
        ],
        'job' => [
            'directories' => ['app/Jobs'],
            'extensions' => ['php'],
            'make_command' => 'make:job',
        ],
        'service' => [
            'directories' => ['app/Services'],
            'extensions' => ['php'],
            'make_command' => null,
        ],
        'provider' => [
            'directories' => ['app/Providers'],
            'extensions' => ['php'],
            'make_command' => 'make:provider',
        ],
        'controller' => [
            'directories' => ['app/Http/Controllers'],
            'extensions' => ['php'],
            'make_command' => 'make:controller',
        ],
        'middleware' => [
            'directories' => ['app/Http/Middleware'],
            'extensions' => ['php'],
            'make_command' => 'make:middleware',
        ],
        'command' => [
            'directories' => ['app/Console/Commands'],
            'extensions' => ['php'],
            'make_command' => 'make:command',
        ],
        'event' => [
            'directories' => ['app/Events'],
            'extensions' => ['php'],
            'make_command' => 'make:event',
        ],
        'listener' => [
            'directories' => ['app/Listeners'],
            'extensions' => ['php'],
            'make_command' => 'make:listener',
        ],
        'mail' => [
            'directories' => ['app/Mail'],
            'extensions' => ['php'],
            'make_command' => 'make:mail',
        ],
        'notification' => [
            'directories' => ['app/Notifications'],
            'extensions' => ['php'],
            'make_command' => 'make:notification',
        ],
        'migration' => [
            'directories' => ['database/migrations'],
            'extensions' => ['php'],
            'make_command' => 'make:migration',
        ],
        'factory' => [
            'directories' => ['database/factories'],
            'extensions' => ['php'],
            'make_command' => 'make:factory',
        ],
        'seeder' => [
            'directories' => ['database/seeders'],
            'extensions' => ['php'],
            'make_command' => 'make:seeder',
        ],
    ];

    public function handle()
    {
        try {
            // 檢查是否在 Git 倉庫中
            if (! $this->isGitRepository()) {
                $this->error('Not a Git repository. Please run this command in a Git repository.');

                return 1;
            }

            // 獲取共用套件路徑
            $sharedPath = $this->getSharedPackagePath();
            if (! $sharedPath) {
                $this->error('Shared package path not found. Please check your setup.');

                return 1;
            }

            // 獲取要打包的檔案
            $filesToPack = $this->getFilesToPack();

            if (empty($filesToPack)) {
                $this->info('No files to pack.');

                return 0;
            }

            // 顯示檔案列表
            $this->displayFileList($filesToPack, $sharedPath);

            // 檢查衝突
            $conflicts = $this->checkConflicts($filesToPack, $sharedPath);
            if (! empty($conflicts)) {
                $this->displayConflicts($conflicts);
            }

            // 如果是 dry-run 模式，不實際移動檔案
            if ($this->option('dry-run')) {
                $this->info('Dry run completed. No files were moved.');

                return 0;
            }

            // 確認是否執行
            if (! $this->confirm('Do you want to pack these files?')) {
                $this->info('Operation cancelled.');

                return 0;
            }

            // 執行檔案移動
            $this->packFiles($filesToPack, $sharedPath);

            $this->info('Files packed successfully!');

            return 0;

        } catch (\Exception $e) {
            $this->error('Error: '.$e->getMessage());

            return 1;
        }
    }

    private function isGitRepository(): bool
    {
        return is_dir(base_path('.git'));
    }

    private function getSharedPackagePath(): ?string
    {
        // 優先順序：vendor 安裝路徑 → 開發環境路徑 → 環境變數路徑
        $paths = [
            base_path('vendor/andyyou/do-shared'),
            base_path('../do-shared'),
            env('SHARED_PACKAGE_PATH'),
        ];

        foreach ($paths as $path) {
            if ($path && File::exists($path) && File::exists($path.'/composer.json')) {
                return $path;
            }
        }

        return null;
    }

    private function getFilesToPack(): array
    {
        $specificFile = $this->option('file');
        $specificType = $this->option('type');

        if ($specificFile) {
            return $this->getSpecificFile($specificFile);
        }

        $gitFiles = $this->getGitStatusFiles();
        $filteredFiles = $this->filterRelevantFiles($gitFiles);

        if ($specificType) {
            return $this->filterByType($filteredFiles, $specificType);
        }

        return $filteredFiles;
    }

    private function getSpecificFile(string $filePath): array
    {
        $fullPath = base_path($filePath);

        if (! File::exists($fullPath)) {
            throw new \Exception("File not found: {$filePath}");
        }

        $fileType = $this->determineFileType($filePath);

        return [
            $filePath => [
                'path' => $filePath,
                'type' => $fileType,
                'full_path' => $fullPath,
            ],
        ];
    }

    private function getGitStatusFiles(): array
    {
        $output = [];
        exec('git status --porcelain 2>/dev/null', $output);

        $files = [];
        foreach ($output as $line) {
            // 解析 Git 狀態輸出
            if (preg_match('/^(\?\?|[AM])\s+(.+)$/', $line, $matches)) {
                $files[] = trim($matches[2]);
            }
        }

        return $files;
    }

    private function filterRelevantFiles(array $files): array
    {
        $relevantFiles = [];

        foreach ($files as $file) {
            // 只處理 app/ 和 database/ 目錄下的 PHP 檔案
            if (Str::startsWith($file, ['app/', 'database/']) && Str::endsWith($file, '.php')) {
                $fileType = $this->determineFileType($file);

                $relevantFiles[$file] = [
                    'path' => $file,
                    'type' => $fileType,
                    'full_path' => base_path($file),
                ];
            }
        }

        return $relevantFiles;
    }

    private function filterByType(array $files, string $type): array
    {
        return array_filter($files, function ($file) use ($type) {
            return $file['type'] === $type;
        });
    }

    private function determineFileType(string $filePath): string
    {
        foreach ($this->fileTypeMapping as $type => $config) {
            foreach ($config['directories'] as $directory) {
                if (Str::startsWith($filePath, $directory.'/')) {
                    return $type;
                }
            }
        }

        return 'unknown';
    }

    private function displayFileList(array $files, string $sharedPath): void
    {
        $this->info('Found files to pack:');
        $this->line('');

        $groupedFiles = [];
        foreach ($files as $file) {
            $type = $file['type'];
            if (! isset($groupedFiles[$type])) {
                $groupedFiles[$type] = [];
            }
            $groupedFiles[$type][] = $file;
        }

        foreach ($groupedFiles as $type => $typeFiles) {
            $count = count($typeFiles);
            $this->line('<info>'.Str::title($type)." ({$count} file".($count > 1 ? 's' : '').'):</info>');

            foreach ($typeFiles as $file) {
                $targetPath = $this->getTargetPath($file['path'], $sharedPath);
                $this->line("  ✓ {$file['path']} → {$targetPath}");
            }

            $this->line('');
        }
    }

    private function checkConflicts(array $files, string $sharedPath): array
    {
        $conflicts = [];

        foreach ($files as $file) {
            $targetPath = $this->getTargetPath($file['path'], $sharedPath);
            $targetFullPath = $sharedPath.'/'.$targetPath;

            if (File::exists($targetFullPath)) {
                $conflicts[] = [
                    'source' => $file['path'],
                    'target' => $targetPath,
                    'reason' => 'File already exists',
                ];
            }
        }

        return $conflicts;
    }

    private function displayConflicts(array $conflicts): void
    {
        $this->warn('Warnings:');
        foreach ($conflicts as $conflict) {
            $this->line("  ⚠ {$conflict['source']}: {$conflict['reason']} (will be skipped)");
        }
        $this->line('');
    }

    private function getTargetPath(string $sourcePath, string $sharedPath): string
    {
        // 顯示相對於專案根目錄的路徑，讓使用者清楚知道檔案移動到哪裡
        $relativePath = str_replace(base_path().'/', '', $sharedPath);

        return $relativePath.'/'.$sourcePath;
    }

    private function packFiles(array $files, string $sharedPath): void
    {
        $moved = 0;
        $skipped = 0;

        foreach ($files as $file) {
            $sourcePath = $file['full_path'];
            $targetPath = $sharedPath.'/'.$file['path'];

            if (File::exists($targetPath)) {
                $this->warn("Skipped (already exists): {$file['path']}");
                $skipped++;

                continue;
            }

            // 確保目標目錄存在
            $targetDir = dirname($targetPath);
            if (! File::exists($targetDir)) {
                File::makeDirectory($targetDir, 0755, true);
            }

            // 移動檔案
            if (File::move($sourcePath, $targetPath)) {
                $this->info("Moved: {$file['path']}");
                $moved++;
            } else {
                $this->error("Failed to move: {$file['path']}");
            }
        }

        $this->line('');
        $this->info("Summary: {$moved} files moved, {$skipped} files skipped.");
    }
}
