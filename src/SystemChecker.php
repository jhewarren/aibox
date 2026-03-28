<?php

declare(strict_types=1);

namespace AIBox;

/**
 * Checks the local machine's capabilities to determine whether
 * it meets the minimum requirements for running the AI stack.
 */
class SystemChecker
{
    /** Minimum required RAM in megabytes (8 GB). */
    public const MIN_RAM_MB = 8192;

    /** Minimum required free disk space in megabytes (10 GB). */
    public const MIN_DISK_MB = 10240;

    /** @var array<string,mixed> Collected system information. */
    private array $info = [];

    /**
     * Run all capability checks and return a summary array.
     *
     * @return array{
     *   os: string,
     *   arch: string,
     *   cpu_cores: int,
     *   ram_total_mb: int,
     *   disk_free_mb: int,
     *   docker_available: bool,
     *   nvidia_gpu: bool,
     *   meets_requirements: bool,
     *   warnings: list<string>
     * }
     */
    public function check(): array
    {
        $this->info = [
            'os'               => $this->detectOS(),
            'arch'             => $this->detectArch(),
            'cpu_cores'        => $this->detectCpuCores(),
            'ram_total_mb'     => $this->detectRamMb(),
            'disk_free_mb'     => $this->detectDiskFreeMb(),
            'docker_available' => $this->isDockerAvailable(),
            'nvidia_gpu'       => $this->hasNvidiaGpu(),
        ];

        $warnings = $this->buildWarnings();
        $meetsRequirements = empty(array_filter($warnings, fn($w) => str_starts_with($w, '[ERROR]')));

        return array_merge($this->info, [
            'meets_requirements' => $meetsRequirements,
            'warnings'           => $warnings,
        ]);
    }

    // -------------------------------------------------------------------------
    // Detection helpers
    // -------------------------------------------------------------------------

    private function detectOS(): string
    {
        return PHP_OS_FAMILY;
    }

    private function detectArch(): string
    {
        return php_uname('m');
    }

    private function detectCpuCores(): int
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $output = $this->exec('nproc --all 2>/dev/null');
            if ($output !== null && ctype_digit(trim($output))) {
                return (int) trim($output);
            }
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $output = $this->exec('sysctl -n hw.logicalcpu 2>/dev/null');
            if ($output !== null && ctype_digit(trim($output))) {
                return (int) trim($output);
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $output = $this->exec('wmic cpu get NumberOfLogicalProcessors /value 2>nul');
            if ($output !== null && preg_match('/NumberOfLogicalProcessors=(\d+)/i', $output, $m)) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /** Returns total physical RAM in MB. */
    private function detectRamMb(): int
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $meminfo = @file_get_contents('/proc/meminfo');
            if ($meminfo !== false && preg_match('/MemTotal:\s+(\d+)\s+kB/i', $meminfo, $m)) {
                return (int) round((int) $m[1] / 1024);
            }
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $output = $this->exec('sysctl -n hw.memsize 2>/dev/null');
            if ($output !== null && ctype_digit(trim($output))) {
                return (int) round((int) trim($output) / 1024 / 1024);
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $output = $this->exec('wmic OS get TotalVisibleMemorySize /value 2>nul');
            if ($output !== null && preg_match('/TotalVisibleMemorySize=(\d+)/i', $output, $m)) {
                return (int) round((int) $m[1] / 1024);
            }
        }

        return 0;
    }

    /** Returns free disk space for the current working directory in MB. */
    private function detectDiskFreeMb(): int
    {
        $free = disk_free_space(getcwd() ?: '/');
        return $free !== false ? (int) round($free / 1024 / 1024) : 0;
    }

    private function isDockerAvailable(): bool
    {
        $output = $this->exec('docker info 2>&1');
        return $output !== null && !str_contains(strtolower($output), 'error');
    }

    private function hasNvidiaGpu(): bool
    {
        $output = $this->exec('nvidia-smi --query-gpu=name --format=csv,noheader 2>/dev/null');
        return $output !== null && trim($output) !== '';
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /** @return list<string> */
    private function buildWarnings(): array
    {
        $warnings = [];

        if ($this->info['ram_total_mb'] > 0 && $this->info['ram_total_mb'] < self::MIN_RAM_MB) {
            $warnings[] = sprintf(
                '[ERROR] Insufficient RAM: %d MB detected, %d MB required.',
                $this->info['ram_total_mb'],
                self::MIN_RAM_MB
            );
        }

        if ($this->info['disk_free_mb'] > 0 && $this->info['disk_free_mb'] < self::MIN_DISK_MB) {
            $warnings[] = sprintf(
                '[ERROR] Insufficient disk space: %d MB free, %d MB required.',
                $this->info['disk_free_mb'],
                self::MIN_DISK_MB
            );
        }

        if (!$this->info['docker_available']) {
            $warnings[] = '[ERROR] Docker is not available. Docker is required to run open-webui.';
        }

        if (!$this->info['nvidia_gpu']) {
            $warnings[] = '[WARN] No NVIDIA GPU detected. Inference will run on CPU and may be slow.';
        }

        return $warnings;
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    /** Execute a shell command and return trimmed output, or null on failure. */
    protected function exec(string $command): ?string
    {
        $output = shell_exec($command);
        return $output !== null ? trim($output) : null;
    }
}
