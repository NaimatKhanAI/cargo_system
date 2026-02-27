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

## STEP 3 - Database setup

### Local (XAMPP)

- phpMyAdmin me `cargo_system` database bana lo, **ya**
- `.env` me `DB_AUTO_CREATE=1` rakh do (local only).

## STEP 4 - Project open karo

Browser me open karo:

`http://localhost/cargo_system`

## LOGIN

- Is app me login currently plain-text hai.
- Default admin auto-create tab hoga jab `.env` me ye set karo:
  - `SEED_ADMIN_USER=admin`
  - `SEED_ADMIN_PASS=your-password`
- First login ke baad ye values `.env` se hata do.

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

## Image Processing API Key (.env)

`process_img.php` feature use karne ke liye project root me `.env` file banao:

```powershell
cd C:\xampp\htdocs\cargo_system
copy .env.example .env
```

Phir `.env` me apni key set karo:

`OPENAI_API_KEY=sk-...`

## Hostinger Deploy (Business Hosting)

1. hPanel me MySQL DB + user create karo.
2. Project `public_html` me upload/extract karo.
3. Root me `.env` banao (`.env.example` copy karke) aur DB values set karo:
   - `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
   - `DB_AUTO_CREATE=0`
4. `output`, `output/source_images`, `output/import_logs` writable hon.
5. Agar OCR feature chahiye to `OPENAI_API_KEY` bhi set karo.

## Public Repo Note

- `.env` git me commit na karo.
- Real DB credentials sirf server `.env` me rakho.
