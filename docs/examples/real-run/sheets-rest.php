<?php
/**
 * Minimal Google Sheets REST client (service-account JWT). The harness reads
 * and edits the sheet through this, never through the package under test.
 */
function gs_token(string $saFile): string
{
    $sa = json_decode(file_get_contents($saFile), true);
    $b64 = fn ($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    $now = time();
    $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim = $b64(json_encode([
        'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/spreadsheets',
        'aud' => $sa['token_uri'], 'iat' => $now, 'exp' => $now + 3600,
    ]));
    openssl_sign("$head.$claim", $sig, $sa['private_key'], 'sha256WithRSAEncryption');
    $jwt = "$head.$claim." . $b64($sig);
    $ch = curl_init($sa['token_uri']);
    curl_setopt_array($ch, [CURLOPT_POST => 1, CURLOPT_RETURNTRANSFER => 1, CURLOPT_POSTFIELDS => http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt])]);
    $res = json_decode(curl_exec($ch), true);

    return $res['access_token'] ?? throw new RuntimeException(json_encode($res));
}

function gs_get(string $token, string $url): array
{
    for ($attempt = 0; ; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"]]);
        $res = json_decode(curl_exec($ch), true) ?? [];
        // The harness shares the 60 reads/min/user quota with the package: wait it out.
        if (($res['error']['code'] ?? 0) === 429 && $attempt < 6) {
            sleep(15);

            continue;
        }
        if (isset($res['error'])) {
            throw new RuntimeException(json_encode($res['error']));
        }

        return $res;
    }
}

function gs_send(string $token, string $method, string $url, array|object $body): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token", 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body)]);
    $res = json_decode(curl_exec($ch), true) ?? [];
    if (isset($res['error'])) {
        throw new RuntimeException(json_encode($res['error']));
    }

    return $res;
}
