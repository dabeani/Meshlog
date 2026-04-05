<?php
/**
 * iOS App Authentication Endpoint
 * POST /api/v1/auth/ — validates credentials, returns a session token
 *
 * Token storage is not yet implemented; the endpoint is intentionally disabled
 * until a persistent token table is added.  Returns 501.
 */
header('Content-Type: application/json; charset=utf-8');
http_response_code(501);
echo json_encode(['error' => 'Token-based auth not yet implemented']);

