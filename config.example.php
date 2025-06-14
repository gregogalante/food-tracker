<?php 

// Application constants
define('DATA_FOLDER', __DIR__ . '/data');
define('AUTH_CODE', '123456'); // Authentication code (in a real application should be stored securely)
define('COOKIE_NAME', 'foodtracker_auth');
define('COOKIE_DURATION', 60 * 60 * 24 * 30); // 30 days in seconds
define('OPENAI_API_KEY', ''); // Replace with your API key

// Feedback locale
define('FEEDBACK_LOCALE', 'EN');

// Daily nutritional targets
define('DAILY_CALORIES_TARGET', 0);
define('DAILY_CARBS_TARGET', 0); // grams
define('DAILY_PROTEINS_TARGET', 0); // grams
define('DAILY_FATS_TARGET', 0); // grams
