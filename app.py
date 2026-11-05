import os, re, asyncio, mimetypes
from pathlib import Path
from datetime import datetime
from fastapi import FastAPI, Request, Form, HTTPException
from fastapi.responses import HTMLResponse, RedirectResponse, FileResponse
from fastapi.templating import Jinja2Templates
from telethon import TelegramClient
from telethon.errors import SessionPasswordNeededError, PhoneCodeInvalidError

BASE = Path(__file__).resolve().parent
DOWNLOADS = BASE / "downloads"
DOWNLOADS.mkdir(exist_ok=True)
API_ID = os.getenv("TG_API_ID", "")
API_HASH = os.getenv("TG_API_HASH", "")
SESSION = str(BASE / "orvix_session")
client = TelegramClient(SESSION, int(API_ID) if API_ID.isdigit() else 0, API_HASH) if API_ID.isdigit() and API_HASH else None
app = FastAPI(title="ORVIX BOT EXPLORER")
templates = Jinja2Templates(directory=str(BASE / "templates"))
state = {"phone_code_hash": None, "phone": None, "authorized": False, "bot": None, "messages": [], "notice": "Add Telegram API credentials to .env, then sign in."}

def safe_bot(value):
    value = value.strip().lstrip("@")
    if not re.fullmatch(r"[A-Za-z0-9_]{5,32}", value):
        raise ValueError("Enter a valid public bot username, e.g. my_store_bot")
    return value

def ready():
    if client is None:
        raise HTTPException(400, "Telegram API credentials are missing. Configure TG_API_ID and TG_API_HASH in .env.")
    if not state["authorized"]:
        raise HTTPException(401, "Sign in to your Telegram account first.")

@app.on_event("startup")
async def startup():
    if client:
        await client.connect()
        state["authorized"] = await client.is_user_authorized()

@app.on_event("shutdown")
async def shutdown():
    if client and client.is_connected():
        await client.disconnect()

@app.get("/", response_class=HTMLResponse)
async def home(request: Request):
    return templates.TemplateResponse("index.html", {"request": request, "state": state, "files": sorted([p.name for p in DOWNLOADS.iterdir() if p.is_file()])})

@app.post("/send-code")
async def send_code(phone: str = Form(...)):
    if client is None:
        state["notice"] = "Missing API credentials. See README."
        return RedirectResponse("/", status_code=303)
    await client.connect()
    sent = await client.send_code_request(phone.strip())
    state["phone"] = phone.strip()
    state["phone_code_hash"] = sent.phone_code_hash
    state["notice"] = "Login code sent in Telegram. Enter it below."
    return RedirectResponse("/", status_code=303)

@app.post("/verify-code")
async def verify_code(code: str = Form(...), password: str = Form("")):
    if client is None or not state["phone_code_hash"]:
        state["notice"] = "Request a login code first."
        return RedirectResponse("/", status_code=303)
    try:
        await client.sign_in(phone=state["phone"], code=code.strip(), phone_code_hash=state["phone_code_hash"])
    except SessionPasswordNeededError:
        if not password:
            state["notice"] = "Two-step verification is enabled. Enter your Telegram password."
            return RedirectResponse("/", status_code=303)
        await client.sign_in(password=password)
    except PhoneCodeInvalidError:
        state["notice"] = "Invalid code. Request a fresh code and try again."
        return RedirectResponse("/", status_code=303)
    state["authorized"] = await client.is_user_authorized()
    state["notice"] = "Telegram account connected." if state["authorized"] else "Sign-in not completed."
    return RedirectResponse("/", status_code=303)

@app.post("/explore")
async def explore(bot_username: str = Form(...)):
    ready()
    try:
        bot = safe_bot(bot_username)
        entity = await client.get_entity(bot)
        if not getattr(entity, "bot", False):
            raise ValueError("That username does not appear to be a Telegram bot.")
        state["bot"] = bot
        await client.send_message(entity, "/start")
        await asyncio.sleep(2)
        msgs = []
        async for m in client.iter_messages(entity, limit=40, reverse=True):
            text = (m.message or "").strip()
            media = bool(m.media)
            if text or media:
                msgs.append({
                    "id": m.id, "date": m.date.strftime("%Y-%m-%d %H:%M") if m.date else "",
                    "text": text[:2500], "media": media,
                    "kind": type(m.media).__name__ if media else "",
                    "downloadable": bool(m.document or m.photo)
                })
        state["messages"] = msgs
        state["notice"] = f"Loaded {len(msgs)} recent messages. Use the Download buttons for files the bot sent to your account."
    except Exception as e:
        state["notice"] = f"Could not explore bot: {str(e)[:250]}"
    return RedirectResponse("/", status_code=303)

@app.post("/download/{message_id}")
async def download(message_id: int):
    ready()
    if not state["bot"]:
        raise HTTPException(400, "Explore a bot first.")
    entity = await client.get_entity(state["bot"])
    msg = await client.get_messages(entity, ids=message_id)
    if not msg or not (msg.document or msg.photo):
        raise HTTPException(404, "This message has no downloadable media.")
    path = await msg.download_media(file=str(DOWNLOADS))
    if not path:
        raise HTTPException(500, "Download failed.")
    return FileResponse(path, filename=Path(path).name, media_type=mimetypes.guess_type(path)[0] or "application/octet-stream")

@app.post("/logout")
async def logout():
    if client:
        await client.log_out()
        await client.connect()
    state.update({"authorized": False, "phone_code_hash": None, "phone": None, "bot": None, "messages": [], "notice": "Logged out."})
    return RedirectResponse("/", status_code=303)
