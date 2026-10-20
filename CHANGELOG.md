# Changelog

Muutoshistoria pääkehityshaaraan (main) tehdyistä committeista, uusin ensin.

| Pvm | # | Muutos |
|---|---|---|
| 2026-10-20 | — | Sivulatauksen logo pysyy paikallaan (ei skaalausta eikä kiertoa); päälle avautuu kultainen varjostus latauksen ajaksi |
| 2026-10-19 | — | JAHOnline: Pirilä-kisan ilmoittautumissivu käyttöön (ruksi Hallinta → kilpailu). Ilmoittautuja saa numeron 1–9979; tiedot tallentuvat verkkoon ja siirtyvät Pirilään taustasynkassa / *Hae kilpailijat nyt* kun kisa on yhdistetty API:n kautta |
| 2026-10-19 | — | Väliaikasivut: 1. sijan ajat (sija 1 ja ero 0) vihreänä, muu brändiväri pysyy kultaisena |
| 2026-10-19 | — | Etusivun yläpalkkiin lisää tilaa yläreunaan, jotta iso logo ei ole kiinni näytön laidassa |
| 2026-10-19 | — | JAHOnline-sivujen vihreä brändiväri vaihdettu logon kultaan (`#c49a22` / tumma `#e4c35a`); painikkeet, linkit, GPS ja live-taulut |
| 2026-10-19 | — | Sivulatauksen logo pienennetty (6rem), jotta rasteritunnus ei pikselöidy |
| 2026-10-19 | — | JAHOnline-logo musta-kultainen (PWA-ikonit, sivulataus, yläpalkki); painikkeet säilyvät vihreinä |
| 2026-10-19 | — | AhoAuto (`/ahoauto/`) asennettavissa omaksi PWA:ksi: JAHOnlinen sovellusalue rajattu `/public/`-polkuun, AhoAutolla oma manifest ja service worker. Osoite `/AhoAuto/` ohjaa `/ahoauto/`-kansioon |
| 2026-10-19 | — | JAHOnline PWA: kotinäytön logo rajattu täyttämään ikoni (192/512/180, maskable). Sivulatauksessa animoitu JAH-logo |
| 2026-10-18 | — | JAHOnline-palvelin: Kompassi 26 siirretty kokonaan `jahonline`-kantaan (kolme puuttunutta D14-kilpailijaa palautettu); `pirila_sync` poistettu. Korjattu kaatuvat `tulokset.php` (ohjaus online-tuloksiin) ja `live_tulokset.php` (puuttuva `live_tapahtumat`). Pirilä: Tulokset-valintaruutu rajaa JSON-kentät, ViestiWin ei lähetä online-rasteja kahdesti, Hk-hakuun `tyyppi=yksilo`, tilanäyttö TAPAHTUMALOKI |
| 2026-10-18 | — | JAHOnline-palvelin: vain JAHOnline API jää siirtotavaksi. Poistettu *Synkka-tila*-sivu ja vanha siirto (MySQL-välikanta `pirila_sync` + daemon, CSV/XML-tuonti, `pirila_live.php`, `pirila_bridge.php`, KILP.DAT-silta, Sync Manager); tarpeelliset tiedot (yhdistetyt Pirilä-asemat, viimeisin synkka, luonne) siirretty *Pirilä-asetukset*-sivulle; lähtöluenta hakee kilpailijan emit-koodilla JAHOnlinen kilpailijoista. HkKisaWin: Yhdistä-varapolun tilaviesti ilman ping-sanaa |
| 2026-10-16 | — | JAHOnline-palvelin: uuden kilpailun luonti omalla sivullaan (`admin/kilpailut.php?uusi=1`), kilpailulistassa painike *Lisää uusi kilpailu*; lista ei enää näytä luontilomaketta alla |
| 2026-10-16 | — | JAHOnline-palvelin: `luo_kilpailu` ja `yhdista` toteutettu; kisalla on luonne (hallinnan valinta *Kisan luonne*), joka valitsee näkyvät valikot: suunnistuksessa radat ja Emit-sivut, juoksussa ja hiihdossa ajanotto, ampumalajeissa ampumapaikka (dokumentti `docs/jahonline-docs/kisan-luonne.md`) |
| 2026-10-16 | — | JAHOnline API: *Testaa (ping)* on nyt *Yhdistä* (`action=yhdista`, vanhalle palvelimelle ping); yhdistetyt kisat ja API-avain muistetaan (`jahonline_yhdistetyt.ini`); uusi painike *Luo uusi JAHOnline-kisa* (näkyy, kun yhteys on luotu) luo netin kisan avatusta kilpailusta luonteella, joka tulee Pirilän kilpailulajista (suunnistus, hiihto, juoksu, …); ViestiWin: `ApiPaikallinenKello` siirretty yhteiseen `ApiYhteydet.cpp`:hen (puuttui ViestiWinistä) |
| 2026-10-12 | — | HkKisaWin: emit-väliaikatuloste: Rata-sarake näytetään vain, jos sarjan kilpailijat kiertävät eri ratoja (aiemmin aina, kun sarjan nimi ei ollut radan nimi). Muutos koskee myös vanhaa (ei-responsiivista) html- ja tekstitulostetta |
| 2026-10-02 | — | HkKisaWin: lähtöluettelo myös mobiili-HTML:nä (responsiivinen: sarakeluokat ja -leveydet, seura nimen alle kapealla näytöllä, ei sarjalinkkejä); Näytä heti ei enää avaa vanhaa tiedostoa, jos tulostus estettiin virheilmoituksella |
| 2026-09-29 | — | HkKisaWin: responsiivinen html-tulostetila (css-muotoilu *Responsiivinen*, mobiilitulosteen oletus): viewport, utf-8, sarakekohtaiset css-luokat, kapealla näytöllä toissijaiset sarakkeet piiloon ja seura nimen alle, vieritettävät väliaikataulukot, ei sarjalinkkejä eikä riviraidoitusta, pääsivu framesetin tilalle, html-merkkien koodaus |
| 2026-09-30 | — | Yhdistetty PirilaTP/main: Zebra FX9600 ja Sirit, lähtöaikojen ristiriitatarkistus, yhteistulossarakkeiden poisto, viestin CSV-tuonti ei merkitse HTIKEP-ratanimeä hylätyksi, kartan kaksoisleimat, GUI-käännös ja julkaisupaketti |
| 2026-09-21 | #70 | Joukkuetiedot: Peruuta toistuvien lisäysten tilassa jättää muokkaustilan päälle |
| 2026-09-21 | #68 | Kilpailijatiedot: epäonnistunut Tallenna jättää muokkaustilan päälle, jotta virheen voi korjata heti |
| 2026-09-18 | #67 | Kilpailijatiedot: sulku muokkaustilassa kysyy tallennusta ja palauttaa haku- ja katselutilan |
| 2026-09-18 | #66 | Maastossa olevat kilpailijat: taulukko ilman maaliaikaa olevista; HkKisaWin ja ViestiWin |
| 2026-09-20 | #30 | Maastossa (ViestiWin): listalla vain emit ja merkitty lähtöaika; suljettu aiempi osuus ei riitä ilman lähtöaikaa |
| 2026-09-20 | #29 | Maastossa (ViestiWin): ääkköset seuran, sarjan ja väliaikaotsikoiden näytössä (Seura/Sarjanimi/MbsToWcs) |
| 2026-09-19 | #28 | Joukkuetiedot: Salli muokkaus piilotetaan muokkaustilassa; pois muokkauksesta Tallenna, Peruuta tai Sulje |
| 2026-09-19 | #27 | ViestiWin: samannimiset dfm:t upotetaan UnitXxx_v.dfm -tiedostoina, ei cbHk-nimikaimoina (FormStatus.OnCreate EReadError) |
| 2026-09-19 | #26 | ViestiWin: ApiIntegration.h ei enää sisällytä HK:n ApiSaike.h:ta (bcc32 E2227 Extra parameter ApiIlmoitaTapahtuma) |
| 2026-09-19 | #25 | Kilpailijatiedot: Salli muokkaus piilotetaan muokkaustilassa; pois muokkauksesta Tallenna, Peruuta tai Sulje |
| 2026-09-19 | #24 | Kilpailijatiedot: Peruuta muutokset poistaa muokkaustilan (palaa haku- ja katseluun) |
| 2026-09-19 | #23 | Kilpailijatiedot: muokkaustilan sulkeminen kysyy tallennusta; epäonnistunut tallennus pitää ikkunan auki; uuden kilpailijan peruutus ei jätä dKilp=0 |
| 2026-09-19 | #23 | Maastossa: sarjan valuku määrää väliaikasarakkeet; statusselitteet yhteiseen tark_selite-funktioon (N = Ilmoittautunut säilyy) |
| 2026-07-28 | #50 | Ratatietoja: kartan kulmapisteiden muokkaus merkitsee nyt muutokset tallentamattomiksi — EdtMapLeft/Top/Right/Bottom- ja EdtScale-kentillä ei ollut OnChange-käsittelijää, joten pelkkien karttakalibrointitietojen muokkaus ei merkinnyt tietoja muuttuneiksi (rataMuutoksia). Tämä saattoi johtaa siihen, että käyttäjä sulki Ratatiedot-ikkunan ja muutokset katosivat tallentamatta ilman varoitusta. Korjattu lisäämällä yhteinen käsittelijä kaikille viidelle kentälle; koskee sekä HkKisaWiniä että ViestiWiniä, koska UnitRadat.cpp/h/dfm on jaettu koodi. |
| 2026-07-26 | #47 | 253 and 254 reader code fix — Emit-lukijakoodien 253 ja 254 käsittelyn korjaus relay-puolella |
| 2026-07-16 | — | Build & layout changes to Relay — Zebra-lukijatuki puuttui viestipuolelta; tulostuksen layout-muutoksia |
| 2026-07-16 | #43 | 42 support for zebra fx9600 rfid reader — Zebra FX9600 RFID-lukijatuki |
| 2026-07-16 | #40 | Show competitor number instead of OK text on accepted punch-check result — Näytetään kilpailijanumero "OK"-tekstin sijaan hyväksytyssä leimantarkistuksessa |
| 2026-07-13 | #41 | Fix cbHk/UnitSarja — Korjaus cbHk/UnitSarja.cpp:hen |
| 2026-06-28 | #34 | Fix badge registration for new relay team — Korjattu emit-badgen rekisteröinti uudelle viestijoukkueelle |
| 2026-06-26 | #29 | Fix piikkikuvio sequence numbering and red-marking for partio courses — Piikkikuvion juokseva numerointi ja punainen merkintä partioradoilla korjattu |
| 2026-06-26 | #25 | HenkHaku: fix sort order and Unicode search inconsistency — Henkilöhaun lajittelu ja Unicode-hakuepäjohdonmukaisuus korjattu |
| 2026-06-26 | #23 | print EI LÄHTIJÖITÄ for every empty start minute — "EI LÄHTIJÖITÄ" tulostetaan jokaiselle tyhjälle lähtöminuutille |
| 2026-06-24 | #31 | automatic file printing in viestiwin — Viestikisassa automaattinen tulostus HTML-muotoon; korjattu oletustila ja lisätty Sulje-painike |
| 2026-06-17 | — | Add optional free-text field (Lisäteksti) to start list and result printouts — Valinnainen vapaa tekstikenttä lähtö- ja tuloslistoihin |
| 2026-06-07 | #21 | fix: reading back split configurations — Korjattu CSV-tuonti (Luetekstitiedostosta1Click): kenttämäärän tarkistus ja lohkon offset olivat väärin, minkä takia kaikki rivit hylättiin ääneti |
| 2026-06-07 | #19 | Fix/pakotalaikaraja comparison — PAKOTALAIKARAJA-vertailu oli >= eikä <=, jolloin pakotettu lähtöaika tallentui vain kun ero ylitti rajan eikä kun se oli rajan sisällä |
| 2026-05-25 | #17 | UnitGAZ.dfm resize — Yhteydet-välilehden ruudukko ja painike venyvät nyt ikkunan koon mukaan |
| 2026-05-19 | #15 | wcstok ISO C11 — wcstok-kutsut päivitetty ISO C11 -muotoon (3-argumentti + ctx) koko koodikannassa; yhteensopivuusmakro bcc32:lle |
| 2026-05-14 | #13 | VInit.cpp: revert korostus.lst to hardcoded name — korostus.lst-nimi palautettu kovakoodatuksi |
| 2026-05-12 | — | CI: add manual console binary package workflow — manuaalinen konsoliversion paketointi CI:hin |
| 2026-05-13 | #12 | fix/getversionex-deprecated — Poistettu vanhentunut GetVersionEx Windows API -kutsu |
| 2026-05-13 | #11 | update_build_version_to_5.22 — HK:n versio päivitetty 5.22:een |
| 2026-05-12 | #10 | rsync_docs — Dokumentaatio: rsync-pohjainen automaattinen tulosten siirto palvelimelle |
| 2026-05-12 | #9 | fix/partio-readout-dialog — Korjattu leimantarkistuskaavake partiokisoissa |
| 2026-05-09 | #7 | korostus-file-with-paiva-number — korostus.lst nimetään päivämäärän mukaan (korostus1.lst, korostus2.lst ...) kun kilpailupäiviä on useita |
| 2026-05-11 | — | Update HkIV.cpp |
| 2026-05-10 | #6 | start-time-from-rfid-over-raspi — Lähtöaika tallennetaan RFID-lukijalta kun käytetään PAKOTALAIKA + LÄHDEPISTEET L-parametrilla |
| 2026-05-09 | #8 | fix/consolsize-error-87 — Korjattu konsoli-ikkunan koon asetus (Windows virhekoodi 87) |
| 2026-05-09 | — | Save start time — Lähtöajan tallennus |
| 2026-05-09 | — | Revert HkCom32.cpp: remove lahdepistehaku block from tark_aika |
| 2026-05-08 | — | haelahdepiste + tark_aika — haelahdepiste-funktion linkitys TU-moduulien välille ja tark_aika-integraatio |
| 2026-04-20 | #5 | docs-import — Olemassa oleva dokumentaatio tuotu repositorioon |
| 2026-04-20 | — | Remove orphaned VTulostus_1.cpp experimental fork |
| 2026-04-20 | — | Remove FTP distribution feature |
| 2026-04-18 | — | Updated readme |
| 2026-04-17 | #3 | remove-secure-bridge-dependency — Secure Bridge -riippuvuus poistettu |
| 2026-04-17 | #2 | piikikuviofiksi — Korjattu piikkien sijainnit suurilla emit-koodeilla (>176) |
| 2026-04-16 | #1 | ci/github-actions-build — GitHub Actions CI -buildi lisätty Windows-käännökselle |
| 2026-04-16 | #1 | Show-all-controls-same-emit-code — Karttanäkymässä näytetään kaikki rastitunnukset joilla on sama emit-koodi, kun rasti puuttuu |
| 2026-04-14 | — | Update HkInitVanha.cpp |
| 2026-04-14 | — | scandinavian letters |
| 2026-04-14 | — | Visual studio 2026 & SecureBridge for RAD Studio 12 |
| 2026-04-14 | — | Update README.md |
| 2026-04-14 | — | Päivitetty CLAUDE.md vastaamaan nykytilannetta |
| 2026-04-14 | — | Päivitetty README vastaamaan nykytilannetta (VS2022, C++ Builder Community) |
| 2026-04-14 | — | Lisätty suomenkieliset kommentit koko koodipohjaan ja korjattu VS2022 yhteensopivuus |

Ennen tätä jaksoa historia jatkuu suoraan projektin alkuperäiseen 2016 vuoden pohjaan
(mm. `Initial commit`, 2016-10-12), noin kymmenen vuoden tauon takana.
