const STORAGE_KEY = "jasenkortti-demo-v1";

export const SEED = {
  club: {
    id: "epsu",
    name: "Etelä-Pohjanmaan Suunnistus ry",
    city: "Seinäjoki",
    sport: "Suunnistus",
  },
  seasons: [
    {
      id: "jasen-2026",
      name: "Jäsenyys 2026",
      priceCents: 4500,
      validFrom: "2026-01-01",
      validTo: "2026-12-31",
    },
  ],
  members: [],
};

function uid(prefix) {
  return `${prefix}-${Math.random().toString(36).slice(2, 10)}`;
}

function emptyState() {
  return {
    club: { ...SEED.club },
    seasons: SEED.seasons.map((s) => ({ ...s })),
    members: [],
  };
}

export function loadState() {
  if (typeof window === "undefined") return emptyState();
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    if (!raw) {
      const seeded = emptyState();
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(seeded));
      return seeded;
    }
    const parsed = JSON.parse(raw);
    if (!parsed.club || !Array.isArray(parsed.seasons) || !Array.isArray(parsed.members)) {
      return emptyState();
    }
    parsed.club = { ...SEED.club };
    return parsed;
  } catch {
    return emptyState();
  }
}

export function saveState(state) {
  window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
}

export function formatEur(cents) {
  return `${(cents / 100).toFixed(2).replace(".", ",")} €`;
}

export function formatFiDate(iso) {
  if (!iso) return "";
  const [y, m, d] = iso.split("-");
  return `${d}.${m}.${y}`;
}

export function addSeason(state, { name, priceCents, validFrom, validTo }) {
  const season = {
    id: uid("kausi"),
    name: name.trim(),
    priceCents: Number(priceCents),
    validFrom,
    validTo,
  };
  const next = { ...state, seasons: [season, ...state.seasons] };
  saveState(next);
  return { state: next, season };
}

export function addMember(state, { seasonId, name, email }) {
  const season = state.seasons.find((s) => s.id === seasonId);
  if (!season) return { error: "Kautta ei löydy." };
  const token = uid("kortti");
  const member = {
    id: uid("jasen"),
    token,
    seasonId,
    name: name.trim(),
    email: email.trim(),
    paidAt: new Date().toISOString(),
  };
  const next = { ...state, members: [member, ...state.members] };
  saveState(next);
  return { state: next, member, season };
}

export function purchaseUrl(origin, seasonId) {
  return `${origin}/osta/${seasonId}`;
}

export function cardUrl(origin, token) {
  return `${origin}/kortti/${token}`;
}
