<?php
// Captura el cuerpo de la solicitud en formato URL-encoded
$raw_data = file_get_contents('php://input');

// Añade un mensaje de prueba al archivo log
file_put_contents('webhook_log.txt', "El archivo PHP se está ejecutando\n", FILE_APPEND);

// Registra los datos crudos en el archivo de log
file_put_contents('webhook_log.txt', "Datos recibidos (URL-encoded): " . $raw_data . "\n", FILE_APPEND);

// Decodifica los datos URL-encoded en un array de PHP
parse_str($raw_data, $data);

// Verifica si los datos fueron decodificados correctamente
if (!empty($data)) {
    // Si se decodificaron, registra el array en el log para inspeccionarlo
    file_put_contents('webhook_log.txt', "Datos decodificados: " . print_r($data, true) . "\n", FILE_APPEND);

    // Extraemos los datos necesarios del contacto
    $contact_name = $data['contacts']['update'][0]['name'] ?? null;
    $phone_number = null;
    $email = null;

    // Busca dinámicamente los campos personalizados para encontrar solo el primer correo
    foreach ($data['contacts']['update'][0]['custom_fields'] as $field) {
        if ($field['code'] === 'PHONE') {
            $phone_number = $field['values'][0]['value'] ?? null;
        }
        if ($field['code'] === 'EMAIL' && !$email) { // Toma solo el primer correo y descarta otros
            $email = $field['values'][0]['value'] ?? null;
        }
    }

    // Si falta el email, no podemos proceder
    if (!$email) {
        file_put_contents('webhook_log.txt', "No se proporcionó el correo electrónico.\n", FILE_APPEND);
        exit;
    }

    // Configura la URL de WooCommerce para la búsqueda de clientes
    $woocommerce_url = "https://provisionapps.com/sistemaABP/wp-json/wc/v3/customers?search=" . urlencode($email);
    $consumer_key = "ck_1928414e20080c927342f27c7e57dda67b656522";
    $consumer_secret = "cs_b27b0caf859f788f6aac7044fcdf1424bc1b168c";

    // Realiza una solicitud GET para buscar el cliente por correo electrónico
    $ch = curl_init($woocommerce_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "$consumer_key:$consumer_secret");
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Decodifica la respuesta de WooCommerce
    $customers = json_decode($response, true);

    // Verifica si algún cliente tiene el email exacto que buscamos
    $customer_exists = false;
    $customer_id = null;
    foreach ($customers as $customer) {
        if ($customer['email'] === $email) {
            $customer_exists = true;
            $customer_id = $customer['id'];
            // Verifica si el nombre ha cambiado
            if ($customer['first_name'] !== $contact_name) {
                // Si el nombre ha cambiado, actualiza el nombre en WooCommerce
                $update_data = [
                    'first_name' => $contact_name,
                    'billing' => [
                        'first_name' => $contact_name,
                        'email' => $email,
                        'phone' => $phone_number
                    ],
                    'shipping' => [
                        'first_name' => $contact_name
                    ]
                ];
                $ch = curl_init("https://provisionapps.com/sistemaABP/wp-json/wc/v3/customers/{$customer_id}");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERPWD, "$consumer_key:$consumer_secret");
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($update_data));
                $update_response = curl_exec($ch);
                $update_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                // Registra el resultado de la actualización en el log
                file_put_contents('webhook_log.txt', "Nombre actualizado para cliente $customer_id: HTTP $update_http_code - $update_response\n", FILE_APPEND);
            }
            break;
        }
    }

    if (!$customer_exists) {
        // Si el cliente no existe, lo creamos
        $new_customer_data = [
            'email' => $email,
            'first_name' => $contact_name,
            'billing' => [
                'first_name' => $contact_name,
                'email' => $email,
                'phone' => $phone_number
            ],
            'shipping' => [
                'first_name' => $contact_name
            ],
            'password' => 'ContraseñaSegura123'
        ];

        // Configura cURL para crear el nuevo cliente
        $ch = curl_init("https://provisionapps.com/sistemaABP/wp-json/wc/v3/customers");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "$consumer_key:$consumer_secret");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($new_customer_data));
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Registra la respuesta de la creación en el log
        file_put_contents('webhook_log.txt', "Respuesta de creación de cliente: HTTP $http_code - $response\n", FILE_APPEND);
    }
} else {
    // Si falla la decodificación, registra un mensaje de error
    file_put_contents('webhook_log.txt', "Error al decodificar los datos URL-encoded\n", FILE_APPEND);
}

// Responde a Kommo para confirmar que recibimos el webhook
echo json_encode(['status' => 'success', 'message' => 'Data received']);
?>
