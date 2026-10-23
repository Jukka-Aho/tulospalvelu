# Iltarastit ja Rastilippu (JAHOnline-palvelin)

Iltarastit ovat kisojen rinnalla oleva palvelu. Tapahtuma valitaan
Rastilipun kuntotapahtumista tai luodaan paikallisesti. Radat tehdään
JAHOnessa ja henkilöt luetaan Emit-USB-lukijalla tai kirjataan nimellä.
JAHOnline-sivuston koodi ei ole tässä repossa; tämä kuvaa palvelimelle
tehdyn toteutuksen.

## Palvelu ja tunnukset

Yläpalkin valitsimessa on **Iltarastit** GPS-seurannan ja tulospalvelun rinnalla.

- Seuran admin / pääkäyttäjä näkee iltarastit, jos jäsenyyden palvelu on
  `tulokset`, `molemmat` tai `iltarastit`. Avain, tapahtuman luonti ja
  kenttätunnukset ovat vain heille.
- Kenttäkäyttäjä (`users.role = iltarasti`, `seura_jasenet.palvelut =
  iltarastit`) kirjautuu suoraan hubiin. Hän valitsee illan, lukee emitin
  ja hallitsee henkilöitä — ei Rastilippu-avainta eikä uuden illan luontia.
- Ajanottaja, jolla on vain tulospalvelu, ei näe Iltarasteja.

Hub: `/admin/iltarastit.php`. Yläpalkin logo ja *Radat* / *Henkilöt*
pysyvät Iltarasteissa, kun valittu tapahtuma on iltarasti.

## Rastilippu-avain

Seuran IRMA/Rastilippu-JWT liitetään hubissa (vain admin). Kun avain on
tallennettu, lomake piilotetaan (*Vaihda avain*). Tyhjä *Tallenna ja
testaa* ei pyyhi avainta. Epäonnistunut testi näyttää Rastilipun viestin,
ei pelkkää `1`.

- API: `https://api.suunnistusliitto.fi/integration`
- Otsake: `X-Authorization-rs: <JWT>`
- Tapahtumat: `GET /events` (ilman perävinoon)
- Avain tallennetaan `seurat.rastilippu_token`-sarakkeeseen **salattuna**
  (`aes-256-gcm`, avainmateriaali palvelimen `$api_key`). JWT:tä ei pidä
  laittaa git-repoon.

Tapahtumalista välimuistissa (`seurat.rastilippu_events_json`). Hub ei
hae Rastilippua joka sivulatauksella. Päivitys: *Päivitä lista Rastilipusta*.

## Tapahtuman luonti

Yksi valikko paikallisista illoista. Admin luo illan Rastilippu-listasta
tai **ilman Rastilippua** (nimi, päivä, paikka).

| Kenttä | Arvo |
|---|---|
| `luonne` | `iltarasti` |
| `pirila_synkka` | 0 |
| `kisa_tyyppi` | `normaali` |
| `nimi`, `paiva`, `paikka` | Rastilipusta tai lomakkeelta |
| `tila` | tuleva `tulossa`, tänä päivänä `kaynnissa`, mennyt `valmis` |
| `rastilippu_event_id` / `_uuid` / `_series_id` | Rastilipun tunnisteet (jos listasta) |

Sama Rastilippu-tapahtuma luodaan seuralle vain kerran
(`UNIQUE (seura_id, rastilippu_event_id)`).

Tulosten lähetystä Rastilippuun ei ole tässä vaiheessa.

## Radat

Hallinta → **Radat**. Iltarastissa lomake on yksinkertainen:

- radan nimi (tuloksissa **rata**, ei sarja)
- matka (m)
- rastikoodit järjestyksessä (välilyönti tai pilkku)

Lähtö on aina nollaleimasimelta (`ennakko = 0`, `maalilaji = 4`). Jokainen
rata luo samannimisen sarjan (1:1). Henkilöt kirjataan tälle radalle.

## Emit: lähtö, maali ja nimellä kirjaus

Yksi sivu `/public/emit_lukija.php` (sisäänluku / leimantarkastus).
Iltarastin valikossa ei ole erillistä Leimantarkastus-linkkiä.

| Tilanne | Toiminta |
|---|---|
| Uusi kortti | Kysytään: lähteväksi vai maaliin |
| Lähtevä | Vähintään sukunimi, seura, rata. Lähtö nollaleimauksesta |
| Jo lähtenyt + rastit kortilla | Leimantarkastus |
| Kaikki rastit oikein | Hyväksytty (`keskhyl = T`), aika nollauksesta maaliin |
| Puuttuva tai väärä rasti | Punainen näyttö. Tila **Esitys** (`keskhyl = E`, `status = ESITYS`) |
| Esitys | Hyväksytty / hylätty / keskeyttänyt. Radan vaihto tarkistaa uudelleen; jos rastit täsmäävät, hyväksytään heti |
| Nimellä, ilman aikaa | Rata + sukunimi (emit valinnainen). *Maastoon* tai *Pois maastosta* |

API-moodit (`api/api_emit_luku.php`): `lue`, `lisaa`/`tallenna`, `maali`,
`paatos` (`T`/`H`/`K`), `vaihda_rata`, `maastoon`, `pois_maastosta`.

Hyväksyntä (`T`) tallentaa ajan nollaleimauksesta maaliin, jos leimat ovat
kannassa.

## Henkilöt

Hallinta → **Henkilöt**: haku nimellä tai emitillä, lisäys, muokkaus,
maastoon/pois ilman aikaa, poisto. *Muokkaa henkilöä* hakee samoin nimellä
tai emitillä (ei numerolla).

## `keskhyl` iltarastissa

| Kirjain | Merkitys | `status` | Tuloksissa |
|---|---|---|---|
| `-` | Lähtenyt / maastossa | OK | maastossa |
| `T` | Hyväksytty | OK | ajat mukana jos aika on; muuten statuslista ilman sijaa |
| `E` | Esitys (ei Pirilän eiläht/DNS) | ESITYS | selkeästi *Esitys*, ei OK |
| `H` | Hylätty | DSQ | oma statuslista |
| `K` | Keskeyttänyt | DNF | oma statuslista |

## Tiedostot (palvelin)

| Tiedosto | Tehtävä |
|---|---|
| `includes/rastilippu.php` | API-asiakas, salaus, tapahtumalistan välimuisti |
| `includes/iltarasti.php` | Tapahtuma, rata↔sarja, päätös, nimellä kirjaus |
| `includes/iltarasti_henkilot_sivu.php` | Kevyt henkilösivu |
| `admin/iltarastit.php` | Hub: avain, illan valinta, paikallinen luonti, tunnukset |
| `includes/kisa_luonne.php` | luonne `iltarasti` |
| `includes/tili.php` / `auth.php` | palvelu `iltarastit`, rooli `iltarasti` |
