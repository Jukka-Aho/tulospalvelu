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
| `tila` | tuleva `tulossa`, tänä päivänä `kaynnissa`, mennyt `valmis` (päivä Europe/Helsinki) |
| `rastilippu_event_id` / `_uuid` / `_series_id` | Rastilipun tunnisteet (jos listasta) |

Sama Rastilippu-tapahtuma luodaan seuralle vain kerran
(`UNIQUE (seura_id, rastilippu_event_id)`).

Tulosten ja rastiväliaikojen lähetys Rastilippuun liitetään myöhemmin.
Aineisto kootaan jo (`rastilippuVieaineisto`): nimet, radat, ajat, tilat
ja emit-väliajat. `rastilippuLahetaTulokset` palauttaa toistaiseksi
että vienti ei ole vielä käytössä.

## Avattu tapahtuma

Koko ikkuna (`iltarastiKuori`). Ylhäällä hamburger ja tapahtuman nimi.

Hamburger:

- Tapahtuman asetukset (nimi, paikka, alku, loppu, radat)
- Osanottajat
- Tulospalvelu (oletusavaus)
- Tulokset (seuranta upotettuna: rata ylhäällä, välilehdet
  **Tulokset** / **Rastiväliajat** kuten Pirilän julkisissa tuloksissa)

Vaihda tapahtuma palauttaa hubiin.

## Radat

Tapahtuman asetukset näyttää radat taulukkona (nimi, matka, rastikoodit).
**Lisää rata** / **Muokkaa** avaa lomakkeen ja palaa tallennuksen jälkeen
asetuksiin.

Iltarastissa lomake on yksinkertainen:

- radan nimi (tuloksissa **rata**, ei sarja)
- matka (km); listassa sama yksikkö, ei metrejä
- rastikoodit järjestyksessä (välilyönti tai pilkku); sama koodi saa toistua
  (silmukka esim. 12–13–12), mutta ei peräkkäin (12–12 kielletty).
  Väliaikasarakkeet tulevat tästä järjestyksestä, ei yksilöllisistä koodeista.
  Jos radan rastilista muuttuu, väliajat käyttävät radan listaa (eivät vanhaa kopiota).

Radan tallennus kesken illan tarkistaa sen radan kilpailijat uudelleen:
väliajat kirjoitetaan uusiksi leimoista, ja hyväksytty/esitys (`T`/`E`)
lasketaan uudestaan myös maastossa olevalle, jolla on leimoja. Käsin
merkitty `H`/`K` ei vaihdu. Osanottajissa on myös **Tarkista leimat
uudelleen**. Kilpailukortin numeron muutos ei siirrä jo tallennettuja
leimoja; ne on avainnettu henkilöön. Seuraava kortin luku käyttää uutta
numeroa.

Lähtö on aina nollaleimasimelta (`ennakko = 0`, `maalilaji = 4`). Jokainen
rata luo samannimisen sarjan (1:1). Henkilöt kirjataan tälle radalle.
Rataa ei voi poistaa, jos sillä on osanottajia.

## Tulospalvelu

`/public/emit_lukija.php` sisäänluku / leimantarkastus, ilman ohjetekstejä.

**Sisäänluku:** etunimi, sukunimi, seura, rata, kilpailukortti, lainakortti,
ei aikaa. Lomake on tiivis, jotta se mahtuu kännykän näytölle kerralla.
Kortti tulee lukijalta; kirjaus onnistuu myös ilman korttia.

**Leimantarkastus:** lukuhetkellä ruutuun tulevat henkilön nimi,
tulosaika, tila (Hyväksytty tai Esitys) ja **kortin leimat** heti.
Leimat verrataan rataan (Pirilän järjestys): oikea vihreänä, väärä
punaisena, ylimääräinen katkoviivalla, puuttuvat alle. Vanhat
ylimääräiset leimat näkyvät silti. Tyhjä ruutu ei jää odottamaan
korttia. Hyväksytty soittaa lyhyen äänen, Esitys toisen. Esitys
vaihdetaan Hyväksytty / Hyl / Kesk. Jos leimat täsmäävät tasan yhteen
toiseen rataan, näytetään arvaus ja yksi nappi radan vaihtoon. Uuden
kortin voi kirjata nimellä myös tässä. Maaliin kirjaus asettaa `T` tai
`E`, joten henkilö ei jää *Maastossa*. Esitys tallennetaan `keskhyl=E`
(status-sarake pysyy `OK`, koska ENUM ei tunne ESITYS-arvoa).

Jos verkko katkeaa, kortin luku jää puhelimen jonoon (`localStorage`)
ja lähtee automaattisesti, kun yhteys palaa. Banneri kertoo jonon pituuden.

Kortin luku vaihtaa välilehden automaattisesti: tunnettu kortti menee
leimantarkastukseen, tuntematon kysyy *Lähteväksi* vai *Maaliin*.

Molemmissa tiloissa vihreä **Yhdistä lukija** omalla rivillään. Yhdistettynä
nappi vaihtuu tekstiksi *Lukija yhdistetty* (klikkaus katkaisee). Valittu
välilehti on täytetty. Kortin leimat näytetään heti luvun jälkeen, myös
uudelle kortille.
*Kirjaa* on AJAX eikä avaa sivulatausikonia.

| Tilanne | Toiminta |
|---|---|
| Tunnettu kortti | Siirtyy leimantarkastukseen, rastitarkistus |
| Tuntematon kortti | Kysyy lähteväksi vai maaliin; sitten nimilomake |
| Kortti leimantarkastuksessa | Leimat ja tulos ruutuun. Uudelle kortille myös nimi |
| Kirjaa kortilla | Lähtö nollaleimauksesta / leimantarkastus |
| Ei aikaa tai ei korttia | Maastoon (sisäänluku) tai pois (leimantarkastus) |
| Lainakortti | `kilpailijat.laina = X`. Sama emit-numero kelpaa seuraavalle, kun edellinen ei ole enää maastossa (`T`/`E`/`H`/`K`). Edellisen leimat pysyvät hänellä. |
| Kaikki rastit oikein | Hyväksytty (`keskhyl = T`), aika nollauksesta maaliin |
| Puuttuva tai väärä rasti | Punainen näyttö. Tila **Esitys** (`keskhyl = E`), eri ääni kuin hyväksytyssä |
| Esitys | Hyväksytty / hylätty / keskeyttänyt. Jos leimat sopivat yhdelle muulle radalle, nappi *Vaihda tälle radalle*. Radan vaihto tarkistaa uudelleen |
| Ei verkkoa | Luku jää jonoon ja lähtee, kun verkko palaa |

API-moodit (`api/api_emit_luku.php`): `lue`, `lisaa`/`tallenna`, `maali`,
`paatos` (`T`/`H`/`K`), `vaihda_rata`, `maastoon`, `pois_maastosta`.
Iltarastissa nolla-emit on sallittu tallennuksessa.

Hyväksyntä (`T`) tallentaa ajan nollaleimauksesta maaliin, jos leimat ovat
kannassa.

## Osanottajat

Hamburger → **Osanottajat**: radat välilehdillä. Sarjan alussa näkyy
montako on vielä maastossa. Maastossa olevan tila on *Maastossa* (ei
*Lähtenyt*). Nimeä, rataa ja kortin numeroa voi muokata. Muokkauksessa
näkyy tulos ja **Näytä leimat** (kortin leimat, vaikka rata ei täsmää).
Radan vaihto tai **Tarkista leimat uudelleen** laskee leimat ja väliajat
uudestaan ja merkitsee `T`/`E` myös maastossa olevalle, jolla on leimoja.
Maastossa olevan voi merkitä pois ilman leimauskorttia. Lisäys on
tulospalvelun sisäänluvussa.

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
| `admin/iltarasti_tulokset.php` | Tulokset ja rastiväliajat upotettuna |
| `admin/iltarasti_valiajat.php` | Ohjaa tulossivun Rastiväliajat-välilehteen |
| `includes/layout.php` / `admin_nav.php` | Kokoikkuna-kuori ja hamburger |
| `includes/kisa_luonne.php` | luonne `iltarasti` |
| `includes/tili.php` / `auth.php` | palvelu `iltarastit`, rooli `iltarasti` |
