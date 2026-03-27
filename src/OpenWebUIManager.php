<?php

declare(strict_types=1);

namespace AIBox;

/**
 * Manages the open-webui container that provides the browser-based
 * frontend for the Ollama back-end.
 */
class OpenWebUIManager
{
    public const CONTAINER_NAME = 'open-webui';
    public const IMAGE = 'ghcr.io/open-webui/open-webui:main';
    public const HOST_PORT = 3000;
    public const DATA_VOLUME = 'open-webui';

    private string $ollamaBaseUrl;

    public function __construct(string $ollamaBaseUrl = OllamaManager::OLLAMA_BASE_URL)
    {
        $this->ollamaBaseUrl = $ollamaBaseUrl;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Ensure open-webui is pulled, created, and running.
     *
     * @return array{running: bool, url: string, messages: list<string>}
     */
    public function setup(): array
    {
        $messages = [];

        if (!$this->isDockerAvailable()) {
            $messages[] = '[ERROR] Docker is not available. Cannot set up open-webui.';
            return ['running' => false, 'url' => '', 'messages' => $messages];
        }

        // Pull the latest image.
        $messages[] = 'Pulling open-webui image (this may take a while on first run)…';
        $this->pullImage($messages);

        // Stop and remove any stale container so we can re-create cleanly.
        $this->removeStaleContainer($messages);

        // Create and start the container.
        $running = $this->run($messages);
        $url = $running ? 'http://localhost:' . self::HOST_PORT : '';

        return [
            'running'  => $running,
            'url'      => $url,
            'messages' => $messages,
        ];
    }

    // -------------------------------------------------------------------------
    // Detection helpers
    // -------------------------------------------------------------------------

    public function isDockerAvailable(): bool
    {
        $output = $this->exec('docker info 2>&1');
        return $output !== null && !str_contains(strtolower($output), 'error');
    }

    public function isRunning(): bool
    {
        $output = $this->exec(
            'docker inspect --format="{{.State.Running}}" '
            . escapeshellarg(self::CONTAINER_NAME) . ' 2>/dev/null'
        );
        return $output === '"true"' || $output === 'true';
    }

    // -------------------------------------------------------------------------
    // Operations
    // -------------------------------------------------------------------------

    /** @param list<string> $messages */
    private function pullImage(array &$messages): void
    {
        $output = $this->exec('docker pull ' . escapeshellarg(self::IMAGE) . ' 2>&1');
        if ($output !== null && str_contains($output, 'Status: ')) {
            $messages[] = 'Image pull complete.';
        }
    }

    /** Remove an existing (possibly stopped) container by name. */
    /** @param list<string> $messages */
    private function removeStaleContainer(array &$messages): void
    {
        $exists = $this->exec(
            'docker ps -a --filter name=^/' . self::CONTAINER_NAME . '$ --format "{{.Names}}" 2>/dev/null'
        );
        if ($exists !== null && $exists !== '') {
            $this->exec('docker rm -f ' . escapeshellarg(self::CONTAINER_NAME) . ' 2>&1');
            $messages[] = 'Removed existing open-webui container.';
        }
    }

    /**
     * Create and start the open-webui container.
     *
     * @param list<string> $messages
     */
    private function run(array &$messages): bool
    {
        // Determine whether to mount the GPU.
        $gpuFlag = $this->hasNvidiaGpu() ? '--gpus all ' : '';

        $cmd = 'docker run -d'
            . ' --name ' . escapeshellarg(self::CONTAINER_NAME)
            . ' ' . $gpuFlag
            . '-p ' . self::HOST_PORT . ':8080'
            . ' -v ' . escapeshellarg(self::DATA_VOLUME) . ':/app/backend/data'
            . ' --add-host=host.docker.internal:host-gateway'
            . ' -e OLLAMA_BASE_URL=' . escapeshellarg($this->ollamaBaseUrl)
            . ' --restart always'
            . ' ' . escapeshellarg(self::IMAGE)
            . ' 2>&1';

        $output = $this->exec($cmd);

        if ($this->isRunning()) {
            $messages[] = 'open-webui container started successfully.';
            return true;
        }

        $messages[] = '[ERROR] Failed to start open-webui container. Output: ' . ($output ?? '(none)');
        return false;
    }

    private function hasNvidiaGpu(): bool
    {
        $output = $this->exec('nvidia-smi --query-gpu=name --format=csv,noheader 2>/dev/null');
        return $output !== null && trim($output) !== '';
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    protected function exec(string $command): ?string
    {
        $output = shell_exec($command);
        return $output !== null ? trim($output) : null;
    }
}
