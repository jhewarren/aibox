<?php

declare(strict_types=1);

namespace AIBox\Tests;

use AIBox\OpenWebUIManager;
use PHPUnit\Framework\TestCase;

class OpenWebUIManagerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function stubManager(
        bool $dockerAvailable,
        bool $containerRunning
    ): OpenWebUIManager {
        return new class($dockerAvailable, $containerRunning) extends OpenWebUIManager {
            public function __construct(
                private bool $dockerUp,
                private bool $containerUp,
            ) {
                parent::__construct();
            }

            public function isDockerAvailable(): bool { return $this->dockerUp; }
            public function isRunning(): bool         { return $this->containerUp; }

            protected function exec(string $command): ?string
            {
                // Simulate docker pull returning a status line.
                if (str_contains($command, 'docker pull')) {
                    return 'Status: Image is up to date';
                }
                // Simulate container not existing (no stale container to remove).
                if (str_contains($command, 'docker ps')) {
                    return '';
                }
                // Simulate docker run succeeding (we flip containerUp in isRunning).
                return 'abc123def456';
            }
        };
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testSetupResultStructure(): void
    {
        $manager = $this->stubManager(dockerAvailable: true, containerRunning: true);
        $result  = $manager->setup();

        $this->assertArrayHasKey('running',  $result);
        $this->assertArrayHasKey('url',      $result);
        $this->assertArrayHasKey('messages', $result);
    }

    public function testSetupSuccessWhenDockerAndContainerAvailable(): void
    {
        $manager = $this->stubManager(dockerAvailable: true, containerRunning: true);
        $result  = $manager->setup();

        $this->assertTrue($result['running']);
        $this->assertStringContainsString((string) OpenWebUIManager::HOST_PORT, $result['url']);
    }

    public function testSetupFailsWhenDockerUnavailable(): void
    {
        $manager = $this->stubManager(dockerAvailable: false, containerRunning: false);
        $result  = $manager->setup();

        $this->assertFalse($result['running']);
        $this->assertSame('', $result['url']);
        $this->assertNotEmpty(
            array_filter($result['messages'], fn($m) => str_contains($m, 'Docker'))
        );
    }

    public function testSetupFailsWhenContainerDoesNotStart(): void
    {
        $manager = $this->stubManager(dockerAvailable: true, containerRunning: false);
        $result  = $manager->setup();

        $this->assertFalse($result['running']);
        $this->assertSame('', $result['url']);
    }

    public function testConstants(): void
    {
        $this->assertSame('open-webui', OpenWebUIManager::CONTAINER_NAME);
        $this->assertSame(3000,         OpenWebUIManager::HOST_PORT);
        $this->assertNotEmpty(OpenWebUIManager::IMAGE);
        $this->assertNotEmpty(OpenWebUIManager::DATA_VOLUME);
    }

    public function testUrlContainsHostPort(): void
    {
        $manager = $this->stubManager(dockerAvailable: true, containerRunning: true);
        $result  = $manager->setup();

        $this->assertSame(
            'http://localhost:' . OpenWebUIManager::HOST_PORT,
            $result['url']
        );
    }
}
