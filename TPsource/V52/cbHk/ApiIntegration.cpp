// Pekka Pirila's sports timekeeping program (Finnish: tulospalveluohjelma)
// Copyright (C) 2015 Pekka Pirila 

// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.

// You should have received a copy of the GNU General Public License
// along with this program.  If not, see <http://www.gnu.org/licenses/>.

//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop

#include "ApiIntegration.h"
#include "ApiSaike.h"

//---------------------------------------------------------------------------
#pragma package(smart_init)

TApiIntegration* TApiIntegration::pInstance = NULL;
TApiIntegration* gApiIntegration = NULL;

//---------------------------------------------------------------------------
TApiIntegration::TApiIntegration()
	: pSaike(NULL), onAloitettu(false), kilpailuAvattu(false)
{
}

//---------------------------------------------------------------------------
TApiIntegration::~TApiIntegration()
{
	Lopeta();
}

//---------------------------------------------------------------------------
TApiIntegration* TApiIntegration::GetInstance(void)
{
	if (pInstance == NULL) {
		pInstance = new TApiIntegration();
		gApiIntegration = pInstance;
	}
	return pInstance;
}

//---------------------------------------------------------------------------
void TApiIntegration::Alusta(void)
{
	if (!kilpailuAvattu)
		return;
	if (onAloitettu && pSaike)
		return;

	try {
		if (pSaike) {
			pSaike->PyynnoPysaytys();
			pSaike->WaitFor();
			delete pSaike;
			pSaike = NULL;
			onAloitettu = false;
		}
		pSaike = new TApiSaike(true);
		if (pSaike) {
			pSaike->FreeOnTerminate = false;
			pSaike->Resume();
			onAloitettu = true;
		}
	} catch (...) {
		onAloitettu = false;
		pSaike = NULL;
	}
}

void TApiIntegration::KilpailuAvattu(void)
{
	kilpailuAvattu = true;
	ApiConfigLataa();
	// Full sync resumes when this competition's ini has kaynnissa=1 (successful ping).
	// JAHOnline workstation transport (siirtotapa=1) also keeps the thread up.
	// Never start before a competition is open.
	if (apiconfig.kaynnissa || apiconfig.siirtotapa == 1)
		Alusta();
}

void TApiIntegration::KilpailuSuljettu(void)
{
	Lopeta();
	kilpailuAvattu = false;
}

void TApiIntegration::Lopeta(void)
{
	if (!pSaike) {
		onAloitettu = false;
		return;
	}

	try {
		pSaike->PyynnoPysaytys();
		pSaike->WaitFor();
		delete pSaike;
	} catch (...) {
		// Virhe pysäyttäessä
	}
	pSaike = NULL;
	onAloitettu = false;
}

//---------------------------------------------------------------------------
void TApiIntegration::AvaaMuokkausikkuna(HWND parentHandle)
{
	if (!FormApiYhteydet) {
		FormApiYhteydet = new TFormApiYhteydet(NULL);
	}
	
	try {
		FormApiYhteydet->ShowModal();
	} catch (...) {
		// Virhe ikkunan avaamisessa
	}
}

//---------------------------------------------------------------------------
