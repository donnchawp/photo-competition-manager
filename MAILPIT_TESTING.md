# Mailpit Email Testing Guide

This guide explains how to test email functionality in the Club Competitions plugin using Mailpit.

---

## What is Mailpit?

Mailpit is a lightweight SMTP server designed for development and testing. It captures all emails sent by your application and provides a web interface to view them, without actually delivering emails to real recipients.

**Benefits:**
- ✅ No emails sent to real addresses during development
- ✅ View all sent emails in a web interface
- ✅ Test email templates and content
- ✅ Inspect email headers and attachments
- ✅ No external SMTP service required

---

## Setup

### 1. Start the Environment

The Mailpit container is configured in `docker-compose.override.yml` and will start automatically with wp-env:

```bash
npx @wordpress/env start
```

This starts:
- WordPress at http://localhost:8888
- Mailpit Web UI at http://localhost:8025
- Mailpit SMTP server at localhost:1025

### 2. Verify Mailpit is Running

```bash
docker ps | grep mailpit
```

You should see:
```
club-competitions-mailpit   axllent/mailpit:latest   ...   0.0.0.0:8025->8025/tcp, 0.0.0.0:1025->1025/tcp
```

### 3. Access the Mailpit Web Interface

Open http://localhost:8025 in your browser. You should see the Mailpit inbox (empty initially).

---

## Testing Email Functionality

### Test 1: Basic WordPress Email

Test that WordPress can send emails through Mailpit:

```bash
npx @wordpress/env run cli wp shell
```

Then in the WP-CLI shell:

```php
wp_mail('test@example.com', 'Test Email', 'This is a test email from WordPress.');
exit;
```

**Expected Result:**
- Check http://localhost:8025
- You should see the test email in the inbox
- Click to view full email details

### Test 2: Upload Token Request

1. **Navigate to the upload page** in WordPress:
   - Go to a page with the `[competition_upload competition="your-slug"]` shortcode

2. **Request an upload link**:
   - Enter a valid member email address
   - Click "Send Upload Link"

3. **Check Mailpit**:
   - Open http://localhost:8025
   - You should see an email with subject: "Upload your images for [Competition Name]"
   - Click to view the email

4. **Verify email content**:
   - ✅ Professional HTML formatting
   - ✅ Member name displayed correctly
   - ✅ Competition title shown
   - ✅ Magic link present (clickable)
   - ✅ Expiration notice (1 hour)
   - ✅ Security message about one-time use

5. **Copy the magic link**:
   - Click the "Upload Images" button in the email OR
   - Copy the URL from the href attribute
   - Paste into browser to test authentication flow

### Test 3: Email Enumeration Protection

Test that the system doesn't reveal whether emails exist:

1. **Test with invalid email**:
   ```
   Enter: nonexistent@example.com
   Click: Send Upload Link
   ```
   **Expected**: Generic success message
   **Check Mailpit**: No email sent ✅

2. **Test with valid email**:
   ```
   Enter: valid-member@example.com
   Click: Send Upload Link
   ```
   **Expected**: Same generic success message
   **Check Mailpit**: Email appears in inbox ✅

**Important**: The response should be identical in both cases to prevent email enumeration.

### Test 4: Rate Limiting

Test that users can't spam token requests:

1. Request upload link for a member
2. Immediately request again (within 5 minutes)
3. **Expected**: Generic success message, but only ONE email in Mailpit
4. Wait 5+ minutes
5. Request again
6. **Expected**: New email appears in Mailpit

### Test 5: Token Expiration

Test that expired tokens show appropriate messages:

1. Request upload link
2. Get token from Mailpit email
3. **Option A - Wait 1 hour**: Token will naturally expire
4. **Option B - Manual expiry**: Update database to expire immediately:
   ```sql
   UPDATE wp_clubcompete_upload_tokens
   SET expires_at = DATE_SUB(NOW(), INTERVAL 2 HOUR)
   WHERE id = [token_id];
   ```
5. Click expired token link
6. **Expected**: Error message about expired token

### Test 6: Single-Use Tokens

Test that tokens can only be used once:

1. Request upload link
2. Get token from Mailpit
3. Click magic link → Upload form appears
4. Upload an image successfully
5. Try to use the same token link again
6. **Expected**: Error message (token already used)

---

## Mailpit Web Interface Features

### Inbox View
- **Search**: Find emails by subject, recipient, or content
- **Filter**: Show only specific emails
- **Delete**: Clear test emails
- **View Source**: See raw email HTML/text

### Email Details View
- **HTML tab**: Rendered email appearance
- **Plain text tab**: Text version of email
- **Source tab**: Raw email source
- **Headers tab**: Email headers (From, To, Subject, etc.)
- **MIME parts**: View attachments and parts

### Useful Features
- **Auto-refresh**: Inbox updates automatically
- **Download**: Save email as .eml file
- **Forward**: Forward to real email (disabled in testing)
- **Delete all**: Clear inbox quickly

---

## Troubleshooting

### Emails Not Appearing in Mailpit

**Check WordPress SMTP configuration**:
```bash
npx @wordpress/env run cli wp shell
```

```php
// Check if SMTP constants are defined
var_dump(defined('SMTP_HOST'));
var_dump(SMTP_HOST);
exit;
```

**Expected output**:
```
bool(true)
string(7) "mailpit"
```

**Check PHPMailer configuration**:
```php
add_action('phpmailer_init', function($phpmailer) {
    error_log('PHPMailer Host: ' . $phpmailer->Host);
    error_log('PHPMailer Port: ' . $phpmailer->Port);
});
```

### Mailpit Container Not Starting

**Check if port 8025 or 1025 are in use**:
```bash
lsof -i :8025
lsof -i :1025
```

**View Mailpit logs**:
```bash
docker logs club-competitions-mailpit
```

**Restart Mailpit**:
```bash
docker restart club-competitions-mailpit
```

### WordPress Not Sending Emails

**Check wp-mail.log** (if WP_DEBUG_LOG enabled):
```bash
npx @wordpress/env run cli tail -f /var/www/html/wp-content/debug.log
```

**Test with WP-CLI**:
```bash
npx @wordpress/env run cli wp shell
```

```php
$result = wp_mail('test@test.com', 'Debug Test', 'Testing email delivery');
var_dump($result); // Should be true
exit;
```

### Network Issues

**Check Docker network**:
```bash
docker network inspect wpenv
```

Verify that both `wordpress` and `mailpit` containers are on the same network.

**Test SMTP connection from WordPress container**:
```bash
npx @wordpress/env run cli bash
```

```bash
# Inside container
telnet mailpit 1025
# Should connect successfully
# Type QUIT to exit
```

---

## Automated Testing with Mailpit

### PHPUnit Integration

You can test email functionality in automated tests:

```php
/**
 * Test upload token email is sent.
 */
public function test_upload_token_email_sent() {
    // Clear Mailpit inbox via API
    $this->clear_mailpit_inbox();

    // Trigger token request
    $_POST['member_email'] = 'test@example.com';
    $_POST['club_competitions_request_token'] = '1';
    $_POST['club_competitions_nonce'] = wp_create_nonce('club_competitions_request_token');

    $shortcode = new UploadShortcode();
    $shortcode->render(['competition' => 'test-competition']);

    // Check Mailpit received email
    $messages = $this->get_mailpit_messages();
    $this->assertCount(1, $messages);
    $this->assertStringContainsString('Upload your images', $messages[0]['Subject']);
}

/**
 * Clear Mailpit inbox.
 */
private function clear_mailpit_inbox() {
    wp_remote_request('http://mailpit:8025/api/v1/messages', [
        'method' => 'DELETE'
    ]);
}

/**
 * Get messages from Mailpit.
 */
private function get_mailpit_messages() {
    $response = wp_remote_get('http://mailpit:8025/api/v1/messages');
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['messages'] ?? [];
}
```

### Mailpit API Endpoints

Mailpit provides a REST API for automated testing:

- **GET /api/v1/messages** - List all messages
- **GET /api/v1/message/{id}** - Get specific message
- **DELETE /api/v1/messages** - Delete all messages
- **DELETE /api/v1/message/{id}** - Delete specific message
- **GET /api/v1/search?query={term}** - Search messages

Example with curl:
```bash
# List all messages
curl http://localhost:8025/api/v1/messages

# Delete all messages
curl -X DELETE http://localhost:8025/api/v1/messages

# Search for messages
curl "http://localhost:8025/api/v1/search?query=upload"
```

---

## Best Practices

### 1. Clear Inbox Regularly
During development, clear the Mailpit inbox to avoid confusion:
- Click "Delete all" in Mailpit UI, OR
- Use API: `curl -X DELETE http://localhost:8025/api/v1/messages`

### 2. Test Different Scenarios
- Valid member emails
- Invalid member emails
- Expired tokens
- Used tokens
- Rate limiting
- HTML rendering in different email clients

### 3. Verify Email Content
Always check:
- Subject line is clear and professional
- Member name is correctly personalized
- Magic links are properly formatted
- Expiration time is displayed
- Site branding appears correctly
- No sensitive information exposed

### 4. Test Across Browsers
View the Mailpit web UI in different browsers to ensure compatibility.

### 5. Check Mobile Rendering
Use Mailpit's HTML preview to verify emails look good on mobile devices.

---

## Production Deployment

**Important**: Mailpit is for **development only**. In production:

1. **Disable MailpitSMTP**:
   The `MailpitSMTP::init()` only runs when `WP_DEBUG` is true, so it's automatically disabled in production.

2. **Use a real SMTP service**:
   - SendGrid
   - Mailgun
   - AWS SES
   - Postmark
   - Your hosting provider's SMTP

3. **Install SMTP plugin**:
   - WP Mail SMTP
   - Easy WP SMTP
   - Post SMTP

4. **Configure WordPress constants** (in production wp-config.php):
   ```php
   define('SMTP_HOST', 'smtp.sendgrid.net');
   define('SMTP_PORT', 587);
   define('SMTP_USER', 'apikey');
   define('SMTP_PASS', 'your-api-key');
   define('SMTP_FROM', 'notifications@yoursite.com');
   define('SMTP_NAME', 'Your Site Name');
   ```

---

## Quick Reference

| Service | URL | Purpose |
|---------|-----|---------|
| WordPress | http://localhost:8888 | Main application |
| Mailpit Web UI | http://localhost:8025 | View captured emails |
| Mailpit SMTP | localhost:1025 | SMTP server endpoint |
| WordPress Admin | http://localhost:8888/wp-admin | Admin dashboard |

### Useful Commands

```bash
# Start environment
npx @wordpress/env start

# Stop environment
npx @wordpress/env stop

# View Mailpit logs
docker logs -f club-competitions-mailpit

# Restart Mailpit
docker restart club-competitions-mailpit

# Access WP-CLI
npx @wordpress/env run cli wp shell

# Clear Mailpit inbox
curl -X DELETE http://localhost:8025/api/v1/messages
```

---

## Additional Resources

- [Mailpit Documentation](https://github.com/axllent/mailpit)
- [Mailpit API Reference](https://github.com/axllent/mailpit#api)
- [@wordpress/env Documentation](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)

---

*Last updated: 2025-01-XX*
