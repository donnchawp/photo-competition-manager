# Mailpit Email Testing - Quick Setup

## 🚀 Quick Start

Mailpit is now configured for email testing in development. Here's how to use it:

### 1. Start the Environment

```bash
npx @wordpress/env start
```

This automatically starts:
- ✅ WordPress (http://localhost:8888)
- ✅ Mailpit Web UI (http://localhost:8025)
- ✅ Mailpit SMTP Server (localhost:1025)

### 2. View Captured Emails

Open http://localhost:8025 in your browser to see all emails sent by WordPress.

### 3. Test It Works

**Quick test via WP-CLI:**
```bash
npx @wordpress/env run cli wp shell
```

Then run:
```php
wp_mail('test@example.com', 'Test Email', 'Hello from WordPress!');
exit;
```

**Check result:**
- Open http://localhost:8025
- You should see the test email

---

## 📧 Testing Upload Token Emails

### Test the Full Flow:

1. **Create a test member** in WordPress admin
2. **Add a competition** with upload shortcode
3. **Request upload link**:
   - Go to the upload page
   - Enter member email
   - Click "Send Upload Link"
4. **Check Mailpit** at http://localhost:8025
5. **Copy the magic link** from the email
6. **Test authentication** by clicking the link

---

## ⚙️ Configuration Files

The following files configure Mailpit:

1. **start-mailpit.sh** - Bash script to start Mailpit on wp-env network
2. **wp-content/mu-plugins/mailpit-smtp.php** - MU plugin that configures SMTP (dev only)

---

## 🔧 How It Works

1. **start-mailpit.sh** starts Mailpit container on the wp-env network
2. **MU plugin** configures WordPress to use Mailpit SMTP (only when WP_DEBUG=true)
3. **All emails** sent by WordPress are captured by Mailpit
4. **Web interface** lets you view/inspect emails
5. **No real emails** are delivered (perfect for testing!)

---

## 📚 Full Documentation

See [MAILPIT_TESTING.md](MAILPIT_TESTING.md) for:
- Detailed testing scenarios
- Troubleshooting guide
- API integration
- Automated testing examples

---

## ✅ Verify Setup

**Check Mailpit is running:**
```bash
docker ps | grep mailpit
```

**Check WordPress SMTP config:**
```bash
npx @wordpress/env run cli wp shell
```
```php
var_dump(defined('SMTP_HOST'));
var_dump(SMTP_HOST);
exit;
```

**Expected:** `bool(true)` and `string(7) "mailpit"`

---

## 🎯 Key Features

- ✅ **Zero config** - Works automatically with wp-env
- ✅ **Web UI** - View emails in browser
- ✅ **API access** - Automate testing
- ✅ **Dev only** - Auto-disabled in production (WP_DEBUG=false)
- ✅ **No cleanup** - Safe to test with any email addresses

---

## 🚨 Important

**Mailpit is for development only!**

In production, use a real SMTP service like:
- SendGrid
- Mailgun
- AWS SES
- Your hosting provider

The MailpitSMTP configuration only runs when `WP_DEBUG=true`, so it's automatically disabled in production.

---

**Quick Links:**
- Mailpit Web UI: http://localhost:8025
- WordPress: http://localhost:8888
- Full Guide: [MAILPIT_TESTING.md](MAILPIT_TESTING.md)
