<?php

declare(strict_types=1);

namespace AIBox\Tests;

use AIBox\OllamaManager;
use PHPUnit\Framework\TestCase;

class OllamaManagerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build an OllamaManager where exec() and HTTP lookups return test doubles.
     *
     * @param array<string,string|null> $execResponses  pattern => response
     * @param bool $httpResponds  whether isRunning()/isModelAvailable() returns true
     * @param bool $modelPresent  whether isModelAvailable() finds the model
     */
    private function stubManager(
        array $execResponses = [],
        bool  $httpResponds = false,
        bool  $modelPresent = false,
        string $model = OllamaManager::DEFAULT_MODEL
    ): OllamaManager {
        return new class($execResponses, $httpResponds, $modelPresent, $model) extends OllamaManager {
            public function __construct(
                private array  $execResponses,
                private bool   $httpResponds,
                private bool   $modelPresent,
                string         $model
            ) {
                parent::__construct($model);
            }

            protected function exec(string $command): ?string
            {
                foreach ($this->execResponses as $pattern => $response) {
                    if (preg_match($pattern, $command)) {
                        return $response;
                    }
                }
                return null;
            }

            public function isRunning(): bool
            {
                return $this->httpResponds;
            }

            public function isModelAvailable(): bool
            {
                return $this->modelPresent;
            }
        };
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testDefaultModelIsLlama31(): void
    {
        $manager = new OllamaManager();
        $this->assertSame('llama3.1:8b', $manager->getModel());
    }

    public function testCustomModelIsPreserved(): void
    {
        $manager = new OllamaManager('phi3:mini');
        $this->assertSame('phi3:mini', $manager->getModel());
    }

    public function testSetupSkipsInstallWhenAlreadyInstalled(): void
    {
        $manager = $this->stubManager(
            execResponses: ['/command -v|which/' => '/usr/bin/ollama'],
            httpResponds:  true,
            modelPresent:  true
        );

        // Force isInstalled() to true by overriding it too.
        $manager2 = new class(true, true, true) extends OllamaManager {
            public function __construct(
                private bool $installed,
                private bool $running,
                private bool $model,
            ) {
                parent::__construct();
            }

            public function isInstalled(): bool      { return $this->installed; }
            public function isRunning(): bool        { return $this->running; }
            public function isModelAvailable(): bool { return $this->model; }

            protected function exec(string $c): ?string { return null; }
        };

        $result = $manager2->setup();

        $this->assertTrue($result['installed']);
        $this->assertTrue($result['running']);
        $this->assertTrue($result['model_ready']);
        $this->assertNotEmpty($result['messages']);
    }

    public function testSetupReturnsErrorMessagesOnWindowsInstall(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows-only test.');
        }

        $manager = new class extends OllamaManager {
            public function isInstalled(): bool      { return false; }
            public function isRunning(): bool        { return false; }
            public function isModelAvailable(): bool { return false; }
            protected function exec(string $c): ?string { return null; }
        };

        $result = $manager->setup();
        $this->assertFalse($result['installed']);
        $this->assertStringContainsString('Windows', implode(' ', $result['messages']));
    }

    public function testSetupResultStructure(): void
    {
        $manager = new class extends OllamaManager {
            public function isInstalled(): bool      { return true; }
            public function isRunning(): bool        { return true; }
            public function isModelAvailable(): bool { return true; }
            protected function exec(string $c): ?string { return null; }
        };

        $result = $manager->setup();

        $this->assertArrayHasKey('installed',   $result);
        $this->assertArrayHasKey('running',     $result);
        $this->assertArrayHasKey('model_ready', $result);
        $this->assertArrayHasKey('messages',    $result);
        $this->assertIsArray($result['messages']);
    }

    public function testSetupAbortsWhenInstallFails(): void
    {
        $manager = new class extends OllamaManager {
            public function isInstalled(): bool      { return false; }
            public function isRunning(): bool        { return false; }
            public function isModelAvailable(): bool { return false; }

            public function install(array &$messages): bool
            {
                $messages[] = '[ERROR] Ollama installation failed.';
                return false;
            }

            protected function exec(string $c): ?string { return null; }
        };

        $result = $manager->setup();

        $this->assertFalse($result['installed']);
        $this->assertFalse($result['running']);
        $this->assertFalse($result['model_ready']);
    }

    public function testBaseUrlConstant(): void
    {
        $this->assertStringStartsWith('http', OllamaManager::OLLAMA_BASE_URL);
    }
}
