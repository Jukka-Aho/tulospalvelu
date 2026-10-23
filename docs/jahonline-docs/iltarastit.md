# Iltarastit ja Rastilippu (JAHOnline-palvelin)

Iltarastit ovat kisojen rinnalla oleva palvelu. Tapahtuma valitaan
Rastilipun kuntotapahtumista, radat tehdään JAHOnessa ja henkilöt luetaan
Emit-USB-lukijalla. JAHOnline-sivuston koodi ei ole tässä repossa; tämä
kuvaa palvelimelle tehdyn toteutuksen.

## Palvelu ja tunnukset

Yläpalkin valitsimessa on **Iltarastit** GPS-seurannan ja tulospalvelun rinnalla.

- Seuran admin / pääkäyttäjä näkee iltarastit, jos jäsenyyden palvelu on
  `tulokset`, `molemmat` tai `iltarastit`.
- Iltarasteille voi luoda omat tunnukset (`users.role = iltarasti`,
  `seura_jasenet.palvelut = iltarastit`). Kirjautuminen avaa suoraan
  iltarastihubin. Tapahtuma valitaan alasvetovalikosta.

Hub: `/admin/iltarastit.php`.

## Rastilippu-avain

Seuran IRMA/Rastilippu-JWT liitetään hubissa (tai *Oma seura* → Iltarastit).

- API: `https://api.suunnistusliitto.fi/integration`
- Otsake: `X-Authorization-rs: <JWT>`
- Tapahtumat: `GET /events` (ilman perävinoon)
- Avain tallennetaan `seurat.rastilippu_token`-sarakkeeseen **salattuna**
  (`aes-256-gcm`, avainmateriaali palvelimen `$api_key`). JWT:tä ei pidä
  laittaa git-repoon.

Testi hakee kuntotapahtumat. Onnistunut testi avaa alasvetovalikon.

## Tapahtuman luonti

Valitse Rastilippu-tapahtuma ja paina **Luo tapahtuma**. Palvelin tekee
`kilpailut`-rivin:

| Kenttä | Arvo |
|---|---|
| `luonne` | `iltarasti` |
| `pirila_synkka` | 0 |
| `kisa_tyyppi` | `normaali` |
| `nimi`, `paiva`, `paikka` | Rastilipusta |
| `rastilippu_event_id` / `_uuid` / `_series_id` | Rastilipun tunnisteet |

Sama Rastilippu-tapahtuma luodaan seuralle vain kerran
(`UNIQUE (seura_id, rastilippu_event_id)`).

Tulosten lähetystä Rastilippuun ei ole tässä vaiheessa.

## Radat

Hallinta → **Radat**. Iltarastissa lomake on yksinkertainen:

- radan nimi
- matka (m)
- rastikoodit järjestyksessä (välilyönti tai pilkku)

Lähtö on aina nollaleimasimelta (`ennakko = 0`, `maalilaji = 4`). Jokainen
rata luo samannimisen sarjan (1:1). Henkilöt kirjataan tälle radalle/sarjalle.

## Emit: lähtö ja maali

`/public/emit_lukija.php` (sisäänluku / leimantarkastus).

| Tilanne | Toiminta |
|---|---|
| Uusi kortti | Kysytään: lähteväksi vai maaliin |
| Lähtevä | Nimi, seura, rata. Lähtö merkitään nollaleimauksesta (`pirila_lahto_at`) |
| Jo lähtenyt + rastit kortilla | Leimantarkastus |
| Kaikki rastit oikein | Hyväksytty (`keskhyl = T`) |
| Puuttuva tai väärä rasti | Punainen näyttö (*puuttuu* / *väärä*). Tila **esitys** (`keskhyl = E`) |
| Esitys | Valitse hyväksytty / hylätty / keskeyttänyt, tai vaihda rata ja tarkista uudelleen |

API-moodit (`api/api_emit_luku.php`): `lue`, `lisaa`/`tallenna`, `maali`,
`paatos` (`T`/`H`/`K`), `vaihda_rata`.

Henkilöitä voi muokata hallinnan *Henkilöt*-sivulta ja *Muokkaa henkilöä*
-näkymästä.

## `keskhyl` iltarastissa

| Kirjain | Merkitys | `status` |
|---|---|---|
| `-` | Lähtenyt | OK |
| `T` | Hyväksytty | OK |
| `E` | Esitys (ei Pirilän eiläht/DNS) | OK, näytetään *Esitys* |
| `H` | Hylätty | DSQ |
| `K` | Keskeyttänyt | DNF |

## Tiedostot (palvelin)

| Tiedosto | Tehtävä |
|---|---|
| `includes/rastilippu.php` | API-asiakas, salaus |
| `includes/iltarasti.php` | Tapahtuman luonti, rata↔sarja, päätös |
| `admin/iltarastit.php` | Hub: avain, tapahtumalista, tunnukset |
| `includes/kisa_luonne.php` | luonne `iltarasti` |
| `includes/tili.php` / `auth.php` | palvelu `iltarastit`, rooli `iltarasti` |
