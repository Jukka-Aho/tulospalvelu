#ifndef ApiSaikeH
#define ApiSaikeH

#include <Classes.hpp>
#include "ApiYhteydet.h"

class TApiSaike : public TThread
{
private:
	bool pysaytysPyynnon;
	int viiveMs;
	UnicodeString viestiJono;

	void __fastcall Kasittele(void);
	void __fastcall Paivita(const UnicodeString msg, bool virhe = false);
	void __fastcall LisaaMemo(void);
	void __fastcall PaivitaOsanottajat(void);

protected:
	void __fastcall Execute(void);

public:
	__fastcall TApiSaike(bool CreateSuspended);
	__fastcall ~TApiSaike(void);

	void PyynnoPysaytys(void);
	bool OnAktiivinen(void) const { return !pysaytysPyynnon; }
};

// Manual / thread entry points
int ApiSynkkaaLahetaKaikki(void);
int ApiSynkkaaHaeKaikki(bool korvaaKentat = false);
UnicodeString ApiRakennaKilpailijatJson(void);
int ApiSovellaKilpailijatJson(const UnicodeString& json, bool korvaaKentat = false);

// Emit-luenta: jonoita kilpailija JAHOnline-läsnäolosynkkaan
void ApiIlmoitaLasna(int kilpno);

// Osanottajat-ruudukko, kun synkka muutti rivejä
void ApiPaivitaOsanottajaNakyma(void);
UnicodeString ApiPaikallinenKello(void);

// Online-rasti / väliaika (piste 0 = maali, 1+ = online)
void ApiIlmoitaTapahtuma(int kilpno, int piste, int aikaSec);
int ApiLahetaTapahtumatNyt(void);

#endif
