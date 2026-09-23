<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;
use Throwable;

class GenerateVapidKeys extends Command
{
    protected $signature = 'aswad:vapid';

    protected $description = 'Generate VAPID keys for waiter pocket Web Push alerts.';

    public function handle(): int
    {
        $keys = $this->makeKeys();
        if (! $keys) {
            $this->error('Could not generate VAPID keys. PHP OpenSSL EC keys failed and Node was not available.');

            return self::FAILURE;
        }

        $this->line('Add these to backend/.env:');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('VAPID_SUBJECT='.config('app.url'));

        return self::SUCCESS;
    }

    /** @return array{publicKey: string, privateKey: string}|null */
    private function makeKeys(): ?array
    {
        try {
            return VAPID::createVapidKeys();
        } catch (Throwable) {
            return $this->makeKeysWithNode();
        }
    }

    /** @return array{publicKey: string, privateKey: string}|null */
    private function makeKeysWithNode(): ?array
    {
        $script = <<<'JS'
const { createECDH } = require('crypto');
const ecdh = createECDH('prime256v1');
ecdh.generateKeys();
const toUrl = (buf) => buf.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
process.stdout.write(JSON.stringify({ publicKey: toUrl(ecdh.getPublicKey()), privateKey: toUrl(ecdh.getPrivateKey()) }));
JS;
        $path = tempnam(sys_get_temp_dir(), 'vapid');
        if ($path === false) {
            return null;
        }
        file_put_contents($path, $script);
        $output = [];
        $code = 1;
        exec('node '.escapeshellarg($path).' 2>NUL', $output, $code);
        @unlink($path);
        if ($code !== 0 || $output === []) {
            return null;
        }
        $decoded = json_decode(implode('', $output), true);

        return is_array($decoded) && isset($decoded['publicKey'], $decoded['privateKey'])
            ? ['publicKey' => $decoded['publicKey'], 'privateKey' => $decoded['privateKey']]
            : null;
    }
}
