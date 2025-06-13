<?php
// Configurazione dell'applicazione
header('Content-Type: application/json');

// Import config
require './config.php';

// Assicurarsi che la cartella data esista
if (!file_exists(DATA_FOLDER)) {
    mkdir(DATA_FOLDER, 0777, true);
}

// Recupero dell'azione dalla query string
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Controllo se l'utente è autenticato (tranne per l'azione di autenticazione)
if ($action !== 'authenticate' && $action !== 'check-auth') {
    if (!isAuthenticated()) {
        respond(false, 'Non autenticato', 401);
    }
}

// Routing delle azioni
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
        respond(false, 'Azione non valida');
}

// Funzione per verificare l'autenticazione
function isAuthenticated() {
    return isset($_COOKIE[COOKIE_NAME]) && $_COOKIE[COOKIE_NAME] === hash('sha256', AUTH_CODE);
}

// Funzione per impostare il cookie di autenticazione
function setAuthCookie() {
    $hash = hash('sha256', AUTH_CODE);
    setcookie(COOKIE_NAME, $hash, time() + COOKIE_DURATION, '/');
}

// Funzione per rispondere con JSON
function respond($success, $message = '', $status = 200, $data = null) {
    http_response_code($status);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

// Funzione per ottenere il body della richiesta
function getRequestBody() {
    return json_decode(file_get_contents('php://input'), true);
}

// Funzione per generare un UUID
function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// Funzione per ottenere il percorso del file giornaliero
function getDayFilePath($date) {
    return DATA_FOLDER . '/day_' . $date . '.json';
}

// Funzione per ottenere il percorso del file mensile
function getMonthFilePath($month) {
    return DATA_FOLDER . '/month_' . $month . '.json';
}

// Funzione per leggere i dati di un file
function readFileData($filePath) {
    if (!file_exists($filePath)) {
        return null;
    }
    
    $data = file_get_contents($filePath);
    return json_decode($data, true);
}

// Funzione per scrivere i dati in un file
function writeFileData($filePath, $data) {
    file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT));
}

// Funzione per aggiornare il file mensile
function updateMonthFile($date, $dayData) {
    $month = substr($date, 0, 7); // YYYY-MM
    $monthFilePath = getMonthFilePath($month);
    
    // Carica i dati mensili esistenti o inizializza un nuovo array
    $monthData = readFileData($monthFilePath) ?: ['days' => []];
    
    // Calcola i totali del giorno
    $dayTotals = [
        'date' => $date,
        'calorie' => 0,
        'carboidrati' => 0,
        'proteine' => 0,
        'grassi' => 0
    ];
    
    if (isset($dayData['records']) && is_array($dayData['records'])) {
        foreach ($dayData['records'] as $record) {
            $dayTotals['calorie'] += floatval($record['calorie']);
            $dayTotals['carboidrati'] += floatval($record['grammi_carboidrati']);
            $dayTotals['proteine'] += floatval($record['grammi_proteine']);
            $dayTotals['grassi'] += floatval($record['grammi_grassi']);
        }
    }
    
    // Aggiorna o aggiungi l'elemento nel file mensile
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
    
    // Ordina i giorni per data
    usort($monthData['days'], function($a, $b) {
        return strcmp($a['date'], $b['date']);
    });
    
    // Salva i dati mensili
    writeFileData($monthFilePath, $monthData);
}

// Funzione per chiamare l'API OpenAI
function callOpenAI($input) {
    // Definisco la cartella di cache
    define('CACHE_FOLDER', DATA_FOLDER . '/cache-llm');
    
    // Assicuriamoci che la directory della cache esista
    if (!file_exists(CACHE_FOLDER)) {
        mkdir(CACHE_FOLDER, 0777, true);
    }
    
    // Genero un hash dell'input per usarlo come chiave nella cache
    $inputHash = md5($input);
    $cacheFilePath = CACHE_FOLDER . '/' . $inputHash . '.json';
    
    // Verifica se abbiamo già la risposta in cache
    if (file_exists($cacheFilePath)) {
        $cachedData = readFileData($cacheFilePath);
        if ($cachedData) {
            // Risposta trovata in cache
            return $cachedData;
        }
    }
    
    if (empty(OPENAI_API_KEY) || OPENAI_API_KEY === 'your-api-key-here') {
        // Se non è configurata l'API key, restituiamo dati simulati per test
        $result = [
            'calorie' => rand(100, 800),
            'grammi_carboidrati' => rand(10, 100),
            'grammi_proteine' => rand(5, 50),
            'grammi_grassi' => rand(3, 30),
            'feedback' => 'Questa è una simulazione di feedback. Configura la tua OpenAI API key per dati reali.'
        ];
        
        // Salva la simulazione in cache
        writeFileData($cacheFilePath, $result);
        return $result;
    }
    
    // Prepara i dati da inviare all'API
    $data = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            [
                'role' => 'system',
                'content' => "Sei un nutrizionista esperto. Analizza l'input dell'utente e fornisci una stima delle calorie e dei macronutrienti per gli alimenti descritti. Fornisci anche un breve feedback nutrizionale. Rispondi SOLO con un JSON nel seguente formato: {\"calorie\": numero, \"grammi_carboidrati\": numero, \"grammi_proteine\": numero, \"grammi_grassi\": numero, \"feedback\": \"testo\"}."
            ],
            [
                'role' => 'user',
                'content' => $input
            ]
        ],
        'temperature' => 0.7
    ];
    
    // Prepara la richiesta curl
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY
    ]);
    
    // Esegui la richiesta
    $response = curl_exec($ch);
    
    // Verifica errori
    if (curl_errno($ch)) {
        error_log('Errore cURL: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }
    
    curl_close($ch);
    
    // Decodifica la risposta
    $responseData = json_decode($response, true);
    
    if (!isset($responseData['choices'][0]['message']['content'])) {
        error_log('Risposta OpenAI non valida: ' . print_r($responseData, true));
        return false;
    }
    
    // Estrai e decodifica il JSON dalla risposta
    $content = $responseData['choices'][0]['message']['content'];
    
    // A volte l'API potrebbe includere backtick nel formato ```json ... ``` 
    $content = preg_replace('/```json\s*(.*?)\s*```/s', '$1', $content);
    $content = preg_replace('/```\s*(.*?)\s*```/s', '$1', $content);
    
    $data = json_decode($content, true);
    
    if (!$data) {
        error_log('Impossibile analizzare la risposta JSON: ' . $content);
        return false;
    }
    
    // Salva la risposta nella cache
    writeFileData($cacheFilePath, $data);
    
    return $data;
}

// Handler per l'autenticazione
function handleAuthentication() {
    $body = getRequestBody();
    
    if (!isset($body['code'])) {
        respond(false, 'Codice mancante');
    }
    
    if ($body['code'] === AUTH_CODE) {
        setAuthCookie();
        respond(true, 'Autenticazione riuscita');
    } else {
        respond(false, 'Codice non valido');
    }
}

// Handler per scaricare le configurazioni
function handleGetConfig() {
    $config = [
        'daily_calories_target' => DAILY_CALORIES_TARGET,
        'daily_carbs_target' => DAILY_CARBS_TARGET,
        'daily_proteins_target' => DAILY_PROTEINS_TARGET,
        'daily_fats_target' => DAILY_FATS_TARGET
    ];
    
    respond(true, '', 200, $config);
}

// Handler per verificare l'autenticazione
function handleCheckAuth() {
    respond(isAuthenticated(), isAuthenticated() ? 'Autenticato' : 'Non autenticato');
}

// Handler per ottenere i dati del giorno
function handleGetDate() {
    if (!isset($_GET['date'])) {
        respond(false, 'Data mancante');
    }
    
    $date = $_GET['date'];
    
    // Verifica formato data
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        respond(false, 'Formato data non valido (richiesto YYYY-MM-DD)');
    }
    
    $filePath = getDayFilePath($date);
    $data = readFileData($filePath);
    
    if ($data === null) {
        // Nessun record per questa data
        $data = [
            'date' => $date,
            'records' => []
        ];
    }
    
    respond(true, '', 200, $data);
}

// Handler per ottenere i dati del mese
function handleGetMonth() {
    if (!isset($_GET['month'])) {
        respond(false, 'Mese mancante');
    }
    
    $month = $_GET['month'];
    
    // Verifica formato mese
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        respond(false, 'Formato mese non valido (richiesto YYYY-MM)');
    }
    
    $filePath = getMonthFilePath($month);
    $data = readFileData($filePath);
    
    if ($data === null) {
        respond(true, '', 200, ['days' => []]);
    } else {
        respond(true, '', 200, $data['days']);
    }
}

// Handler per creare un nuovo record
function handleCreateRecord() {
    $body = getRequestBody();
    
    if (!isset($body['input'])) {
        respond(false, 'Input mancante');
    }
    
    $date = isset($body['date']) ? $body['date'] : date('Y-m-d');
    
    // Verifica formato data
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        respond(false, 'Formato data non valido (richiesto YYYY-MM-DD)');
    }
    
    // Ottieni i dati del giorno o inizializza
    $filePath = getDayFilePath($date);
    $dayData = readFileData($filePath);
    
    if ($dayData === null) {
        $dayData = [
            'date' => $date,
            'records' => []
        ];
    }
    
    // Chiama OpenAI per ottenere i dati nutrizionali
    $nutritionData = callOpenAI($body['input']);
    
    if ($nutritionData === false) {
        respond(false, 'Errore nell\'analisi dell\'input');
    }
    
    // Crea il nuovo record
    $record = [
        'uuid' => generateUUID(),
        'timestamp' => time(),
        'input' => $body['input'],
        'calorie' => $nutritionData['calorie'],
        'grammi_carboidrati' => $nutritionData['grammi_carboidrati'],
        'grammi_proteine' => $nutritionData['grammi_proteine'],
        'grammi_grassi' => $nutritionData['grammi_grassi'],
        'feedback' => $nutritionData['feedback']
    ];
    
    // Aggiungi il record ai dati del giorno
    $dayData['records'][] = $record;
    
    // Ordina i record per timestamp (decrescente)
    usort($dayData['records'], function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    // Salva i dati del giorno
    writeFileData($filePath, $dayData);
    
    // Aggiorna il file mensile
    updateMonthFile($date, $dayData);
    
    respond(true, 'Record creato con successo', 200, $record);
}

// Handler per aggiornare un record esistente
function handleUpdateRecord() {
    if (!isset($_GET['date']) || !isset($_GET['uuid'])) {
        respond(false, 'Data o UUID mancante');
    }
    
    $date = $_GET['date'];
    $uuid = $_GET['uuid'];
    $body = getRequestBody();
    
    // Verifica formato data
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        respond(false, 'Formato data non valido (richiesto YYYY-MM-DD)');
    }
    
    // Carica i dati del giorno
    $filePath = getDayFilePath($date);
    $dayData = readFileData($filePath);
    
    if ($dayData === null) {
        respond(false, 'Nessun dato trovato per questa data');
    }
    
    // Trova il record da aggiornare
    $recordIndex = -1;
    foreach ($dayData['records'] as $index => $record) {
        if ($record['uuid'] === $uuid) {
            $recordIndex = $index;
            break;
        }
    }
    
    if ($recordIndex === -1) {
        respond(false, 'Record non trovato');
    }
    
    // Aggiorna i campi modificabili
    $record = $dayData['records'][$recordIndex];
    
    if (isset($body['calorie'])) {
        $record['calorie'] = $body['calorie'];
    }
    
    if (isset($body['grammi_carboidrati'])) {
        $record['grammi_carboidrati'] = $body['grammi_carboidrati'];
    }
    
    if (isset($body['grammi_proteine'])) {
        $record['grammi_proteine'] = $body['grammi_proteine'];
    }
    
    if (isset($body['grammi_grassi'])) {
        $record['grammi_grassi'] = $body['grammi_grassi'];
    }
    
    if (isset($body['feedback'])) {
        $record['feedback'] = $body['feedback'];
    }
    
    // Aggiorna il record
    $dayData['records'][$recordIndex] = $record;
    
    // Salva i dati del giorno
    writeFileData($filePath, $dayData);
    
    // Aggiorna il file mensile
    updateMonthFile($date, $dayData);
    
    respond(true, 'Record aggiornato con successo', 200, $record);
}

// Handler per eliminare un record esistente
function handleDeleteRecord() {
    if (!isset($_GET['date']) || !isset($_GET['uuid'])) {
        respond(false, 'Data o UUID mancante');
    }
    
    $date = $_GET['date'];
    $uuid = $_GET['uuid'];
    
    // Verifica formato data
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        respond(false, 'Formato data non valido (richiesto YYYY-MM-DD)');
    }
    
    // Carica i dati del giorno
    $filePath = getDayFilePath($date);
    $dayData = readFileData($filePath);
    
    if ($dayData === null) {
        respond(false, 'Nessun dato trovato per questa data');
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
        respond(false, 'Record non trovato');
    }
    
    // Aggiorna i record
    $dayData['records'] = $newRecords;
    
    // Salva i dati del giorno
    writeFileData($filePath, $dayData);
    
    // Aggiorna il file mensile
    updateMonthFile($date, $dayData);
    
    respond(true, 'Record eliminato con successo');
}
?>
