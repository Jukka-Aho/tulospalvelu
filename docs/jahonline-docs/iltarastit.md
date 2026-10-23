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
  ja hallitsee osanottajia — ei Rastilippu-avainta eikä uuden illan luontia.
- Ajanottaja, jolla on vain tulospalvelu, ei näe Iltarasteja.

Hub: `/admin/iltarastit.php`. **Avaa** avaa illan koko ikkunaan
(hamburger + tapahtuman nimi, ei JAHOnline-brändiä).

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
tai **ilman Rastilippua** (nimi, päivä, alkamis- ja päättymisajankohta,
paikka). Luonti ja *Avaa* vievät tulospalveluun.

| Kenttä | Arvo |
|---|---|
| `luonne` | `iltarasti` |
| `pirila_synkka` | 0 |
| `kisa_tyyppi` | `normaali` |
| `nimi`, `paiva`, `paikka` | Rastilipusta tai lomakkeelta |
| `alku_at`, `loppu_at` | DATETIME; oletus päivän 16:00–21:00 |
| `tila` | tuleva `tulossa`, tänä päivänä `kaynnissa`, mennyt `valmis` |
| `rastilippu_event_id` / `_uuid` / `_series_id` | Rastilipun tunnisteet (jos listasta) |

Sama Rastilippu-tapahtuma luodaan seuralle vain kerran
(`UNIQUE (seura_id, rastilippu_event_id)`).

Tulosten lähetystä Rastilippuun ei ole tässä vaiheessa.

## Avattu tapahtuma

Koko ikkuna (`iltarastiKuori`). Ylhäällä hamburger ja tapahtuman nimi.

Hamburger:

- Tapahtuman asetukset (nimi, paikka, alku, loppu, radat)
- Osanottajat
- Tulospalvelu (oletusavaus)
- Tulokset (seuranta upotettuna)

Vaihda tapahtuma palauttaa hubiin.

## Radat

Tapahtuman asetukset → **Radat**. Iltarastissa lomake on yksinkertainen:

- radan nimi (tuloksissa **rata**, ei sarja)
- matka (m)
- rastikoodit järjestyksessä (välilyönti tai pilkku)

Lähtö on aina nollaleimasimelta (`ennakko = 0`, `maalilaji = 4`). Jokainen
rata luo samannimisen sarjan (1:1). Henkilöt kirjataan tälle radalle.

## Tulospalvelu

`/public/emit_lukija.php` sisäänluku / leimantarkastus, ilman ohjetekstejä.

Kentät: etunimi, sukunimi, seura, rata, kilpailukortti, lainakortti,
ei aikaa. Lomake on tiivis, jotta se mahtuu kännykän näytölle kerralla.
Kortti tulee lukijalta; kirjaus onnistuu myös ilman korttia.

Molemmissa tiloissa **Yhdistä lukija**. Yhdistettynä näkyy vain
lukijakuvake (klikkaus katkaisee). *Kirjaa* on AJAX eikä avaa
sivulatausikonia.

| Tilanne | Toiminta |
|---|---|
| Kortti sisäänluvussa | Täyttää kilpailukortin ja nimen, jos tunnettu |
| Kortti leimantarkastuksessa | Tunnettu: rastitarkistus. Tuntematon: lomake |
| Kirjaa kortilla | Lähtö nollaleimauksesta / leimantarkastus |
| Ei aikaa tai ei korttia | Maastoon (sisäänluku) tai pois (leimantarkastus) |
| Lainakortti | `kilpailijat.laina = X` |
| Kaikki rastit oikein | Hyväksytty (`keskhyl = T`), aika nollauksesta maaliin |
| Puuttuva tai väärä rasti | Punainen näyttö. Tila **Esitys** (`keskhyl = E`) |
| Esitys | Hyväksytty / hylätty / keskeyttänyt. Radan vaihto tarkistaa uudelleen |

API-moodit (`api/api_emit_luku.php`): `lue`, `lisaa`/`tallenna`, `maali`,
`paatos` (`T`/`H`/`K`), `vaihda_rata`, `maastoon`, `pois_maastosta`.
Iltarastissa nolla-emit on sallittu tallennuksessa.

Hyväksyntä (`T`) tallentaa ajan nollaleimauksesta maaliin, jos leimat ovat
kannassa.

## Osanottajat

Hamburger → **Osanottajat**: haku nimellä tai emitillä, lisäys, muokkaus,
maastoon/pois ilman aikaa, poisto.

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
| `includes/iltarasti.php` | Tapahtuma, alku/loppu, rata↔sarja, päätös, nimellä kirjaus |
| `includes/iltarasti_henkilot_sivu.php` | Osanottajat |
| `admin/iltarastit.php` | Hub: avain, illan valinta, paikallinen luonti, tunnukset |
| `admin/iltarasti_asetukset.php` | Nimi, paikka, alku, loppu, radat |
| `admin/iltarasti_tulokset.php` | Tulokset upotettuna |
| `includes/layout.php` / `admin_nav.php` | Kokoikkuna-kuori ja hamburger |
| `includes/kisa_luonne.php` | luonne `iltarasti` |
| `includes/tili.php` / `auth.php` | palvelu `iltarastit`, rooli `iltarasti` |
