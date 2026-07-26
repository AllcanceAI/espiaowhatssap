<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ==========================================
// CONFIGURATION
// ==========================================
define('VEOPAG_CLIENT_ID', 'SUA_CLIENT_ID_AQUI');
define('VEOPAG_CLIENT_SECRET', 'SUA_CLIENT_SECRET_AQUI');

// Predefined products (to prevent price tampering)
$products = [
    'full' => 19.00,
    'discount' => 9.90
];

// ==========================================
// HELPER FUNCTIONS
// ==========================================
function getVeoPagToken($clientId, $clientSecret) {
    $cacheFile = __DIR__ . '/veopag_token_cache.json';
    if (file_exists($cacheFile)) {
        $cache = json_decode(file_get_contents($cacheFile), true);
        if ($cache && isset($cache['token']) && isset($cache['expires_at']) && $cache['expires_at'] > time()) {
            return $cache['token'];
        }
    }
    
    // Authenticate with VeoPag
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.veopag.com/api/auth/login');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'client_id' => $clientId,
        'client_secret' => $clientSecret
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['token'])) {
            file_put_contents($cacheFile, json_encode([
                'token' => $data['token'],
                'expires_at' => time() + 2700 // Cache for 45 minutes
            ]));
            return $data['token'];
        }
    }
    return null;
}

// ==========================================
// ENDPOINT IMPLEMENTATION
// ==========================================
$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'create') {
    // Get parameters
    $productKey = isset($_POST['product']) ? $_POST['product'] : 'full';
    $name = isset($_POST['name']) ? trim($_POST['name']) : 'Cliente Pix';
    $document = isset($_POST['document']) ? preg_replace('/\D/', '', $_POST['document']) : '';
    
    if (empty($document) || strlen($document) < 11) {
        echo json_encode([
            'success' => false,
            'message' => 'CPF válido é obrigatório.'
        ]);
        exit;
    }
    
    $amount = isset($products[$productKey]) ? $products[$productKey] : 19.00;
    
    // Auth token
    $token = getVeoPagToken(VEOPAG_CLIENT_ID, VEOPAG_CLIENT_SECRET);
    if (!$token) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro de autenticação com o gateway. Verifique as credenciais.'
        ]);
        exit;
    }
    
    // Unique ID for this transaction
    $externalId = 'espiao_' . uniqid() . '_' . time();
    
    // Create PIX charge
    $payload = [
        'amount' => $amount,
        'external_id' => $externalId,
        'payer' => [
            'name' => $name,
            'document' => $document
        ],
        // UTM / Tracking parameters
        'utm_source' => isset($_POST['utm_source']) ? $_POST['utm_source'] : '',
        'utm_medium' => isset($_POST['utm_medium']) ? $_POST['utm_medium'] : '',
        'utm_campaign' => isset($_POST['utm_campaign']) ? $_POST['utm_campaign'] : '',
        'utm_content' => isset($_POST['utm_content']) ? $_POST['utm_content'] : '',
        'utm_term' => isset($_POST['utm_term']) ? $_POST['utm_term'] : '',
        'src' => isset($_POST['src']) ? $_POST['src'] : '',
        'sck' => isset($_POST['sck']) ? $_POST['sck'] : ''
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.veopag.com/api/payments/deposit');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'accept: application/json',
        'Authorization: Bearer ' . $token
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 || $httpCode === 201) {
        $data = json_decode($response, true);
        if (isset($data['transactionId']) && isset($data['qrcode'])) {
            // Generate QR Code image URL using qrserver
            $qrCodeText = $data['qrcode'];
            $qrCodeImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($qrCodeText);
            
            echo json_encode([
                'success' => true,
                'transactionId' => $data['transactionId'],
                'qrcode' => $qrCodeText,
                'qr_code_image' => $qrCodeImageUrl,
                'amount' => $amount
            ]);
            exit;
        }
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao gerar o PIX junto à VeoPag.',
        'details' => json_decode($response, true)
    ]);
    exit;
}

if ($action === 'status') {
    $transactionId = isset($_GET['transactionId']) ? trim($_GET['transactionId']) : '';
    
    if (empty($transactionId)) {
        echo json_encode([
            'success' => false,
            'message' => 'ID da transação é obrigatório.'
        ]);
        exit;
    }
    
    $token = getVeoPagToken(VEOPAG_CLIENT_ID, VEOPAG_CLIENT_SECRET);
    if (!$token) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro de autenticação.'
        ]);
        exit;
    }
    
    // Check status
    $url = 'https://api.veopag.com/api/transactions/deposit?transaction_id=' . urlencode($transactionId);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: application/json',
        'Authorization: Bearer ' . $token
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        
        $status = 'waiting_payment';
        if (isset($data['status'])) {
            $status = $data['status'];
        } elseif (isset($data[0]['status'])) {
            $status = $data[0]['status'];
        }
        
        $isPaid = (strtoupper($status) === 'PAID' || strtoupper($status) === 'COMPLETED');
        
        echo json_encode([
            'success' => true,
            'status' => $status,
            'paid' => $isPaid
        ]);
        exit;
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao consultar status.'
    ]);
    exit;
}

echo json_encode([
    'success' => false,
    'message' => 'Ação inválida.'
]);
