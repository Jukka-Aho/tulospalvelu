"use client";

import { useEffect, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import Link from "next/link";
import { addMember, formatEur, formatFiDate, loadState } from "../../../lib/store";

export default function CheckoutPage() {
  const params = useParams();
  const router = useRouter();
  const seasonId = params.seasonId;
  const [state, setState] = useState(null);
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [error, setError] = useState("");
  const [paying, setPaying] = useState(false);

  useEffect(() => {
    setState(loadState());
  }, []);

  if (!state) {
    return (
      <main className="wrap phone">
        <p className="muted">Ladataan…</p>
      </main>
    );
  }

  const season = state.seasons.find((s) => s.id === seasonId);
  if (!season) {
    return (
      <main className="wrap phone">
        <h1>Kautta ei löydy</h1>
        <Link href="/seura">Takaisin seuran näkymään</Link>
      </main>
    );
  }

  function pay(e) {
    e.preventDefault();
    setError("");
    setPaying(true);
    const result = addMember(state, { seasonId, name, email });
    if (result.error) {
      setError(result.error);
      setPaying(false);
      return;
    }
    router.push(`/kortti/${result.member.token}`);
  }

  return (
    <main className="wrap phone">
      <p className="kicker">{state.club.name}</p>
      <h1>{season.name}</h1>
      <p className="muted">
        Voimassa {formatFiDate(season.validFrom)}–{formatFiDate(season.validTo)}
      </p>
      <p style={{ fontSize: "1.4rem" }}>{formatEur(season.priceCents)}</p>
      <form className="panel" onSubmit={pay}>
        <div className="row">
          <label>
            Nimi
            <input value={name} onChange={(e) => setName(e.target.value)} required />
          </label>
          <label>
            Sähköposti
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />
          </label>
          {error ? <p className="err">{error}</p> : null}
          <button className="btn primary" type="submit" disabled={paying}>
            {paying ? "Maksetaan…" : "Maksa (demo)"}
          </button>
          <p className="muted">Demo: maksua ei veloiteta. EpSu-kortti aukeaa heti.</p>
        </div>
      </form>
    </main>
  );
}
