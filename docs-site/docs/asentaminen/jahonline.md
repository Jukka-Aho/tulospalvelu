# Pirilä synkka (JAHOnline)

Kilpailijat, läsnäolo, maaliajat ja online-rastit siirtyvät **HkKisaWinistä**
ja **ViestiWinistä** suoraan JAHOnline-tulospalveluun. JAHOnline API on
ainoa siirtotapa; netin asetukset ovat sivulla **Hallinta → Pirilä-asetukset**.

Valikko (HkKisaWin ja ViestiWin): **JAHOnline API (synkka)**.

Suunnistuksessa osanottajat elävät Pirilässä (IRMA / CSV / käsin). JAHOnline
näyttää ne synkan jälkeen. Emit-luenta merkitsee lähtijän läsnäolevaksi ja
lähettää tiedon heti nettiin.

## Käyttöönotto

1. Asenna HkKisaWin / ViestiWin tavalliseen tapaan ([asennuksen vaiheet](asennuksen_vaiheet.md)).
2. Avaa kilpailu HkKisaWinissä tai ViestiWinissä.
3. Valitse **JAHOnline API (synkka)**.
4. Täytä:
   - **Bridge-URL:** `https://jahonline.com/public/api/kilpailijat_bridge.php`
     (oletus; yleensä ei tarvitse muuttaa)
   - **API-avain:** JAHOnline-käyttäjän `api_token` (Hallinta → käyttäjä)
5. Paina **Hae kilpailut** ja valitse listasta netissä luotu Pirilä-kilpailu.
   Jos kisaa ei vielä ole netissä, paina **Luo uusi JAHOnline-kisa**
   (painike näkyy, kun yhteys on luotu). Kisa luodaan avatun kilpailun
   nimellä ja päivämäärällä. Sen luonne tulee Pirilän kilpailulajista
   (suunnistus, hiihto, juoksu, …) ja määrää, mitkä valikot netissä näkyvät:
   esimerkiksi juoksussa ajanottosivut, suunnistuksessa ratatiedot ja
   Emit-sivut.
6. Paina **Yhdistä** (paikallisen kilpailun pitää olla auki). Onnistunut yhdistäminen aktivoi yhteyden. Ohjelma muistaa
   yhdistetyt kisat, joten seuraavalla kerralla kisa on valmiina listassa.
7. **Lähetä kilpailijat nyt** vie Pirilän osanottajat (nimet, emit-koodit, ajat,
   lähtöajat, läsnäolo, online-väliajat ja Emit-rastiväliajat) JAHOnlineen.
8. **Hae kilpailijat nyt** vain jos netistä pitää tuoda päivityksiä takaisin
   paikalliseen `KILP.DAT`-kantaan. Taustahaku täyttää vain tyhjät ajat; se ei
   pyyhi Pirilässä jo olevaa maalia.

Asetukset tallentuvat tiedostoon `jahonline_api.ini` **kilpailun kansioon**
(sama hakemisto kuin `laskenta.cfg` / `KILP.DAT`). Tiedosto luetaan, kun
kilpailu avataan. Jos kansiossa ei ole tiedostoa, vanha exe-kansion ini
kopioidaan sinne. Yhdistetyt kisat ja API-avain muistetaan lisäksi
exe-kansion tiedostossa `jahonline_yhdistetyt.ini`, joten uusi kilpailukansio
saa avaimen automaattisesti.

## Läsnäolo emit-luennasta

Kun kilpailijan emit-kortti luetaan (leimantarkastus tai parametrin
`ESILUENTA` mukainen lähtöluenta), ohjelma:

1. yhdistää kortin kilpailijan tietoihin (`KILP.DAT`)
2. merkitsee kilpailijan **läsnäolevaksi**, jos hänet oli merkitty
   poissaolevaksi, ei-lähteneeksi, vakantiksi tai ilmoittautuneeksi
3. lähettää läsnäolotiedon heti JAHOnlineen (`lasna: true`, status `LASNA`
   kun tulosta ei vielä ole)

Lähdön esiluenta otetaan käyttöön parametrilla `ESILUENTA` yhdessä
lukijamäärityksen `LUKIJA=…` kanssa. Katso myös
[Emitkoodien kirjaus ja lähtijöiden kirjaus](../hkkisawin/6.11_emitkoodien_lukeminen_kilpailijoille.md).

Osanottajakaavakkeen tallennus (nimi, seura, emit, lähtöaika, tulos) jonoittaa
muutoksen samaan tapaan kuin Pirilän sisäinen tiedonsiirto. Jono lähtee nettiin
noin 200 ms välein, kun API-avain ja `kilpailu_id` on asetettu. Asemaverkon
`YHTEYS=` / `comfl` ei ole edellytys.

Automaattinen taustasynkka (välilehti *Automaatio*) lähettää lisäksi koko
osanottajalistan valitun välein (`lahetysvali`, oletus 10 s), kun yhdistäminen
on onnistunut.

## Työasemien siirto

Samalla välilehdellä **Työasemien siirto** valitsee, kulkeeko Pirilän oma
asemaliikenne suoraan koneelta koneelle vai JAHOnlinen kautta.

- **Lähiverkko säilyy.** Synkan valinta ei katkaise koneelta koneelle -yhteyttä.
  Yksi kone voi olla ainoa netissä. Muut koneet ovat sen kanssa samassa
  lähiverkossa, ja se kone vie tiedot JAHOnlineen.
- **Tapahtumaloki** on lisä tälle koneelle. Sillä kone lukee puhelimen ja
  sellaisten asemien rivit, jotka eivät ole lähiverkossa, ja välittää ne
  `laheta`-liikenteenä muille. Anna asemalle nimi, jotta se ei lue omia
  rivejään takaisin.

**Lopeta synkka** pysäyttää täyssynkan. Jos siirto on JAHOnline, asemien välinen
loki jää päälle.

Puhelimella samaa kisaa voi kirjata sivulta **Hallinta → Kilpailutoiminnat**.
Lähtöluenta, nimenhuuto ja lähtökello ovat kehyksessä **Lähtö**
(käyttöoikeus *lähtötoiminnat*). Ampumapaikka näkyy, kun kisaan on merkitty
ampumapaikat (käyttöoikeus *ampumapaikat*).

Pirilä-kisassa hallinnan **Radat** on **Radat (Pirila)**: radat ja rastikoodit
tulevat synkasta, muokkaus vain Pirilässä. Jos kisaa ei ole liitetty Pirilään,
radat määritetään JAHOnlinen hallinnassa.

Kun netissä on ruksi *Jaettu ajanottolista* ja Pirilässä *Maaliajat ilman numeroa*,
maaliaika kirjataan Pirilässä ja numero selaimen Numerot-sivulla. Juoksun oma
ajanotto jää käyttöön, ja sen voi yhdistää Pirilään samalla ruksilla.

## Online-rastit (kaksisuuntainen vienti)

Kyllä: online-väliajat kulkevat saman JAHOnline-bridgen kautta, sekä
henkilökohtaisessa kisassa että viestissä.

- Ajanotto / rastileima → heti `action=tapahtuma` (`piste` 0 = maali, ≥ 1 = online)
- Täysi lista menee `synkkaa`-sanoman `valiajat[]`-kentässä
- Emit-rastiväliajat ovat samoja `valiajat[]`-rivejä kentällä `rasti_koodi`
  (`laskeemitvaliajat`; erillistä `rastivaliajat[]`-taulukkoa ei käytetä)
- Haku JAHOnlinesta kirjoittaa online-väliajat takaisin `KILP.DAT`:iin;
  rivit joissa `rasti_koodi > 0` ohitetaan, jotta emit-leimat eivät mene `va[]`:iin
- Viestissä jokaisella rivillä on `osuus` (1, 2, 3…)

JAHOnlinen tulossivu näyttää online-sarakkeet (`sarjat.valia_lkm`, rivit ilman
`rasti_koodi`) ja rastiväliajat (`/public/valiajat.php`, rivit joissa `rasti_koodi`).

Keräilylähtöiset CSV/IRMA/Kilmo-tuonnit HkKisaWinissä toimivat edelleen
kertatuontiin **ennen** kisaa.

## Lisätietoja

- JAHOnline-sivun korvaava ohje (kopioitavaksi palvelimelle
  `docs/pirila.md`): [docs/jahonline-docs/pirila.md](https://github.com/Jukka-Aho/tulospalvelu/blob/main/docs/jahonline-docs/pirila.md)
- Protokolla: [JAHOnline API](https://github.com/Jukka-Aho/tulospalvelu/blob/main/docs/api-jahonline.md)
  (Yhdistä / synkkaa / kilpailijat, Bearer-avain)
- Valikko (HkKisaWin ja ViestiWin): **JAHOnline API (synkka)**
