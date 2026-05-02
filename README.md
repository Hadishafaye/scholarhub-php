# ScholarHub — PHP Edition

Scholarship discovery platform for Afghan and international students.  
Built for **Hostinger shared hosting** (Apache + PHP 8.1+, no Node.js required).

## Stack

| Layer    | Technology                          |
|----------|-------------------------------------|
| Frontend | Vanilla HTML / CSS / JavaScript     |
| Backend  | PHP 8.1+ (single router file)       |
| Database | Supabase (PostgreSQL via REST API)  |
| AI       | Groq API (llama-3.3-70b-versatile)  |
| Hosting  | Hostinger Shared Hosting (Apache)   |

---

## Deployment to Hostinger

### 1. Upload files

In **hPanel → File Manager** (or via FTP/SFTP):

Upload **all files** to your domain's `public_html` folder:

```
public_html/
├── .htaccess
├── index.html
├── admin.html
├── config.php          ← you create this (see step 2)
└── api/
    ├── .htaccess
    └── router.php
```

> Do **not** upload `config.example.php` or `README.md` to production.

---

### 2. Create `config.php`

Copy `config.example.php`, rename it to `config.php`, and fill in your real values:

```php
<?php
define('SUPABASE_URL',         'https://your-project-id.supabase.co');
define('SUPABASE_ANON_KEY',    'your-anon-key');
define('SUPABASE_SERVICE_KEY', 'your-service-role-key');
define('SESSION_SECRET',       'a-long-random-secret-string');
define('ADMIN_PASSWORD',       'your-admin-password');
define('GROQ_API_KEY',         'gsk_your-groq-key');
```

**Where to get these values:**
- **Supabase**: [supabase.com](https://supabase.com) → Your Project → Settings → API
- **Groq**: [console.groq.com/keys](https://console.groq.com/keys)
- **SESSION_SECRET**: any random string (32+ characters)
- **ADMIN_PASSWORD**: choose a strong password

---

### 3. Enable mod_rewrite on Hostinger

In **hPanel → Advanced → Apache .htaccess Editor** — confirm the `.htaccess` is accepted.  
Hostinger shared hosting has `mod_rewrite` enabled by default.

---

### 4. Test

Visit:
- `https://yourdomain.com/` → public scholarship browser
- `https://yourdomain.com/admin` → admin panel (enter your ADMIN_PASSWORD)
- `https://yourdomain.com/api/health` → should return `{"status":"ok"}`

---

## API Endpoints

| Method   | Path                       | Auth     | Description              |
|----------|----------------------------|----------|--------------------------|
| `GET`    | `/api/health`              | Public   | Health check             |
| `POST`   | `/api/auth/login`          | Public   | Admin login → JWT token  |
| `GET`    | `/api/scholarships`        | Public   | Published scholarships   |
| `GET`    | `/api/scholarships/all`    | Admin    | All scholarships         |
| `POST`   | `/api/scholarships`        | Admin    | Create scholarship       |
| `PATCH`  | `/api/scholarships/:id`    | Admin    | Update scholarship       |
| `DELETE` | `/api/scholarships/:id`    | Admin    | Delete scholarship       |
| `POST`   | `/api/ai/chat`             | Public   | Groq AI proxy            |
| `POST`   | `/api/ai/advisor`          | Public   | AI scholarship advisor   |

---

## Security Notes

- `config.php` is blocked from public access via `api/.htaccess`
- JWT tokens expire after 8 hours
- AI endpoints are rate-limited (20 requests/minute per IP)
- Admin routes require a valid JWT in the `Authorization: Bearer <token>` header

---

## Local Development

You can test locally with PHP's built-in server:

```bash
cd scholarhub-php
php -S localhost:8080
```

Then open `http://localhost:8080`.
