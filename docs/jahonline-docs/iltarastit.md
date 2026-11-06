# Iltarastit ja Rastilippu (JAHOnline-palvelin)

Iltarastit ovat kisojen rinnalla oleva palvelu. Tapahtuma valitaan
Rastilipun kuntotapahtumista tai luodaan paikallisesti. Radat tehdään
JAHOnessa ja henkilöt luetaan Emit-USB-lukijalla tai kirjataan nimellä.
JAHOnline-sivuston koodi ei ole tässä repossa; tämä kuvaa palvelimelle
tehdyn toteutuksen.

Julkisella etusivulla (`/public/index.php`) kilpailut ja iltarastit ovat
erilliset osiot. Kilpailuilla on sarakkeet **Käynnissä**, **Ilmoittautuminen**
ja **Valmiit**. Iltarastit ovat yhtenä sarakkeena, uusin ylimpänä. Haku
kattaa molemmat. Kirjautuneen *Seura · omat kisat* erottaa samoin, jos
listassa on molempia.

## Palvelu ja tunnukset

Järjestelmän admin avaa seuralle palvelut erikseen (`/admin/seurat.php`):
**GPS**, **Kilpailut** ja **Iltarastit**. GPS-paketti (ilmainen / pro /
analyysi) rajoittaa vain GPS:n ominaisuuksia ja kuukausikiintiötä.
Oman seuran sivu (`/admin/oma_seura.php`) ei näytä GPS-tietoja, jos GPS
ei ole seuralla päällä.

Uusi seura rekisteröityy tällä hetkellä **iltarastikäyttäjäksi** (rooli
`iltarasti`, seuran omistaja). Kirjautuminen vie iltarastihubiin, kun
GPS ja kilpailut eivät ole päällä. Oman seuran sivulla voi lisätä vain
iltarastikäyttäjiä (iltarastikäyttäjä / pääkäyttäjä).

**IRMA/Rastilippu-liitäntä** on seuran itse valitsema lisäominaisuus
(`seurat.rastilippu_liitanta`). Hinta on **5 € / yhdistetty tapahtuma**,
laskutus **vuosittain** seuran `laskutus_email`-osoitteeseen. Paikallinen
ilta ilman Rastilippua ei kasvata summaa. JWT-avain ja tapahtuman
yhdistäminen ovat käytössä vasta kun liitäntä on päällä. Järjestelmän
admin näkee vuosikohtaisen laskutuslistan seurojen hallinnassa
(seura, laskutussähköposti, tapahtumamäärä, summa). Seura, jolla
iltarastit eivät ole päällä, ei näe Iltarastit-valitsinta.

Yläpalkin valitsimessa on **Iltarastit**. GPS-seuranta näkyy vain, jos
GPS-palvelu on seuralla päällä.

Iltarasteissa on kaksi tasoa (`seura_jasenet.rooli`, `users.role = iltarasti`):

- **Pääkäyttäjä** (`omistaja` / `admin`, tai järjestelmän `paakayttaja`):
  seuran Rastilippu-avain, seuran iltarastitunnusten luonti ja salasanojen
  nollaus. Ennen käyttäjien lisäämistä vaaditaan seuran nimi (avain
  tallennetaan sille seuralle).
- **Iltarastikäyttäjä** (`jasen`): valitsee seuran Rastilippu-listasta
  tapahtuman johon ilta yhdistetään, lukee emitin ja hallitsee osanottajia.
  Ei näe eikä muuta JWT-avainta.
- Järjestelmän seuran admin / GPS-pääkäyttäjä näkee iltarastit, jos
  seuralla on iltarastit päällä **ja** jäsenyyden palvelu on `tulokset`,
  `molemmat` tai `iltarastit`.
- Ajanottaja, jolla on vain tulospalvelu, ei näe Iltarasteja.

Hub: `/admin/iltarastit.php`. **Avaa** avaa illan koko ikkunaan
(hamburger + tapahtuman nimi, ei JAHOnline-brändiä).

Kirjautumissivulla on *Unohditko salasanan?* (`/auth/unohdit.php`).
Vaihtolinkki lähtee tunnuksen sähköpostiin (voimassa 2 h, kertakäyttöinen).
Sähköposti on pakollinen: uusi tunnus ei synny ilman sitä, ja vanha tunnus
ilman osoitetta joutuu antamaan sen kirjautumisen yhteydessä
(`/auth/sahkoposti.php`) ennen kuin pääsee eteenpäin.

Salasanan on oltava vähintään 8 merkkiä ja sisällettävä kolme seuraavista:
iso kirjain, pieni kirjain, numero, erikoismerkki. Sama vaatimus on
rekisteröinnissä, hubin tunnuksissa, oman salasanan vaihdossa ja
vaihtolinkissä.

## Rastilippu-avain

Seuran IRMA/Rastilippu-JWT liitetään hubissa (vain pääkäyttäjä). Avain on
**seurakohtainen** (`seurat.rastilippu_token`). Kun avain on tallennettu,
lomake piilotetaan (*Vaihda avain*). Tyhjä *Tallenna ja testaa* ei pyyhi
avainta. Epäonnistunut testi näyttää Rastilipun viestin, ei pelkkää `1`.

- API: `https://api.suunnistusliitto.fi/integration`
- Otsake: `X-Authorization-rs: <JWT>`
- Tapahtumat: `GET /events` (ilman perävinoon)
- Avain tallennetaan `seurat.rastilippu_token`-sarakkeeseen **salattuna**
  (`aes-256-gcm`, avainmateriaali palvelimen `$api_key`). JWT:tä ei pidä
  laittaa git-repoon.

Tapahtumalista välimuistissa (`seurat.rastilippu_events_json`). Hub ei
hae Rastilippua joka sivulatauksella. Päivitys: *Päivitä lista Rastilipusta*.

## Tapahtuman luonti

Yksi valikko paikallisista illoista. Iltarastikäyttäjä ja pääkäyttäjä
valitsevat illan seuran Rastilippu-listasta (seuran avaimella). Pääkäyttäjä
voi luoda illan myös **ilman Rastilippua** (nimi, **pakollinen päivä**,
paikka). Luonti ja *Avaa* vievät tulospalveluun. Paikallisen illan voi
yhdistää Rastilippu-tapahtumaan myöhemmin tapahtuman asetuksista (nimi ja
päivä säilyvät). Sama Rastilippu-tapahtuma voi olla vain yhdellä illalla.

| Kenttä | Arvo |
|---|---|
| `luonne` | `iltarasti` |
| `pirila_synkka` | 0 |
| `kisa_tyyppi` | `normaali` |
| `nimi`, `paiva`, `paikka` | Rastilipusta tai lomakkeelta; päivä pakollinen |
| `tila` | tuleva `tulossa`, tänä päivänä `kaynnissa`, mennyt `valmis` (päivä Europe/Helsinki) |
| `rastilippu_event_id` / `_uuid` / `_series_id` | Rastilipun tunnisteet (luonnissa tai asetuksista myöhemmin) |

Sama Rastilippu-tapahtuma luodaan tai yhdistetään seuralle vain kerran
(`UNIQUE (seura_id, rastilippu_event_id)`).

Tulokset, radat ja emit-väliajat lähtevät Rastilippuun automaattisesti
taustalla, kun yhdistetty ilta muuttuu (leimantarkastus, päätös, rata,
osanottaja, yhdistäminen). Pyyntö merkitään jonoon ja lähetetään
sivun palattua (`fastcgi_finish_request`) tai cronista
(`cron/rastilippu_lahetys.php`, minuutittain). USB-luku ei odota
Rastilippua. Sama sisältö lähetetään vain kerran (`rastilippu_lahetys_hash`).

Lähetys noudattaa EResults Liten rajapintaa:

- `POST /event/{uuid}/courses` — radat (`id`, `name`, `length`, `controls`, `order`)
- `POST /event/{uuid}/results` — tulokset (`name`, `club`, `courseId`,
  `emitNumber`, `status`, `startTime`, `controlTimes`)
- Tila: hyväksytty/esitys `0`, hylätty `1`, keskeyttänyt `2`. Maastossa
  olevia ei lähetetä.
- Jos Rastilipussa on jo samanniminen rata, sen UUID säilytetään.

## Avattu tapahtuma

Koko ikkuna (`iltarastiKuori`). Ylhäällä hamburger ja tapahtuman nimi.

Hamburger:

- Tapahtuman asetukset (nimi, paikka, **pakollinen päivä**, Rastilippu-yhdistäminen, radat)
- Osanottajat
- Tulospalvelu (oletusavaus)
- Tulokset (seuranta upotettuna: rata ylhäällä, välilehdet
  **Tulokset** / **Rastiväliajat** kuten Pirilän julkisissa tuloksissa).
  Kilpailunumeroita ei näytetä (iltarastissa tunnistetaan nimellä).

Vaihda tapahtuma palauttaa hubiin.

## Radat

Tapahtuman asetukset näyttää radat taulukkona (nimi, matka, rastikoodit).
**Lisää rata** / **Muokkaa** avaa lomakkeen ja palaa tallennuksen jälkeen
asetuksiin.

Iltarastissa lomake on yksinkertainen:

- radan nimi (tuloksissa **rata**, ei sarja)
- matka (km); listassa sama yksikkö, ei metrejä
- rastikoodit järjestyksessä, **yksi rasti per rivi**; sama koodi saa toistua
  (silmukka esim. 12–13–12), mutta ei peräkkäin (12–12 kielletty).
  Rastin perässä on valintaruutu *Sammunut* (emitin patteri vähissä):
  sitä rastia ei vaadita leimoissa, ja rata hyväksytään vaikka leima puuttuu.
  Väliaikasarakkeet tulevat tästä järjestyksestä, ei yksilöllisistä koodeista.
  Jos radan rastilista muuttuu, väliajat käyttävät radan listaa (eivät vanhaa kopiota).

Radan tallennus kesken illan tarkistaa sen radan kilpailijat uudelleen:
väliajat kirjoitetaan uusiksi leimoista, ja hyväksytty/esitys (`T`/`E`)
lasketaan uudestaan myös maastossa olevalle, jolla on leimoja. Hyväksytyn
aika tallennetaan nollauksesta radan viimeiseen rastikoodiin. Käsin
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
Leimat verrataan rataan (Pirilän järjestys): oikea vihreänä, rastikoodi
joka ei ole radalla punaisena (*väärä*), radan rasti väärässä kohdassa
katkoviivalla (*väärässä paikassa*), puuttuvat alle. Sammuneeksi merkittyä
rastia ei vaadita (patteri vähissä). Rastinro näkyy
vain rataan täsmäävissä leimoissa. Vanhat leimat näkyvät silti. Tyhjä ruutu ei jää odottamaan
korttia. Hyväksytty soittaa lyhyen äänen, Esitys toisen. Esitys
vaihdetaan Hyväksytty / Hyl / Kesk. Jos leimat täsmäävät tasan yhteen
toiseen rataan, näytetään arvaus ja yksi nappi radan vaihtoon. Uuden
kortin voi kirjata nimellä myös tässä. Maaliin kirjaus asettaa `T` tai
`E`, joten henkilö ei jää *Maastossa*. Jos rata puuttuu tai tarkistus
kaatuu, leimat silti merkitsevät Esityksen. Esitys tallennetaan
`keskhyl=E` (status-sarake pysyy `OK`, koska ENUM ei tunne ESITYS-arvoa).

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
| Kaikki rastit oikein (tai puuttuva merkitty sammuneeksi) | Hyväksytty (`keskhyl = T`), aika nollauksesta maaliin |
| Puuttuva tai väärä rasti | Punainen näyttö. Tila **Esitys** (`keskhyl = E`), eri ääni kuin hyväksytyssä |
| Esitys | Hyväksytty / hylätty / keskeyttänyt. Jos leimat sopivat yhdelle muulle radalle, nappi *Vaihda tälle radalle*. Radan vaihto tarkistaa uudelleen |
| Ei verkkoa | Luku jää jonoon ja lähtee, kun verkko palaa |

API-moodit (`api/api_emit_luku.php`): `lue`, `lisaa`/`tallenna`, `maali`,
`paatos` (`T`/`H`/`K`), `vaihda_rata`, `maastoon`, `pois_maastosta`.
Iltarastissa nolla-emit on sallittu tallennuksessa.

Hyväksyntä (`T`) tallentaa ajan nollaleimauksesta maaliin, jos leimat ovat
kannassa. Leimantarkastus kirjoittaa emit-väliajat (`valiajat`, `laji=emit`)
heti: nollaleima (koodi 0) säilytetään, juoksuajat lasketaan siitä, ja
rastiväliajat-välilehti päivittyy ilman sarjan tai kilpailijan
uudelleenlaskentaa. Uudelleenlaskenta tekee saman kirjoituksen uudestaan
jos rata tai leimat muuttuvat.

## Osanottajat

Hamburger → **Osanottajat**: radat välilehdillä. Sarjan alussa näkyy
montako on vielä maastossa. Listassa näkyy tarkistuksen tulos
(Maastossa / Hyväksytty / Esitys / Hylätty / Keskeyttänyt) ja kilpailijan
aika. Molemmat voi muuttaa muokkauksessa. Hyväksytty tai Esitys ilman
käsin syötettyä aikaa laskee ajan nollauksesta radan viimeiseen
maalirastiin (vaikka väliin jäisi puuttuvia). Maastossa olevan tila on
*Maastossa* (ei *Lähtenyt*). Nimeä, rataa ja kortin numeroa voi muokata.
Muokkauksessa näkyy tulos, aika ja **Näytä leimat**. Rastinro näytetään
vain rataan täsmäävissä leimoissa (Pirilän `tulkinta`). Radan rasti
väärässä kohdassa merkitään *väärässä paikassa* (ei rastinroa);
radalle kuulumaton koodi on *väärä*. **Tarkista leimat uudelleen**
laskee leimat ja väliajat uudestaan ja merkitsee `T`/`E`.
Osanottajasivun avaus tarkistaa samalla kaikki maastossa olevat, joilla
on jo `emit_leimat`, joten vanha luku ei jää *Maastossa*.
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
| `includes/rastilippu.php` | API-asiakas, salaus, tapahtumalistan välimuisti, tulosten taustalähetys |
| `cron/rastilippu_lahetys.php` | Jonon purku minuutittain (epäonnistuneen taustalähetyksen uusinta) |
| `includes/iltarasti.php` | Tapahtuma, Rastilippu-yhdistäminen, rata↔sarja, päätös, nimellä kirjaus |
| `includes/iltarasti_henkilot_sivu.php` | Osanottajat |
| `admin/iltarastit.php` | Hub: avain (pääkäyttäjä), Rastilippu-valinta, paikallinen luonti, tunnukset |
| `admin/iltarasti_asetukset.php` | Nimi, paikka, päivä, Rastilippu-yhdistäminen, radat |
| `includes/salasana_reset.php` | Unohtuneen salasanan token ja sähköposti |
| `auth/unohdit.php` / `uusi_salasana.php` | Unohtuneen salasanan vaihtolinkki sähköpostiin |
| `auth/sahkoposti.php` | Pakollinen sähköposti kirjautumisen yhteydessä |
| `admin/iltarasti_tulokset.php` | Tulokset ja rastiväliajat upotettuna |
| `admin/iltarasti_valiajat.php` | Ohjaa tulossivun Rastiväliajat-välilehteen |
| `includes/layout.php` / `admin_nav.php` | Kokoikkuna-kuori ja hamburger |
| `includes/kisa_luonne.php` | luonne `iltarasti` |
| `includes/tili.php` / `auth.php` | seuran palvelut, Rastilippu-lisäosa 5 €/tapahtuma, vuosilaskutus, rooli `iltarasti` |
| `admin/seurat.php` | Seurojen hallinta: palvelut ja vuosittainen Rastilippu-laskutus |
| `admin/oma_seura.php` | Oma seura: GPS piilossa jos ei päällä, Rastilippu-lisäosa ja laskutussähköposti |
