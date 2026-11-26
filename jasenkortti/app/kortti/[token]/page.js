"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import QRCode from "qrcode";
import { cardUrl, formatFiDate, loadState } from "../../../lib/store";

export default function CardPage() {
  const params = useParams();
  const token = params.token;
  const [state, setState] = useState(null);
  const [qr, setQr] = useState("");
  const [copied, setCopied] = useState(false);
  const origin = typeof window !== "undefined" ? window.location.origin : "";

  useEffect(() => {
    setState(loadState());
  }, []);

  const member = state?.members.find((m) => m.token === token);
  const season = member && state.seasons.find((s) => s.id === member.seasonId);
  const link = origin && token ? cardUrl(origin, token) : "";

  useEffect(() => {
    if (!link) return;
    QRCode.toDataURL(link, { margin: 1, width: 220 }).then(setQr);
  }, [link]);

  if (!state) {
    return (
      <main className="wrap phone">
        <p className="muted">Ladataan…</p>
      </main>
    );
  }

  if (!member || !season) {
    return (
      <main className="wrap phone">
        <h1>Korttia ei löydy</h1>
        <p className="muted">
          Demo tallentaa kortit tähän selaimeen. Avaa sama ostolinkki ja maksa uudelleen.
        </p>
        <Link href="/seura">Seuran hallinta</Link>
      </main>
    );
  }

  return (
    <main className="wrap phone">
      <p className="kicker">Jäsenkortti</p>
      <article className="card">
        <div className="club">{state.club.name}</div>
        <h2>{member.name}</h2>
        <p className="meta">{season.name}</p>
        <p className="meta">
          Voimassa {formatFiDate(season.validFrom)}–{formatFiDate(season.validTo)}
        </p>
        <div className="qr">
          {qr ? <img src={qr} alt="Jäsenkortin QR-koodi" width={180} height={180} /> : null}
        </div>
      </article>
      <section className="panel" style={{ marginTop: 18 }}>
        <p>Korttilinkki (magic link -tyyli, ei sähköpostia demossa)</p>
        <p className="linkbox">{link}</p>
        <button
          className="btn primary"
          type="button"
          onClick={() => {
            setCopied(true);
            const done = () => setTimeout(() => setCopied(false), 1800);
            if (navigator.clipboard?.writeText) {
              navigator.clipboard.writeText(link).then(done).catch(done);
            } else {
              done();
            }
          }}
        >
          {copied ? "Kopioitu" : "Kopioi korttilinkki"}
        </button>
        <p className="muted" style={{ marginTop: 10 }}>
          Tallenna linkki. Sillä kortin voi avata myöhemmin tässä selaimessa.
        </p>
        <p>
          <Link href="/seura">Seuran ostajalista</Link>
        </p>
      </section>
    </main>
  );
}
