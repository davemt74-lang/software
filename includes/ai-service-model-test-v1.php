<?php
declare(strict_types=1);

/** Verify visibility of one configured model using the installation credential. */
function vp3_ai_service_test_model_v1(PDO $pdo, array $actor, string $provider, string $model, ?callable $transport = null): array
{
    $hosts = ['openai' => 'api.openai.com', 'anthropic' => 'api.anthropic.com'];
    if (!isset($hosts[$provider])) throw new InvalidArgumentException('Select a valid model provider.');

    $known = false;
    foreach (array_keys(vp3_ai_service_catalog_v1()) as $service) {
        if (isset(vp3_ai_service_models_v1($pdo, $service)[$provider][$model])) {
            $known = true;
            break;
        }
    }
    if (!$known) throw new InvalidArgumentException('Save this model before testing it.');

    ai_v100_rate_limit('test', $actor);
    $failure = ai_provider_configuration_failure($provider);
    if ($failure !== null) return $failure;
    $key = ai_provider_api_key($provider);
    if ($key === '' || preg_match('/[\x00-\x20\x7f]/', $key)) {
        return ['ok' => false, 'error' => 'The saved provider key is unavailable or malformed.'];
    }
    $url = 'https://'.$hosts[$provider].'/v1/models/'.rawurlencode($model);
    $headers = ['Accept: application/json'];
    if ($provider === 'openai') $headers[] = 'Authorization: Bearer '.$key;
    else array_push($headers, 'x-api-key: '.$key, 'anthropic-version: 2023-06-01');

    try {
        if ($transport !== null) {
            [$status, $body] = $transport($url, $headers);
        } else {
            $body = '';
            $curl = curl_init($url);
            if ($curl === false) throw new RuntimeException('Model lookup transport unavailable.');
            curl_setopt_array($curl, [
                CURLOPT_HTTPGET => true, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROXY => '', CURLOPT_NETRC => CURL_NETRC_IGNORED,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 65536) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (defined('CURLOPT_PROTOCOLS')) curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            $success = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            if ($success === false) throw new RuntimeException('Model lookup transport failed.');
        }
    } catch (Throwable $error) {
        return ['ok' => false, 'error' => 'The provider model lookup could not be completed.'];
    }

    if ($status !== 200) return ['ok' => false, 'error' => 'The saved key could not retrieve this model.', 'status' => (int)$status];
    $decoded = is_string($body) && strlen($body) <= 65536 ? json_decode($body, true, 16) : null;
    $resolved = $decoded['id'] ?? null;
    if (!vp3_ai_service_model_id_v1($resolved) || str_contains($resolved, $key)) {
        return ['ok' => false, 'error' => 'The provider did not return a usable model identifier.'];
    }
    return ['ok' => true, 'model' => $model, 'resolved_model' => $resolved];
}
