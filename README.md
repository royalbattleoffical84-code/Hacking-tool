# SHIVAM BOT EXPLORER

A local website panel for exploring messages and downloading documents/photos that a Telegram store bot sends to **your own Telegram account**.

## Important limits
- A bot username does not grant access to its server, database, admin panel, or hidden product files.
- The panel can only read messages/media visible to the Telegram account you sign in with.
- Some store bots require menu-button interactions inside Telegram. This starter panel sends `/start` and reads recent messages; it does not bypass paywalls, access controls, or private product permissions.
- Use only with bots and content you are authorized to access. Respect the seller's terms and copyright.

## Setup (Windows / macOS / Linux)
1. Install Python 3.10 or newer.
2. Create Telegram API credentials at https://my.telegram.org (API development tools).
3. Copy `.env.example` to `.env` and enter your `TG_API_ID` and `TG_API_HASH`.
4. Install dependencies:
   ```bash
   python -m venv .venv
   # Windows: .venv\Scripts\activate
   # macOS/Linux: source .venv/bin/activate
   pip install -r requirements.txt
   ```
5. Set the environment variables from `.env` in your terminal, then run:
   ```bash
   uvicorn app:app --host 127.0.0.1 --port 8000
   ```
6. Open http://127.0.0.1:8000

## Loading .env
This starter keeps dependencies small and does not auto-load `.env`. Easiest: set the two environment variables in your terminal before starting Uvicorn.
- PowerShell:
  ```powershell
  $env:TG_API_ID="123456"
  $env:TG_API_HASH="your_api_hash"
  uvicorn app:app --host 127.0.0.1 --port 8000
  ```
- macOS/Linux:
  ```bash
  export TG_API_ID="123456"
  export TG_API_HASH="your_api_hash"
  uvicorn app:app --host 127.0.0.1 --port 8000
  ```

## Security
- Keep this panel on `127.0.0.1`; do not expose it publicly. It has no web-login protection.
- Never share the `.session` file, API hash, login code, or 2FA password.
- Log out before deleting the project/session. If you suspect session exposure, terminate active Telegram sessions from Telegram Settings > Devices.
- Downloads are saved in `downloads/`.
