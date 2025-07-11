# Food Tracker

<img src="/icon.png" alt="Food Tracker Icon" width="100">

A simple **food tracker application** built with Vibe Coding.

This application allows users to track their food intake, manage their diet, and monitor their nutritional goals using OpenAI API to calculate nutritional values.

## Installation

1. Copy the folder content on a PHP server.

2. Rename the `config.example.php` file to `config.php` and fill in your configuration.

3. Update the `pwa/manifest.json` file with your application details (correct start URL etc.).

4. Enjoy your food tracker!

## Protect data

Here there is an example of .htaccess to avoid direct access to data stored on `data/` folder and return a 403 error:

```apache
Deny from all

Options -Indexes

<Files ".htaccess">
    Require all denied
</Files>

ErrorDocument 403 "Access to this resource is forbidden."
```
