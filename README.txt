ORVIX PAY — AI ASSISTANT ONLY PATCH

Scope: only ai.php and ai-chat.php are changed. No payment, wallet, order,
merchant, login, admin, database schema, or Gmail files are included.

WHAT WAS FIXED
- The uploaded original ai-chat.php was still calling the Admin-panel
  ai_endpoint directly. It was NOT calling the Render /v1/ai-chat route.
- The replacement reads ORVIX_IMAP_API_URL and ORVIX_IMAP_API_KEY from the
  constants loaded by includes/bootstrap.php -> root config.php.
- It sends requests to Render /v1/ai-chat and lets Render's GEMINI_MODEL
  environment variable choose the model (so a stale Admin model value cannot
  override it).
- The frontend now shows the actual returned PHP/backend error instead of
  converting every failure into the generic Network problem message.

INSTALL
1. Back up the current ai.php and ai-chat.php from InfinityFree htdocs.
2. Upload these two files from this patch to the same htdocs locations,
   replacing only those two files.
3. Do not replace any other project file.
4. Ensure root config.php defines ORVIX_IMAP_API_URL and ORVIX_IMAP_API_KEY.
   The key must exactly match Render's ORVIX_IMAP_API_KEY.
5. Render Environment must contain GEMINI_API_KEY. GEMINI_MODEL is optional;
   if set, use a model ID enabled for your Google AI Studio API key.
6. The existing Render server.js must already include POST /v1/ai-chat and
   its existing auth middleware. No server.js replacement is included.
7. Test while logged into ORVIX PAY. If it fails, the chat will now show a
   more useful error; also check InfinityFree Error Logs and Render Logs.

SECURITY
Do not paste or commit config.php, database passwords, FamAPI keys, Gemini
keys, or the Render bridge secret. Rotate any credential previously exposed.
