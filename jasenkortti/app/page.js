import Link from "next/link";

export default function HomePage() {
  return (
    <main className="wrap">
      <p className="kicker">Jukka Aho · SaaS-demo</p>
      <div className="hero">
        <h1>EpSu-kortti seuroille</h1>
        <p className="lede">
          Myy seuralle kausimyynti: jäsen ostaa puhelimella, saa henkilökohtaisen
          EpSu-kortin QR-koodilla. Tämä on klikkailtava demo — maksua ei
          veloiteta.
        </p>
        <div className="actions">
          <Link className="btn primary" href="/seura">
            Seuran hallinta
          </Link>
          <Link className="btn ghost" href="/osta/jasen-2026">
            Jäsenen osto (esimerkki)
          </Link>
        </div>
      </div>
      <section className="panel">
        <h2>Näin kokeilet</h2>
        <ol className="muted">
          <li>Avaa seuran näkymä — Etelä-Pohjanmaan Suunnistus ry ja kausi Jäsenyys 2026 on valmiina.</li>
          <li>Kopioi ostolinkki ja avaa se puhelimen kokoisessa ikkunassa.</li>
          <li>Syötä nimi ja sähköposti, paina Maksa (demo).</li>
          <li>EpSu-kortti aukeaa. Kopioi korttilinkki, jolla kortin voi avata uudelleen.</li>
          <li>Seuran ostajalistassa näkyy uusi jäsen.</li>
        </ol>
      </section>
    </main>
  );
}
