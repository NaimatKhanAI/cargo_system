# Cargo System Setup Guide

## STEP 1 - XAMPP start karo

XAMPP Control Panel open karo, phir start karo:

- Apache
- MySQL

Dono services green honi chahiye.

## STEP 2 - Project folder place karo

Project folder name:

`cargo_system`

Is folder ko paste karo:

`C:\xampp\htdocs\`

Final path ye honi chahiye:

`C:\xampp\htdocs\cargo_system`

## STEP 3 - Database auto create (no manual work)

Is project me auto DB create hai.
Aapko database manually banane ki zarurat nahi hai.

## STEP 4 - Project open karo

Browser me open karo:

`http://localhost/cargo_system`

## LOGIN

- username: `admin`
- password: `1234`

## PDF Generation Setup (Dompdf)

Agar ye error aaye:

`Failed opening required 'dompdf/autoload.inc.php'`

To project root se ye command run karo:

```powershell
cd dompdf
C:\xampp\php\php.exe C:\xampp\php\composer.phar install --no-dev --ignore-platform-req=ext-gd --ignore-platform-req=ext-zip
```

Phir ye file exist karni chahiye:

`dompdf/vendor/autoload.php`

## Live Access with ngrok

If Apache runs on port 80:

```powershell
ngrok http 80
```

Example live URL:

`https://32e4-160-30-109-81.ngrok-free.app/cargo_system/index.php`
