<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ==========================================
// CONFIGURATION
// ==========================================
// Choose your provider: 'proxy', 'z-api', 'evolution-api', 'green-api'
define('PROVIDER', 'proxy');

// Proxy configuration (default, uses your existing working server)
define('PROXY_URL', 'https://appespiao.online/pt/api.php?phone=');

// Z-API configuration
define('ZAPI_INSTANCE', '');
define('ZAPI_TOKEN', '');
define('ZAPI_CLIENT_TOKEN', ''); // Optional

// Evolution API configuration
define('EVOLUTION_API_URL', ''); // e.g. https://api.evolution.com
define('EVOLUTION_API_KEY', '');
define('EVOLUTION_INSTANCE', '');

// Green API configuration
define('GREEN_API_URL', 'https://api.green-api.com');
define('GREEN_API_ID', '');
define('GREEN_API_TOKEN', '');

// ==========================================
// IMPLEMENTATION
// ==========================================

$phone = isset($_GET['phone']) ? $_GET['phone'] : '';

// Clean phone number (keep only digits)
$phone = preg_replace('/\D/', '', $phone);

if (empty($phone)) {
    echo json_encode([
        'success' => false,
        'message' => 'Phone number is required.',
        'link' => null,
        'urlImage' => null
    ]);
    exit;
}

$urlImage = null;

switch (PROVIDER) {
    case 'proxy':
        $url = PROXY_URL . $phone;
        $response = file_get_contents_curl($url);
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['urlImage'])) {
                $urlImage = $data['urlImage'];
            } elseif (isset($data['link'])) {
                $urlImage = $data['link'];
            }
        }
        break;

    case 'z-api':
        $url = "https://api.z-api.io/instances/" . ZAPI_INSTANCE . "/token/" . ZAPI_TOKEN . "/profile-picture?phone=" . $phone;
        $headers = [];
        if (!empty(ZAPI_CLIENT_TOKEN)) {
            $headers[] = "Client-Token: " . ZAPI_CLIENT_TOKEN;
        }
        $response = file_get_contents_curl($url, $headers);
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['link'])) {
                $urlImage = $data['link'];
            }
        }
        break;

    case 'evolution-api':
        $url = rtrim(EVOLUTION_API_URL, '/') . "/chat/fetchProfilePicture/" . EVOLUTION_INSTANCE;
        $body = json_encode(['number' => $phone]);
        $headers = [
            "Content-Type: application/json",
            "apikey: " . EVOLUTION_API_KEY
        ];
        $response = file_get_contents_curl($url, $headers, 'POST', $body);
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['profilePictureUrl'])) {
                $urlImage = $data['profilePictureUrl'];
            }
        }
        break;

    case 'green-api':
        $url = rtrim(GREEN_API_URL, '/') . "/waInstance" . GREEN_API_ID . "/getAvatar/" . GREEN_API_TOKEN;
        $body = json_encode(['chatId' => $phone . "@c.us"]);
        $headers = [
            "Content-Type: application/json"
        ];
        $response = file_get_contents_curl($url, $headers, 'POST', $body);
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['urlAvatar'])) {
                $urlImage = $data['urlAvatar'];
            }
        }
        break;
}

// Return in the exact format the frontend script expects
echo json_encode([
    'success' => true,
    'link' => $urlImage,
    'urlImage' => $urlImage
]);

/**
 * Helper function to make HTTP requests using cURL
 */
function file_get_contents_curl($url, $headers = [], $method = 'GET', $body = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    }
    
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}
