# EpSu-kortti (demo)

Clickable Finnish SaaS demo: clubs sell digital membership cards; members buy on a phone.

```bash
cd jasenkortti
npm install
npm run dev
```

Open http://127.0.0.1:3000

- `/` platform landing
- `/seura` club admin (seeded Esimerkkiseura + Jäsenyys 2026)
- `/osta/jasen-2026` member checkout
- `/kortti/<token>` personal card + QR

Data lives in `localStorage` in this browser (no real payment or email).
