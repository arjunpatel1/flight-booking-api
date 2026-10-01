<?php

namespace Modules\Core\Services\Secret;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Secret Manager for Laravel Backend
 * 
 * Handles encryption, storage, and rotation of secrets
 */
class SecretManager
{
    private string $secretsPath;
    private string $encryptionKey;

    public function __construct()
    {
        $this->secretsPath = storage_path('app/secrets');
        $this->ensureSecretsDirectory();
        $this->encryptionKey = $this->getOrCreateEncryptionKey();
    }

    /**
     * Encrypt a secret value
     */
    public function encrypt(string $plaintext): string
    {
        try {
            return Crypt::encryptString($plaintext);
        } catch (\Exception $e) {
            Log::error('Failed to encrypt secret', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Failed to encrypt secret: ' . $e->getMessage());
        }
    }

    /**
     * Decrypt a secret value
     */
    public function decrypt(string $ciphertext): string
    {
        try {
            return Crypt::decryptString($ciphertext);
        } catch (\Exception $e) {
            Log::error('Failed to decrypt secret', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Failed to decrypt secret: ' . $e->getMessage());
        }
    }

    /**
     * Store a secret
     */
    public function store(string $key, string $value): void
    {
        try {
            $encrypted = $this->encrypt($value);
            $filePath = $this->secretsPath . '/' . $this->sanitizeKey($key) . '.enc';
            file_put_contents($filePath, $encrypted);
            Log::info('Secret stored successfully', ['key' => $key]);
        } catch (\Exception $e) {
            Log::error('Failed to store secret', ['key' => $key, 'error' => $e->getMessage()]);
            throw new \RuntimeException('Failed to store secret: ' . $e->getMessage());
        }
    }

    /**
     * Retrieve a secret
     */
    public function retrieve(string $key): ?string
    {
        try {
            $filePath = $this->secretsPath . '/' . $this->sanitizeKey($key) . '.enc';
            
            if (!file_exists($filePath)) {
                Log::warning('Secret not found', ['key' => $key]);
                return null;
            }

            $encrypted = file_get_contents($filePath);
            return $this->decrypt($encrypted);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve secret', ['key' => $key, 'error' => $e->getMessage()]);
            throw new \RuntimeException('Failed to retrieve secret: ' . $e->getMessage());
        }
    }

    /**
     * Check if a secret exists
     */
    public function exists(string $key): bool
    {
        $filePath = $this->secretsPath . '/' . $this->sanitizeKey($key) . '.enc';
        return file_exists($filePath);
    }

    /**
     * Delete a secret
     */
    public function delete(string $key): void
    {
        try {
            $filePath = $this->secretsPath . '/' . $this->sanitizeKey($key) . '.enc';
            
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::info('Secret deleted successfully', ['key' => $key]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to delete secret', ['key' => $key, 'error' => $e->getMessage()]);
            throw new \RuntimeException('Failed to delete secret: ' . $e->getMessage());
        }
    }

    /**
     * Rotate encryption key and re-encrypt all secrets
     */
    public function rotateEncryptionKey(): void
    {
        try {
            Log::info('Starting encryption key rotation');
            
            // Backup current encryption key
            $backupPath = $this->secretsPath . '/encryption_key.backup';
            if (file_exists($this->secretsPath . '/encryption_key')) {
                copy($this->secretsPath . '/encryption_key', $backupPath);
            }

            // Generate new encryption key
            $newKey = Str::random(32);
            file_put_contents($this->secretsPath . '/encryption_key', $newKey);
            
            // Re-encrypt all secrets with new key
            $secrets = glob($this->secretsPath . '/*.enc');
            foreach ($secrets as $secretPath) {
                $key = basename($secretPath, '.enc');
                $value = $this->retrieve($key);
                if ($value !== null) {
                    $this->store($key, $value);
                }
            }

            Log::info('Encryption key rotation completed successfully');
            Log::warning('Backup key saved to ' . $backupPath);
        } catch (\Exception $e) {
            Log::error('Failed to rotate encryption key', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Failed to rotate encryption key: ' . $e->getMessage());
        }
    }

    /**
     * Get secret from environment or storage
     */
    public function get(string $key, ?string $default = null): ?string
    {
        // Try environment first
        $envValue = env($key);
        if ($envValue !== null) {
            return $envValue;
        }

        // Try encrypted storage
        $storedValue = $this->retrieve($key);
        if ($storedValue !== null) {
            return $storedValue;
        }

        return $default;
    }

    /**
     * Set secret to encrypted storage
     */
    public function set(string $key, string $value): void
    {
        $this->store($key, $value);
    }

    /**
     * Get all secret keys
     */
    public function allKeys(): array
    {
        $secrets = glob($this->secretsPath . '/*.enc');
        return array_map(function ($path) {
            return basename($path, '.enc');
        }, $secrets);
    }

    /**
     * Sanitize key for filesystem
     */
    private function sanitizeKey(string $key): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
    }

    /**
     * Ensure secrets directory exists
     */
    private function ensureSecretsDirectory(): void
    {
        if (!is_dir($this->secretsPath)) {
            mkdir($this->secretsPath, 0700, true);
            Log::info('Created secrets directory', ['path' => $this->secretsPath]);
        }
    }

    /**
     * Get or create encryption key
     */
    private function getOrCreateEncryptionKey(): string
    {
        $keyPath = $this->secretsPath . '/encryption_key';
        
        if (file_exists($keyPath)) {
            return file_get_contents($keyPath);
        }

        $key = Str::random(32);
        file_put_contents($keyPath, $key);
        Log::info('Created new encryption key');
        
        return $key;
    }
}
