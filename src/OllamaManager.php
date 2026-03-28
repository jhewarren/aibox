<?php

declare(strict_types=1);

namespace AIBox;

/**
 * Manages the Ollama installation, service lifecycle, and model availability.
 */
class OllamaManager
{
    public const DEFAULT_MODEL = 'llama3.1:8b';
    public const OLLAMA_BASE_URL = 'http://localhost:11434';

    /** Install script URL published by the Ollama project. */
    private const INSTALL_SCRIPT_URL = 'https://ollama.com/install.sh';

    private string $model;

    public function __construct(string $model = self::DEFAULT_MODEL)
    {
        $this->model = $model;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Ensure Ollama is installed, running, and the target model is pulled.
     *
     * @return array{installed: bool, running: bool, model_ready: bool, messages: list<string>}
     */
    public function setup(): array
    {
        $messages = [];
        $installed = $this->isInstalled();

        if (!$installed) {
            $messages[] = 'Ollama not found – attempting installation…';
            $installed = $this->install($messages);
        } else {
            $messages[] = 'Ollama is already installed.';
        }

        $running = false;
        if ($installed) {
            $running = $this->isRunning();
            if (!$running) {
                $messages[] = 'Ollama service is not running – starting it…';
                $running = $this->start($messages);
            } else {
                $messages[] = 'Ollama service is already running.';
            }
        }

        $modelReady = false;
        if ($running) {
            $modelReady = $this->isModelAvailable();
            if (!$modelReady) {
                $messages[] = "Model '{$this->model}' not found – pulling (this may take a while)…";
                $modelReady = $this->pullModel($messages);
            } else {
                $messages[] = "Model '{$this->model}' is already available.";
            }
        }

        return [
            'installed'   => $installed,
            'running'     => $running,
            'model_ready' => $modelReady,
            'messages'    => $messages,
        ];
    }

    // -------------------------------------------------------------------------
    // Detection helpers
    // -------------------------------------------------------------------------

    public function isInstalled(): bool
    {
        $output = $this->exec('command -v ollama 2>/dev/null || which ollama 2>/dev/null');
        return $output !== null && $output !== '';
    }

    public function isRunning(): bool
    {
        $context = stream_context_create(['http' => ['timeout' => 3]]);
        $response = @file_get_contents(self::OLLAMA_BASE_URL . '/api/tags', false, $context);
        return $response !== false;
    }

    public function isModelAvailable(): bool
    {
        $context = stream_context_create(['http' => ['timeout' => 5]]);
        $response = @file_get_contents(self::OLLAMA_BASE_URL . '/api/tags', false, $context);
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['models'])) {
            return false;
        }

        foreach ($data['models'] as $m) {
            if (isset($m['name']) && $m['name'] === $this->model) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Operations
    // -------------------------------------------------------------------------

    /**
     * Install Ollama using the official install script (Linux/macOS).
     *
     * @param list<string> $messages
     */
    public function install(array &$messages): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $messages[] = '[ERROR] Automatic Ollama installation is not supported on Windows. '
                . 'Please download from https://ollama.com/download and re-run aibox.';
            return false;
        }

        // Download and execute the official installer.
        $cmd = 'curl -fsSL ' . escapeshellarg(self::INSTALL_SCRIPT_URL) . ' | sh 2>&1';
        $output = $this->exec($cmd);

        if ($this->isInstalled()) {
            $messages[] = 'Ollama installed successfully.';
            return true;
        }

        $messages[] = '[ERROR] Ollama installation failed. Output: ' . ($output ?? '(none)');
        return false;
    }

    /**
     * Start the Ollama service in the background.
     *
     * @param list<string> $messages
     */
    public function start(array &$messages): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->exec('start /B ollama serve >nul 2>&1');
        } else {
            $this->exec('nohup ollama serve > /tmp/ollama.log 2>&1 &');
        }

        // Allow a few seconds for the service to start.
        sleep(3);

        if ($this->isRunning()) {
            $messages[] = 'Ollama service started successfully.';
            return true;
        }

        $messages[] = '[ERROR] Failed to start the Ollama service. '
            . 'Check /tmp/ollama.log for details.';
        return false;
    }

    /**
     * Pull the configured model from the Ollama registry.
     *
     * @param list<string> $messages
     */
    public function pullModel(array &$messages): bool
    {
        $cmd = 'ollama pull ' . escapeshellarg($this->model) . ' 2>&1';
        $output = $this->exec($cmd);

        if ($this->isModelAvailable()) {
            $messages[] = "Model '{$this->model}' pulled successfully.";
            return true;
        }

        $messages[] = "[ERROR] Failed to pull model '{$this->model}'. Output: " . ($output ?? '(none)');
        return false;
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
