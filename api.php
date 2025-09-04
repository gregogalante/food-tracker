<?php
// Application configuration
header('Content-Type: application/json');

// Import configuration
require './config.php';

// Make sure the data folder exists
if (!file_exists(DATA_FOLDER)) {
    mkdir(DATA_FOLDER, 0777, true);
}

// Get action from query string
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Check if user is authenticated (except for authentication actions)
if ($action !== 'authenticate' && $action !== 'check-auth') {
    if (!isAuthenticated()) {
        respond(false, 'Not authenticated', 401);
    }
}

// Action routing
switch ($action) {
    case 'authenticate':
        handleAuthentication();
        break;
    case 'check-auth':
        handleCheckAuth();
        break;
    case 'date':
        handleGetDate();
        break;
    case 'month':
        handleGetMonth();
        break;
    case 'record-create':
        handleCreateRecord();
        break;
    case 'record-update':
        handleUpdateRecord();
        break;
    case 'record-delete':
        handleDeleteRecord();
        break;
    case 'get-config':
        handleGetConfig();
        break;
    default:
        respond(false, 'Invalid action');
}

// Function to verify authentication
function isAuthenticated() {
    return isset($_COOKIE[COOKIE_NAME]) && $_COOKIE[COOKIE_NAME] === hash('sha256', AUTH_CODE);
}

// Function to set authentication cookie
function setAuthCookie() {
    $hash = hash('sha256', AUTH_CODE);
    setcookie(COOKIE_NAME, $hash, time() + COOKIE_DURATION, '/');
}

// Function to respond with JSON
function respond($success, $message = '', $status = 200, $data = null) {
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

// Function to get request body
function getRequestBody() {
    return json_decode(file_get_contents('php://input'), true);
}

// Function to generate UUID
function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// Function to get daily file path
function getDayFilePath($date) {
    return DATA_FOLDER . '/day_' . $date . '.json';
}

// Function to get monthly file path
function getMonthFilePath($month) {
    return DATA_FOLDER . '/month_' . $month . '.json';
}

// Function to read data from a file
function readFileData($filePath) {
    if (!file_exists($filePath)) {
        return null;
    }
    
    $data = file_get_contents($filePath);
    return json_decode($data, true);
}

// Function to write data to a file
function writeFileData($filePath, $data) {
    file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT));
}

// Function to update monthly file
function updateMonthFile($date, $dayData) {
    $month = substr($date, 0, 7); // YYYY-MM
    $monthFilePath = getMonthFilePath($month);
    
    // Load existing monthly data or initialize a new array
    $monthData = readFileData($monthFilePath) ?: ['days' => []];
    
    // Calculate day totals
    $dayTotals = [
        'date' => $date,
        'calories' => 0,
        'carbs' => 0,
        'proteins' => 0,
        'fats' => 0
    ];
    
    if (isset($dayData['records']) && is_array($dayData['records'])) {
        foreach ($dayData['records'] as $record) {
            $dayTotals['calories'] += floatval($record['calories']);
            $dayTotals['carbs'] += floatval($record['gram_carbs']);
            $dayTotals['proteins'] += floatval($record['gram_proteins']);
            $dayTotals['fats'] += floatval($record['gram_fats']);
        }
    }
    
    // Update or add the element in the monthly file
    $dayIndex = -1;
    foreach ($monthData['days'] as $index => $day) {
        if ($day['date'] === $date) {
            $dayIndex = $index;
            break;
        }
    }
    
    if ($dayIndex >= 0) {
        $monthData['days'][$dayIndex] = $dayTotals;
    } else {
        $monthData['days'][] = $dayTotals;
    }
    
    // Sort days by date
    usort($monthData['days'], function($a, $b) {
        return strcmp($a['date'], $b['date']);
    });
    
    // Save monthly data
    writeFileData($monthFilePath, $monthData);
}

// Function to call OpenAI API
function callOpenAI($input) {
    // Define cache folder
    define('CACHE_FOLDER', DATA_FOLDER . '/cache-llm');
    
    // Make sure the cache directory exists
    if (!file_exists(CACHE_FOLDER)) {
        mkdir(CACHE_FOLDER, 0777, true);
    }
    
    // Generate input hash to use as cache key
    $inputHash = md5($input);
    $cacheFilePath = CACHE_FOLDER . '/' . $inputHash . '.json';
    
    // Check if we already have the response in cache
    if (file_exists($cacheFilePath)) {
        $cachedData = readFileData($cacheFilePath);
        if ($cachedData) {
            // Response found in cache
            return $cachedData;
        }
    }
    
    if (empty(OPENAI_API_KEY) || OPENAI_API_KEY === 'your-api-key-here') {
        // If API key is not configured, return simulated data for testing
        $result = [
            'calories' => rand(100, 800),
            'gram_carbs' => rand(10, 100),
            'gram_proteins' => rand(5, 50),
            'gram_fats' => rand(3, 30),
            'quantity' => rand(1, 5),
            'star_rating' => rand(1, 5),
            'feedback' => 'This is a simulated feedback. Configure your OpenAI API key for real data.'
        ];
        
        // Save simulation to cache
        writeFileData($cacheFilePath, $result);
        return $result;
    }
    
    // Prepare data to send to API
    $data = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            [
                'role' => 'system',
                'content' => "You are an expert nutritionist. Analyze the user's input and provide an estimate of calories and macronutrients for the described foods. Also provide a brief nutritional feedback and a star rating of the meal's healthiness (1 - low, 5 - high). Multiply the calories and macronutrients for the quantity defined by the user; the default quantity is 1. Write the feedback using the language ".FEEDBACK_LOCALE.". Respond ONLY with a JSON in the following format: {\"calories\": number, \"gram_carbs\": number, \"gram_proteins\": number, \"gram_fats\": number, \"feedback\": \"text\", \"quantity\": number, \"star_rating\": number}."
            ],
            [
                'role' => 'user',
                'content' => $input
            ]
        ],
        'temperature' => 0.25
    ];
    
    // Prepare curl request
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY
    ]);
    
    // Execute request
    $response = curl_exec($ch);
    
    // Check errors
    if (curl_errno($ch)) {
        error_log('cURL Error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }
    
    curl_close($ch);
    
    // Decode response
    $responseData = json_decode($response, true);
    
    if (!isset($responseData['choices'][0]['message']['content'])) {
        error_log('Invalid OpenAI response: ' . print_r($responseData, true));
        return false;
    }
    
    // Extract and decode JSON from response
    $content = $responseData['choices'][0]['message']['content'];
    
    // Sometimes API might include backticks in format ```json ... ``` 
    $content = preg_replace('/```json\s*(.*?)\s*```/s', '$1', $content);
    $content = preg_replace('/```\s*(.*?)\s*```/s', '$1', $content);
    
    $data = json_decode($content, true);
    
    if (!$data) {
        error_log('Unable to parse JSON response: ' . $content);
        return false;
    }
    
    // Salva la risposta nella cache
    writeFileData($cacheFilePath, $data);
    
    return $data;
}

// Handler for authentication
function handleAuthentication() {
    $body = getRequestBody();
    
    if (!isset($body['code'])) {
        respond(false, 'Missing code');
    }
    
    if ($body['code'] === AUTH_CODE) {
        setAuthCookie();
        respond(true, 'Authentication successful');
    } else {
        respond(false, 'Invalid code');
    }
}

// Handler to download configurations
function handleGetConfig() {
    $config = [
        'daily_calories_target' => DAILY_CALORIES_TARGET,
        'daily_carbs_target' => DAILY_CARBS_TARGET,
        'daily_proteins_target' => DAILY_PROTEINS_TARGET,
        'daily_fats_target' => DAILY_FATS_TARGET
    ];
    
    respond(true, '', 200, $config);
}

// Handler to verify authentication
function handleCheckAuth() {
    respond(isAuthenticated(), isAuthenticated() ? 'Authenticated' : 'Not authenticated');
}

// Handler to get day data
function handleGetDate() {
    if (!isset($_GET['date'])) {
        respond(false, 'Missing date');
    }
    
    $date = $_GET['date'];
    
    // Verify date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        respond(false, 'Invalid date format (required YYYY-MM-DD)');
    }
    
    $filePath = getDayFilePath($date);
    $data = readFileData($filePath);
    
    if ($data === null) {
        // No record for this date
        $data = [
            'date' => $date,
            'records' => []
        ];
    }
    
    respond(true, '', 200, $data);
}

// Handler to get month data
function handleGetMonth() {
    if (!isset($_GET['month'])) {
        respond(false, 'Missing month');
    }
    
    $month = $_GET['month'];
    
    // Verify month format
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        respond(false, 'Invalid month format (required YYYY-MM)');
    }
    
    $filePath = getMonthFilePath($month);
    $data = readFileData($filePath);

    if ($data === null) {
        respond(true, '', 200, []);
    } else {
        respond(true, '', 200, $data['days']);
    }
}

// Handler to create a new record
function handleCreateRecord() {
    $body = getRequestBody();
    
    if (!isset($body['input'])) {
        respond(false, 'Missing input');
    }
    
    $date = isset($body['date']) ? $body['date'] : date('Y-m-d');
    
    // Verify date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        respond(false, 'Invalid date format (required YYYY-MM-DD)');
    }
    
    // Get day data or initialize
    $filePath = getDayFilePath($date);
    $dayData = readFileData($filePath);
    
    if ($dayData === null) {
        $dayData = [
            'date' => $date,
            'records' => []
        ];
    }
    
    // Normalizza l'input: trim e downcase
    $body['input'] = strtolower(trim($body['input']));
    // Call OpenAI to get nutritional data
    $nutritionData = callOpenAI($body['input']);
    
    if ($nutritionData === false) {
        respond(false, 'Error analyzing input');
    }
    
    // Crea il nuovo record
    $record = [
        'uuid' => generateUUID(),
        'timestamp' => time(),
        'input' => $body['input'],
        'calories' => $nutritionData['calories'],
        'gram_carbs' => $nutritionData['gram_carbs'],
        'gram_proteins' => $nutritionData['gram_proteins'],
        'gram_fats' => $nutritionData['gram_fats'],
        'feedback' => $nutritionData['feedback'],
        'quantity' => $nutritionData['quantity'],
        'star_rating' => isset($nutritionData['star_rating']) ? $nutritionData['star_rating'] : null
    ];
    
    // Aggiungi il record ai dati del giorno
    $dayData['records'][] = $record;
    
    // Ordina i record per timestamp (decrescente)
    usort($dayData['records'], function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    // Save day data
    writeFileData($filePath, $dayData);
    
    // Update monthly file
    updateMonthFile($date, $dayData);
    
    respond(true, 'Record created successfully', 200, $record);
}

// Handler to update an existing record
function handleUpdateRecord() {
    if (!isset($_GET['date']) || !isset($_GET['uuid'])) {
        respond(false, 'Missing date or UUID');
    }
    
    $date = $_GET['date'];
    $uuid = $_GET['uuid'];
    $body = getRequestBody();
    
    // Verify date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        respond(false, 'Invalid date format (required YYYY-MM-DD)');
    }
    
    // Load day data
    $filePath = getDayFilePath($date);
    $dayData = readFileData($filePath);
    
    if ($dayData === null) {
        respond(false, 'No data found for this date');
    }
    
    // Find record to update
    $recordIndex = -1;
    foreach ($dayData['records'] as $index => $record) {
        if ($record['uuid'] === $uuid) {
            $recordIndex = $index;
            break;
        }
    }
    
    if ($recordIndex === -1) {
        respond(false, 'Record not found');
    }
    
    // Normalizza l'input se presente: trim e downcase
    $record = $dayData['records'][$recordIndex];

    if (isset($body['input'])) {
        $record['input'] = strtolower(trim($body['input']));
    }
    if (isset($body['calories'])) {
        $record['calories'] = $body['calories'];
    }
    if (isset($body['gram_carbs'])) {
        $record['gram_carbs'] = $body['gram_carbs'];
    }
    if (isset($body['gram_proteins'])) {
        $record['gram_proteins'] = $body['gram_proteins'];
    }
    if (isset($body['gram_fats'])) {
        $record['gram_fats'] = $body['gram_fats'];
    }
    if (isset($body['feedback'])) {
        $record['feedback'] = $body['feedback'];
    }
    if (isset($body['quantity'])) {
        $record['quantity'] = $body['quantity'];
    }

    // Aggiorna il record
    $dayData['records'][$recordIndex] = $record;
    
    // Save day data
    writeFileData($filePath, $dayData);
    
    // Update monthly file
    updateMonthFile($date, $dayData);
    
    respond(true, 'Record updated successfully', 200, $record);
}

// Handler to delete an existing record
function handleDeleteRecord() {
    if (!isset($_GET['date']) || !isset($_GET['uuid'])) {
        respond(false, 'Missing date or UUID');
    }
    
    $date = $_GET['date'];
    $uuid = $_GET['uuid'];
    
    // Verify date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        respond(false, 'Invalid date format (required YYYY-MM-DD)');
    }
    
    // Load day data
    $filePath = getDayFilePath($date);
    $dayData = readFileData($filePath);
    
    if ($dayData === null) {
        respond(false, 'No data found for this date');
    }
    
    // Trova e rimuovi il record
    $found = false;
    $newRecords = [];
    foreach ($dayData['records'] as $record) {
        if ($record['uuid'] === $uuid) {
            $found = true;
        } else {
            $newRecords[] = $record;
        }
    }
    
    if (!$found) {
        respond(false, 'Record not found');
    }
    
    // Update records
    $dayData['records'] = $newRecords;
    
    // Save day data
    writeFileData($filePath, $dayData);
    
    // Update monthly file
    updateMonthFile($date, $dayData);
    
    respond(true, 'Record deleted successfully');
}
?>
