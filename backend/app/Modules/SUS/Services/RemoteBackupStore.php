<?php

namespace App\Modules\SUS\Services;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Copia externa de respaldos en un almacenamiento de objetos compatible con S3 (Backblaze B2, AWS S3, MinIO...).
 * Firma las peticiones con AWS Signature V4 usando el cliente HTTP de Laravel (sin dependencias extra).
 * Direcciones de estilo «ruta»: {endpoint}/{bucket}/{llave}. Configuración en escudo.backups.remote (BACKUP_S3_*).
 */
class RemoteBackupStore
{
    public function configured(): bool
    {
        $c = $this->cfg();

        return $c['endpoint'] !== '' && $c['bucket'] !== '' && $c['key'] !== '' && $c['secret'] !== '';
    }

    /** Sube un archivo local. */
    public function put(string $key, string $file): void
    {
        $hash = hash_file('sha256', $file);
        $stream = fopen($file, 'rb') ?: throw new RuntimeException('No se pudo abrir la copia para subirla.');
        try {
            $res = $this->send('PUT', $key, [], $hash, Utils::streamFor($stream), [
                'Content-Type' => 'application/octet-stream',
                'Content-Length' => (string) filesize($file),
            ]);
        } finally {
            is_resource($stream) && fclose($stream);
        }
        $this->check($res, 'subir la copia');
    }

    public function delete(string $key): void
    {
        $this->check($this->send('DELETE', $key), 'borrar la copia');
    }

    /**
     * Lista los objetos bajo un prefijo.
     *
     * @return list<array{key: string, last_modified: int, size: int}>
     */
    public function list(string $prefix): array
    {
        $out = [];
        $token = null;
        do {
            $query = ['list-type' => '2', 'prefix' => $prefix];
            if ($token !== null) {
                $query['continuation-token'] = $token;
            }
            $res = $this->send('GET', '', $query);
            $this->check($res, 'listar las copias');
            $xml = @simplexml_load_string($res->body());
            if ($xml === false) {
                throw new RuntimeException('Respuesta no válida del almacenamiento externo al listar.');
            }
            foreach ($xml->Contents as $o) {
                $out[] = ['key' => (string) $o->Key, 'last_modified' => (int) strtotime((string) $o->LastModified), 'size' => (int) $o->Size];
            }
            $token = (string) $xml->IsTruncated === 'true' ? (string) $xml->NextContinuationToken : null;
        } while ($token);

        return $out;
    }

    /** Destino legible para el panel (sin credenciales). */
    public function describe(): ?string
    {
        if (! $this->configured()) {
            return null;
        }
        $c = $this->cfg();

        return (parse_url($c['endpoint'], PHP_URL_HOST) ?: $c['endpoint']).' / '.$c['bucket'];
    }

    // ---------------------------------------------------------------- Firma V4

    private function cfg(): array
    {
        $c = (array) config('escudo.backups.remote');

        return [
            'endpoint' => rtrim((string) ($c['endpoint'] ?? ''), '/'),
            'bucket' => trim((string) ($c['bucket'] ?? ''), '/'),
            'key' => (string) ($c['key'] ?? ''),
            'secret' => (string) ($c['secret'] ?? ''),
            'region' => (string) (($c['region'] ?? '') ?: 'us-east-1'),
        ];
    }

    private function send(string $method, string $key, array $query = [], ?string $payloadHash = null, mixed $body = null, array $extra = []): Response
    {
        $c = $this->cfg();
        $endpoint = str_contains($c['endpoint'], '://') ? $c['endpoint'] : 'https://'.$c['endpoint'];
        $host = (string) parse_url($endpoint, PHP_URL_HOST);
        $port = parse_url($endpoint, PHP_URL_PORT);
        $hostHeader = $port ? $host.':'.$port : $host;
        $basePath = rtrim((string) parse_url($endpoint, PHP_URL_PATH), '/');

        $path = $basePath.'/'.$c['bucket'].($key !== '' ? '/'.ltrim($key, '/') : '');
        $uri = implode('/', array_map('rawurlencode', explode('/', $path)));
        ksort($query);
        $qs = implode('&', array_map(fn ($k, $v) => rawurlencode((string) $k).'='.rawurlencode((string) $v), array_keys($query), $query));

        $payloadHash ??= hash('sha256', '');
        $amzDate = gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);
        $scope = "$date/{$c['region']}/s3/aws4_request";
        $canonical = implode("\n", [
            $method, $uri, $qs,
            "host:$hostHeader\nx-amz-content-sha256:$payloadHash\nx-amz-date:$amzDate\n",
            'host;x-amz-content-sha256;x-amz-date',
            $payloadHash,
        ]);
        $toSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n".hash('sha256', $canonical);
        $k = hash_hmac('sha256', $date, 'AWS4'.$c['secret'], true);
        $k = hash_hmac('sha256', $c['region'], $k, true);
        $k = hash_hmac('sha256', 's3', $k, true);
        $k = hash_hmac('sha256', 'aws4_request', $k, true);
        $signature = hash_hmac('sha256', $toSign, $k);

        $headers = $extra + [
            'x-amz-date' => $amzDate,
            'x-amz-content-sha256' => $payloadHash,
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$c['key']}/$scope, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature=$signature",
        ];
        $url = (string) parse_url($endpoint, PHP_URL_SCHEME).'://'.$hostHeader.$uri.($qs !== '' ? '?'.$qs : '');
        $req = Http::withHeaders($headers)->timeout((int) config('escudo.backups.timeout_seconds'));
        if ($body !== null) {
            $req = $req->withBody($body, $extra['Content-Type'] ?? 'application/octet-stream');
        }

        return $req->send($method, $url);
    }

    private function check(Response $res, string $what): void
    {
        if (! $res->successful()) {
            preg_match('#<Code>([^<]+)</Code>#', $res->body(), $m);
            throw new RuntimeException("El almacenamiento externo no permitió $what (HTTP {$res->status()}".(isset($m[1]) ? ", {$m[1]}" : '').').');
        }
    }
}
