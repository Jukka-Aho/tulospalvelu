"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import {
  addSeason,
  formatEur,
  formatFiDate,
  loadState,
  purchaseUrl,
} from "../../lib/store";

export default function ClubAdminPage() {
  const [state, setState] = useState(null);
  const [copied, setCopied] = useState("");
  const [form, setForm] = useState({
    name: "Jäsenyys 2027",
    price: "50",
    validFrom: "2027-01-01",
    validTo: "2027-12-31",
  });
  const origin = typeof window !== "undefined" ? window.location.origin : "";

  useEffect(() => {
    setState(loadState());
  }, []);

  const buyersBySeason = useMemo(() => {
    if (!state) return {};
    const map = {};
    for (const m of state.members) {
      map[m.seasonId] = map[m.seasonId] || [];
      map[m.seasonId].push(m);
    }
    return map;
  }, [state]);

  if (!state) {
    return (
      <main className="wrap">
        <p className="muted">Ladataan…</p>
      </main>
    );
  }

  function copy(text, id) {
    setCopied(id);
    const done = () => setTimeout(() => setCopied(""), 1800);
    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(done);
    } else {
      done();
    }
  }

  function onCreate(e) {
    e.preventDefault();
    const euros = Number(String(form.price).replace(",", "."));
    const { state: next } = addSeason(state, {
      name: form.name,
      priceCents: Math.round(euros * 100),
      validFrom: form.validFrom,
      validTo: form.validTo,
    });
    setState(next);
  }

  return (
    <main className="wrap">
      <div className="topbar">
        <div>
          <p className="kicker">Seuran hallinta</p>
          <h1>{state.club.name}</h1>
          <p className="muted">
            {state.club.sport} · {state.club.city}
          </p>
        </div>
        <Link href="/">Etusivu</Link>
      </div>

      <section className="panel">
        <h2>Uusi kausi</h2>
        <form className="row three" onSubmit={onCreate}>
          <label>
            Nimi
            <input
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              required
            />
          </label>
          <label>
            Hinta (€)
            <input
              value={form.price}
              onChange={(e) => setForm({ ...form, price: e.target.value })}
              required
            />
          </label>
          <label>
            Voimassa alkaen
            <input
              type="date"
              value={form.validFrom}
              onChange={(e) => setForm({ ...form, validFrom: e.target.value })}
              required
            />
          </label>
          <label>
            Voimassa asti
            <input
              type="date"
              value={form.validTo}
              onChange={(e) => setForm({ ...form, validTo: e.target.value })}
              required
            />
          </label>
          <div style={{ alignSelf: "end" }}>
            <button className="btn primary" type="submit">
              Luo kausi
            </button>
          </div>
        </form>
      </section>

      {state.seasons.map((season) => {
        const link = purchaseUrl(origin, season.id);
        const buyers = buyersBySeason[season.id] || [];
        return (
          <section className="panel" key={season.id}>
            <h2>{season.name}</h2>
            <p className="muted">
              {formatEur(season.priceCents)} · {formatFiDate(season.validFrom)}–
              {formatFiDate(season.validTo)}
            </p>
            <p>Ostolinkki jäsenille</p>
            <p className="linkbox">{link}</p>
            <div className="actions">
              <button className="btn ghost" type="button" onClick={() => copy(link, season.id)}>
                {copied === season.id ? "Kopioitu" : "Kopioi ostolinkki"}
              </button>
              <Link className="btn primary" href={`/osta/${season.id}`}>
                Avaa ostosivu
              </Link>
            </div>
            <h3 style={{ marginTop: 18 }}>Ostajat</h3>
            {buyers.length === 0 ? (
              <p className="muted">Ei ostajia vielä.</p>
            ) : (
              <table>
                <thead>
                  <tr>
                    <th>Nimi</th>
                    <th>Sähköposti</th>
                    <th>Maksettu</th>
                  </tr>
                </thead>
                <tbody>
                  {buyers.map((m) => (
                    <tr key={m.id}>
                      <td>{m.name}</td>
                      <td>{m.email}</td>
                      <td>{new Date(m.paidAt).toLocaleString("fi-FI")}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </section>
        );
      })}
    </main>
  );
}
