# AIBox

A simple tool to facilitate the implementation of a **standalone AI solution** on a local machine.

AIBox automates the full setup of a local, private AI stack:

| Component | Description |
|-----------|-------------|
| **Ollama** | Runs the `llama3.1:8b` language model locally |
| **open-webui** | Browser-based chat frontend (Docker container) |

---

## Requirements

| Requirement | Minimum |
|-------------|---------|
| PHP | 8.1 or newer |
| RAM | 8 GB (16 GB recommended) |
| Free disk | 10 GB |
| Docker | Required for open-webui |
| GPU | Optional – NVIDIA GPU speeds up inference |

---

## Quick Start

```bash
# Clone the repository
git clone https://github.com/jhewarren/aibox.git
cd aibox

# Bootstrap (installs Composer deps, then runs the setup)
bash install.sh
```

The `install.sh` script will:
1. Verify PHP 8.1+
2. Download and run Composer if it is not already installed
3. Install PHP dependencies
4. Launch `bin/aibox`

---

## What `bin/aibox` does

```
Step 1 – System Capability Check
  Checks RAM, disk, Docker availability, and optional NVIDIA GPU.
  Aborts if hard requirements (RAM / disk / Docker) are not met.

Step 2 – Ollama Setup
  Installs Ollama if not present (Linux/macOS).
  Starts the Ollama service if it is not running.
  Pulls the llama3.1:8b model if it has not been downloaded.

Step 3 – open-webui Setup
  Pulls the ghcr.io/open-webui/open-webui:main Docker image.
  Starts the container on http://localhost:3000, pointing it at Ollama.
```

After a successful run you will see:

```
  open-webui is running at : http://localhost:3000
  Ollama API               : http://localhost:11434
  Model loaded             : llama3.1:8b
```

Open **http://localhost:3000** in your browser to start chatting.

---

## Running Tests

```bash
php vendor/phpunit/phpunit/phpunit --colors=always
```

---

## Project Structure

```
aibox/
├── bin/
│   └── aibox              # Main CLI entry point
├── src/
│   ├── SystemChecker.php  # Detects CPU, RAM, disk, GPU, Docker
│   ├── OllamaManager.php  # Installs, starts, and configures Ollama
│   └── OpenWebUIManager.php # Pulls and runs open-webui in Docker
├── tests/
│   ├── SystemCheckerTest.php
│   ├── OllamaManagerTest.php
│   └── OpenWebUIManagerTest.php
├── composer.json
├── phpunit.xml
└── install.sh             # Bootstrap script
```
