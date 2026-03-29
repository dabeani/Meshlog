<?php
/**
 * iOS App Authentication Endpoint
 * POST /api/v1/auth/login
 * Returns: { token, user: { id, name, permissions }, expires_in }
 */
require_once "../../../lib/meshlog.class.php";
require_once "../../../lib/meshlog.user.class.php";
require_once "../../../config.php";

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['username']) || !isset($input['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Username and password required']);
        exit;
    }
    
    $meshlog = new MeshLog(array_merge($config['db'], array('ntp' => $config['ntp'] ?? array())));
    $err = $meshlog->getError();
    
    if ($err) {
        http_response_code(500);
        echo json_encode(['error' => $err]);
        exit;
    }
    
    $user = MeshLogUser::login($meshlog, $input['username'], $input['password']);
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials']);
        $meshlog->auditLog(\MeshLogAuditLog::EVENT_LOGIN_FAIL, htmlspecialchars($input['username'], ENT_QUOTES, 'UTF-8'), 'iOS app');
        exit;
    }
    
    $meshlog->auditLog(\MeshLogAuditLog::EVENT_LOGIN_OK, $user->name, 'iOS app');
    
    // Generate a simple JWT-like token (or use session token)
    $token = bin2hex(random_bytes(32));
    
    // Store token in a temporary table or cache (you may want to extend this)
    // For now, return user info with a secure token
    
    echo json_encode([
        'token' => $token,
        'user' => [
            'id' => $user->getId(),
            'name' => $user->name,
            'permissions' => $user->permissions,
        ],
        'expires_in' => 86400 * 7  // 7 days
    ]);
    exit;
}

// GET - Verify token
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $headers = meshlog_get_all_headers();
    $token = $headers['Authorization'] ?? null;
    
    if (!$token) {
        http_response_code(401);
        echo json_encode(['error' => 'Token required']);
        exit;
    }
    
    // Validate token (implement token storage/validation)
    echo json_encode(['valid' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);

function meshlog_get_all_headers() {
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        return is_array($headers) ? $headers : [];
    }

    $headers = [];
    foreach ($_SERVER as $name => $value) {
        if (substr($name, 0, 5) === 'HTTP_') {
            $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
            $headers[$key] = $value;
        }
    }
    return $headers;
}

?>
