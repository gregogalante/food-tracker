<?php 

// Costanti dell'applicazione
define('DATA_FOLDER', __DIR__ . '/data');
define('AUTH_CODE', '123456'); // Codice di autenticazione (in un'applicazione reale dovrebbe essere salvato in modo sicuro)
define('COOKIE_NAME', 'foodtracker_auth');
define('COOKIE_DURATION', 60 * 60 * 24 * 30); // 30 giorni in secondi
define('OPENAI_API_KEY', ''); // Sostituisci con la tua API key

// Target nutrizionali giornalieri
define('DAILY_CALORIES_TARGET', 0);
define('DAILY_CARBS_TARGET', 0); // grammi
define('DAILY_PROTEINS_TARGET', 0); // grammi
define('DAILY_FATS_TARGET', 0); // grammi
