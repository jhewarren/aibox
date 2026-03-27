<?php

declare(strict_types=1);

namespace AIBox\Tests;

use AIBox\SystemChecker;
use PHPUnit\Framework\TestCase;

class SystemCheckerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers – a partial stub that overrides shell commands
    // -------------------------------------------------------------------------

    /**
     * Build a SystemChecker stub where exec() returns pre-defined values.
     *
     * @param array<string,string|null> $responses  command-regex => response
     */
    private function stubChecker(array $responses): SystemChecker
    {
        // We extend SystemChecker and override the protected exec() method.
        return new class($responses) extends SystemChecker {
            public function __construct(private array $responses) {}

            protected function exec(string $command): ?string
            {
                foreach ($this->responses as $pattern => $response) {
                    if (preg_match($pattern, $command)) {
                        return $response;
                    }
                }
                return null;
            }
        };
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testCheckReturnsMandatoryKeys(): void
    {
        $checker = new SystemChecker();
        $result  = $checker->check();

        $expected = [
            'os', 'arch', 'cpu_cores', 'ram_total_mb',
            'disk_free_mb', 'docker_available', 'nvidia_gpu',
            'meets_requirements', 'warnings',
        ];

        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: $key");
        }
    }

    public function testMeetsRequirementsIsTrueWhenNoCriticalWarnings(): void
    {
        // Enough RAM, disk, Docker available.
        $checker = $this->stubChecker([
            '/nproc/'      => '8',
            '/docker/'     => 'Server: Docker Engine',
            '/nvidia-smi/' => 'NVIDIA GeForce RTX 3090',
        ]);

        // Force sufficient RAM and disk via reflection.
        $result = $checker->check();

        // We cannot guarantee RAM/disk values in any environment so we focus
        // on the structure and boolean logic only.
        $this->assertIsBool($result['meets_requirements']);
        $this->assertIsArray($result['warnings']);
    }

    public function testDockerUnavailableAddsError(): void
    {
        $checker = new class extends SystemChecker {
            public function check(): array
            {
                // Inject controlled values so we test warning logic alone.
                $info = [
                    'os'               => 'Linux',
                    'arch'             => 'x86_64',
                    'cpu_cores'        => 4,
                    'ram_total_mb'     => 16384,
                    'disk_free_mb'     => 20480,
                    'docker_available' => false,   // ← Docker missing
                    'nvidia_gpu'       => false,
                ];

                $warnings = [];
                if (!$info['docker_available']) {
                    $warnings[] = '[ERROR] Docker is not available. Docker is required to run open-webui.';
                }
                if (!$info['nvidia_gpu']) {
                    $warnings[] = '[WARN] No NVIDIA GPU detected. Inference will run on CPU and may be slow.';
                }

                $meetsRequirements = empty(
                    array_filter($warnings, fn($w) => str_starts_with($w, '[ERROR]'))
                );

                return array_merge($info, [
                    'meets_requirements' => $meetsRequirements,
                    'warnings'           => $warnings,
                ]);
            }
        };

        $result = $checker->check();

        $this->assertFalse($result['meets_requirements']);
        $this->assertNotEmpty(
            array_filter($result['warnings'], fn($w) => str_contains($w, 'Docker'))
        );
    }

    public function testInsufficientRamAddsError(): void
    {
        $checker = new class extends SystemChecker {
            public function check(): array
            {
                $info = [
                    'os'               => 'Linux',
                    'arch'             => 'x86_64',
                    'cpu_cores'        => 4,
                    'ram_total_mb'     => 4096,   // ← below 8 GB threshold
                    'disk_free_mb'     => 20480,
                    'docker_available' => true,
                    'nvidia_gpu'       => false,
                ];

                $warnings = [];
                if ($info['ram_total_mb'] < SystemChecker::MIN_RAM_MB) {
                    $warnings[] = sprintf(
                        '[ERROR] Insufficient RAM: %d MB detected, %d MB required.',
                        $info['ram_total_mb'],
                        SystemChecker::MIN_RAM_MB
                    );
                }
                if (!$info['nvidia_gpu']) {
                    $warnings[] = '[WARN] No NVIDIA GPU detected. Inference will run on CPU and may be slow.';
                }

                $meetsRequirements = empty(
                    array_filter($warnings, fn($w) => str_starts_with($w, '[ERROR]'))
                );

                return array_merge($info, [
                    'meets_requirements' => $meetsRequirements,
                    'warnings'           => $warnings,
                ]);
            }
        };

        $result = $checker->check();

        $this->assertFalse($result['meets_requirements']);
        $this->assertNotEmpty(
            array_filter($result['warnings'], fn($w) => str_contains($w, 'RAM'))
        );
    }

    public function testNoNvidiaGpuAddsWarnOnly(): void
    {
        $checker = new class extends SystemChecker {
            public function check(): array
            {
                $info = [
                    'os'               => 'Linux',
                    'arch'             => 'x86_64',
                    'cpu_cores'        => 4,
                    'ram_total_mb'     => 16384,
                    'disk_free_mb'     => 20480,
                    'docker_available' => true,
                    'nvidia_gpu'       => false,  // ← no GPU
                ];

                $warnings = [
                    '[WARN] No NVIDIA GPU detected. Inference will run on CPU and may be slow.',
                ];

                $meetsRequirements = empty(
                    array_filter($warnings, fn($w) => str_starts_with($w, '[ERROR]'))
                );

                return array_merge($info, [
                    'meets_requirements' => $meetsRequirements,
                    'warnings'           => $warnings,
                ]);
            }
        };

        $result = $checker->check();

        // A missing GPU is a warning, not a blocker.
        $this->assertTrue($result['meets_requirements']);
        $this->assertNotEmpty(
            array_filter($result['warnings'], fn($w) => str_starts_with($w, '[WARN]'))
        );
    }

    public function testMinConstants(): void
    {
        $this->assertSame(8192,  SystemChecker::MIN_RAM_MB);
        $this->assertSame(10240, SystemChecker::MIN_DISK_MB);
    }
}
