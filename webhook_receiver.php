<?php
/**
 * Receptor de Webhooks Kommo -> WooCommerce.
 *
 * Kommo considera inválido cualquier webhook que no reciba una respuesta HTTP
 * exitosa en menos de 2 segundos. Por eso este script lee y registra el payload
 * inmediatamente, confirma la recepción a Kommo y luego procesa WooCommerce.
 */

const LOG_FILE = __DIR__ . '/webhook_log.txt';
const WOOCOMMERCE_BASE_URL = 'https://provisionapps.com/sistemaABP/wp-json/wc/v3';
const DEFAULT_CUSTOMER_PASSWORD_LENGTH = 20;

$consumerKey = getenv('WOOCOMMERCE_CONSUMER_KEY') ?: 'ck_1928414e20080c927342f27c7e57dda67b656522';
$consumerSecret = getenv('WOOCOMMERCE_CONSUMER_SECRET') ?: 'cs_b27b0caf859f788f6aac7044fcdf1424bc1b168c';
$kommoAccessToken = getenv('KOMMO_ACCESS_TOKEN') ?: '';

if (!defined('KOMMO_WEBHOOK_TEST_MODE')) {
    handleKommoWebhook($consumerKey, $consumerSecret, $kommoAccessToken);
}

function handleKommoWebhook(string $consumerKey, string $consumerSecret, string $kommoAccessToken = ''): void
{
    ignore_user_abort(true);
    set_time_limit(30);
    register_shutdown_function('logFatalShutdownError');

    $rawData = file_get_contents('php://input') ?: '';
    logMessage('Webhook recibido desde Kommo antes de responder.', [
        'bytes' => strlen($rawData),
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? null,
    ]);

    sendKommoAcceptedResponse();

    try {
        if ($rawData === '') {
            logMessage('Payload vacío; se confirma a Kommo y no se procesa WooCommerce.');
            return;
        }

        $data = decodeKommoPayload($rawData);
        if ($data === []) {
            logMessage('No se pudo decodificar el payload URL-encoded de Kommo.', ['raw' => truncateForLog($rawData)]);
            return;
        }

        logMessage('Payload decodificado.', ['entities' => array_keys($data)]);

        $contact = extractCustomerFromKommoPayload($data);
        if ($contact === null) {
            $contact = extractCustomerFromKommoApi($data, $kommoAccessToken);
        }

        if ($contact === null) {
            logMessage('No se encontró email válido en el webhook ni en la consulta opcional a Kommo; no se puede crear cliente WooCommerce.', [
                'entities' => array_keys($data),
                'lead_id' => getFirstLeadId($data),
                'has_kommo_token' => $kommoAccessToken !== '',
            ]);
            return;
        }

        syncWooCommerceCustomer($contact, $consumerKey, $consumerSecret);
    } catch (Throwable $exception) {
        logMessage('Error controlado durante el procesamiento posterior a la respuesta a Kommo.', [
            'type' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);
    }
}

/**
 * Responde con HTTP 200 para cumplir el SLA de 2 segundos de Kommo.
 */
function sendKommoAcceptedResponse(): void
{
    $responseBody = json_encode([
        'status' => 'accepted',
        'message' => 'Webhook accepted for processing',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($responseBody));
        header('Connection: close');
    }

    echo $responseBody;

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }

    if (ob_get_level() > 0) {
        @ob_flush();
    }
    flush();
}

function decodeKommoPayload(string $rawData): array
{
    $trimmed = ltrim($rawData);
    if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
        $json = json_decode($rawData, true);
        if (is_array($json)) {
            return $json;
        }
    }

    $decoded = [];
    parse_str($rawData, $decoded);

    return is_array($decoded) ? $decoded : [];
}

function extractCustomerFromKommoPayload(array $data): ?array
{
    foreach (getKommoEntityCandidates($data) as $entity) {
        $customFields = $entity['custom_fields_values'] ?? $entity['custom_fields'] ?? [];
        $email = normalizeEmail(findCustomFieldValue($customFields, 'EMAIL', ['email', 'correo', 'e-mail']));

        if ($email === null) {
            $email = normalizeEmail($entity['email'] ?? null);
        }

        if ($email === null) {
            continue;
        }

        $name = normalizeText($entity['name'] ?? null) ?: getNameFromEmail($email);
        $phone = normalizePhone(findCustomFieldValue($customFields, 'PHONE', ['phone', 'teléfono', 'telefono', 'celular', 'móvil', 'movil']));

        return [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'source_id' => $entity['id'] ?? null,
            'source_entity' => $entity['_entity_type'] ?? 'unknown',
        ];
    }

    return null;
}

function extractCustomerFromKommoApi(array $data, string $kommoAccessToken): ?array
{
    $leadId = getFirstLeadId($data);
    if ($leadId === null) {
        return null;
    }

    if ($kommoAccessToken === '') {
        logMessage('El webhook es de lead y no trae email. Para leer el contacto vinculado desde Kommo configura KOMMO_ACCESS_TOKEN.', [
            'lead_id' => $leadId,
        ]);
        return null;
    }

    $subdomain = getKommoSubdomain($data);
    if ($subdomain === null) {
        logMessage('No se pudo determinar el subdominio de Kommo para consultar el contacto vinculado.', [
            'lead_id' => $leadId,
        ]);
        return null;
    }

    $leadResponse = kommoApiRequest($subdomain, '/api/v4/leads/' . rawurlencode((string)$leadId) . '?with=contacts', $kommoAccessToken);
    if (!$leadResponse['ok'] || !is_array($leadResponse['json'])) {
        logMessage('No se pudo consultar el lead en Kommo para obtener contactos vinculados.', [
            'lead_id' => $leadId,
            'http_code' => $leadResponse['http_code'],
            'error' => $leadResponse['error'],
        ]);
        return null;
    }

    $contacts = $leadResponse['json']['_embedded']['contacts'] ?? [];
    if (!is_array($contacts) || $contacts === []) {
        logMessage('El lead consultado en Kommo no tiene contactos vinculados.', ['lead_id' => $leadId]);
        return null;
    }

    foreach ($contacts as $linkedContact) {
        $contactId = is_array($linkedContact) ? ($linkedContact['id'] ?? null) : null;
        if ($contactId === null) {
            continue;
        }

        $contactResponse = kommoApiRequest($subdomain, '/api/v4/contacts/' . rawurlencode((string)$contactId), $kommoAccessToken);
        if (!$contactResponse['ok'] || !is_array($contactResponse['json'])) {
            logMessage('No se pudo consultar un contacto vinculado al lead en Kommo.', [
                'lead_id' => $leadId,
                'contact_id' => $contactId,
                'http_code' => $contactResponse['http_code'],
                'error' => $contactResponse['error'],
            ]);
            continue;
        }

        $contact = $contactResponse['json'];
        $contact['_entity_type'] = 'contacts.api';
        $extracted = extractCustomerFromKommoPayload(['contacts' => ['add' => [$contact]]]);
        if ($extracted !== null) {
            logMessage('Cliente extraído desde contacto vinculado consultado por API de Kommo.', [
                'lead_id' => $leadId,
                'contact_id' => $contactId,
                'email' => $extracted['email'],
            ]);
            return $extracted;
        }
    }

    return null;
}

function getKommoEntityCandidates(array $data): array
{
    $candidates = [];
    $entityTypes = ['contacts', 'leads', 'companies'];
    $actions = ['add', 'update', 'status', 'responsible_user', 'restore'];

    foreach ($entityTypes as $entityType) {
        foreach ($actions as $action) {
            if (empty($data[$entityType][$action]) || !is_array($data[$entityType][$action])) {
                continue;
            }

            foreach ($data[$entityType][$action] as $entity) {
                if (is_array($entity)) {
                    $entity['_entity_type'] = $entityType . '.' . $action;
                    $candidates[] = $entity;
                }
            }
        }
    }

    $singularEntities = [
        'contact' => 'contacts',
        'lead' => 'leads',
        'company' => 'companies',
    ];
    foreach ($singularEntities as $singular => $plural) {
        if (!empty($data[$singular]['event']) && is_array($data[$singular]['event'])) {
            $entity = $data[$singular]['event'];
            $entity['_entity_type'] = $plural . '.event';
            $candidates[] = $entity;
        }
    }

    return $candidates;
}

function getFirstLeadId(array $data): ?int
{
    $actions = ['add', 'update', 'status', 'responsible_user', 'restore'];
    foreach ($actions as $action) {
        if (empty($data['leads'][$action]) || !is_array($data['leads'][$action])) {
            continue;
        }

        foreach ($data['leads'][$action] as $lead) {
            if (is_array($lead) && isset($lead['id']) && is_numeric($lead['id'])) {
                return (int)$lead['id'];
            }
        }
    }

    if (!empty($data['lead']['event']['id']) && is_numeric($data['lead']['event']['id'])) {
        return (int)$data['lead']['event']['id'];
    }

    return null;
}

function getKommoSubdomain(array $data): ?string
{
    $subdomain = normalizeText($data['account']['subdomain'] ?? null);
    if ($subdomain !== null) {
        return preg_replace('/[^a-zA-Z0-9-]/', '', $subdomain) ?: null;
    }

    $self = normalizeText($data['account']['_links']['self'] ?? null);
    if ($self === null) {
        return null;
    }

    $host = parse_url($self, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return null;
    }

    $parts = explode('.', $host);

    return $parts[0] ?? null;
}

function findCustomFieldValue($fields, string $code, array $nameHints): ?string
{
    if (!is_array($fields)) {
        return null;
    }

    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $fieldCode = strtoupper((string)($field['code'] ?? $field['field_code'] ?? ''));
        $fieldName = mb_strtolower((string)($field['name'] ?? $field['field_name'] ?? ''), 'UTF-8');
        $matchesCode = $fieldCode === strtoupper($code);
        $matchesName = false;

        foreach ($nameHints as $hint) {
            if ($fieldName !== '' && str_contains($fieldName, mb_strtolower($hint, 'UTF-8'))) {
                $matchesName = true;
                break;
            }
        }

        if (!$matchesCode && !$matchesName) {
            continue;
        }

        $value = extractFirstFieldValue($field['values'] ?? null);
        if ($value !== null) {
            return $value;
        }
    }

    return null;
}

function extractFirstFieldValue($values): ?string
{
    if (!is_array($values)) {
        return normalizeText($values);
    }

    foreach ($values as $value) {
        if (is_array($value)) {
            $candidate = $value['value'] ?? $value['enum'] ?? reset($value);
        } else {
            $candidate = $value;
        }

        $normalized = normalizeText($candidate);
        if ($normalized !== null) {
            return $normalized;
        }
    }

    return null;
}

function normalizeEmail($value): ?string
{
    $email = normalizeText($value);
    if ($email === null) {
        return null;
    }

    $email = mb_strtolower($email, 'UTF-8');

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

function normalizePhone($value): ?string
{
    $phone = normalizeText($value);
    if ($phone === null) {
        return null;
    }

    return mb_substr($phone, 0, 100, 'UTF-8');
}

function normalizeText($value): ?string
{
    if ($value === null || is_array($value) || is_object($value)) {
        return null;
    }

    $value = trim((string)$value);

    return $value === '' ? null : $value;
}

function getNameFromEmail(string $email): string
{
    $localPart = explode('@', $email)[0] ?? 'Cliente';
    $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $localPart) ?: 'Cliente';

    return trim(mb_convert_case($name, MB_CASE_TITLE, 'UTF-8')) ?: 'Cliente';
}

function syncWooCommerceCustomer(array $contact, string $consumerKey, string $consumerSecret): void
{
    if ($consumerKey === '' || $consumerSecret === '') {
        logMessage('Credenciales de WooCommerce vacías; no se sincroniza el cliente.', ['email' => $contact['email']]);
        return;
    }

    logMessage('Sincronizando cliente con WooCommerce.', [
        'email' => $contact['email'],
        'source' => $contact['source_entity'],
        'source_id' => $contact['source_id'],
    ]);

    $existingCustomer = findWooCommerceCustomerByEmail($contact['email'], $consumerKey, $consumerSecret);
    $payload = buildWooCommerceCustomerPayload($contact);

    if ($existingCustomer !== null) {
        $customerId = (int)$existingCustomer['id'];
        $response = wooCommerceRequest('PUT', '/customers/' . $customerId, $payload, $consumerKey, $consumerSecret);
        logMessage('Cliente actualizado en WooCommerce.', [
            'customer_id' => $customerId,
            'http_code' => $response['http_code'],
            'ok' => $response['ok'],
        ]);
        return;
    }

    $payload['password'] = generateCustomerPassword();
    $response = wooCommerceRequest('POST', '/customers', $payload, $consumerKey, $consumerSecret);

    if (!$response['ok'] && (($response['json']['code'] ?? '') === 'registration-error-email-exists')) {
        $retryCustomer = findWooCommerceCustomerByEmail($contact['email'], $consumerKey, $consumerSecret);
        if ($retryCustomer !== null) {
            $customerId = (int)$retryCustomer['id'];
            $retryResponse = wooCommerceRequest('PUT', '/customers/' . $customerId, buildWooCommerceCustomerPayload($contact), $consumerKey, $consumerSecret);
            logMessage('Cliente existente recuperado tras error de email duplicado y actualizado.', [
                'customer_id' => $customerId,
                'http_code' => $retryResponse['http_code'],
                'ok' => $retryResponse['ok'],
            ]);
            return;
        }
    }

    logMessage('Resultado de creación de cliente en WooCommerce.', [
        'email' => $contact['email'],
        'http_code' => $response['http_code'],
        'ok' => $response['ok'],
        'error_code' => $response['json']['code'] ?? null,
        'body' => $response['body'],
    ]);
}

function findWooCommerceCustomerByEmail(string $email, string $consumerKey, string $consumerSecret): ?array
{
    $response = wooCommerceRequest('GET', '/customers?search=' . rawurlencode($email), null, $consumerKey, $consumerSecret);
    if (!$response['ok'] || !is_array($response['json'])) {
        logMessage('No se pudo buscar el cliente en WooCommerce.', [
            'email' => $email,
            'http_code' => $response['http_code'],
            'error' => $response['error'],
            'body' => $response['body'],
        ]);
        return null;
    }

    foreach ($response['json'] as $customer) {
        if (is_array($customer) && normalizeEmail($customer['email'] ?? null) === $email) {
            return $customer;
        }
    }

    return null;
}

function buildWooCommerceCustomerPayload(array $contact): array
{
    [$firstName, $lastName] = splitCustomerName($contact['name']);

    $payload = [
        'email' => $contact['email'],
        'first_name' => $firstName,
        'last_name' => $lastName,
        'billing' => [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $contact['email'],
        ],
        'shipping' => [
            'first_name' => $firstName,
            'last_name' => $lastName,
        ],
    ];

    if (!empty($contact['phone'])) {
        $payload['billing']['phone'] = $contact['phone'];
    }

    return $payload;
}

function splitCustomerName(string $name): array
{
    $parts = preg_split('/\s+/u', trim($name), 2) ?: [];

    return [
        $parts[0] ?? $name,
        $parts[1] ?? '',
    ];
}

function generateCustomerPassword(): string
{
    try {
        return bin2hex(random_bytes(DEFAULT_CUSTOMER_PASSWORD_LENGTH));
    } catch (Throwable $exception) {
        return bin2hex(openssl_random_pseudo_bytes(DEFAULT_CUSTOMER_PASSWORD_LENGTH));
    }
}

function wooCommerceRequest(string $method, string $path, ?array $payload, string $consumerKey, string $consumerSecret): array
{
    $url = WOOCOMMERCE_BASE_URL . $path;
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $consumerKey . ':' . $consumerSecret,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);

    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $body = curl_exec($ch);
    $error = curl_error($ch) ?: null;
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = null;
    if (is_string($body) && $body !== '') {
        $json = json_decode($body, true);
    }

    return [
        'ok' => $error === null && $httpCode >= 200 && $httpCode <= 299,
        'http_code' => $httpCode,
        'error' => $error,
        'json' => $json,
        'body' => is_string($body) ? truncateForLog($body) : null,
    ];
}

function kommoApiRequest(string $subdomain, string $path, string $accessToken): array
{
    $url = 'https://' . $subdomain . '.kommo.com' . $path;
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $accessToken,
        ],
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch) ?: null;
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = null;
    if (is_string($body) && $body !== '') {
        $json = json_decode($body, true);
    }

    return [
        'ok' => $error === null && $httpCode >= 200 && $httpCode <= 299,
        'http_code' => $httpCode,
        'error' => $error,
        'json' => $json,
        'body' => is_string($body) ? truncateForLog($body) : null,
    ];
}

function logMessage(string $message, array $context = []): void
{
    $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message;
    if ($context !== []) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    $result = @file_put_contents(LOG_FILE, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    if ($result === false) {
        error_log($line);
    }
}

function logFatalShutdownError(): void
{
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    logMessage('Error fatal al procesar webhook.', [
        'type' => $error['type'],
        'message' => $error['message'],
        'file' => $error['file'],
        'line' => $error['line'],
    ]);
}

function truncateForLog(string $value, int $limit = 2000): string
{
    if (strlen($value) <= $limit) {
        return $value;
    }

    return substr($value, 0, $limit) . '... [truncated]';
}
