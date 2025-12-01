<?php
$currentVersion = '01-12-2025';
$updatelast = '01-12-2025';

// Start session for network stats
session_start();

// Security: Proper error handling for production
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

function safeTrim($value, $default = '') {
    if ($value === null || $value === false) {
        return $default;
    }
    return trim($value);
}

// Helper function: Check if Rakitan Manager is enabled
function isRakitanManagerEnabled() {
    $status = shell_exec("uci -q get rakitanmanager.cfg.enabled 2>/dev/null");
    return intval(safeTrim($status, '0')) === 1;
}

// Helper function: Check and block if enabled
function checkAndBlockIfEnabled() {
    if (isRakitanManagerEnabled()) {
        jsonResponse([
            'success' => false, 
            'message' => 'Aksi tidak dapat dilakukan ketika Rakitan Manager dalam status ENABLE. Silahkan STOP terlebih dahulu.',
            'blocked' => true
        ], 403);
        exit;
    }
}

// Helper function: Sanitize shell command arguments
function sanitizeShellArg($arg) {
    return escapeshellarg($arg);
}

// Helper function: JSON response with proper headers
function jsonResponse($data, $statusCode = 200) {
    // Clear any previous output
    if (ob_get_length()) {
        ob_clean();
    }
    
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Validate API requests and ensure clean output
function validateApiRequest() {
    // Only process API requests
    if (!isset($_GET['api'])) {
        return;
    }
    
    // Clean output buffer for API calls
    if (ob_get_length()) {
        ob_clean();
    }
    
    // Set JSON header for all API responses
    header('Content-Type: application/json; charset=utf-8');
}

// Call validation
validateApiRequest();


//GITHUB

// Helper function: Get GitHub Token from UCI config
function getGitHubToken() {
    $token = shell_exec("uci -q get rakitanmanager.cfg.github_token 2>/dev/null");
    $token = $token !== null ? trim($token) : '';
    return !empty($token) ? $token : null;
}

// Fallback function when cURL is not available or has issues
function githubApiRequestFallback($url, $cacheKey = null, $cacheFile = null) {
    // Try to use cached data first
    if ($cacheKey && $cacheFile && file_exists($cacheFile)) {
        $cacheData = json_decode(file_get_contents($cacheFile), true);
        if ($cacheData) {
            return [
                'success' => true,
                'data' => $cacheData['data'],
                'cached' => true,
                'cache_age' => time() - $cacheData['timestamp'],
                'warning' => 'Using cached data'
            ];
        }
    }
    
    // Try file_get_contents with stream context
    $token = getGitHubToken();
    
    $options = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: RakitanManager/1.0\r\n" .
                       "Accept: application/vnd.github.v3+json\r\n",
            'timeout' => 15,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true
        ]
    ];
    
    if ($token) {
        $options['http']['header'] .= "Authorization: token {$token}\r\n";
    }
    
    $context = stream_context_create($options);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        // Try cached data as last resort
        if ($cacheKey && $cacheFile && file_exists($cacheFile)) {
            $cacheData = json_decode(file_get_contents($cacheFile), true);
            if ($cacheData) {
                return [
                    'success' => true,
                    'data' => $cacheData['data'],
                    'cached' => true,
                    'cache_age' => time() - $cacheData['timestamp'],
                    'warning' => 'Using cached data due to network failure'
                ];
            }
        }
        
        return [
            'success' => false,
            'message' => 'Failed to fetch from GitHub: Network error'
        ];
    }
    
    // Get HTTP status code from response headers
    $httpCode = 200;
    if (isset($http_response_header) && !empty($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (strpos($header, 'HTTP/') === 0) {
                preg_match('/HTTP\/\d\.\d\s+(\d+)/', $header, $matches);
                if (isset($matches[1])) {
                    $httpCode = intval($matches[1]);
                }
                break;
            }
        }
    }
    
    // Handle HTTP errors
    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMessage = isset($errorData['message']) ? $errorData['message'] : 'Unknown error';
        
        return [
            'success' => false,
            'message' => "GitHub API error (HTTP {$httpCode}): {$errorMessage}",
            'http_code' => $httpCode
        ];
    }
    
    // Parse response
    $data = json_decode($response, true);
    
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'message' => 'Invalid JSON response from GitHub: ' . json_last_error_msg()
        ];
    }
    
    // Save to cache
    if ($cacheKey && $data !== null && $cacheFile) {
        $cacheData = [
            'timestamp' => time(),
            'data' => $data
        ];
        @file_put_contents($cacheFile, json_encode($cacheData), LOCK_EX);
    }
    
    return [
        'success' => true,
        'data' => $data,
        'cached' => false,
        'fallback_method' => true
    ];
}

// Helper function: GitHub API Request with improved error handling
function githubApiRequest($url, $cacheKey = null, $cacheDuration = 300) {
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    
    // Check cache if key provided
    if ($cacheKey) {
        $cacheFile = $cacheDir . '/' . md5($cacheKey) . '.json';
        if (file_exists($cacheFile)) {
            $cacheData = json_decode(file_get_contents($cacheFile), true);
            if ($cacheData && (time() - $cacheData['timestamp']) < $cacheDuration) {
                return [
                    'success' => true,
                    'data' => $cacheData['data'],
                    'cached' => true,
                    'cache_age' => time() - $cacheData['timestamp']
                ];
            }
        }
    }
    
    // Check if cURL is available and working
    if (!function_exists('curl_init')) {
        return githubApiRequestFallback($url, $cacheKey, $cacheFile ?? null);
    }
    
    // Initialize cURL
    $ch = curl_init();
    if ($ch === false) {
        return githubApiRequestFallback($url, $cacheKey, $cacheFile ?? null);
    }
    
    // Try multiple cURL configurations for better compatibility
    $curlOptions = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'RakitanManager/1.0',
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HEADER => true,
        CURLOPT_ENCODING => '', // Let cURL handle encoding automatically
    ];
    
    // Add GitHub token if available
    $token = getGitHubToken();
    $headers = ['Accept: application/vnd.github.v3+json'];
    if ($token) {
        $headers[] = 'Authorization: token ' . $token;
    }
    $curlOptions[CURLOPT_HTTPHEADER] = $headers;
    
    curl_setopt_array($ch, $curlOptions);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    
    // If cURL fails, use fallback
    if ($response === false || $errno !== 0) {
        return githubApiRequestFallback($url, $cacheKey, $cacheFile ?? null);
    }
    
    // Parse headers and body
    $headersPart = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    
    // Extract rate limit info
    $rateLimit = [
        'limit' => null,
        'remaining' => null,
        'reset' => null,
        'used' => null
    ];
    
    if (preg_match('/x-ratelimit-limit:\s*(\d+)/i', $headersPart, $matches)) {
        $rateLimit['limit'] = intval($matches[1]);
    }
    if (preg_match('/x-ratelimit-remaining:\s*(\d+)/i', $headersPart, $matches)) {
        $rateLimit['remaining'] = intval($matches[1]);
    }
    if (preg_match('/x-ratelimit-reset:\s*(\d+)/i', $headersPart, $matches)) {
        $rateLimit['reset'] = intval($matches[1]);
    }
    
    if ($rateLimit['limit'] !== null && $rateLimit['remaining'] !== null) {
        $rateLimit['used'] = $rateLimit['limit'] - $rateLimit['remaining'];
    }
    
    // Handle rate limiting
    if ($httpCode === 403 && $rateLimit['remaining'] === 0) {
        // Return cached data if available
        if ($cacheKey && isset($cacheFile) && file_exists($cacheFile)) {
            $cacheData = json_decode(file_get_contents($cacheFile), true);
            return [
                'success' => true,
                'data' => $cacheData['data'],
                'cached' => true,
                'cache_age' => time() - $cacheData['timestamp'],
                'rate_limited' => true,
                'rate_limit' => $rateLimit
            ];
        }
        
        return [
            'success' => false,
            'message' => 'GitHub API rate limit exceeded',
            'rate_limited' => true,
            'rate_limit' => $rateLimit,
            'http_code' => $httpCode
        ];
    }
    
    // Handle other HTTP errors
    if ($httpCode !== 200) {
        $errorData = json_decode($body, true);
        $errorMessage = isset($errorData['message']) ? $errorData['message'] : 'Unknown error';
        
        return [
            'success' => false,
            'message' => "GitHub API error (HTTP {$httpCode}): {$errorMessage}",
            'http_code' => $httpCode,
            'rate_limit' => $rateLimit
        ];
    }
    
    // Parse JSON response
    $data = json_decode($body, true);
    
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'message' => 'Invalid JSON response from GitHub: ' . json_last_error_msg()
        ];
    }
    
    // Save to cache
    if ($cacheKey && $data !== null && isset($cacheFile)) {
        $cacheData = [
            'timestamp' => time(),
            'data' => $data
        ];
        @file_put_contents($cacheFile, json_encode($cacheData), LOCK_EX);
    }
    
    return [
        'success' => true,
        'data' => $data,
        'cached' => false,
        'rate_limit' => $rateLimit
    ];
}

// API: Get Latest Version from GitHub Releases (with cache)
if (isset($_GET['api']) && $_GET['api'] === 'get_latest_version') {
    $owner = 'rtaserver-wrt';
    $repo = 'RakitanManager-Reborn';
    
    $apiUrl = "https://api.github.com/repos/{$owner}/{$repo}/releases/latest";
    $cacheKey = "latest_release_{$owner}_{$repo}";
    
    // Cache for 5 minutes (300 seconds)
    $result = githubApiRequest($apiUrl, $cacheKey, 300);
    
    if (!$result['success']) {
        jsonResponse([
            'success' => false,
            'message' => $result['message'],
            'rate_limit' => $result['rate_limit'] ?? null
        ], $result['http_code'] ?? 500);
    }
    
    $data = $result['data'];
    
    // Extract relevant information
    $versionInfo = [
        'success' => true,
        'tag_name' => $data['tag_name'] ?? 'Unknown',
        'name' => $data['name'] ?? 'Unknown',
        'published_at' => $data['published_at'] ?? null,
        'body' => $data['body'] ?? '',
        'html_url' => $data['html_url'] ?? '',
        'prerelease' => $data['prerelease'] ?? false,
        'draft' => $data['draft'] ?? false,
        'author' => [
            'login' => $data['author']['login'] ?? 'Unknown',
            'avatar_url' => $data['author']['avatar_url'] ?? '',
            'html_url' => $data['author']['html_url'] ?? ''
        ],
        'assets' => [],
        'cached' => $result['cached'] ?? false,
        'cache_age' => $result['cache_age'] ?? 0,
        'rate_limit' => $result['rate_limit'] ?? null,
        'method_used' => isset($result['fallback_method']) ? 'fallback' : 'curl'
    ];
    
    // Add download assets if available
    if (isset($data['assets']) && is_array($data['assets'])) {
        foreach ($data['assets'] as $asset) {
            $versionInfo['assets'][] = [
                'name' => $asset['name'] ?? '',
                'size' => $asset['size'] ?? 0,
                'size_mb' => isset($asset['size']) ? round($asset['size'] / (1024 * 1024), 2) : 0,
                'download_url' => $asset['browser_download_url'] ?? '',
                'download_count' => $asset['download_count'] ?? 0,
                'content_type' => $asset['content_type'] ?? 'application/octet-stream'
            ];
        }
    }
    
    jsonResponse($versionInfo);
}

// API: Get All Releases from GitHub (with cache and pagination)
if (isset($_GET['api']) && $_GET['api'] === 'get_all_releases') {
    $owner = 'rtaserver-wrt';
    $repo = 'RakitanManager-Reborn';
    $perPage = isset($_GET['per_page']) ? min(max(intval($_GET['per_page']), 1), 100) : 10;
    $page = isset($_GET['page']) ? max(intval($_GET['page']), 1) : 1;
    
    $apiUrl = "https://api.github.com/repos/{$owner}/{$repo}/releases?per_page={$perPage}&page={$page}";
    $cacheKey = "all_releases_{$owner}_{$repo}_{$perPage}_{$page}";
    
    // Cache for 10 minutes
    $result = githubApiRequest($apiUrl, $cacheKey, 600);
    
    if (!$result['success']) {
        jsonResponse([
            'success' => false,
            'message' => $result['message'],
            'rate_limit' => $result['rate_limit'] ?? null
        ], $result['http_code'] ?? 500);
    }
    
    $data = $result['data'];
    
    if (!is_array($data)) {
        jsonResponse([
            'success' => false, 
            'message' => 'Invalid response format from GitHub'
        ], 500);
    }
    
    $releases = [];
    foreach ($data as $release) {
        $releases[] = [
            'tag_name' => $release['tag_name'] ?? 'Unknown',
            'name' => $release['name'] ?? 'Unknown',
            'published_at' => $release['published_at'] ?? null,
            'body' => $release['body'] ?? '',
            'html_url' => $release['html_url'] ?? '',
            'prerelease' => $release['prerelease'] ?? false,
            'draft' => $release['draft'] ?? false,
            'assets_count' => isset($release['assets']) ? count($release['assets']) : 0
        ];
    }
    
    jsonResponse([
        'success' => true,
        'releases' => $releases,
        'count' => count($releases),
        'page' => $page,
        'per_page' => $perPage,
        'cached' => $result['cached'] ?? false,
        'cache_age' => $result['cache_age'] ?? 0,
        'rate_limit' => $result['rate_limit'] ?? null,
        'method_used' => isset($result['fallback_method']) ? 'fallback' : 'curl'
    ]);
}

// API: Get Changelog from CHANGELOG.md (with improved cache and fallback)
if (isset($_GET['api']) && $_GET['api'] === 'get_changelog') {
    $owner = 'rtaserver-wrt';
    $repo = 'RakitanManager-Reborn';
    $branch = $_GET['branch'] ?? 'main';
    
    // Validate branch
    if (!preg_match('/^[a-zA-Z0-9\-_\.\/]+$/', $branch)) {
        jsonResponse([
            'success' => false, 
            'message' => 'Invalid branch name'
        ], 400);
    }
    
    // Check cache first
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    
    $cacheKey = "changelog_{$owner}_{$repo}_{$branch}";
    $cacheFile = $cacheDir . '/' . md5($cacheKey) . '.txt';
    $cacheDuration = 600; // 10 minutes
    
    $fromCache = false;
    $changelogContent = null;
    $foundFile = null;
    
    // Check cache
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheDuration) {
        $changelogContent = file_get_contents($cacheFile);
        $foundFile = 'CHANGELOG.md';
        $fromCache = true;
    }
    
    // Fetch from GitHub if not cached
    if ($changelogContent === null) {
        $changelogFiles = [
            'CHANGELOG.md',
            'CHANGELOG',
            'changelog.md',
            'HISTORY.md',
            'RELEASES.md',
            'NEWS.md'
        ];
        
        foreach ($changelogFiles as $filename) {
            $rawUrl = "https://raw.githubusercontent.com/{$owner}/{$repo}/{$branch}/{$filename}";
            
            // Use our improved GitHub request function
            $result = githubApiRequest($rawUrl, "changelog_raw_{$filename}", 600);
            
            if ($result['success'] && !empty($result['data'])) {
                $changelogContent = $result['data'];
                $foundFile = $filename;
                break;
            }
        }
        
        // Use cached version if fetch failed
        if ($changelogContent === null && file_exists($cacheFile)) {
            $changelogContent = file_get_contents($cacheFile);
            $foundFile = 'CHANGELOG.md';
            $fromCache = true;
        }
        
        // Return error if no changelog found
        if ($changelogContent === null) {
            jsonResponse([
                'success' => false, 
                'message' => 'Changelog file not found in repository'
            ], 404);
        }
        
        // Save to cache
        @file_put_contents($cacheFile, $changelogContent, LOCK_EX);
    }
    
    // Parse changelog to extract versions
    $versions = [];
    $lines = explode("\n", $changelogContent);
    $currentVersion = null;
    $currentContent = [];
    
    foreach ($lines as $line) {
        // Match version headers (various formats)
        if (preg_match('/^#{1,3}\s*\[?v?(\d+\.\d+\.?\d*[^\]]*)\]?\s*[-–—]?\s*(.*)$/i', $line, $matches)) {
            // Save previous version
            if ($currentVersion !== null) {
                $versions[] = [
                    'version' => $currentVersion,
                    'content' => trim(implode("\n", $currentContent))
                ];
            }
            
            // Start new version
            $currentVersion = trim($matches[1]);
            $currentContent = [$line];
        } elseif ($currentVersion !== null) {
            $currentContent[] = $line;
        }
    }
    
    // Save last version
    if ($currentVersion !== null) {
        $versions[] = [
            'version' => $currentVersion,
            'content' => trim(implode("\n", $currentContent))
        ];
    }
    
    jsonResponse([
        'success' => true,
        'filename' => $foundFile,
        'raw_content' => $changelogContent,
        'parsed_versions' => $versions,
        'version_count' => count($versions),
        'cached' => $fromCache,
        'cache_age' => file_exists($cacheFile) ? (time() - filemtime($cacheFile)) : 0
    ]);
}

// API: Compare Current Version with Latest (with cache)
if (isset($_GET['api']) && $_GET['api'] === 'check_update') {
    $owner = 'rtaserver-wrt';
    $repo = 'RakitanManager-Reborn';
    $currentVersion = $_GET['current_version'] ?? '';
    
    if (empty($currentVersion)) {
        jsonResponse([
            'success' => false, 
            'message' => 'current_version parameter required'
        ], 400);
    }
    
    // Clean version strings (remove 'v' prefix and non-numeric characters except dots)
    $cleanCurrent = preg_replace('/^v/', '', $currentVersion);
    $cleanCurrent = preg_replace('/[^0-9\.]/', '', $cleanCurrent);
    
    $apiUrl = "https://api.github.com/repos/{$owner}/{$repo}/releases/latest";
    $cacheKey = "latest_release_{$owner}_{$repo}";
    
    // Cache for 5 minutes
    $result = githubApiRequest($apiUrl, $cacheKey, 300);
    
    if (!$result['success']) {
        jsonResponse([
            'success' => false,
            'message' => $result['message'],
            'rate_limit' => $result['rate_limit'] ?? null
        ], $result['http_code'] ?? 500);
    }
    
    $data = $result['data'];
    $latestTag = $data['tag_name'] ?? '0.0.0';
    $cleanLatest = preg_replace('/^v/', '', $latestTag);
    $cleanLatest = preg_replace('/[^0-9\.]/', '', $cleanLatest);
    
    // Compare versions
    $updateAvailable = version_compare($cleanLatest, $cleanCurrent, '>');
    
    jsonResponse([
        'success' => true,
        'current_version' => $currentVersion,
        'current_version_clean' => $cleanCurrent,
        'latest_version' => $latestTag,
        'latest_version_clean' => $cleanLatest,
        'update_available' => $updateAvailable,
        'release_url' => $data['html_url'] ?? '',
        'release_notes' => $data['body'] ?? '',
        'published_at' => $data['published_at'] ?? null,
        'cached' => $result['cached'] ?? false,
        'cache_age' => $result['cache_age'] ?? 0,
        'rate_limit' => $result['rate_limit'] ?? null,
        'method_used' => isset($result['fallback_method']) ? 'fallback' : 'curl'
    ]);
}

// API: Set GitHub Token
if (isset($_GET['api']) && $_GET['api'] === 'set_github_token') {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = isset($input['token']) ? trim($input['token']) : '';
    
    if (empty($token)) {
        // Remove token
        shell_exec("uci delete rakitanmanager.cfg.github_token 2>/dev/null");
        shell_exec("uci commit rakitanmanager");
        
        jsonResponse([
            'success' => true,
            'message' => 'GitHub token removed successfully'
        ]);
    }
    
    // Validate token format (supports both classic and fine-grained tokens)
    if (!preg_match('/^(ghp_[a-zA-Z0-9]{36}|github_pat_[a-zA-Z0-9_]{82})$/', $token)) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid GitHub token format. Expected format: ghp_... or github_pat_...'
        ], 400);
    }
    
    // Verify token by making a test API call
    $testResult = githubApiRequest('https://api.github.com/user', null, 0);
    
    if (!$testResult['success']) {
        jsonResponse([
            'success' => false,
            'message' => 'Token verification failed: ' . $testResult['message']
        ], 400);
    }
    
    // Save token to UCI
    shell_exec("uci set rakitanmanager.cfg.github_token=" . sanitizeShellArg($token));
    shell_exec("uci commit rakitanmanager");
    
    jsonResponse([
        'success' => true,
        'message' => 'GitHub token saved and verified successfully',
        'user' => $testResult['data']['login'] ?? 'Unknown'
    ]);
}

// API: Get GitHub Rate Limit Status
if (isset($_GET['api']) && $_GET['api'] === 'get_rate_limit') {
    $result = githubApiRequest('https://api.github.com/rate_limit', null, 0);
    
    if (!$result['success']) {
        jsonResponse([
            'success' => false,
            'message' => $result['message'],
            'has_token' => getGitHubToken() !== null
        ], $result['http_code'] ?? 500);
    }
    
    $data = $result['data'];
    $core = $data['rate'] ?? [];
    
    $response = [
        'success' => true,
        'core' => [
            'limit' => $core['limit'] ?? 0,
            'remaining' => $core['remaining'] ?? 0,
            'reset' => $core['reset'] ?? 0,
            'reset_time' => isset($core['reset']) ? date('Y-m-d H:i:s', $core['reset']) : null,
            'used' => isset($core['limit'], $core['remaining']) ? $core['limit'] - $core['remaining'] : 0,
            'percent_used' => isset($core['limit'], $core['remaining']) && $core['limit'] > 0 
                ? round((($core['limit'] - $core['remaining']) / $core['limit']) * 100, 2) 
                : 0
        ],
        'has_token' => getGitHubToken() !== null,
        'rate_limit_info' => $result['rate_limit'] ?? null,
        'method_used' => isset($result['fallback_method']) ? 'fallback' : 'curl'
    ];
    
    // Add search rate limit if available
    if (isset($data['resources']['search'])) {
        $search = $data['resources']['search'];
        $response['search'] = [
            'limit' => $search['limit'] ?? 0,
            'remaining' => $search['remaining'] ?? 0,
            'reset' => $search['reset'] ?? 0,
            'reset_time' => isset($search['reset']) ? date('Y-m-d H:i:s', $search['reset']) : null
        ];
    }
    
    jsonResponse($response);
}

// API: Clear GitHub Cache
if (isset($_GET['api']) && $_GET['api'] === 'clear_github_cache') {
    $cacheDir = __DIR__ . '/cache';
    $cleared = 0;
    $errors = [];
    
    if (is_dir($cacheDir)) {
        $files = glob($cacheDir . '/*');
        if ($files === false) {
            jsonResponse([
                'success' => false,
                'message' => 'Failed to read cache directory'
            ], 500);
        }
        
        foreach ($files as $file) {
            if (is_file($file)) {
                if (@unlink($file)) {
                    $cleared++;
                } else {
                    $errors[] = basename($file);
                }
            }
        }
    }
    
    $response = [
        'success' => true,
        'message' => "Cleared {$cleared} cache file(s)",
        'cleared' => $cleared
    ];
    
    if (!empty($errors)) {
        $response['warnings'] = "Failed to delete " . count($errors) . " file(s)";
        $response['failed_files'] = $errors;
    }
    
    jsonResponse($response);
}

// API: Test GitHub Connection
if (isset($_GET['api']) && $_GET['api'] === 'test_github_connection') {
    $testResults = [];
    
    // Check if cURL is available
    $testResults['curl_available'] = [
        'success' => function_exists('curl_init'),
        'message' => function_exists('curl_init') ? 'cURL is available' : 'cURL is not available (will use fallback method)'
    ];
    
    // Test 1: Basic connectivity
    $connectivityTest = githubApiRequest('https://api.github.com', null, 0);
    $testResults['connectivity'] = [
        'success' => $connectivityTest['success'],
        'message' => $connectivityTest['success'] ? 
            'Connected to GitHub API' : 
            'Failed to connect: ' . $connectivityTest['message'],
        'method_used' => isset($connectivityTest['fallback_method']) ? 'fallback' : 'curl'
    ];
    
    // Test 2: Rate limit check
    $rateLimitResult = githubApiRequest('https://api.github.com/rate_limit', null, 0);
    $testResults['rate_limit'] = [
        'success' => $rateLimitResult['success'],
        'message' => $rateLimitResult['success'] ? 'Rate limit accessible' : $rateLimitResult['message'],
        'details' => $rateLimitResult['rate_limit'] ?? null,
        'method_used' => isset($rateLimitResult['fallback_method']) ? 'fallback' : 'curl'
    ];
    
    // Test 3: Token validation (if token exists)
    $token = getGitHubToken();
    if ($token) {
        $userResult = githubApiRequest('https://api.github.com/user', null, 0);
        $testResults['token'] = [
            'success' => $userResult['success'],
            'message' => $userResult['success'] ? 'Token is valid' : 'Token is invalid',
            'user' => isset($userResult['data']['login']) ? $userResult['data']['login'] : null,
            'method_used' => isset($userResult['fallback_method']) ? 'fallback' : 'curl'
        ];
    } else {
        $testResults['token'] = [
            'success' => true,
            'message' => 'No token configured (using public API)'
        ];
    }
    
    // Overall status
    $allSuccess = $testResults['connectivity']['success'] && 
                  $testResults['rate_limit']['success'];
    
    jsonResponse([
        'success' => $allSuccess,
        'message' => $allSuccess ? 'All tests passed' : 'Some tests failed',
        'tests' => $testResults,
        'has_token' => $token !== null
    ]);
}
// =============================================================



// Ensure server-side current version is loaded from VERSION file or UCI config
$versionFileMain = __DIR__ . '/VERSION';
$configVersionMain = shell_exec("uci -q get rakitanmanager.cfg.version 2>/dev/null");
$configVersionMain = $configVersionMain !== null ? trim($configVersionMain) : '';
if (file_exists($versionFileMain)) {
    $fileVersionMain = trim(@file_get_contents($versionFileMain));
    if (!empty($fileVersionMain)) {
        $currentVersion = $fileVersionMain;
    } elseif (!empty($configVersionMain)) {
        $currentVersion = $configVersionMain;
    }
} else {
    if (!empty($configVersionMain)) {
        $currentVersion = $configVersionMain;
    }
}

// API: Get Current Version from Config
if (isset($_GET['api']) && $_GET['api'] === 'get_current_version') {
    $versionFile = __DIR__ . '/VERSION';
    $configVersion = shell_exec("uci -q get rakitanmanager.cfg.version 2>/dev/null");
    
    // Clean config version
    $configVersion = $configVersion !== null ? trim($configVersion) : '';
    
    $source = 'default';
    
    // Try to read from VERSION file first
    if (file_exists($versionFile)) {
        $fileVersion = trim(file_get_contents($versionFile));
        if (!empty($fileVersion)) {
            $currentVersion = $fileVersion;
            $source = 'file';
        }
    }
    
    // Then try UCI config (only if not already set from file)
    if ($source === 'default' && !empty($configVersion)) {
        $currentVersion = $configVersion;
        $source = 'uci';
    }
    
    jsonResponse([
        'success' => true,
        'version' => $currentVersion,
        'source' => $source,
        'debug_info' => [ // Optional: for debugging
            'file_exists' => file_exists($versionFile),
            'config_version' => $configVersion,
            'config_exists' => !empty($configVersion)
        ]
    ]);
}

// API: Update Current Version in Config
if (isset($_GET['api']) && $_GET['api'] === 'update_current_version') {
    checkAndBlockIfEnabled();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $newVersion = isset($input['version']) ? trim($input['version']) : '';
    
    if (empty($newVersion)) {
        jsonResponse(['success' => false, 'message' => 'Version parameter required'], 400);
    }
    
    // Validate version format
    if (!preg_match('/^v?\d+\.\d+\.\d+([\-\.][a-zA-Z0-9]+)?$/', $newVersion)) {
        jsonResponse(['success' => false, 'message' => 'Invalid version format. Expected: x.y.z or vx.y.z'], 400);
    }
    
    // Update in UCI config
    $cleanVersion = preg_replace('/^v/', '', $newVersion);
    shell_exec("uci set rakitanmanager.cfg.version=" . sanitizeShellArg($cleanVersion));
    shell_exec("uci commit rakitanmanager");
    
    // Also update VERSION file
    $versionFile = __DIR__ . '/VERSION';
    file_put_contents($versionFile, $cleanVersion);
    
    jsonResponse([
        'success' => true,
        'message' => 'Version updated successfully',
        'version' => $cleanVersion
    ]);
}

// API: Get Version Info (Combined current + latest)
if (isset($_GET['api']) && $_GET['api'] === 'get_version_info') {
    $response = [
        'success' => true,
        'current_version' => $currentVersion,
        'latest_version' => null,
        'update_available' => false,
        'last_checked' => null,
        'changelog' => null
    ];
    
    try {
        // Determine current version locally (prefer VERSION file, then UCI config)
        $versionFile = __DIR__ . '/VERSION';
        $configVersion = shell_exec("uci -q get rakitanmanager.cfg.version 2>/dev/null");
        $configVersion = $configVersion !== null ? trim($configVersion) : '';

        $currentV = $currentVersion;
        $source = 'default';

        if (file_exists($versionFile)) {
            $fileVersion = trim(@file_get_contents($versionFile));
            if (!empty($fileVersion)) {
                $currentV = $fileVersion;
                $source = 'file';
            }
        }

        if ($source === 'default' && !empty($configVersion)) {
            $currentV = $configVersion;
            $source = 'uci';
        }

        $response['current_version'] = $currentV;
        $response['current_source'] = $source;

        // Get latest version using internal GitHub request (same logic as get_latest_version)
        $owner = 'rtaserver-wrt';
        $repo = 'RakitanManager-Reborn';
        $apiUrl = "https://api.github.com/repos/{$owner}/{$repo}/releases/latest";
        $cacheKey = "latest_release_{$owner}_{$repo}";

        $latestResult = githubApiRequest($apiUrl, $cacheKey, 300);
        if ($latestResult['success']) {
            $data = $latestResult['data'];
            $latestTag = $data['tag_name'] ?? null;
            $response['latest_version'] = $latestTag;
            $response['latest_info'] = [
                'name' => $data['name'] ?? 'Unknown',
                'published_at' => $data['published_at'] ?? null,
                'html_url' => $data['html_url'] ?? '',
                'body' => $data['body'] ?? ''
            ];

            // Compare cleaned versions
            $cleanCurrent = preg_replace('/^v/', '', $response['current_version']);
            $cleanLatest = preg_replace('/^v/', '', $response['latest_version'] ?? '');
            $response['update_available'] = version_compare($cleanLatest, $cleanCurrent, '>');

            // If update available, try to fetch changelog (use same raw-file lookup as get_changelog)
            if ($response['update_available']) {
                $branch = 'main';
                $cacheDir = __DIR__ . '/cache';
                if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0755, true); }
                $changelogFiles = ['CHANGELOG.md','CHANGELOG','changelog.md','HISTORY.md','RELEASES.md','NEWS.md'];
                $changelogContent = null;
                foreach ($changelogFiles as $filename) {
                    $rawUrl = "https://raw.githubusercontent.com/{$owner}/{$repo}/{$branch}/{$filename}";
                    $res = githubApiRequest($rawUrl, "changelog_raw_{$filename}", 600);
                    if ($res['success'] && !empty($res['data'])) {
                        $changelogContent = $res['data'];
                        break;
                    }
                }

                if ($changelogContent !== null) {
                    // parse minimal changelog: return raw content and first few lines
                    $response['changelog'] = [
                        'success' => true,
                        'raw_content_preview' => substr($changelogContent, 0, 400),
                        'found' => true
                    ];
                }
            }
        }

        $response['last_checked'] = time();

    } catch (Exception $e) {
        $response['error'] = $e->getMessage();
    }
    
    jsonResponse($response);
}

// =============================================================


// Read network configuration
$contnetwork = @file_get_contents('/etc/config/network');
$linesnetwork = $contnetwork ? explode("\n", $contnetwork) : [];

$interface_modem = [];
foreach ($linesnetwork as $linenetwork) {
    if (strpos($linenetwork, 'config interface') !== false) {
        $parts = explode(' ', $linenetwork);
        $interface = trim(end($parts), "'");
        if (!empty($interface)) {
            $interface_modem[] = $interface;
        }
    }
}

$interfaces = [];
$outputinterface = shell_exec('ip address 2>/dev/null');
preg_match_all('/^\d+: (\S+):/m', $outputinterface, $matchesinterface);
if (!empty($matchesinterface[1])) {
    $interfaces = array_combine($matchesinterface[1], $matchesinterface[1]);
} else {
    $interfaces = [];
}

$androididdevices = shell_exec("adb devices 2>/dev/null | grep 'device' | grep -v 'List of' | awk '{print $1}'");
$androidid = array_filter(explode("\n", safeTrim($androididdevices)));

$rakitanmanager_output = shell_exec("uci -q get rakitanmanager.cfg.enabled 2>/dev/null");
$rakitanmanager_status = (int)safeTrim($rakitanmanager_output, '0');

$branch_output = shell_exec("uci -q get rakitanmanager.cfg.branch 2>/dev/null");
$branch_select = safeTrim($branch_output, '');

// API: START/STOP Rakitan Manager
if (isset($_GET['api']) && $_GET['api'] === 'toggle_rakitanmanager') {
    $current_output = shell_exec("uci -q get rakitanmanager.cfg.enabled 2>/dev/null");
    $current_status = (int)safeTrim($current_output, '0');
    $new_status = $current_status ? 0 : 1;

    // Set new status in UCI
    shell_exec("uci set rakitanmanager.cfg.enabled=" . sanitizeShellArg($new_status));
    shell_exec("uci commit rakitanmanager");

    // Start or stop the service
    if ($new_status == 1) {
        shell_exec("/usr/share/rakitanmanager/core-manager.sh -s");
        $message = 'Rakitan Manager STARTED successfully';
    } else {
        shell_exec("/usr/share/rakitanmanager/core-manager.sh -k");
        $message = 'Rakitan Manager STOPPED successfully';
    }

    jsonResponse([
        'success' => true,
        'enabled' => $new_status,
        'message' => $message
    ]);
}

// API: Check Dependencies
if (isset($_GET['api']) && $_GET['api'] === 'check_dependencies') {
    function checkPythonPackage($packageName) {
        $result = [
            'installed' => false,
            'version' => null,
            'latest' => null,
            'status' => 'not_installed'
        ];

        if ($packageName === 'datetime') {
            $result['installed'] = true;
            $result['version'] = 'Built-in';
            $result['latest'] = 'Built-in';
            $result['status'] = 'installed';
            return $result;
        }

        try {
            // Check if package is installed and get version
            $importName = str_replace('-', '_', $packageName);
            $command = "python3 -c \"import $importName; print($importName.__version__ if hasattr($importName, '__version__') else 'Unknown')\" 2>/dev/null";
            $version = trim(shell_exec($command));

            if (!empty($version) && $version !== 'Unknown') {
                $result['installed'] = true;
                $result['version'] = $version;
                $result['status'] = 'installed';

                // Try to get latest version from PyPI
                $pypiCommand = "curl -s --connect-timeout 5 'https://pypi.org/pypi/" . sanitizeShellArg($packageName) . "/json' 2>/dev/null | python3 -c \"import sys, json; data=json.load(sys.stdin); print(data['info']['version'])\" 2>/dev/null";
                $latest = trim(shell_exec($pypiCommand));
                if (!empty($latest) && $latest !== 'null') {
                    $result['latest'] = $latest;
                    if (version_compare($version, $latest, '<')) {
                        $result['status'] = 'update';
                    }
                } else {
                    $result['latest'] = 'Unknown';
                }
            } else {
                // Try to get latest version even if not installed
                $pypiCommand = "curl -s --connect-timeout 5 'https://pypi.org/pypi/" . sanitizeShellArg($packageName) . "/json' 2>/dev/null | python3 -c \"import sys, json; data=json.load(sys.stdin); print(data['info']['version'])\" 2>/dev/null";
                $latest = trim(shell_exec($pypiCommand));
                $result['latest'] = (!empty($latest) && $latest !== 'null') ? $latest : 'Unknown';
            }
        } catch (Exception $e) {
            // Silent fail for dependencies check
        }

        return $result;
    }

    function checkPip() {
        $result = [
            'installed' => false,
            'version' => null,
            'latest' => null,
            'status' => 'not_installed'
        ];

        try {
            $command = "python3 -m pip --version 2>/dev/null | cut -d' ' -f2";
            $version = trim(shell_exec($command));

            if (!empty($version)) {
                $result['installed'] = true;
                $result['version'] = $version;
                $result['status'] = 'installed';
                $result['latest'] = 'Unknown';
            }
        } catch (Exception $e) {
            // Silent fail
        }

        return $result;
    }

    $dependencies = [
        'pip' => checkPip(),
        'requests' => checkPythonPackage('requests'),
        'huawei-lte-api' => checkPythonPackage('huawei-lte-api'),
        'datetime' => checkPythonPackage('datetime')
    ];

    jsonResponse($dependencies);
}

// API: Install Dependency - BLOCK IF ENABLED
if (isset($_GET['api']) && $_GET['api'] === 'install_dependency') {
    checkAndBlockIfEnabled(); // BLOCK HERE
    
    $package = $_GET['package'] ?? '';
    $action = $_GET['action'] ?? 'install';

    if (empty($package)) {
        jsonResponse(['success' => false, 'message' => 'Package name required'], 400);
    }

    if ($package === 'datetime') {
        jsonResponse(['success' => false, 'message' => 'datetime is built-in module'], 400);
    }

    // Validate package name (security)
    if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $package)) {
        jsonResponse(['success' => false, 'message' => 'Invalid package name'], 400);
    }

    if ($action === 'install') {
        $command = "python3 -m pip install " . sanitizeShellArg($package) . " 2>&1";
    } elseif ($action === 'update') {
        $command = "python3 -m pip install --upgrade " . sanitizeShellArg($package) . " 2>&1";
    } else {
        jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }

    $output = shell_exec($command);

    if (stripos($output, 'ERROR') === false && stripos($output, 'error') === false) {
        jsonResponse([
            'success' => true,
            'message' => ucfirst($action) . ' completed successfully'
        ]);
    } else {
        jsonResponse([
            'success' => false,
            'message' => 'Failed to ' . $action . ': ' . trim($output)
        ], 500);
    }
}

// API: Get Modems
if (isset($_GET['api']) && $_GET['api'] === 'get_modems') {
    $modemsFile = '/usr/share/rakitanmanager/modems.json';
    if (!file_exists($modemsFile)) {
        jsonResponse([]);
    }

    $modems = json_decode(file_get_contents($modemsFile), true);
    if ($modems === null) {
        jsonResponse(['error' => 'Invalid JSON format'], 500);
    }

    jsonResponse($modems);
}

// API: Save Modem - BLOCK IF ENABLED
if (isset($_GET['api']) && $_GET['api'] === 'save_modem') {
    checkAndBlockIfEnabled(); // BLOCK HERE
    
    $modemsFile = '/usr/share/rakitanmanager/modems.json';

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(['success' => false, 'message' => 'Invalid JSON data'], 400);
    }

    // Load existing modems
    $modems = [];
    if (file_exists($modemsFile)) {
        $modems = json_decode(file_get_contents($modemsFile), true) ?? [];
    }

    // Generate ID if not provided
    if (!isset($input['id']) || empty($input['id'])) {
        $input['id'] = 'modem-' . uniqid();
    }

    // Check if modem with this ID already exists
    $existingIndex = -1;
    foreach ($modems as $index => $modem) {
        if ($modem['id'] === $input['id']) {
            $existingIndex = $index;
            break;
        }
    }

    // Set timestamps
    $now = date('c');
    if ($existingIndex >= 0) {
        $input['updated_at'] = $now;
        $input['created_at'] = $modems[$existingIndex]['created_at'];
        $modems[$existingIndex] = $input;
        $message = 'Modem updated successfully';
    } else {
        $input['created_at'] = $now;
        $input['updated_at'] = $now;
        $modems[] = $input;
        $message = 'Modem created successfully';
    }

    // Save to file
    if (file_put_contents($modemsFile, json_encode($modems, JSON_PRETTY_PRINT))) {
        jsonResponse(['success' => true, 'message' => $message, 'modem' => $input]);
    } else {
        jsonResponse(['success' => false, 'message' => 'Failed to save modem data'], 500);
    }
}

// API: Delete Modem - BLOCK IF ENABLED
if (isset($_GET['api']) && $_GET['api'] === 'delete_modem') {
    checkAndBlockIfEnabled(); // BLOCK HERE
    
    $modemId = $_GET['id'] ?? '';
    if (empty($modemId)) {
        jsonResponse(['success' => false, 'message' => 'Modem ID required'], 400);
    }

    $modemsFile = '/usr/share/rakitanmanager/modems.json';
    if (!file_exists($modemsFile)) {
        jsonResponse(['success' => false, 'message' => 'Modems file not found'], 404);
    }

    $modems = json_decode(file_get_contents($modemsFile), true);
    if ($modems === null) {
        jsonResponse(['success' => false, 'message' => 'Invalid JSON format'], 500);
    }

    // Find and remove modem
    $found = false;
    foreach ($modems as $index => $modem) {
        if ($modem['id'] === $modemId) {
            unset($modems[$index]);
            $modems = array_values($modems);
            $found = true;
            break;
        }
    }

    if (!$found) {
        jsonResponse(['success' => false, 'message' => 'Modem not found'], 404);
    }

    if (file_put_contents($modemsFile, json_encode($modems, JSON_PRETTY_PRINT))) {
        jsonResponse(['success' => true, 'message' => 'Modem deleted successfully']);
    } else {
        jsonResponse(['success' => false, 'message' => 'Failed to delete modem'], 500);
    }
}

// API: Get Android Devices
if (isset($_GET['api']) && $_GET['api'] === 'get_android_devices') {
    $response = ['success' => false, 'devices' => [], 'message' => ''];
    
    try {
        $adbCheck = shell_exec('which adb 2>/dev/null');
        $adbPath = safeTrim($adbCheck);
        
        if (empty($adbPath)) {
            $response['message'] = 'ADB not found. Please install Android Debug Bridge.';
            jsonResponse($response);
        }
        
        $devicesOutput = shell_exec('adb devices -l 2>/dev/null');
        $devices = [];
        
        if (!empty($devicesOutput)) {
            $lines = explode("\n", safeTrim($devicesOutput));
            
            foreach ($lines as $line) {
                if (strpos($line, 'List of devices') !== false || trim($line) === '') {
                    continue;
                }
                
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 2 && $parts[1] === 'device') {
                    $deviceId = sanitizeShellArg($parts[0]);
                    
                    $model_out = shell_exec("adb -s $deviceId shell getprop ro.product.model 2>/dev/null");
                    $manufacturer_out = shell_exec("adb -s $deviceId shell getprop ro.product.manufacturer 2>/dev/null");
                    $androidVersion_out = shell_exec("adb -s $deviceId shell getprop ro.build.version.release 2>/dev/null");
                    $deviceName_out = shell_exec("adb -s $deviceId shell getprop ro.product.device 2>/dev/null");
                    
                    $model = safeTrim($model_out, 'Unknown Model');
                    $manufacturer = safeTrim($manufacturer_out, 'Unknown Manufacturer');
                    $androidVersion = safeTrim($androidVersion_out, 'Unknown');
                    $deviceName = safeTrim($deviceName_out, 'Unknown');
                    
                    $devices[] = [
                        'id' => trim($parts[0]),
                        'model' => $model,
                        'manufacturer' => $manufacturer,
                        'android_version' => $androidVersion,
                        'device_name' => $deviceName,
                        'status' => 'connected',
                        'usb_interface' => 'usb' . count($devices)
                    ];
                }
            }
        }
        
        $response['success'] = true;
        $response['devices'] = $devices;
        $response['message'] = count($devices) . ' Android device(s) found';
        
    } catch (Exception $e) {
        $response['message'] = 'Error scanning Android devices: ' . $e->getMessage();
    }
    
    jsonResponse($response);
}

// API: Get System Status
if (isset($_GET['api']) && $_GET['api'] === 'get_system_status') {
    $response = [
        'success' => true,
        'rakitanmanager' => [
            'enabled' => $rakitanmanager_status,
            'branch' => $branch_select ?: 'main'
        ],
        'network' => [
            'config_interfaces' => $interface_modem,
            'active_interfaces' => array_values($interfaces),
            'android_devices' => $androidid
        ],
        'timestamp' => time()
    ];
    
    jsonResponse($response);
}

// API: Network Stats
if (isset($_GET['api']) && $_GET['api'] === 'get_network_stats') {
    try {
        $interface = 'br-lan';
        $procNetDev = '/proc/net/dev';

        if (!file_exists($procNetDev)) {
            jsonResponse(['success' => false, 'error' => 'Network stats not available']);
        }

        $lines = @file($procNetDev, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            jsonResponse(['success' => false, 'error' => 'Failed to read network stats']);
        }

        $stats = null;

        foreach ($lines as $line) {
            if (strpos($line, $interface . ':') !== false) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 10) {
                    $stats = [
                        'interface' => $interface,
                        'bytes_rx' => (int)$parts[1],
                        'bytes_tx' => (int)$parts[9],
                        'timestamp' => microtime(true)
                    ];
                }
                break;
            }
        }

        if (!$stats) {
            jsonResponse(['success' => false, 'error' => 'Interface br-lan not found']);
        }

        $response = [
            'success' => true,
            'download_speed' => 0,
            'upload_speed' => 0,
            'data_usage' => 0
        ];

        $total_bytes = $stats['bytes_rx'] + $stats['bytes_tx'];
        $response['data_usage'] = round($total_bytes / (1024 * 1024 * 1024), 2);

        if (isset($_SESSION['network_prev'])) {
            $prev = $_SESSION['network_prev'];
            $time_diff = $stats['timestamp'] - $prev['timestamp'];

            if ($time_diff > 0) {
                $rx_diff = $stats['bytes_rx'] - $prev['bytes_rx'];
                $tx_diff = $stats['bytes_tx'] - $prev['bytes_tx'];

                $response['download_speed'] = round(($rx_diff * 8) / ($time_diff * 1000000), 2);
                $response['upload_speed'] = round(($tx_diff * 8) / ($time_diff * 1000000), 2);
            }
        }

        $_SESSION['network_prev'] = $stats;

        jsonResponse($response);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'error' => 'Network stats error: ' . $e->getMessage()]);
    }
}

// API: Get Rakitan Manager Log
if (isset($_GET['api']) && $_GET['api'] === 'get_rakitanmanager_log') {
    $logFile = '/usr/share/rakitanmanager/rakitanmanager.log';
    
    if (!file_exists($logFile)) {
        jsonResponse([
            'success' => true, 
            'message' => 'Log file not found', 
            'content' => 'No log entries found.'
        ]);
    }

    try {
        // Baca isi file (batasi baris terakhir agar tidak overload)
        $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        if ($lines === false) {
            jsonResponse([
                'success' => true, 
                'message' => 'Failed to read log file', 
                'content' => 'Unable to read log file.'
            ]);
        }

        // Ambil 200 baris terakhir untuk efisiensi
        $lines = array_slice($lines, -200);
        $content = implode("\n", $lines);

        jsonResponse([
            'success' => true, 
            'content' => $content
        ]);
    } catch (Exception $e) {
        jsonResponse([
            'success' => false, 
            'message' => 'Error reading log: ' . $e->getMessage(), 
            'content' => ''
        ]);
    }
}

// API: Add Log Entry
if (isset($_GET['api']) && $_GET['api'] === 'add_log_entry') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['message'])) {
        jsonResponse([
            'success' => false, 
            'message' => 'Message required'
        ], 400);
    }

    $logFile = '/usr/share/rakitanmanager/rakitanmanager.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$input['message']}\n";

    // Write to log file with file locking to prevent race conditions
    if (file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX)) {
        jsonResponse([
            'success' => true, 
            'message' => 'Log entry added'
        ]);
    } else {
        jsonResponse([
            'success' => false, 
            'message' => 'Failed to write log'
        ], 500);
    }
}

// API: Clear Log
if (isset($_GET['api']) && $_GET['api'] === 'clear_log') {
    $logFile = '/usr/share/rakitanmanager/rakitanmanager.log';
    
    $timestamp = date('Y-m-d H:i:s');
    $initialEntry = "[{$timestamp}] Log cleared - System restarted\n";
    
    // Clear file and write initial entry
    if (file_put_contents($logFile, $initialEntry, LOCK_EX)) {
        jsonResponse([
            'success' => true, 
            'message' => 'Log cleared successfully'
        ]);
    } else {
        jsonResponse([
            'success' => false, 
            'message' => 'Failed to clear log'
        ], 500);
    }
}
?>
<!doctype html>
<html lang="id" class="h-full">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>OpenWrt Rakitan Manager</title>
  <script src="./assets/js/tailwind.js"></script>
  <link rel="stylesheet" href="./assets/css/all.min.css">
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            primary: {
              50: '#eff6ff',
              100: '#dbeafe',
              200: '#bfdbfe',
              300: '#93c5fd',
              400: '#60a5fa',
              500: '#3b82f6',
              600: '#2563eb',
              700: '#1d4ed8',
              800: '#1e40af',
              900: '#1e3a8a',
            },
            success: {
              50: '#f0fdf4',
              100: '#dcfce7',
              200: '#bbf7d0',
              300: '#86efac',
              400: '#4ade80',
              500: '#22c55e',
              600: '#16a34a',
              700: '#15803d',
              800: '#166534',
              900: '#14532d',
            },
            danger: {
              50: '#fef2f2',
              100: '#fee2e2',
              200: '#fecaca',
              300: '#fca5a5',
              400: '#f87171',
              500: '#ef4444',
              600: '#dc2626',
              700: '#b91c1c',
              800: '#991b1b',
              900: '#7f1d1d',
            },
            warning: {
              50: '#fffbeb',
              100: '#fef3c7',
              200: '#fde68a',
              300: '#fcd34d',
              400: '#fbbf24',
              500: '#f59e0b',
              600: '#d97706',
              700: '#b45309',
              800: '#92400e',
              900: '#78350f',
            }
          },
          animation: {
            'fade-in': 'fadeIn 0.5s ease-in-out',
            'slide-in': 'slideIn 0.3s ease-out',
            'pulse-slow': 'pulse 3s infinite',
            'bounce-slow': 'bounce 2s infinite',
          },
          keyframes: {
            fadeIn: {
              '0%': { opacity: '0' },
              '100%': { opacity: '1' },
            },
            slideIn: {
              '0%': { transform: 'translateY(-10px)', opacity: '0' },
              '100%': { transform: 'translateY(0)', opacity: '1' },
            }
          }
        }
      }
    }
  </script>
  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
    
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      min-height: 100vh;
      line-height: 1.6;
    }

    /* Version Header Styles */
    .version-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 1rem;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        color: white;
        position: relative;
        overflow: hidden;
    }

    .version-header::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" opacity="0.1"><circle cx="50" cy="50" r="2" fill="white"/></svg>') repeat;
    }

    .version-info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .version-card {
        background: rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(10px);
        border-radius: 0.75rem;
        padding: 1.25rem;
        border: 1px solid rgba(255, 255, 255, 0.2);
        transition: all 0.3s ease;
    }

    .version-card:hover {
        background: rgba(255, 255, 255, 0.2);
        transform: translateY(-2px);
    }

    .version-card h3 {
        font-size: 0.875rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
        opacity: 0.9;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .version-card .version-value {
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0;
        line-height: 1.2;
    }

    .version-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.375rem 0.75rem;
        border-radius: 2rem;
        font-size: 0.75rem;
        font-weight: 600;
        margin-left: 0.5rem;
        animation: pulse 2s infinite;
    }

    .badge-latest {
        background: rgba(16, 185, 129, 0.2);
        border: 1px solid rgba(16, 185, 129, 0.4);
        color: #10b981;
    }

    .badge-update {
        background: rgba(245, 158, 11, 0.2);
        border: 1px solid rgba(245, 158, 11, 0.4);
        color: #f59e0b;
        animation: bounce 2s infinite;
    }

    .badge-outdated {
        background: rgba(239, 68, 68, 0.2);
        border: 1px solid rgba(239, 68, 68, 0.4);
        color: #ef4444;
    }

    /* Update Notification Styles: modern, responsive and accessible */
    .update-notification {
        position: fixed;
        top: 1rem;
        right: 1rem;
        z-index: 60;
        background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(250,250,251,0.95));
        border: 1px solid rgba(15, 23, 42, 0.06);
        backdrop-filter: blur(6px);
        -webkit-backdrop-filter: blur(6px);
        box-shadow: 0 8px 20px rgba(2,6,23,0.12);
        border-radius: 12px;
        padding: 14px 16px;
        width: 380px;
        max-width: calc(100% - 2rem);
        animation: modalSlideIn 220ms cubic-bezier(.2,.9,.3,1);
        display: flex;
        align-items: center;
        gap: 12px;
        transition: transform 180ms ease, opacity 180ms ease;
        transform-origin: top right;
    }

    .update-notification[hidden] {
        opacity: 0;
        transform: translateY(-6px) scale(.995);
        pointer-events: none;
    }

    .update-notification .update-body {
        flex: 1 1 auto;
        min-width: 0;
    }

    .update-notification h4 {
        color: #0f172a;
        font-weight: 700;
        margin: 0 0 4px 0;
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 1rem;
    }

    .update-notification p {
        color: #334155;
        margin: 0;
        font-size: 0.95rem;
        line-height: 1.35;
        /* Allow wrapping so long messages don't get truncated */
        overflow: visible;
        text-overflow: initial;
        white-space: normal;
        word-break: break-word;
    }

    .update-notification .update-actions {
        display: flex;
        gap: 12px;
        align-items: center;
        margin-left: 12px;
        flex-shrink: 0;
    }

    .update-close-btn {
        position: absolute;
        top: 8px;
        right: 8px;
        width: 36px;
        height: 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        border: none;
        background: transparent;
        color: #0f172a;
        cursor: pointer;
        transition: background 120ms ease, color 120ms ease;
    }

    .update-close-btn:focus,
    .update-close-btn:hover {
        background: rgba(15,23,42,0.06);
        outline: none;
    }

    .update-notification .btn {
        white-space: nowrap;
    }

    /* Responsive: center on small screens */
    @media (max-width: 640px) {
        .update-notification {
            left: 1rem;
            right: 1rem;
            top: 1rem;
            width: auto;
            border-radius: 10px;
            padding: 12px;
            flex-direction: column;
            align-items: stretch;
            gap: 8px;
        }

        .update-notification .update-body {
            order: 1;
            margin-right: 0;
        }

        .update-notification .update-actions {
            order: 2;
            width: 100%;
            justify-content: flex-end;
        }

        .update-notification .update-actions .btn {
            min-width: 110px;
        }
    }

    /* Changelog Styles */
    .changelog-container {
        max-height: 400px;
        overflow-y: auto;
        background: #1f2937;
        border-radius: 0.75rem;
        padding: 1.5rem;
        margin: 1rem 0;
    }

    .changelog-entry {
        padding: 1rem;
        border-left: 4px solid #3b82f6;
        background: #374151;
        margin-bottom: 1rem;
        border-radius: 0 0.5rem 0.5rem 0;
    }

    .changelog-version {
        font-weight: 700;
        color: #3b82f6;
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .changelog-date {
        font-size: 0.875rem;
        color: #9ca3af;
        margin-bottom: 0.75rem;
    }

    .changelog-content {
        color: #d1d5db;
        line-height: 1.6;
    }

    .changelog-content ul {
        margin: 0.5rem 0;
        padding-left: 1.5rem;
    }

    .changelog-content li {
        margin-bottom: 0.25rem;
    }

    /* Responsive Design for Version Components */
    @media (max-width: 768px) {
        .version-info-grid {
            grid-template-columns: 1fr;
        }
        
        .version-card {
            padding: 1rem;
        }
        
        .version-card .version-value {
            font-size: 1.25rem;
        }
        
        .version-header {
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .changelog-container {
            max-height: 300px;
            padding: 1rem;
        }
    }

    /* Animation for update highlight */
    @keyframes highlightUpdate {
        0% { background-color: rgba(245, 158, 11, 0.1); }
        50% { background-color: rgba(245, 158, 11, 0.3); }
        100% { background-color: rgba(245, 158, 11, 0.1); }
    }

    .highlight-update {
        animation: highlightUpdate 2s ease-in-out 3;
    }

    /* About Modal Styles */
    .about-feature-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .about-feature-list li {
        padding: 0.375rem 0;
        display: flex;
        align-items: flex-start;
    }

    .about-feature-list li i {
        margin-right: 0.75rem;
        width: 1rem;
        text-align: center;
        flex-shrink: 0;
        margin-top: 0.125rem;
    }

    /* Animasi untuk modal about */
    @keyframes modalSlideIn {
        from {
            opacity: 0;
            transform: translateY(-30px) scale(0.95);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }
        to {
            opacity: 1;
        }
    }

    #about-modal {
        animation: fadeIn 0.2s ease-out;
    }

    #about-modal .glass-card {
        animation: modalSlideIn 0.3s ease-out;
    }

    @media (max-width: 768px) {
      #about-modal .glass-card {
        margin: 1rem;
        max-width: calc(100% - 2rem);
      }
      
      #about-modal [style*="grid-template-columns"] {
        grid-template-columns: 1fr !important;
      }
      
      #about-modal [style*="font-size: 1.75rem"] {
        font-size: 1.5rem !important;
      }
      
      #about-modal [style*="font-size: 1.5rem"] {
        font-size: 1.25rem !important;
      }
    }

    @media (max-width: 480px) {
      #about-modal > div {
        padding: 1rem;
      }
      
      #about-modal [style*="padding: 2rem"] {
        padding: 1.25rem !important;
      }
      
      #about-modal [style*="padding: 1.5rem"] {
        padding: 1rem !important;
      }
    }
    
    .glass-card {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(12px);
      border: 1px solid rgba(255, 255, 255, 0.25);
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
      border-radius: 1rem;
    }
    
    .status-indicator {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      display: inline-block;
      margin-right: 8px;
      animation: pulse 2s infinite;
      flex-shrink: 0;
    }
    
    .status-online {
      background-color: #10b981;
      box-shadow: 0 0 8px rgba(16, 185, 129, 0.6);
    }
    
    .status-offline {
      background-color: #ef4444;
      box-shadow: 0 0 8px rgba(239, 68, 68, 0.6);
    }
    
    @keyframes pulse {
      0%, 100% { 
        opacity: 1; 
        transform: scale(1);
      }
      50% { 
        opacity: 0.7; 
        transform: scale(1.05);
      }
    }
    
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      padding: 0.75rem 1.5rem;
      border-radius: 0.75rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s ease-in-out;
      border: none;
      font-size: 0.875rem;
      white-space: nowrap;
      font-family: inherit;
      line-height: 1;
    }
    
    .btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none !important;
    }
    
    .btn:not(:disabled):hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
    }
    
    .btn:not(:disabled):active {
      transform: translateY(0);
    }
    
    .btn-primary {
      background-color: #3b82f6;
      color: white;
    }
    
    .btn-primary:not(:disabled):hover {
      background-color: #2563eb;
      box-shadow: 0 6px 20px rgba(59, 130, 246, 0.3);
    }
    
    .btn-danger {
      background-color: #ef4444;
      color: white;
    }
    
    .btn-danger:not(:disabled):hover {
      background-color: #dc2626;
      box-shadow: 0 6px 20px rgba(239, 68, 68, 0.3);
    }
    
    .btn-success {
      background-color: #10b981;
      color: white;
    }
    
    .btn-success:not(:disabled):hover {
      background-color: #059669;
      box-shadow: 0 6px 20px rgba(16, 185, 129, 0.3);
    }
    
    .btn-warning {
      background-color: #f59e0b;
      color: white;
    }
    
    .btn-warning:not(:disabled):hover {
      background-color: #d97706;
      box-shadow: 0 6px 20px rgba(245, 158, 11, 0.3);
    }
    
    .btn-secondary {
      background-color: #6b7280;
      color: white;
    }
    
    .btn-secondary:not(:disabled):hover {
      background-color: #4b5563;
      box-shadow: 0 6px 20px rgba(107, 114, 128, 0.3);
    }
    
    .stat-value {
      font-size: 2rem;
      font-weight: 700;
      margin: 0.5rem 0;
      line-height: 1.2;
    }
    
    .stat-label {
      font-size: 0.875rem;
      color: #6b7280;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      font-weight: 600;
    }
    
    .info-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0.75rem 0;
      border-bottom: 1px solid #e5e7eb;
      flex-wrap: wrap;
      gap: 0.5rem;
    }
    
    .info-row:last-child {
      border-bottom: none;
    }
    
    .info-label {
      font-weight: 600;
      color: #374151;
      min-width: 120px;
    }
    
    .info-value {
      color: #6b7280;
      word-break: break-word;
      text-align: right;
    }
    
    .progress-bar {
      width: 100%;
      height: 8px;
      background-color: #e5e7eb;
      border-radius: 4px;
      overflow: hidden;
      margin-top: 0.5rem;
    }
    
    .progress-fill {
      height: 100%;
      background-color: #3b82f6;
      transition: width 0.3s ease;
      border-radius: 4px;
    }
    
    .toast {
      position: fixed;
      top: 1.25rem;
      right: 1.25rem;
      background: white;
      padding: 1rem 1.5rem;
      border-radius: 0.75rem;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
      display: none;
      z-index: 1000;
      border-left: 4px solid #3b82f6;
      max-width: 90%;
      animation: slideIn 0.3s ease-out;
      border: 1px solid #e5e7eb;
    }
    
    .toast.show {
      display: flex;
      align-items: center;
      gap: 0.75rem;
    }
    
    .toast-icon {
      font-size: 1.25rem;
      flex-shrink: 0;
    }
    
    .toast-success {
      border-left-color: #10b981;
    }
    
    .toast-error {
      border-left-color: #ef4444;
    }
    
    .toast-warning {
      border-left-color: #f59e0b;
    }
    
    .dependency-card {
      background: #f9fafb;
      border: 2px solid #e5e7eb;
      border-radius: 0.75rem;
      padding: 1.25rem;
      transition: all 0.2s;
      height: 100%;
    }
    
    .dependency-card:hover {
      border-color: #cbd5e1;
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
      transform: translateY(-2px);
    }
    
    .badge {
      padding: 0.25rem 0.75rem;
      border-radius: 1rem;
      font-size: 0.75rem;
      font-weight: 600;
      white-space: nowrap;
      display: inline-flex;
      align-items: center;
    }
    
    input, select, textarea {
      width: 100%;
      padding: 0.75rem 1rem;
      border: 2px solid #e5e7eb;
      border-radius: 0.75rem;
      font-size: 0.875rem;
      transition: all 0.2s;
      font-family: inherit;
      background-color: white;
    }
    
    input:focus, select:focus, textarea:focus {
      outline: none;
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    input[type="checkbox"] {
      width: auto;
      transform: scale(1.2);
    }
    
    table {
      border-spacing: 0;
      width: 100%;
      border-collapse: collapse;
    }
    
    thead {
      background-color: #f9fafb;
      position: sticky;
      top: 0;
    }
    
    tbody tr {
      transition: background-color 0.2s;
      border-bottom: 1px solid #e5e7eb;
    }
    
    tbody tr:hover {
      background-color: #f8fafc;
    }
    
    #log-container {
      background: #1f2937;
      border-radius: 0.75rem;
      padding: 1.25rem;
      min-height: 300px;
      max-height: 400px;
      overflow-y: auto;
      font-family: 'Fira Code', 'Courier New', monospace;
      font-size: 0.875rem;
      color: #10b981;
      line-height: 1.6;
      border: 1px solid #374151;
    }
    
    .device-info {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 0.5rem;
      padding: 0.75rem;
      margin: 0.5rem 0;
    }
    
    .device-info h4 {
      margin: 0 0 0.5rem 0;
      color: #1e40af;
      font-size: 0.875rem;
    }
    
    .device-info p {
      margin: 0.25rem 0;
      font-size: 0.75rem;
      color: #4b5563;
    }
    
    .system-status {
      background: #f0f9ff;
      border: 1px solid #bae6fd;
      border-radius: 0.75rem;
      padding: 1.25rem;
      margin: 0.75rem 0;
    }
    
    .system-status h3 {
      margin: 0 0 0.75rem 0;
      color: #0369a1;
      font-size: 1rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    
    .status-item {
      display: flex;
      justify-content: space-between;
      padding: 0.5rem 0;
      border-bottom: 1px solid #e0f2fe;
    }
    
    .status-item:last-child {
      border-bottom: none;
    }
    
    .mobile-menu {
      display: none;
    }
    
    .content-area {
      transition: all 0.3s ease;
    }

    /* Smooth scrolling */
    html {
      scroll-behavior: smooth;
    }

    /* Selection color */
    ::selection {
      background-color: #3b82f6;
      color: white;
    }

    /* Scrollbar styling */
    ::-webkit-scrollbar {
      width: 6px;
    }

    ::-webkit-scrollbar-track {
      background: #f1f5f9;
      border-radius: 3px;
    }

    ::-webkit-scrollbar-thumb {
      background: #cbd5e1;
      border-radius: 3px;
    }

    ::-webkit-scrollbar-thumb:hover {
      background: #94a3b8;
    }

    /* Focus visible for accessibility */
    button:focus-visible,
    input:focus-visible,
    select:focus-visible,
    textarea:focus-visible {
      outline: 2px solid #3b82f6;
      outline-offset: 2px;
    }
    
    @media (max-width: 1024px) {
      .content-area {
        width: 100%;
      }
      
      .mobile-menu {
        display: flex;
      }
    }
    
    @media (max-width: 768px) {
      .stat-value {
        font-size: 1.75rem;
      }
      
      .btn {
        padding: 0.625rem 1.25rem;
        font-size: 0.8125rem;
      }
      
      .info-label {
        min-width: 100px;
        font-size: 0.8125rem;
      }
      
      .info-value {
        font-size: 0.8125rem;
        text-align: left;
      }
      
      .info-row {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.25rem;
      }
      
      .toast {
        right: 0.625rem;
        top: 0.625rem;
        left: 0.625rem;
        padding: 0.75rem 1rem;
        max-width: none;
      }
      
      table {
        display: block;
        overflow-x: auto;
      }

      .glass-card {
        margin: 0.5rem;
        padding: 1rem;
      }
    }
    
    @media (max-width: 480px) {
      body {
        font-size: 0.875rem;
      }
      
      .stat-value {
        font-size: 1.5rem;
      }
      
      .btn {
        width: 100%;
        justify-content: center;
      }
      
      .btn-group {
        flex-direction: column;
        gap: 0.5rem;
        width: 100%;
      }

      .dependency-card {
        padding: 1rem;
      }

      #log-container {
        padding: 1rem;
        min-height: 250px;
        max-height: 300px;
      }
    }

    /* Print styles */
    @media print {
      .btn, .toast, #about-modal {
        display: none !important;
      }
      
      .glass-card {
        box-shadow: none;
        border: 1px solid #000;
      }
    }
  </style>
</head>
<body class="min-h-screen bg-gray-50">
  <!-- Modal About -->
  <div id="about-modal" class="fixed inset-0 bg-black bg-opacity-60 z-50 flex items-center justify-center p-4 hidden" style="backdrop-filter: blur(8px);">
    <div class="glass-card w-full max-w-2xl max-h-[90vh] overflow-y-auto" style="border-radius: 1.5rem;">
      <!-- Header dengan Gradient -->
      <div class="relative" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 2rem; border-radius: 1.5rem 1.5rem 0 0;">
        <button onclick="closeAboutModal()" style="position: absolute; top: 1rem; right: 1rem; width: 2.5rem; height: 2.5rem; background: rgba(255,255,255,0.2); backdrop-filter: blur(10px); border-radius: 50%; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s;">
          <i class="fas fa-times" style="color: white; font-size: 1.25rem;"></i>
        </button>
        
        <div style="text-align: center; color: white;">
          <div style="width: 5rem; height: 5rem; background: rgba(255,255,255,0.2); backdrop-filter: blur(10px); border-radius: 1.25rem; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; box-shadow: 0 8px 32px rgba(0,0,0,0.1);">
            <i class="fas fa-wifi" style="font-size: 2rem;"></i>
          </div>
          <h2 style="font-size: 1.75rem; font-weight: 700; margin-bottom: 0.5rem;">OpenWrt Rakitan Manager</h2>
          <p style="color: rgba(255,255,255,0.9); font-size: 0.95rem;">Dashboard kontrol dan monitoring modem USB dengan ADB dinamis</p>
        </div>
      </div>
      
      <div style="padding: 2rem;">
        <!-- Info Cards Grid -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
          <div style="background: linear-gradient(135deg, #ebf4ff 0%, #dbeafe 100%); border-radius: 1rem; padding: 1.5rem; border: 2px solid #bfdbfe;">
            <div style="color: #1e40af; font-size: 0.75rem; font-weight: 600; margin-bottom: 0.5rem; letter-spacing: 0.05em;">VERSI</div>
              <div style="color: #1e3a8a; font-size: 1.5rem; font-weight: 700;">v<?php echo htmlspecialchars($currentVersion, ENT_QUOTES); ?></div>
          </div>
          
          <div style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); border-radius: 1rem; padding: 1.5rem; border: 2px solid #a7f3d0;">
            <div style="color: #065f46; font-size: 0.75rem; font-weight: 600; margin-bottom: 0.5rem; letter-spacing: 0.05em;">STATUS</div>
            <div style="display: flex; align-items: center;">
              <span class="status-indicator status-online"></span>
              <span style="color: #064e3b; font-size: 1.5rem; font-weight: 700;">Active</span>
            </div>
          </div>
        </div>

        <!-- Developer Info -->
        <div style="background: #f9fafb; border-radius: 1rem; padding: 1.5rem; border: 1px solid #e5e7eb; margin-bottom: 1.5rem;">
          <div class="info-row">
            <span class="info-label">
              <i class="fas fa-user-tie" style="margin-right: 0.5rem; color: #6366f1;"></i>
              Developer
            </span>
            <span class="info-value" style="font-weight: 600; color: #111827;">RizkiKotet</span>
          </div>
          <div class="info-row">
            <span class="info-label">
              <i class="fas fa-calendar-alt" style="margin-right: 0.5rem; color: #6366f1;"></i>
              Update Terakhir
            </span>
            <span class="info-value" style="font-weight: 600; color: #111827;"><?php echo htmlspecialchars($updatelast, ENT_QUOTES); ?></span>
          </div>
        </div>

        <!-- Fitur Utama -->
        <div style="background: linear-gradient(135deg, #faf5ff 0%, #f3e8ff 100%); border: 2px solid #e9d5ff; border-radius: 1rem; padding: 1.5rem; margin-bottom: 1.5rem;">
          <h4 style="font-weight: 700; color: #6b21a8; margin-bottom: 1rem; display: flex; align-items: center; font-size: 1.125rem;">
            <i class="fas fa-star" style="margin-right: 0.5rem; color: #eab308;"></i>
            Fitur Utama
          </h4>
          <ul class="about-feature-list" style="list-style: none; padding: 0;">
            <li style="display: flex; align-items: center; padding: 0.5rem 0; color: #7c3aed;">
              <div style="width: 1.5rem; height: 1.5rem; background: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 0.75rem; flex-shrink: 0;">
                <i class="fas fa-check" style="color: white; font-size: 0.75rem;"></i>
              </div>
              <span style="font-weight: 500; font-size: 0.9375rem;">Manajemen modem USB otomatis</span>
            </li>
            <li style="display: flex; align-items: center; padding: 0.5rem 0; color: #7c3aed;">
              <div style="width: 1.5rem; height: 1.5rem; background: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 0.75rem; flex-shrink: 0;">
                <i class="fas fa-check" style="color: white; font-size: 0.75rem;"></i>
              </div>
              <span style="font-weight: 500; font-size: 0.9375rem;">Monitoring jaringan real-time</span>
            </li>
            <li style="display: flex; align-items: center; padding: 0.5rem 0; color: #7c3aed;">
              <div style="width: 1.5rem; height: 1.5rem; background: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 0.75rem; flex-shrink: 0;">
                <i class="fas fa-check" style="color: white; font-size: 0.75rem;"></i>
              </div>
              <span style="font-weight: 500; font-size: 0.9375rem;">Dukungan ADB untuk perangkat Android</span>
            </li>
            <li style="display: flex; align-items: center; padding: 0.5rem 0; color: #7c3aed;">
              <div style="width: 1.5rem; height: 1.5rem; background: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 0.75rem; flex-shrink: 0;">
                <i class="fas fa-check" style="color: white; font-size: 0.75rem;"></i>
              </div>
              <span style="font-weight: 500; font-size: 0.9375rem;">Log system terintegrasi</span>
            </li>
          </ul>
        </div>

        <!-- Social Links -->
        <div style="margin-bottom: 1.5rem;">
          <h4 style="font-weight: 700; color: #111827; margin-bottom: 1rem; display: flex; align-items: center; font-size: 1rem;">
            <i class="fas fa-share-alt" style="margin-right: 0.5rem; color: #3b82f6;"></i>
            Connect With Us
          </h4>
          
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
            <!-- GitHub Button -->
            <a href="https://github.com/rtaserver-wrt/RakitanManager-Reborn" target="_blank" style="background: #24292e; color: white; border-radius: 1rem; padding: 1.25rem; display: flex; align-items: center; justify-content: center; text-decoration: none; transition: all 0.2s; box-shadow: 0 4px 12px rgba(0,0,0,0.1);" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 8px 20px rgba(0,0,0,0.2)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(0,0,0,0.1)';">
              <i class="fab fa-github" style="font-size: 1.75rem; margin-right: 1rem;"></i>
              <div style="text-align: left;">
                <div style="font-size: 0.75rem; color: rgba(255,255,255,0.7);">View on</div>
                <div style="font-weight: 700; font-size: 1.125rem;">GitHub</div>
              </div>
            </a>
            
            <!-- Telegram Button -->
            <a href="https://t.me/rizkikotet" target="_blank" style="background: #0088cc; color: white; border-radius: 1rem; padding: 1.25rem; display: flex; align-items: center; justify-content: center; text-decoration: none; transition: all 0.2s; box-shadow: 0 4px 12px rgba(0,136,204,0.2);" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 8px 20px rgba(0,136,204,0.3)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(0,136,204,0.2)';">
              <i class="fab fa-telegram" style="font-size: 1.75rem; margin-right: 1rem;"></i>
              <div style="text-align: left;">
                <div style="font-size: 0.75rem; color: rgba(255,255,255,0.9);">Join us on</div>
                <div style="font-weight: 700; font-size: 1.125rem;">Telegram</div>
              </div>
            </a>
          </div>
        </div>

        <!-- QR Code Donasi -->
        <div style="background: linear-gradient(135deg, #fefce8 0%, #fef3c7 100%); border: 2px solid #fbbf24; border-radius: 1rem; padding: 1.5rem; margin-bottom: 1.5rem;">
          <h4 style="font-weight: 700; color: #92400e; margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: center; font-size: 1.125rem;">
            <i class="fas fa-heart" style="margin-right: 0.5rem; color: #ef4444; animation: pulse 2s infinite;"></i>
            Dukung Pengembangan
          </h4>
          
          <div style="background: white; border-radius: 1rem; padding: 1.5rem; box-shadow: inset 0 2px 8px rgba(0,0,0,0.05);">
            <div style="text-align: center; margin: 20px 0;">
              <img src="./assets/download.png" alt="Donate" style="max-width: 100%; height: auto; display: inline-block;">
            </div>
            <p style="font-size: 0.8125rem; color: #4b5563; font-weight: 600; text-align: center;">Scan untuk Donasi</p>
          </div>
          
          <p style="text-align: center; color: #92400e; font-size: 0.9375rem; font-weight: 600; margin-top: 1rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
            Terima kasih atas dukungan Anda!
            <span style="font-size: 1.25rem;">🙏</span>
          </p>
        </div>

        <!-- Checkbox -->
        <div style="display: flex; align-items: center; padding: 1rem; background: #f9fafb; border-radius: 0.75rem; border: 1px solid #e5e7eb; margin-bottom: 1rem;">
          <input type="checkbox" id="dont-show-today" style="width: 1.125rem; height: 1.125rem; accent-color: #3b82f6; cursor: pointer; margin-right: 0.75rem;">
          <label for="dont-show-today" style="font-size: 0.9375rem; font-weight: 500; color: #374151; cursor: pointer; user-select: none;">
            Jangan tampilkan lagi untuk hari ini
          </label>
        </div>
      </div>
      
      <!-- Footer -->
      <div style="display: flex; justify-content: flex-end; gap: 0.75rem; padding: 1.5rem 2rem; border-top: 1px solid #e5e7eb; background: #f9fafb; border-radius: 0 0 1.5rem 1.5rem;">
        <button onclick="closeAboutModal()" class="btn btn-primary" style="gap: 0.5rem;">
          <i class="fas fa-check"></i>
          Mengerti
        </button>
      </div>
    </div>
  </div>

  <div class="flex min-h-screen">
    <!-- Main Content -->
    <div class="content-area flex-1 p-4 lg:p-8">
      <!-- Header -->
            <div class="bg-white/60 backdrop-blur-lg rounded-2xl p-6 mb-6 lg:mb-8 shadow">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                    <div class="flex-1">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1">
                                <h1 class="text-2xl lg:text-3xl font-extrabold text-gray-900 mb-1">OpenWrt Rakitan Manager</h1>
                                <p class="text-sm text-gray-600 mb-4">Dashboard kontrol dan monitoring modem USB dengan ADB dinamis</p>
                            </div>
                            <div class="flex-shrink-0">
                                <button id="about-btn" class="inline-flex items-center gap-2 bg-gray-100 hover:bg-gray-200 text-gray-800 px-3 py-2 rounded-md" onclick="showAboutModal()">
                                    <i class="fas fa-info-circle"></i>
                                    About
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                            <div class="p-4 bg-white shadow-sm rounded-lg">
                                <div class="flex items-center justify-between">
                                    <div class="text-xs text-gray-500"><i class="fas fa-code-branch"></i> Current</div>
                                    <div id="current-version-display" class="text-sm font-semibold text-gray-800"> <i class="fas fa-spinner fa-spin"></i> Loading...</div>
                                </div>
                            </div>

                            <div class="p-4 bg-white shadow-sm rounded-lg">
                                <div class="flex items-center justify-between">
                                    <div class="text-xs text-gray-500"><i class="fas fa-rocket"></i> Latest</div>
                                    <div id="latest-version-display" class="text-sm font-semibold text-gray-800"> <i class="fas fa-spinner fa-spin"></i> Loading...</div>
                                </div>
                            </div>

                            <div class="p-4 bg-white shadow-sm rounded-lg">
                                <div class="flex items-center justify-between">
                                    <div class="text-xs text-gray-500"><i class="fas fa-sync"></i> Last Checked</div>
                                    <div id="last-checked-display" class="text-sm font-medium text-gray-700"><i class="fas fa-clock"></i> Just now</div>
                                </div>
                            </div>
                        </div>

                        <div id="version-container" class="flex items-center flex-wrap gap-3">
                            <span id="current-version" class="inline-flex items-center gap-2 bg-indigo-600 text-white px-3 py-1 rounded-full text-sm font-medium">
                                <i class="fas fa-code-branch"></i>
                                <span>Loading...</span>
                            </span>

                            <span id="update-badge" class="hidden inline-flex items-center gap-2 bg-amber-500 text-white px-3 py-1 rounded-full text-sm font-medium">
                                <i class="fas fa-download"></i>
                                <span>Update Available!</span>
                            </span>

                            <button id="btn-check-update" onclick="checkForUpdates()" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded-md text-sm font-semibold">
                                <i class="fas fa-sync-alt"></i>
                                Check Update
                            </button>

                            <button id="btn-view-changelog" onclick="showChangelog()" class="inline-flex items-center gap-2 border border-gray-200 text-gray-700 px-3 py-1 rounded-md text-sm">
                                <i class="fas fa-file-alt"></i>
                                Changelog
                            </button>
                        </div>
                    </div>
                </div>
            </div>

      <!-- Stats Cards -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 lg:gap-6 mb-6 lg:mb-8">
        <div class="glass-card rounded-2xl p-6">
          <div class="flex items-center justify-between mb-4">
            <div class="stat-label">Download Speed</div>
            <i class="fas fa-download text-green-500 text-xl"></i>
          </div>
          <div class="stat-value text-green-600">
            <span id="download-speed">0.0</span> 
            <span class="text-xl">Mbps</span>
          </div>
          <div class="progress-bar">
            <div class="progress-fill bg-green-500" style="width: 0%"></div>
          </div>
        </div>
        
        <div class="glass-card rounded-2xl p-6">
          <div class="flex items-center justify-between mb-4">
            <div class="stat-label">Upload Speed</div>
            <i class="fas fa-upload text-amber-500 text-xl"></i>
          </div>
          <div class="stat-value text-amber-600">
            <span id="upload-speed">0.0</span> 
            <span class="text-xl">Mbps</span>
          </div>
          <div class="progress-bar">
            <div class="progress-fill bg-amber-500" style="width: 0%"></div>
          </div>
        </div>
        
        <div class="glass-card rounded-2xl p-6">
          <div class="flex items-center justify-between mb-4">
            <div class="stat-label">Data Usage</div>
            <i class="fas fa-chart-pie text-purple-500 text-xl"></i>
          </div>
          <div class="stat-value text-purple-600">
            <span id="data-usage">0.0</span> 
            <span class="text-xl">GB</span>
          </div>
          <div class="text-sm text-gray-500 mt-2">Bulan ini</div>
        </div>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 lg:gap-8">
        <!-- System Status -->
        <div class="glass-card rounded-2xl p-6">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <div>
              <h2 class="text-xl font-bold text-gray-800 mb-1">
                <i class="fas fa-server mr-2 text-blue-500"></i>
                System Status
              </h2>
              <p class="text-gray-600 text-sm">Status sistem dan konfigurasi jaringan</p>
            </div>
            <button id="toggle-rakitanmanager" class="btn <?php echo $rakitanmanager_status ? 'btn-danger' : 'btn-success'; ?>" onclick="toggleRakitanManager()">
              <?php echo $rakitanmanager_status ? '<i class="fas fa-stop"></i> STOP' : '<i class="fas fa-play"></i> START'; ?>
            </button>
          </div>
          
          <div class="system-status">
            <h3 class="flex items-center">
              <i class="fas fa-cog mr-2"></i>
              Rakitan Manager Status
            </h3>
            <div class="status-item">
              <span class="font-semibold">Status:</span>
              <span id="rakitanmanager-status" class="font-bold <?php echo $rakitanmanager_status ? 'text-green-600' : 'text-red-600'; ?>">
                <?php echo $rakitanmanager_status ? 'Enabled' : 'Disabled'; ?>
              </span>
            </div>
            <div class="status-item">
              <span class="font-semibold">Branch:</span>
              <span class="font-bold text-blue-600"><?php echo $branch_select ?: 'main'; ?></span>
            </div>
          </div>
        </div>

        <!-- Dependency Checker -->
        <div class="glass-card rounded-2xl p-6">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <div>
              <h2 class="text-xl font-bold text-gray-800 mb-1">
                <i class="fab fa-python mr-2 text-green-500"></i>
                Python Dependencies
              </h2>
              <p class="text-gray-600 text-sm">Status instalasi dan pembaruan dependensi wajib</p>
            </div>
            <button class="btn btn-primary" onclick="checkAllDependencies()">
              <i class="fas fa-sync-alt"></i>
              Check All
            </button>
          </div>
          
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- pip -->
            <div class="dependency-card">
              <div class="flex justify-between items-start mb-3">
                <div>
                  <div class="font-bold text-gray-800 text-lg mb-1">pip</div>
                  <div class="text-sm text-gray-600">Package Installer</div>
                </div>
                <span id="pip-badge" class="badge bg-blue-100 text-blue-800">Checking...</span>
              </div>
              <div class="flex justify-between items-center text-sm text-gray-600 mb-3">
                <span>Version: <span id="pip-version" class="font-semibold text-gray-800">-</span></span>
                <span id="pip-latest" class="text-xs">Latest: -</span>
              </div>
              <button id="pip-btn" class="btn btn-secondary w-full" onclick="handleDependency('pip')" disabled>
                <i class="fas fa-circle-notch fa-spin"></i>
                Checking...
              </button>
            </div>
            
            <!-- requests -->
            <div class="dependency-card">
              <div class="flex justify-between items-start mb-3">
                <div>
                  <div class="font-bold text-gray-800 text-lg mb-1">requests</div>
                  <div class="text-sm text-gray-600">HTTP Library</div>
                </div>
                <span id="requests-badge" class="badge bg-blue-100 text-blue-800">Checking...</span>
              </div>
              <div class="flex justify-between items-center text-sm text-gray-600 mb-3">
                <span>Version: <span id="requests-version" class="font-semibold text-gray-800">-</span></span>
                <span id="requests-latest" class="text-xs">Latest: -</span>
              </div>
              <button id="requests-btn" class="btn btn-secondary w-full" onclick="handleDependency('requests')" disabled>
                <i class="fas fa-circle-notch fa-spin"></i>
                Checking...
              </button>
            </div>
            
            <!-- huawei-lte-api -->
            <div class="dependency-card">
              <div class="flex justify-between items-start mb-3">
                <div>
                  <div class="font-bold text-gray-800 text-lg mb-1">huawei-lte-api</div>
                  <div class="text-sm text-gray-600">Huawei API Client</div>
                </div>
                <span id="huawei-badge" class="badge bg-blue-100 text-blue-800">Checking...</span>
              </div>
              <div class="flex justify-between items-center text-sm text-gray-600 mb-3">
                <span>Version: <span id="huawei-version" class="font-semibold text-gray-800">-</span></span>
                <span id="huawei-latest" class="text-xs">Latest: -</span>
              </div>
              <button id="huawei-btn" class="btn btn-secondary w-full" onclick="handleDependency('huawei-lte-api')" disabled>
                <i class="fas fa-circle-notch fa-spin"></i>
                Checking...
              </button>
            </div>
            
            <!-- datetime -->
            <div class="dependency-card">
              <div class="flex justify-between items-start mb-3">
                <div>
                  <div class="font-bold text-gray-800 text-lg mb-1">datetime</div>
                  <div class="text-sm text-gray-600">Date & Time Module</div>
                </div>
                <span id="datetime-badge" class="badge bg-green-100 text-green-800">Built-in</span>
              </div>
              <div class="flex justify-between items-center text-sm text-gray-600 mb-3">
                <span>Version: <span id="datetime-version" class="font-semibold text-gray-800">Built-in</span></span>
                <span id="datetime-latest" class="text-xs">Built-in</span>
              </div>
              <button id="datetime-btn" class="btn btn-success w-full" onclick="handleDependency('datetime')" disabled>
                <i class="fas fa-check"></i>
                Built-in Module
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Modem List Table -->
      <div class="glass-card rounded-2xl p-6 mt-6 lg:mt-8">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
          <h2 class="text-xl font-bold text-gray-800">
            <i class="fas fa-wifi mr-2 text-blue-500"></i>
            Daftar Modem
          </h2>
          <div class="flex flex-wrap gap-2 btn-group">
            <button id="add-modem-btn" class="btn btn-primary" onclick="addNewModem()">
              <i class="fas fa-plus"></i>
              Tambah Devices / Modem
            </button>
          </div>
        </div>
        
        <div class="overflow-x-auto rounded-lg">
          <table class="w-full">
            <thead>
              <tr class="bg-gray-50">
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Nama</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Device Name</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Method Ping</th>
                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Host Ping</th>
                <th class="px-4 py-3 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">Action</th>
              </tr>
            </thead>
            <tbody id="modem-list" class="divide-y divide-gray-200">
              <!-- Modems will be loaded dynamically from API -->
            </tbody>
          </table>
        </div>
      </div>

      <!-- System Log -->
      <div class="glass-card rounded-2xl p-6 mt-6 lg:mt-8">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
          <h2 class="text-xl font-bold text-gray-800">
            <i class="fas fa-clipboard-list mr-2 text-blue-500"></i>
            System log
          </h2>
          <div class="flex flex-wrap gap-2 btn-group">
            <button class="btn btn-primary" onclick="loadRakitanManagerLog()">
              <i class="fas fa-download"></i>
              Load log
            </button>
            <button class="btn btn-success" onclick="saveLog()">
              <i class="fas fa-save"></i>
              Save Log
            </button>
            <button class="btn btn-danger" onclick="clearLog()">
              <i class="fas fa-trash"></i>
              Clear Log
            </button>
          </div>
        </div>
        
        <div id="log-container" class="rounded-lg">
          <div class="log-entry">[System] OpenWrt Rakitan Manager started</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Toast Notification -->
  <div id="toast" class="toast">
    <div id="toast-icon" class="toast-icon">
      <i class="fas fa-check-circle"></i>
    </div>
    <div>
      <div id="toast-title" class="font-semibold mb-1">Success</div>
      <div id="toast-message" class="text-sm text-gray-600">Action completed successfully</div>
    </div>
  </div>

  <!-- Modal Form Tambah Modem -->
  <div id="modal-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4 hidden">
    <div class="glass-card rounded-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
      <div class="flex justify-between items-center p-6 border-b border-gray-200">
        <h2 class="text-2xl font-bold text-gray-800" id="modal-title">➕ Tambah Modem Baru</h2>
        <button onclick="closeModal()" class="p-2 rounded-full hover:bg-gray-100 transition-colors">
          <i class="fas fa-times text-gray-500 text-xl"></i>
        </button>
      </div>
      
      <form onsubmit="submitModemForm(event)" class="p-6 space-y-4">
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-2">Nama Modem</label>
          <input type="text" id="modem-name" placeholder="Contoh: Modem-5" required 
                 class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
        </div>
        
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-2">Devices Name</label>
          <select id="device-type" required onchange="handleDeviceTypeChange()"
                  class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
            <option value="">-- Pilih Devices --</option>
            <option value="Rakitan">Rakitan</option>
            <option value="HP (Phone)">HP (Phone)</option>
            <option value="Huawei / Orbit">Huawei / Orbit</option>
            <option value="Hilink">Hilink</option>
            <option value="MF90">MF90</option>
            <option value="Custom Script">Custom Script</option>
          </select>
        </div>
        
        <div id="dynamic-fields" class="space-y-4"></div>
        
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-2">Method</label>
          <select id="method" required
                  class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
            <option value="">-- Pilih Method --</option>
            <option value="ICMP">ICMP</option>
            <option value="CURL">CURL</option>
            <option value="HTTP">HTTP</option>
            <option value="HTTP/S">HTTP/S</option>
          </select>
        </div>
        
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-2">Host Ping</label>
          <input type="text" id="host-ping" placeholder="Contoh: 8.8.8.8" required
                 class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
        </div>
        
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-2">Device Modem Untuk Cek Ping</label>
          <select id="ping-device" required
                  class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
            <option value="default">Jangan Gunakan | Default</option>
            <?php
              foreach ($interfaces as $devicemodem) {
                echo "<option value=\"$devicemodem\">$devicemodem</option>";
              }
            ?>
          </select>
        </div>
        
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-2">Percobaan Ping Gagal</label>
          <input type="number" id="retry-count" placeholder="Contoh: 3" min="1" required
                 class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
        </div>
        
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-2">Jeda Waktu Detik | Sebelum Melanjutkan Cek Ping</label>
          <input type="number" id="delay-time" placeholder="Contoh: 5" min="1" required
                 class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
        </div>
        
        <div class="flex flex-col sm:flex-row justify-between gap-3 pt-4">
          <button type="button" id="delete-modem-btn" onclick="deleteModem()" class="btn btn-danger order-2 sm:order-1 hidden">
            <i class="fas fa-trash"></i>
            Hapus Modem
          </button>
          <div class="flex gap-3 order-1 sm:order-2">
            <button type="button" onclick="closeModal()" class="btn btn-secondary">
              <i class="fas fa-times"></i>
              Batal
            </button>
            <button type="submit" class="btn btn-primary">
              <i class="fas fa-save"></i>
              Simpan Modem
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal Changelog -->
  <div id="changelog-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] flex flex-col">
      <!-- Modal Header -->
      <div class="flex items-center justify-between p-4 border-b">
        <h3 class="text-xl font-bold text-gray-800">
          <i class="fas fa-file-alt text-blue-600"></i> Changelog
        </h3>
        <button onclick="closeChangelog()" class="text-gray-500 hover:text-gray-700">
          <i class="fas fa-times text-xl"></i>
        </button>
      </div>
      
      <!-- Modal Body -->
      <div id="changelog-content" class="flex-1 overflow-y-auto p-6">
        <div class="flex items-center justify-center py-8">
          <i class="fas fa-spinner fa-spin text-3xl text-blue-600"></i>
        </div>
      </div>
      
      <!-- Modal Footer -->
      <div class="flex items-center justify-between p-4 border-t bg-gray-50">
        <div class="text-xs text-gray-500">
          <span id="changelog-cache-info"></span>
        </div>
        <button onclick="closeChangelog()" class="px-4 py-2 bg-gray-500 text-white rounded hover:bg-gray-600 transition">
          Close
        </button>
      </div>
    </div>
  </div>

  <!-- Modal Update Details -->
  <div id="update-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full">
      <!-- Modal Header -->
      <div class="flex items-center justify-between p-4 border-b bg-gradient-to-r from-blue-500 to-blue-600">
        <h3 class="text-xl font-bold text-white">
          <i class="fas fa-download"></i> Update Available
        </h3>
        <button onclick="closeUpdateModal()" class="text-white hover:text-gray-200">
          <i class="fas fa-times text-xl"></i>
        </button>
      </div>
      
      <!-- Modal Body -->
      <div id="update-content" class="p-6">
        <!-- Content will be injected here -->
      </div>
      
      <!-- Modal Footer -->
      <div class="flex items-center justify-end gap-2 p-4 border-t bg-gray-50">
        <button onclick="closeUpdateModal()" class="px-4 py-2 bg-gray-500 text-white rounded hover:bg-gray-600 transition">
          Later
        </button>
        <a id="btn-download-update" href="#" target="_blank" 
          class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 transition">
          <i class="fas fa-download"></i> Download Update
        </a>
      </div>
    </div>
  </div>

  <script>
    // Helper function: Check if response is JSON
    function isJsonResponse(response) {
        const contentType = response.headers.get('content-type');
        return contentType && contentType.includes('application/json');
    }

    // Helper function: Safe JSON parsing
    async function safeJsonParse(response) {
        try {
            const text = await response.text();
            
            // Try to parse JSON
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('JSON parsing error:', e, 'Response text:', text.substring(0, 200));
                
                // If it's HTML, try to extract error message
                if (text.includes('<br />') || text.includes('<b>')) {
                    const match = text.match(/<b>(.*?)<\/b>/);
                    if (match) {
                        return {
                            success: false,
                            message: `Server error: ${match[1]}`
                        };
                    }
                }
                
                return {
                    success: false,
                    message: 'Invalid response format from server'
                };
            }
        } catch (error) {
            console.error('Error reading response:', error);
            return {
                success: false,
                message: 'Failed to read server response'
            };
        }
    }
    // =============================================
    // VERSION MANAGEMENT SYSTEM
    // =============================================

    let versionData = {
        current: <?php echo json_encode($currentVersion); ?>,
        latest: null,
        updateAvailable: false,
        lastChecked: null,
        changelog: null
    };

    // Single handler for current version (getter/setter)
    function setCurrentVersion(version, source = '') {
        if (!version) return;
        versionData.current = version;

        const currentVersionEl = document.getElementById('current-version');
        const currentDisplayEl = document.getElementById('current-version-display');
        if (currentVersionEl) currentVersionEl.innerHTML = `<i class="fas fa-code-branch"></i> v${version}`;
        if (currentDisplayEl) currentDisplayEl.innerHTML = `v${version}`;
        // optional: log source of update
        if (source) console.log('setCurrentVersion from', source);
    }

    function getCurrentVersion() {
        return versionData.current || '';
    }

    function getCurrentVersionClean() {
        return (getCurrentVersion() || '').toString().replace(/^v/, '').replace(/[^0-9\.]/g, '');
    }

    // Initialize version system
    async function initializeVersionSystem() {
        await loadCurrentVersion();
        await checkVersionUpdate();
    }

    // Load current version from server
    async function loadCurrentVersion() {
        try {
            const response = await fetch('?api=get_current_version');
            const result = await safeJsonParse(response);
            
            if (result.success) {
                setCurrentVersion(result.version, 'loadCurrentVersion');
                updateVersionDisplay();
            } else {
                console.error('Error loading current version:', result.message);
            }
        } catch (error) {
            console.error('Error loading current version:', error);
        }
    }

    // Check for version updates
    async function checkVersionUpdate() {
        const checkBtn = document.getElementById('btn-check-update');
        const originalText = checkBtn?.innerHTML;
        
        if (checkBtn) {
            checkBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';
            checkBtn.disabled = true;
        }
        
        try {
            const response = await fetchWithTimeout('?api=get_version_info', {}, 15000);
            
            // Cek content type
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                const data = await response.json();
                
                if (data.success) {
                    setCurrentVersion(data.current_version, 'checkVersionUpdate');
                    versionData.latest = data.latest_version;
                    versionData.updateAvailable = data.update_available;
                    versionData.lastChecked = data.last_checked;
                    versionData.changelog = data.changelog;
                    
                    updateVersionDisplay();
                    showUpdateNotificationIfAvailable();
                    
                    if (data.update_available) {
                        addLogEntry(`Update available: ${data.current_version} → ${data.latest_version}`);
                    } else {
                        addLogEntry('Version check: You are using the latest version');
                    }
                } else {
                    showToast('Failed to check for updates: ' + (data.message || 'Unknown error'), 'error');
                }
            } else {
                const text = await response.text();
                console.error('Non-JSON response from get_version_info:', text);
                showToast('Failed to check for updates: Invalid response format', 'error');
            }
        } catch (error) {
            console.error('Error checking version update:', error);
            showToast('Failed to check for updates', 'error');
        } finally {
            if (checkBtn) {
                checkBtn.innerHTML = originalText;
                checkBtn.disabled = false;
            }
        }
    }

    // Helper: fetch with timeout using AbortController
    async function fetchWithTimeout(resource, options = {}, timeout = 15000) {
        const controller = new AbortController();
        const id = setTimeout(() => controller.abort(), timeout);
        try {
            const resp = await fetch(resource, { ...options, signal: controller.signal });
            clearTimeout(id);
            return resp;
        } catch (err) {
            clearTimeout(id);
            throw err;
        }
    }

    // Update version display in header
    function updateVersionDisplay() {
        const currentVersionEl = document.getElementById('current-version');
        const latestVersionEl = document.getElementById('latest-version');
        const updateBadge = document.getElementById('update-badge');
        const versionContainer = document.getElementById('version-container');
        
        if (currentVersionEl) {
            currentVersionEl.innerHTML = `<i class="fas fa-code-branch"></i> v${versionData.current}`;
        }
        
        if (latestVersionEl && versionData.latest) {
            latestVersionEl.innerHTML = `<i class="fas fa-rocket"></i> ${versionData.latest}`;
        }
        
        if (updateBadge && versionContainer) {
            if (versionData.updateAvailable) {
                updateBadge.classList.remove('hidden');
                updateBadge.innerHTML = `<i class="fas fa-download"></i> Update Available!`;
                versionContainer.classList.add('highlight-update');
                
                // Remove highlight after animation
                setTimeout(() => {
                    versionContainer.classList.remove('highlight-update');
                }, 6000);
            } else {
                updateBadge.classList.add('hidden');
                versionContainer.classList.remove('highlight-update');
            }
        }
    }

    // Show update notification if available
    function showUpdateNotificationIfAvailable() {
        if (!versionData.updateAvailable) return;
        
        const existingNotification = document.getElementById('update-notification');
        if (existingNotification) return;
        
        const notification = document.createElement('div');
        notification.id = 'update-notification';
        notification.className = 'update-notification';
        notification.innerHTML = `
            <div style="position: relative; width: 100%;">
                <button class="update-close-btn" onclick="dismissUpdateNotification()" aria-label="Close update notification">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </button>
                <div class="update-body">
                    <h4>
                        <i class="fas fa-download" style="color:#0ea5a4"></i>
                        New Version Available!
                    </h4>
                    <p aria-live="polite">
                        Upgrade from <strong>v${versionData.current}</strong> to <strong>${versionData.latest}</strong> — the latest features and improvements are ready to install.
                    </p>
                </div>
                <div class="update-actions">
                    <button onclick="showUpdateDetails()" class="btn btn-warning btn-sm" aria-haspopup="dialog">
                        <i class="fas fa-info-circle"></i>&nbsp;Details
                    </button>
                    <a id="btn-download-update" class="btn btn-primary btn-sm" href="#" role="button">
                        <i class="fas fa-download"></i>&nbsp;Download
                    </a>
                </div>
            </div>
        `;

        // Append to body as floating overlay
        document.body.appendChild(notification);
        // Accessibility: announce and focus the notification briefly
        notification.setAttribute('role', 'status');
        notification.tabIndex = -1;
        try { notification.focus(); } catch (e) { /* ignore focus issues */ }

        // Ensure download link is set immediately
        const downloadBtnInit = document.getElementById('btn-download-update');
        if (downloadBtnInit && versionData.latest) {
            downloadBtnInit.href = `https://github.com/rtaserver-wrt/RakitanManager-Reborn/releases/tag/${versionData.latest}`;
        }
    }

    // Dismiss update notification
    function dismissUpdateNotification() {
        const notification = document.getElementById('update-notification');
        if (notification) {
            notification.remove();
        }
    }

    // Show update details modal
    async function showUpdateDetails() {
        const modal = document.getElementById('update-modal');
        const content = document.getElementById('update-content');
        
        if (!modal || !content) return;
        
        content.innerHTML = `
            <div class="flex items-center justify-center py-8">
                <i class="fas fa-spinner fa-spin text-3xl text-blue-600"></i>
                <span class="ml-3 text-gray-600">Loading update details...</span>
            </div>
        `;
        
        modal.classList.remove('hidden');
        
        try {
            // Get latest release info
            const response = await fetch('?api=get_latest_version');
            const data = await response.json();
            
            if (data.success) {
                const publishedDate = new Date(data.published_at).toLocaleDateString();
                const changes = data.body ? data.body.replace(/\n/g, '<br>') : 'No release notes available.';
                
                content.innerHTML = `
                    <div class="space-y-6">
                        <!-- Version Comparison -->
                        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
                            <div class="flex items-center justify-between">
                                <div class="text-center flex-1">
                                    <div class="text-sm text-gray-600 mb-1">Current Version</div>
                                    <div class="text-2xl font-bold text-gray-700">v${versionData.current}</div>
                                </div>
                                <div class="text-center mx-4">
                                    <i class="fas fa-arrow-right text-xl text-blue-500"></i>
                                </div>
                                <div class="text-center flex-1">
                                    <div class="text-sm text-gray-600 mb-1">Latest Version</div>
                                    <div class="text-2xl font-bold text-green-600">${data.tag_name}</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Release Info -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="bg-gray-50 rounded-lg p-4">
                                <h4 class="font-semibold text-gray-800 mb-2 flex items-center gap-2">
                                    <i class="fas fa-calendar"></i> Release Date
                                </h4>
                                <p class="text-gray-600">${publishedDate}</p>
                            </div>
                            <div class="bg-gray-50 rounded-lg p-4">
                                <h4 class="font-semibold text-gray-800 mb-2 flex items-center gap-2">
                                    <i class="fas fa-user"></i> Publisher
                                </h4>
                                <p class="text-gray-600">${data.author?.login || 'Unknown'}</p>
                            </div>
                        </div>
                        
                        <!-- Release Notes -->
                        <div>
                            <h4 class="font-semibold text-gray-800 mb-3 flex items-center gap-2">
                                <i class="fas fa-newspaper"></i> Whats New
                            </h4>
                            <div class="bg-gray-50 rounded-lg p-4 max-h-64 overflow-y-auto">
                                <div class="prose prose-sm max-w-none">
                                    ${formatReleaseNotes(changes)}
                                </div>
                            </div>
                        </div>
                        
                        <!-- Download Info -->
                        ${data.assets && data.assets.length > 0 ? `
                        <div>
                            <h4 class="font-semibold text-gray-800 mb-3 flex items-center gap-2">
                                <i class="fas fa-download"></i> Downloads
                            </h4>
                            <div class="space-y-2">
                                ${data.assets.map(asset => `
                                    <div class="flex items-center justify-between bg-gray-50 rounded-lg p-3">
                                        <div class="flex items-center gap-3">
                                            <i class="fas fa-file-archive text-blue-500"></i>
                                            <div>
                                                <div class="font-medium text-gray-800">${asset.name}</div>
                                                <div class="text-sm text-gray-600">${asset.size_mb} MB • ${asset.download_count} downloads</div>
                                            </div>
                                        </div>
                                        <a href="${asset.download_url}" target="_blank" class="btn btn-primary btn-sm">
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                        ` : ''}
                    </div>
                `;
            } else {
                content.innerHTML = `
                    <div class="text-center py-8 text-red-600">
                        <i class="fas fa-exclamation-triangle text-3xl mb-2"></i>
                        <p>Failed to load update details</p>
                    </div>
                `;
            }
        } catch (error) {
            content.innerHTML = `
                <div class="text-center py-8 text-red-600">
                    <i class="fas fa-exclamation-triangle text-3xl mb-2"></i>
                    <p>Error loading update details</p>
                </div>
            `;
        }
        
        // Update download button
        const downloadBtn = document.getElementById('btn-download-update');
        if (downloadBtn && versionData.latest) {
            downloadBtn.href = `https://github.com/rtaserver-wrt/RakitanManager-Reborn/releases/tag/${versionData.latest}`;
        }
    }

    // Format release notes with better styling
    function formatReleaseNotes(notes) {
        if (!notes) return '<p class="text-gray-500 italic">No release notes available.</p>';
        
        let html = notes;
        
        // Headers
        html = html.replace(/^### (.+)$/gm, '<h3 class="text-lg font-bold mt-4 mb-2 text-gray-800">$1</h3>');
        html = html.replace(/^## (.+)$/gm, '<h2 class="text-xl font-bold mt-4 mb-2 text-gray-800">$1</h2>');
        html = html.replace(/^# (.+)$/gm, '<h1 class="text-2xl font-bold mt-4 mb-2 text-gray-800">$1</h1>');
        
        // Lists
        html = html.replace(/^\* (.+)$/gm, '<li class="ml-4 mb-1">$1</li>');
        html = html.replace(/^- (.+)$/gm, '<li class="ml-4 mb-1">$1</li>');
        
        // Bold and Italic
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong class="font-semibold">$1</strong>');
        html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
        
        // Code
        html = html.replace(/`(.+?)`/g, '<code class="bg-gray-200 px-1 py-0.5 rounded text-sm font-mono">$1</code>');
        
        // Wrap lists
        html = html.replace(/(<li class="ml-4 mb-1">.+?<\/li>)+/g, '<ul class="list-disc my-2">$&</ul>');
        
        return html;
    }

    // Enhanced changelog display
    async function showChangelog() {
        const modal = document.getElementById('changelog-modal');
        const content = document.getElementById('changelog-content');
        
        modal.classList.remove('hidden');
        
        content.innerHTML = `
            <div class="flex items-center justify-center py-8">
                <i class="fas fa-spinner fa-spin text-3xl text-blue-600"></i>
                <span class="ml-3 text-gray-600">Loading changelog...</span>
            </div>
        `;
        
        try {
            const response = await fetch('?api=get_changelog');
            const data = await response.json();
            
            if (data.success) {
                let html = '';
                
                if (data.parsed_versions && data.parsed_versions.length > 0) {
                    html = '<div class="space-y-6">';
                    
                    data.parsed_versions.forEach((version, index) => {
                        const isLatest = index === 0;
                        const isCurrent = version.version === versionData.current.replace(/^v/, '');
                        
                        html += `
                            <div class="changelog-entry ${isLatest ? 'border-blue-500' : 'border-gray-500'} ${isCurrent ? 'bg-blue-50 border-blue-300' : ''}">
                                <div class="changelog-version">
                                    <span>v${version.version}</span>
                                    ${isLatest ? '<span class="badge badge-latest">Latest</span>' : ''}
                                    ${isCurrent ? '<span class="badge badge-update">Current</span>' : ''}
                                </div>
                                <div class="changelog-content">
                                    ${formatReleaseNotes(version.content)}
                                </div>
                            </div>
                        `;
                    });
                    
                    html += '</div>';
                } else {
                    html = `<div class="changelog-container"><div class="prose prose-invert max-w-none">${formatReleaseNotes(data.raw_content)}</div></div>`;
                }
                
                content.innerHTML = html;
            } else {
                content.innerHTML = `
                    <div class="text-center py-8 text-red-600">
                        <i class="fas fa-exclamation-triangle text-3xl mb-2"></i>
                        <p>${data.message || 'Failed to load changelog'}</p>
                    </div>
                `;
            }
        } catch (error) {
            content.innerHTML = `
                <div class="text-center py-8 text-red-600">
                    <i class="fas fa-exclamation-triangle text-3xl mb-2"></i>
                    <p>Error loading changelog</p>
                </div>
            `;
        }
    }

    // Manual version check (exposed globally)
    function checkForUpdates() {
        checkVersionUpdate();
    }

    // Close update modal
    function closeUpdateModal() {
        document.getElementById('update-modal').classList.add('hidden');
    }

    // Close changelog modal
    function closeChangelog() {
        document.getElementById('changelog-modal').classList.add('hidden');
    }

    // =============================================
    // ABOUT MODAL FUNCTIONS
    // =============================================

    function showAboutModal() {
        const modal = document.getElementById('about-modal');
        if (modal) {
            modal.classList.remove('hidden');
        }
    }

    function closeAboutModal() {
        const modal = document.getElementById('about-modal');
        const dontShowCheckbox = document.getElementById('dont-show-today');
        
        if (modal) {
            modal.classList.add('hidden');
        }
        
        // Jika checkbox dicentang, simpan preferensi di localStorage
        if (dontShowCheckbox && dontShowCheckbox.checked) {
            const today = new Date().toDateString();
            localStorage.setItem('dontShowAboutModal', today);
            addLogEntry('About modal disabled for today');
        }
    }

    function shouldShowAboutModal() {
        // Cek apakah modal sudah ditampilkan hari ini
        const lastDontShow = localStorage.getItem('dontShowAboutModal');
        const today = new Date().toDateString();
        
        // Jika tidak ada preferensi atau preferensi dari hari yang berbeda, tampilkan modal
        return !lastDontShow || lastDontShow !== today;
    }

    // Fungsi untuk menampilkan modal about secara otomatis
    function autoShowAboutModal() {
        // Tunggu sebentar agar halaman selesai loading
        setTimeout(() => {
            if (shouldShowAboutModal()) {
                showAboutModal();
                addLogEntry('About modal displayed on page load');
            }
        }, 1500);
    }

    // =============================================
    // CONFIGURATION & INITIALIZATION
    // =============================================
    const defaultConfig = {
        dashboard_title: "OpenWrt Rakitan Manager",
        device_name: "Huawei E3372",
        primary_color: "#3b82f6",
        success_color: "#10b981",
        danger_color: "#ef4444",
        background_gradient_start: "#667eea",
        background_gradient_end: "#764ba2"
    };
    
    let config = { ...defaultConfig };
    let isEditMode = false;
    let currentEditModem = null;
    let modemsData = [];
    let isLoadingRakitanLog = false;
    let lastRakitanLogContent = '';
    let autoRefreshInterval;
    let isRakitanManagerEnabled = <?php echo $rakitanmanager_status; ?>;

    const dependencies = {
        pip: { installed: false, version: null, latest: null, status: 'checking' },
        requests: { installed: false, version: null, latest: null, status: 'checking' },
        'huawei-lte-api': { installed: false, version: null, latest: null, status: 'checking' },
        datetime: { installed: true, version: 'Built-in', latest: 'Built-in', status: 'installed' }
    };

    // =============================================
    // UI/UX ENHANCEMENTS
    // =============================================

    // Toast notification system
    function showToast(message, type = 'success', duration = 3000) {
        const toast = document.getElementById('toast');
        const toastTitle = document.getElementById('toast-title');
        const toastMessage = document.getElementById('toast-message');
        const toastIcon = document.getElementById('toast-icon');
        
        if (!toast || !toastTitle || !toastMessage || !toastIcon) return;
        
        toastMessage.textContent = message;
        
        // Set color and icon based on type
        if (type === 'error') {
            toast.className = 'toast show toast-error';
            toastTitle.textContent = 'Error';
            toastIcon.innerHTML = '<i class="fas fa-exclamation-circle text-red-500"></i>';
        } else if (type === 'warning') {
            toast.className = 'toast show toast-warning';
            toastTitle.textContent = 'Warning';
            toastIcon.innerHTML = '<i class="fas fa-exclamation-triangle text-amber-500"></i>';
        } else if (type === 'info') {
            toast.className = 'toast show';
            toastTitle.textContent = 'Info';
            toastIcon.innerHTML = '<i class="fas fa-info-circle text-blue-500"></i>';
        } else {
            toast.className = 'toast show toast-success';
            toastTitle.textContent = 'Success';
            toastIcon.innerHTML = '<i class="fas fa-check-circle text-green-500"></i>';
        }
        
        // Auto-hide after duration
        setTimeout(() => {
            toast.classList.remove('show');
        }, duration);
    }

    function updateToastPosition() {
        const toast = document.getElementById('toast');
        if (!toast) return;
        
        if (window.innerWidth < 768) {
            toast.style.top = '1rem';
            toast.style.right = '1rem';
            toast.style.left = '1rem';
            toast.style.maxWidth = 'calc(100% - 2rem)';
        } else {
            toast.style.top = '1.25rem';
            toast.style.right = '1.25rem';
            toast.style.left = 'auto';
            toast.style.maxWidth = '400px';
        }
    }

    // Loading states
    function setButtonLoading(button, isLoading, loadingText = 'Loading...') {
        if (!button) return;
        
        if (isLoading) {
            button.disabled = true;
            button.setAttribute('data-original-text', button.innerHTML);
            button.innerHTML = `<i class="fas fa-spinner fa-spin"></i> ${loadingText}`;
        } else {
            button.disabled = false;
            const originalText = button.getAttribute('data-original-text');
            if (originalText && !button.hasAttribute('data-updated')) {
                button.innerHTML = originalText;
            }
            button.removeAttribute('data-original-text');
            button.removeAttribute('data-updated');
        }
    }

    // =============================================
    // BLOCKING FUNCTIONS WHEN ENABLED
    // =============================================

    function updateUIForEnabledStatus() {
        const isEnabled = isRakitanManagerEnabled;
        
        // Block dependency buttons
        const dependencyButtons = ['pip-btn', 'requests-btn', 'huawei-btn'];
        dependencyButtons.forEach(id => {
            const btn = document.getElementById(id);
            if (!btn) return;

            if (isEnabled) {
                // Only save original state once
                if (!btn.hasAttribute('data-original-state')) {
                    const originalState = JSON.stringify({
                        html: btn.innerHTML,
                        className: btn.className,
                        disabled: btn.disabled
                    });
                    btn.setAttribute('data-original-state', originalState);
                }
                
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-ban"></i> Disabled (ENABLED)';
                btn.className = 'btn btn-secondary w-full';
            } else {
                // Restore original state
                if (btn.hasAttribute('data-original-state')) {
                    const originalState = JSON.parse(btn.getAttribute('data-original-state'));
                    btn.innerHTML = originalState.html;
                    btn.className = originalState.className;
                    btn.disabled = originalState.disabled;
                    btn.removeAttribute('data-original-state');
                }
            }
        });

        // Block add modem button
        const addModemBtn = document.getElementById('add-modem-btn');
        if (addModemBtn) {
            if (isEnabled) {
                addModemBtn.disabled = true;
                addModemBtn.setAttribute('data-original-html', addModemBtn.innerHTML);
                addModemBtn.innerHTML = '<i class="fas fa-ban"></i> Tambah Devices (DISABLED)';
                addModemBtn.className = 'btn btn-secondary';
            } else {
                addModemBtn.disabled = false;
                addModemBtn.innerHTML = '<i class="fas fa-plus"></i> Tambah Devices / Modem';
                addModemBtn.className = 'btn btn-primary';
            }
        }
    }

    // =============================================
    // NETWORK & SYSTEM FUNCTIONS
    // =============================================

    async function refreshData() {
        try {
            const response = await fetch('?api=get_network_stats');
            const data = await response.json();

            if (data.error) {
                console.error('Network stats error:', data.error);
                return;
            }

            updateNetworkStatsUI(data);
            
            // Only show toast on manual refresh
            if (event && event.type === 'click') {
                showToast('Data berhasil di-refresh');
            }
        } catch (error) {
            console.error('Error refreshing data:', error);
        }
    }

    function updateNetworkStatsUI(data) {
        const downloadElement = document.getElementById('download-speed');
        const uploadElement = document.getElementById('upload-speed');
        const dataElement = document.getElementById('data-usage');

        if (downloadElement) downloadElement.textContent = data.download_speed;
        if (uploadElement) uploadElement.textContent = data.upload_speed;
        if (dataElement) dataElement.textContent = data.data_usage;

        // Update progress bars based on speeds
        const downloadProgress = Math.min((data.download_speed / 100) * 100, 100);
        const uploadProgress = Math.min((data.upload_speed / 50) * 100, 100);

        const downloadProgressBar = downloadElement?.closest('.glass-card')?.querySelector('.progress-fill');
        const uploadProgressBar = uploadElement?.closest('.glass-card')?.querySelector('.progress-fill');

        if (downloadProgressBar) {
            downloadProgressBar.style.width = downloadProgress + '%';
        }
        if (uploadProgressBar) {
            uploadProgressBar.style.width = uploadProgress + '%';
        }
    }

    async function toggleRakitanManager() {
        const toggleBtn = document.getElementById('toggle-rakitanmanager');
        const statusElement = document.getElementById('rakitanmanager-status');
        
        setButtonLoading(toggleBtn, true, 'Processing...');
        
        try {
            const response = await fetch('?api=toggle_rakitanmanager');
            const data = await response.json();
            
            if (data.success) {
                isRakitanManagerEnabled = data.enabled;
                
                // Update tombol toggle secara dinamis - TANPA reload
                if (data.enabled) {
                    // Set flag bahwa tombol sudah diupdate manual
                    toggleBtn.setAttribute('data-updated', 'true');
                    toggleBtn.innerHTML = '<i class="fas fa-stop"></i> STOP';
                    toggleBtn.className = 'btn btn-danger';
                    
                    if (statusElement) {
                        statusElement.textContent = 'Enabled';
                        statusElement.className = 'font-bold text-green-600';
                    }
                    showToast('Rakitan Manager STARTED successfully');
                    addLogEntry('Rakitan Manager service started - Modem management disabled');
                    updateUIForEnabledStatus();
                } else {
                    // Set flag bahwa tombol sudah diupdate manual
                    toggleBtn.setAttribute('data-updated', 'true');
                    toggleBtn.innerHTML = '<i class="fas fa-play"></i> START';
                    toggleBtn.className = 'btn btn-success';
                    
                    if (statusElement) {
                        statusElement.textContent = 'Disabled';
                        statusElement.className = 'font-bold text-red-600';
                    }
                    showToast('Rakitan Manager STOPPED successfully');
                    addLogEntry('Rakitan Manager service stopped - Modem management enabled');
                    updateUIForEnabledStatus();
                }
                // Refresh modem list to update manage buttons
                loadModems();
            } else {
                showToast('Failed to toggle Rakitan Manager', 'error');
            }
        } catch (error) {
            console.error('Error toggling Rakitan Manager:', error);
            showToast('Failed to toggle Rakitan Manager', 'error');
        } finally {
            setButtonLoading(toggleBtn, false);
        }
    }

    // =============================================
    // DEPENDENCY MANAGEMENT
    // =============================================

    async function checkAllDependencies() {
        const checkBtn = document.querySelector('button[onclick*="checkAllDependencies"]');
        setButtonLoading(checkBtn, true, 'Checking...');
        addLogEntry('Starting dependency check...');

        try {
            const response = await fetch('?api=check_dependencies');
            
            // Cek content type
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                const data = await response.json();

                // Update dependencies object
                Object.keys(data).forEach(packageName => {
                    dependencies[packageName] = data[packageName];
                });

                // Update UI for each dependency
                Object.keys(data).forEach(packageName => {
                    updateDependencyUI(packageName, data[packageName]);
                });

                addLogEntry('Dependency check completed');
                showToast('Pengecekan dependensi selesai');
            } else {
                const text = await response.text();
                console.error('Non-JSON response from check_dependencies:', text);
                showToast('Gagal mengecek dependensi: Respons tidak valid', 'error');
            }
        } catch (error) {
            console.error('Error checking dependencies:', error);
            addLogEntry('Error checking dependencies: ' + error.message);
            showToast('Gagal mengecek dependensi', 'error');
        } finally {
            setButtonLoading(checkBtn, false);
        }
    }

    function updateDependencyUI(packageName, data) {
        const safeId = packageName === 'huawei-lte-api' ? 'huawei' : packageName;
        const badge = document.getElementById(`${safeId}-badge`);
        const versionEl = document.getElementById(`${safeId}-version`);
        const latestEl = document.getElementById(`${safeId}-latest`);
        const btn = document.getElementById(`${safeId}-btn`);

        if (!badge || !versionEl || !latestEl || !btn) return;

        if (packageName === 'datetime') {
            badge.textContent = 'Installed';
            badge.className = 'badge bg-green-100 text-green-800';
            versionEl.textContent = 'Built-in';
            latestEl.textContent = 'Built-in';
            btn.innerHTML = '<i class="fas fa-check"></i> Built-in Module';
            btn.className = 'btn btn-success w-full';
            btn.disabled = true;
            addLogEntry(`${packageName}: Built-in module (always available)`);
        } else if (data.installed) {
            if (data.status === 'update') {
                badge.textContent = 'Update Available';
                badge.className = 'badge bg-amber-100 text-amber-800';
                versionEl.textContent = data.version;
                latestEl.textContent = `Latest: ${data.latest}`;
                btn.innerHTML = '<i class="fas fa-arrow-up"></i> Update';
                btn.className = 'btn btn-warning w-full';
                btn.disabled = false;
                addLogEntry(`${packageName}: v${data.version} installed, update available (v${data.latest})`);
            } else {
                badge.textContent = 'Installed';
                badge.className = 'badge bg-green-100 text-green-800';
                versionEl.textContent = data.version;
                latestEl.textContent = `Latest: ${data.latest}`;
                btn.innerHTML = '<i class="fas fa-check"></i> Installed';
                btn.className = 'btn btn-success w-full';
                btn.disabled = true;
                addLogEntry(`${packageName}: v${data.version} (up to date)`);
            }
        } else {
            badge.textContent = 'Not Installed';
            badge.className = 'badge bg-red-100 text-red-800';
            versionEl.textContent = 'Not installed';
            latestEl.textContent = `Latest: ${data.latest}`;
            btn.innerHTML = '<i class="fas fa-download"></i> Install';
            btn.className = 'btn btn-primary w-full';
            btn.disabled = false;
            addLogEntry(`${packageName}: Not installed (latest: v${data.latest})`);
        }

        // Apply blocking if enabled
        if (isRakitanManagerEnabled && packageName !== 'datetime') {
            btn.disabled = true;
            btn.setAttribute('data-original-html', btn.innerHTML);
            btn.innerHTML = '<i class="fas fa-ban"></i> Disabled (ENABLED)';
            btn.className = 'btn btn-secondary w-full';
        }
    }

    async function handleDependency(packageName) {
        // Check if enabled before proceeding
        if (isRakitanManagerEnabled) {
            showToast('Tidak bisa install/update dependensi ketika Rakitan Manager ENABLE', 'error');
            return;
        }
        
        const dep = dependencies[packageName];
        const safeId = packageName === 'huawei-lte-api' ? 'huawei' : packageName;
        const btn = document.getElementById(`${safeId}-btn`);
        const badge = document.getElementById(`${safeId}-badge`);

        if (!btn || !badge) return;

        const action = dep.status === 'not_installed' ? 'install' : 'update';
        setButtonLoading(btn, true, action === 'install' ? 'Installing...' : 'Updating...');
        
        badge.textContent = action === 'install' ? 'Installing...' : 'Updating...';
        badge.className = 'badge bg-blue-100 text-blue-800';

        addLogEntry(`${action.charAt(0).toUpperCase() + action.slice(1)} ${packageName}...`);

        try {
            const response = await fetch(`?api=install_dependency&package=${encodeURIComponent(packageName)}&action=${action}`);
            const result = await response.json();

            if (result.success) {
                showToast(result.message);
                addLogEntry(`${packageName}: ${result.message}`);
            } else {
                if (result.blocked) {
                    showToast(result.message, 'error');
                    addLogEntry(`Blocked: ${result.message}`);
                } else {
                    showToast('Error: ' + result.message, 'error');
                    addLogEntry(`Failed to ${action} ${packageName}: ${result.message}`);
                }
            }
        } catch (error) {
            console.error('Install error:', error);
            showToast('Gagal menghubungi server', 'error');
            addLogEntry(`Network error saat ${action} ${packageName}`);
        }

        // Refresh status setelah selesai
        await checkAllDependencies();
    }

    // =============================================
    // MODEM MANAGEMENT
    // =============================================

    async function loadModems() {
        try {
            const response = await fetch('?api=get_modems');
            const modems = await response.json();

            const modemList = document.getElementById('modem-list');
            if (!modemList) return;

            modemList.innerHTML = '';

            if (modems.length === 0) {
                modemList.innerHTML = '<tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">No modems found</td></tr>';
                return;
            }

            modems.forEach(modem => {
                const row = document.createElement('tr');
                row.className = 'hover:bg-gray-50 transition-colors';
                
                const manageButton = isRakitanManagerEnabled ? 
                    '<button class="btn btn-secondary" disabled>' +
                    '<i class="fas fa-ban"></i>' +
                    '<span class="hidden sm:inline">Manage (DISABLED)</span>' +
                    '</button>' :
                    '<button class="btn btn-primary" onclick="manageModem(\'' + modem.id + '\')">' +
                    '<i class="fas fa-cog"></i>' +
                    '<span class="hidden sm:inline">Manage</span>' +
                    '</button>';
                
                row.innerHTML = `
                    <td class="px-4 py-4 text-gray-900 font-semibold">${escapeHtml(modem.name)}</td>
                    <td class="px-4 py-4 text-gray-600">${escapeHtml(modem.device_name)}</td>
                    <td class="px-4 py-4">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            ${escapeHtml(modem.method)}
                        </span>
                    </td>
                    <td class="px-4 py-4 text-gray-600">${escapeHtml(modem.host_ping)}</td>
                    <td class="px-4 py-4 text-center">
                        ${manageButton}
                    </td>
                `;
                modemList.appendChild(row);
            });
            
            modemsData = modems;
        } catch (error) {
            console.error('Error loading modems:', error);
            showToast('Failed to load modems', 'error');
        }
    }

    function manageModem(modemId) {
        // Check if enabled before proceeding
        if (isRakitanManagerEnabled) {
            showToast('Tidak bisa manage modem ketika Rakitan Manager ENABLE', 'error');
            return;
        }
        
        const modem = modemsData.find(m => m.id === modemId);
        if (!modem) {
            showToast('Modem tidak ditemukan', 'error');
            return;
        }

        isEditMode = true;
        currentEditModem = modem;

        const modal = document.getElementById('modal-overlay');
        const modalTitle = document.getElementById('modal-title');
        const deleteBtn = document.getElementById('delete-modem-btn');

        if (modalTitle) modalTitle.textContent = '✏️ Edit Modem';
        if (deleteBtn) {
            deleteBtn.style.display = 'block';
            // Apply blocking to delete button if enabled
            if (isRakitanManagerEnabled) {
                deleteBtn.disabled = true;
                deleteBtn.setAttribute('data-original-html', deleteBtn.innerHTML);
                deleteBtn.innerHTML = '<i class="fas fa-ban"></i> Hapus (DISABLED)';
                deleteBtn.className = 'btn btn-secondary order-2 sm:order-1';
            } else {
                deleteBtn.disabled = false;
                deleteBtn.innerHTML = '<i class="fas fa-trash"></i> Hapus Modem';
                deleteBtn.className = 'btn btn-danger order-2 sm:order-1';
            }
        }

        // Fill form
        document.getElementById('modem-name').value = modem.name || '';
        document.getElementById('device-type').value = modem.device_name || '';
        handleDeviceTypeChange();

        // Wait for dynamic fields to render
        setTimeout(() => {
            const deviceType = modem.device_name;
            if (deviceType === 'Rakitan') {
                if (modem.port_modem) document.getElementById('port-modem').value = modem.port_modem;
                if (modem.interface_mm) document.getElementById('interface-mm').value = modem.interface_mm;
            } else if (deviceType === 'HP (Phone)') {
                if (modem.android_device) document.getElementById('android-device').value = modem.android_device;
                if (modem.renew_method) document.getElementById('renew-method').value = modem.renew_method;
            } else if (['Huawei / Orbit', 'Hilink', 'MF90'].includes(deviceType)) {
                if (modem.ip_modem) document.getElementById('ip-modem').value = modem.ip_modem;
                if (modem.username) document.getElementById('username-modem').value = modem.username;
                if (modem.password) document.getElementById('password-modem').value = modem.password;
            } else if (deviceType === 'Custom Script') {
                if (modem.custom_script) document.getElementById('custom-script').value = modem.custom_script;
            }
        }, 100);

        document.getElementById('method').value = modem.method || '';
        document.getElementById('host-ping').value = modem.host_ping || '';
        document.getElementById('ping-device').value = modem.ping_device || 'default';
        document.getElementById('retry-count').value = modem.retry_count || '';
        document.getElementById('delay-time').value = modem.delay_time || '';

        if (modal) modal.classList.remove('hidden');
    }

    function addNewModem() {
        // Check if enabled before proceeding
        if (isRakitanManagerEnabled) {
            showToast('Tidak bisa tambah modem ketika Rakitan Manager ENABLE', 'error');
            return;
        }
        
        isEditMode = false;
        currentEditModem = null;
        const modal = document.getElementById('modal-overlay');
        const modalTitle = document.getElementById('modal-title');
        const deleteBtn = document.getElementById('delete-modem-btn');
        
        if (modalTitle) modalTitle.textContent = '➕ Tambah Modem Baru';
        if (deleteBtn) deleteBtn.style.display = 'none';
        if (modal) modal.classList.remove('hidden');
    }

    function handleDeviceTypeChange() {
        const deviceType = document.getElementById('device-type').value;
        const dynamicFields = document.getElementById('dynamic-fields');
        if (!dynamicFields) return;

        dynamicFields.innerHTML = '';

        if (deviceType === 'Rakitan') {
            dynamicFields.innerHTML = `
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Port Modem</label>
                    <select id="port-modem" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                        <option value="">-- Pilih Port --</option>
                        <option value="/dev/ttyUSB0">/dev/ttyUSB0</option>
                        <option value="/dev/ttyUSB1">/dev/ttyUSB1</option>
                        <option value="/dev/ttyUSB2">/dev/ttyUSB2</option>
                        <option value="/dev/ttyACM0">/dev/ttyACM0</option>
                        <option value="/dev/ttyACM1">/dev/ttyACM1</option>
                        <option value="/dev/ttyACM2">/dev/ttyACM2</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Interface Rakitan Manager</label>
                    <select id="interface-mm" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                        <option value="">-- Pilih Interface --</option>
                        <?php
                          foreach ($interface_modem as $interface) {
                            echo "<option value=\"$interface\">$interface</option>";
                          }
                        ?>
                    </select>
                </div>
            `;
        } else if (deviceType === 'HP (Phone)') {
            dynamicFields.innerHTML = `
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Android Device</label>
                    <div class="flex gap-2 items-center">
                        <select id="android-device" required class="flex-1 px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                            <option value="">-- Pilih Android Device --</option>
                        </select>
                        <button type="button" onclick="scanAndUpdateAndroidDevices()" class="btn btn-primary whitespace-nowrap">
                            <i class="fas fa-search"></i>
                            Scan
                        </button>
                    </div>
                    <div class="mt-2">
                        <small class="text-gray-500">Gunakan tombol Scan untuk mendeteksi perangkat Android yang terhubung via USB</small>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Metode Renew IP</label>
                    <select id="renew-method" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                        <option value="">-- Pilih Metode --</option>
                        <option value="v1">Mode v1</option>
                        <option value="v2">Mode v2</option>
                    </select>
                </div>
            `;
        } else if (['Huawei / Orbit', 'Hilink', 'MF90'].includes(deviceType)) {
            dynamicFields.innerHTML = `
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">IP Modem</label>
                    <input type="text" id="ip-modem" placeholder="Contoh: 192.168.8.1" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Username</label>
                    <input type="text" id="username-modem" placeholder="Masukkan username" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Password</label>
                    <input type="password" id="password-modem" placeholder="Masukkan password" required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                </div>
            `;
        } else if (deviceType === 'Custom Script') {
            dynamicFields.innerHTML = `
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Script</label>
                    <textarea id="custom-script" placeholder="Masukkan custom script..." required class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition min-h-[150px] font-mono resize-vertical"></textarea>
                </div>
            `;
        }
    }

    async function scanAndUpdateAndroidDevices() {
        const androidDeviceSelect = document.getElementById('android-device');
        if (!androidDeviceSelect) return;

        const originalValue = androidDeviceSelect.value;
        const scanBtn = androidDeviceSelect.nextElementSibling;

        setButtonLoading(scanBtn, true, 'Scanning...');
        showToast('Scanning Android devices...', 'warning');
        
        androidDeviceSelect.disabled = true;
        androidDeviceSelect.innerHTML = '<option value="">Scanning...</option>';

        try {
            const response = await fetch('?api=get_android_devices');
            const result = await response.json();

            androidDeviceSelect.innerHTML = '<option value="">-- Pilih Android Device --</option>';

            if (result.success && result.devices.length > 0) {
                result.devices.forEach(device => {
                    const option = document.createElement('option');
                    option.value = device.id;
                    option.textContent = `${device.model} (${device.manufacturer}) - ${device.id}`;
                    androidDeviceSelect.appendChild(option);
                });

                // Restore original value if it still exists
                if (originalValue && androidDeviceSelect.querySelector(`option[value="${originalValue}"]`)) {
                    androidDeviceSelect.value = originalValue;
                }

                showToast(`Found ${result.devices.length} Android device(s)`);
                addLogEntry(`Updated Android devices list: ${result.devices.length} device(s) found`);
            } else {
                showToast('No Android devices found', 'warning');
                addLogEntry('No Android devices detected during scan');
            }
        } catch (error) {
            console.error('Scan error:', error);
            showToast('Failed to scan Android devices', 'error');
            addLogEntry('Error scanning Android devices: ' + error.message);
        } finally {
            androidDeviceSelect.disabled = false;
            setButtonLoading(scanBtn, false);
        }
    }

    function closeModal() {
        const modal = document.getElementById('modal-overlay');
        if (modal) modal.classList.add('hidden');
        
        // Reset form
        const form = document.querySelector('#modal-overlay form');
        if (form) form.reset();
        document.getElementById('dynamic-fields').innerHTML = '';
    }

    async function deleteModem() {
        // Check if enabled before proceeding
        if (isRakitanManagerEnabled) {
            showToast('Tidak bisa menghapus modem ketika Rakitan Manager ENABLE', 'error');
            return;
        }
        
        if (!currentEditModem || !currentEditModem.id) return;

        if (!confirm(`Hapus modem "${currentEditModem.name}"? Tindakan ini tidak bisa dikembalikan.`)) return;

        try {
            const response = await fetch(`?api=delete_modem&id=${encodeURIComponent(currentEditModem.id)}`);
            const result = await response.json();
            if (result.success) {
                showToast(result.message);
                addLogEntry(`Modem "${currentEditModem.name}" dihapus`);
                closeModal();
                loadModems();
            } else {
                if (result.blocked) {
                    showToast(result.message, 'error');
                } else {
                    showToast('Error: ' + result.message, 'error');
                }
            }
        } catch (error) {
            console.error('Delete error:', error);
            showToast('Gagal menghapus modem', 'error');
        }
    }

    async function submitModemForm(event) {
        event.preventDefault();

        // Check if enabled before proceeding
        if (isRakitanManagerEnabled) {
            showToast('Tidak bisa menyimpan modem ketika Rakitan Manager ENABLE', 'error');
            return;
        }

        const submitBtn = event.target.querySelector('button[type="submit"]');
        setButtonLoading(submitBtn, true, 'Menyimpan...');

        const modemData = {
            name: document.getElementById('modem-name').value,
            device_name: document.getElementById('device-type').value,
            method: document.getElementById('method').value,
            host_ping: document.getElementById('host-ping').value,
            ping_device: document.getElementById('ping-device').value,
            retry_count: parseInt(document.getElementById('retry-count').value),
            delay_time: parseInt(document.getElementById('delay-time').value)
        };

        // Add device-specific fields
        const deviceType = document.getElementById('device-type').value;
        if (deviceType === 'Rakitan') {
            modemData.port_modem = document.getElementById('port-modem').value;
            modemData.interface_mm = document.getElementById('interface-mm').value;
        } else if (deviceType === 'HP (Phone)') {
            modemData.android_device = document.getElementById('android-device').value;
            modemData.renew_method = document.getElementById('renew-method').value;
            modemData.androidid = document.getElementById('android-device').value;
            modemData.modpes = document.getElementById('renew-method').value === 'v1' ? 'modpesv1' : 'modpesv2';
        } else if (['Huawei / Orbit', 'Hilink', 'MF90'].includes(deviceType)) {
            modemData.ip_modem = document.getElementById('ip-modem').value;
            modemData.username = document.getElementById('username-modem').value;
            modemData.password = document.getElementById('password-modem').value;
            modemData.iporbit = document.getElementById('ip-modem').value;
            modemData.usernameorbit = document.getElementById('username-modem').value;
            modemData.passwordorbit = document.getElementById('password-modem').value;
        } else if (deviceType === 'Custom Script') {
            modemData.custom_script = document.getElementById('custom-script').value;
            modemData.script = document.getElementById('custom-script').value;
        }

        // Set ID for edit mode
        if (isEditMode && currentEditModem) {
            modemData.id = currentEditModem.id;
        }

        try {
            const response = await fetch('?api=save_modem', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(modemData)
            });

            const result = await response.json();

            if (result.success) {
                addLogEntry(`Modem "${modemData.name}" ${isEditMode ? 'updated' : 'created'} successfully`);
                showToast(`Modem "${modemData.name}" ${isEditMode ? 'updated' : 'created'} successfully!`);
                closeModal();
                loadModems();
            } else {
                if (result.blocked) {
                    showToast(result.message, 'error');
                } else {
                    showToast('Error: ' + result.message, 'error');
                }
            }
        } catch (error) {
            console.error('Error saving modem:', error);
            showToast('Failed to save modem', 'error');
        } finally {
            setButtonLoading(submitBtn, false);
        }
    }

    // =============================================
    // LOG MANAGEMENT
    // =============================================

    async function loadRakitanManagerLog() {
        if (isLoadingRakitanLog) return;
        
        try {
            isLoadingRakitanLog = true;
            const response = await fetch('?api=get_rakitanmanager_log');
            const result = await response.json();

            if (result.success) {
                if (result.content !== lastRakitanLogContent) {
                    lastRakitanLogContent = result.content;
                    renderLog(result.content);
                }
            } else {
                console.log('Log load info: ' + result.message);
            }
        } catch (error) {
            console.error('Error loading Rakitan Manager log:', error);
        } finally {
            isLoadingRakitanLog = false;
        }
    }

    function renderLog(logContent) {
        const logContainer = document.getElementById('log-container');
        if (!logContainer) return;

        logContainer.innerHTML = '';

        if (logContent && logContent.trim()) {
            const lines = logContent.split('\n').filter(line => line.trim());
            lines.forEach(line => {
                const entry = document.createElement('div');
                entry.className = 'log-entry font-mono text-sm';
                
                // Add color coding based on log level
                if (line.includes('ERROR') || line.includes('Error')) {
                    entry.className += ' text-red-400';
                } else if (line.includes('WARNING') || line.includes('Warning')) {
                    entry.className += ' text-yellow-400';
                } else if (line.includes('INFO') || line.includes('Info')) {
                    entry.className += ' text-blue-400';
                } else if (line.includes('DEBUG') || line.includes('Debug')) {
                    entry.className += ' text-gray-400';
                } else {
                    entry.className += ' text-green-400';
                }
                
                entry.textContent = line;
                logContainer.appendChild(entry);
            });
        } else {
            const emptyMessage = document.createElement('div');
            emptyMessage.className = 'log-entry text-gray-500 text-center py-8';
            emptyMessage.textContent = 'No log entries found';
            logContainer.appendChild(emptyMessage);
        }

        // Auto-scroll to bottom
        logContainer.scrollTop = logContainer.scrollHeight;
    }

    async function addLogEntry(message) {
        try {
            const response = await fetch('?api=add_log_entry', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ message })
            });

            // Cek apakah respons adalah JSON
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                const result = await response.json();
                
                if (result.success) {
                    await loadRakitanManagerLog();
                } else {
                    console.error('Failed to add log entry:', result.message);
                }
            } else {
                // Jika bukan JSON, coba baca sebagai teks
                const text = await response.text();
                console.error('Non-JSON response:', text);
                
                // Coba load log tetap
                await loadRakitanManagerLog();
            }
        } catch (error) {
            console.error('Error adding log entry:', error);
            // Tetap coba load log meskipun ada error
            await loadRakitanManagerLog();
        }
    }

    function saveLog() {
        const logContainer = document.getElementById('log-container');
        if (!logContainer) return;
        
        const logContent = logContainer.innerText;
        
        // Create blob and download
        const blob = new Blob([logContent], { type: 'text/plain' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        
        // Generate filename with timestamp
        const timestamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, -5);
        a.download = `rakitanmanager_log_${timestamp}.txt`;
        
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
        
        showToast('Log berhasil disimpan!');
        addLogEntry('Log file saved successfully');
    }

    async function clearLog() {
        if (!confirm('Apakah Anda yakin ingin menghapus semua log?')) return;
        
        try {
            const response = await fetch('?api=clear_log');
            const result = await response.json();
            
            if (result.success) {
                showToast('Log berhasil dihapus!');
                await loadRakitanManagerLog();
            } else {
                showToast('Gagal menghapus log: ' + result.message, 'error');
            }
        } catch (error) {
            console.error('Error clearing log:', error);
            showToast('Gagal menghapus log', 'error');
        }
    }

    

    // Close update modal
    function closeUpdateModal() {
        document.getElementById('update-modal').classList.add('hidden');
    }

    // Show changelog
    async function showChangelog() {
        const modal = document.getElementById('changelog-modal');
        const content = document.getElementById('changelog-content');
        const cacheInfo = document.getElementById('changelog-cache-info');
        
        modal.classList.remove('hidden');
        
        // Show loading
        content.innerHTML = `
            <div class="flex items-center justify-center py-8">
                <i class="fas fa-spinner fa-spin text-3xl text-blue-600"></i>
                <span class="ml-3 text-gray-600">Loading changelog...</span>
            </div>
        `;
        
        try {
            const response = await fetch('?api=get_changelog');
            const data = await response.json();
            
            if (data.success) {
                let html = '';
                
                if (data.parsed_versions && data.parsed_versions.length > 0) {
                    // Display parsed versions
                    html = '<div class="space-y-6">';
                    
                    data.parsed_versions.forEach((version, index) => {
                        const isLatest = index === 0;
                        html += `
                            <div class="border-l-4 ${isLatest ? 'border-blue-500 bg-blue-50' : 'border-gray-300'} pl-4 py-2">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="font-bold text-lg ${isLatest ? 'text-blue-600' : 'text-gray-800'}">
                                        v${version.version}
                                    </span>
                                    ${isLatest ? '<span class="px-2 py-1 text-xs bg-blue-500 text-white rounded">Latest</span>' : ''}
                                </div>
                                <div class="text-sm text-gray-700 prose prose-sm max-w-none">
                                    ${formatMarkdown(version.content)}
                                </div>
                            </div>
                        `;
                    });
                    
                    html += '</div>';
                } else {
                    // Display raw content
                    html = `<div class="prose prose-sm max-w-none">${formatMarkdown(data.raw_content)}</div>`;
                }
                
                content.innerHTML = html;
                
                // Update cache info
                if (data.cached) {
                    cacheInfo.textContent = `Cached (${Math.round(data.cache_age / 60)} min old)`;
                } else {
                    cacheInfo.textContent = 'Fresh data';
                }
            } else {
                content.innerHTML = `
                    <div class="text-center py-8 text-red-600">
                        <i class="fas fa-exclamation-triangle text-3xl mb-2"></i>
                        <p>${data.message || 'Failed to load changelog'}</p>
                    </div>
                `;
            }
        } catch (error) {
            content.innerHTML = `
                <div class="text-center py-8 text-red-600">
                    <i class="fas fa-exclamation-triangle text-3xl mb-2"></i>
                    <p>Error loading changelog</p>
                </div>
            `;
        }
    }

    // Close changelog modal
    function closeChangelog() {
        document.getElementById('changelog-modal').classList.add('hidden');
    }

    // Format markdown content
    function formatMarkdown(text) {
        if (!text) return '';
        
        // Convert markdown to HTML
        let html = text;
        
        // Headers
        html = html.replace(/^### (.+)$/gm, '<h3 class="text-lg font-bold mt-4 mb-2">$1</h3>');
        html = html.replace(/^## (.+)$/gm, '<h2 class="text-xl font-bold mt-4 mb-2">$1</h2>');
        html = html.replace(/^# (.+)$/gm, '<h1 class="text-2xl font-bold mt-4 mb-2">$1</h1>');
        
        // Bold
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        
        // Italic
        html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
        
        // Lists
        html = html.replace(/^\* (.+)$/gm, '<li class="ml-4">$1</li>');
        html = html.replace(/^- (.+)$/gm, '<li class="ml-4">$1</li>');
        
        // Line breaks
        html = html.replace(/\n/g, '<br>');
        
        // Wrap lists
        html = html.replace(/(<li class="ml-4">.+?<\/li>(?:<br>)?)+/g, '<ul class="list-disc my-2">$&</ul>');
        
        return html;
    }

    // Show notification
    function showNotification(message, type = 'info') {
        const colors = {
            success: 'bg-green-500',
            error: 'bg-red-500',
            info: 'bg-blue-500',
            warning: 'bg-yellow-500'
        };
        
        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            info: 'fa-info-circle',
            warning: 'fa-exclamation-triangle'
        };
        
        const notification = document.createElement('div');
        notification.className = `fixed top-4 right-4 ${colors[type]} text-white px-6 py-3 rounded-lg shadow-lg z-50 flex items-center gap-3 animate-slide-in-right`;
        notification.innerHTML = `
            <i class="fas ${icons[type]}"></i>
            <span>${message}</span>
        `;
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.classList.add('animate-fade-out');
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }

    // =============================================
    // UTILITY FUNCTIONS
    // =============================================

    function escapeHtml(unsafe) {
        if (typeof unsafe !== 'string') return unsafe;
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // Format timestamp to relative time
    function formatRelativeTime(timestamp) {
        if (!timestamp) return 'Never';
        
        const now = Date.now();
        const diff = now - (timestamp * 1000);
        const minutes = Math.floor(diff / 60000);
        const hours = Math.floor(diff / 3600000);
        const days = Math.floor(diff / 86400000);
        
        if (minutes < 1) return 'Just now';
        if (minutes < 60) return `${minutes}m ago`;
        if (hours < 24) return `${hours}h ago`;
        return `${days}d ago`;
    }

    // Update version displays
    function updateVersionDisplay() {
        const currentVersionEl = document.getElementById('current-version');
        const currentDisplayEl = document.getElementById('current-version-display');
        const latestDisplayEl = document.getElementById('latest-version-display');
        const lastCheckedEl = document.getElementById('last-checked-display');
        const updateBadge = document.getElementById('update-badge');
        const versionContainer = document.getElementById('version-container');
        
        if (currentVersionEl) {
            currentVersionEl.innerHTML = `<i class="fas fa-code-branch"></i> v${versionData.current}`;
        }
        
        if (currentDisplayEl) {
            currentDisplayEl.innerHTML = `v${versionData.current}`;
        }
        
        if (latestDisplayEl) {
            if (versionData.latest) {
                latestDisplayEl.innerHTML = `${versionData.latest}`;
            } else {
                latestDisplayEl.innerHTML = `<i class="fas fa-question-circle"></i> Unknown`;
            }
        }
        
        if (lastCheckedEl && versionData.lastChecked) {
            lastCheckedEl.innerHTML = `<i class="fas fa-clock"></i> ${formatRelativeTime(versionData.lastChecked)}`;
        }
        
        if (updateBadge && versionContainer) {
            if (versionData.updateAvailable) {
                updateBadge.classList.remove('hidden');
                updateBadge.innerHTML = `<i class="fas fa-download"></i> Update to ${versionData.latest}!`;
                versionContainer.classList.add('highlight-update');
                
                // Remove highlight after animation
                setTimeout(() => {
                    versionContainer.classList.remove('highlight-update');
                }, 6000);
            } else {
                updateBadge.classList.add('hidden');
                versionContainer.classList.remove('highlight-update');
            }
        }
    }

    // =============================================
    // INITIALIZATION
    // =============================================

    function initializeApp() {
        // Update toast position
        updateToastPosition();
        
        // Apply initial blocking state
        updateUIForEnabledStatus();

        // Initialize version system
        initializeVersionSystem().catch(error => {
            console.error('Failed to initialize version system:', error);
        });
        
        // Load initial data with error handling
        setTimeout(() => {
            // Load dependencies with error handling
            checkAllDependencies().catch(error => {
                console.error('Failed to load dependencies:', error);
                showToast('Failed to load dependencies', 'error');
            });
            
            // Load modems with error handling
            loadModems().catch(error => {
                console.error('Failed to load modems:', error);
                showToast('Failed to load modems', 'error');
            });
            
            // Load logs with error handling
            loadRakitanManagerLog().catch(error => {
                console.error('Failed to load logs:', error);
            });
            
            addLogEntry('OpenWrt Rakitan Manager started');
            autoShowAboutModal();
            
            // Start auto-refresh with error handling
            autoRefreshInterval = setInterval(() => {
                refreshData().catch(error => {
                    console.error('Auto-refresh failed:', error);
                });
            }, 3000);
            
            // Log auto-refresh
            setInterval(() => {
                loadRakitanManagerLog().catch(error => {
                    console.error('Log refresh failed:', error);
                });
            }, 1);
        }, 1000);

        // Handle window resize
        window.addEventListener('resize', debounce(function() {
            updateToastPosition();
        }, 250));

        // Handle page visibility changes
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                if (autoRefreshInterval) {
                    clearInterval(autoRefreshInterval);
                }
            } else {
                autoRefreshInterval = setInterval(() => {
                    refreshData().catch(error => {
                        console.error('Auto-refresh failed:', error);
                    });
                }, 3000);
            }
        });

        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        // Close modal when clicking on overlay
        document.getElementById('modal-overlay')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    }

    // Start the application
    initializeApp();
  </script>
  <script src="./assets/js/all.min.js"></script>
</body>
</html>