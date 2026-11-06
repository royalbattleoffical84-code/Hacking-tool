# ORVIX PAY v3 — upgrade notes

**v3 me naya:** ZapUPI-style theme + grouped menu, Popups/Ads (Admin → Popups & Ticker), Mystery Box (Admin → Mystery Box, default OFF), Pay by Link, Static Paylink, Activity Logs, Wallet History, Gemini AI preset. Upgrade same tarah: config.php overwrite mat karo, baaki files replace; DB auto-upgrade.
**v3.1:** Admin panel ka apna sidebar menu; Pay by Link & Static Paylink me payment ke baad buyer ko link / message / file (max 5 MB) milti hai. Files `private_files/` me rehti hain (direct URL band, sirf payment ke baad download.php se).
**v3.6 (PayX style):** Merchant apna Gmail + Fam UPI ID + App Password connect karta hai (Connect page). Order par merchant ka apna UPI ID aata hai, customer pay karke UTR dalta hai, Gmail me Fam ki mail se verify hota hai (sender+DKIM/SPF, UTR, exact amount, time, ek UTR ek hi baar). Is mode me platform fee/wallet nahi lagta. Admin → Wallet & Rates me 'Unlock fee' (0 = free). Merchant 'Automatic (no UTR)' option bhi chun sakta hai. **InfinityFree outgoing IMAP (port 993) block kar sakti hai.**
**Recharge Portal abhi "Coming soon" hai.** Mystery Box ke wallet rewards tumhare budget se jaate hain — chhote amounts rakho.

## Purane (v1) install ko upgrade karna
1. **config.php ko overwrite MAT karo** (usme tumhari DB details + FamApi key hain). Is zip me config.php diya hi nahi hai.
2. Baaki saari files InfinityFree `htdocs/` me upload karke purani files replace kar do.
3. Site kholo — **database apne aap upgrade ho jata hai** (naye tables/columns, 3 default plans). Purana data safe rehta hai.
4. Admin -> **Overview** me "Database upgrade notes" dikhe to mujhe bhej do.

## Pehle ye set karo (Admin Panel)
- **Wallet & Rates**: fee %, minimum deposit, deposit bonus, referral %, Free plan monthly orders.
- **Plans**: Starter / Pro / Pro Yearly default hain — price, days, fee %, orders/month apne hisaab se badlo.
- **AI Assistant**: endpoint + model + key. Default Google Gemini (aistudio.google.com se key). Admin → AI Assistant me 'Use Google Gemini preset' button hai.
- **Connect Merchant** (admin account se): tumhara FamPay jo connect hoga, usi me merchants ke API Wallet deposits aayenge.

## Test checklist
1. Naya merchant register -> sirf Connect Merchant khulna chahiye (baaki menu 🔒).
2. Connect karo -> API Token live. Bina connect token se create-order => `NOT_LIVE`.
3. Admin se merchant ko wallet credit do -> Test Payment -> Cashier page pe UPI ID text nahi dikhna chahiye (sirf QR / Pay Now).
4. Pay karo -> "Payment Successful" apne aap; wallet se fee kati (sirf ek baar).
5. Wallet -> Deposit -> Plans -> Subscribe -> Themes me premium themes unlock.
6. Admin -> Orders -> manual Success / Fail.
7. AI Assistant se "PHP integration" pucho.
