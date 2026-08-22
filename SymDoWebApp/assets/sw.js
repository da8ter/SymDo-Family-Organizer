/*
 * SymDo — Service Worker fuer Web Push.
 *
 * Ausgeliefert unter /hook/lists/pwa (ohne Endung, siehe PWA_HOOK_PATH in
 * AppCore.php). Damit ist sein Zustaendigkeitsbereich /hook/lists/ — er umfasst
 * die Seite /hook/lists/webapp und die API darunter.
 *
 * BEWUSST OHNE fetch-Handler. Der Bereich schliesst die token-gesicherte API mit
 * ein; jede Zwischenspeicherung wuerde Adressen mit `?t=<Token>` in
 * Cache-Schluessel schreiben. Ein Worker ohne fetch-Handler mischt sich in keine
 * einzige Anfrage ein und kostet nichts.
 *
 * Der Worker kennt kein Token und kann deshalb selbst nichts an die API melden.
 * Ein erneuertes Abo (pushsubscriptionchange) meldet die Seite beim naechsten
 * Start — sie hat den Token, er nicht.
 */

// Bei jeder Aenderung hochzaehlen: Die Datei wird mit `Cache-Control: no-cache`
// ausgeliefert, der Browser prueft sie also gegen — aber nur, wenn sich die Bytes
// unterscheiden, gilt sie als neue Fassung.
const SYMDO_SW_VERSION = 4;

const SYMDO_SEITE = '/hook/lists/webapp';
// Weissliste der Zielbereiche. Die Nutzlast kommt aus dem eigenen Haus, reist aber
// durch fremde Server — was hier nicht drinsteht, oeffnet einfach die Uebersicht.
const SYMDO_TABS = ['dashboard', 'ki', 'todos', 'shopping', 'calendar', 'notes'];

self.addEventListener('install', (event) => {
  // Sofort uebernehmen: Es gibt nichts zu warmzulaufen, und eine halbe Fassung
  // (alter Worker, neue Seite) waere nur eine Fehlerquelle.
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

/**
 * Eine eingehende Nachricht anzeigen.
 *
 * Es MUSS in jedem Fall eine Anzeige entstehen — auch bei kaputter Nutzlast.
 * Chrome zeigt sonst von sich aus „Diese Seite wurde im Hintergrund
 * aktualisiert", und Safari kann einer App das Abo entziehen, die eine Nachricht
 * still verschluckt.
 */
self.addEventListener('push', (event) => {
  event.waitUntil((async () => {
    let daten = {};
    try {
      daten = event.data ? event.data.json() : {};
    } catch (e) {
      daten = {};
    }
    const titel = (typeof daten.title === 'string' && daten.title.trim() !== '')
      ? daten.title
      : 'SymDo';
    const text = (typeof daten.body === 'string') ? daten.body : '';
    const tab = SYMDO_TABS.includes(daten.tab) ? daten.tab : '';
    // Zahl aufs App-Symbol. Muss HIER stehen und nicht in der Seite: wenn ein
    // Vorschlag hereinkommt, ist die App zu — die Seite kaeme erst beim naechsten
    // Oeffnen dazu, und bis dahin zeigte das Symbol nichts.
    if (typeof daten.badge === 'number' && daten.badge >= 0 && 'setAppBadge' in navigator) {
      try {
        const b = daten.badge > 0 ? navigator.setAppBadge(daten.badge) : navigator.clearAppBadge();
        if (b && typeof b.catch === 'function') b.catch(() => {});
      } catch (e) {}
    }
    // Bausteine, die die Nutzlast mitbringen DARF. Alles Optionale: fehlt es,
    // bleibt es bei der Vorgabe. Adressen muessen aus dem EIGENEN Haus kommen —
    // die Nutzlast reist durch fremde Server, und ein Verweis auf einen fremden
    // Host in einer Benachrichtigung waere ein Datenabfluss.
    const eigen = (w) => typeof w === 'string' && w.startsWith('/') && !w.startsWith('//');
    const optionen = {
      body: text,
      icon: eigen(daten.icon) ? daten.icon : SYMDO_SEITE + '/appicon-180.png',
      badge: SYMDO_SEITE + '/appicon-32.png',
      // Gleiche Kennung ersetzt statt zu stapeln: Zwei Erinnerungen an dieselbe
      // Aufgabe sollen nicht zweimal auf dem Sperrbildschirm liegen.
      tag: typeof daten.tag === 'string' && daten.tag !== '' ? daten.tag : undefined,
      data: { tab: tab },
    };
    if (eigen(daten.image)) { optionen.image = daten.image; }
    if (typeof daten.renotify === 'boolean') { optionen.renotify = daten.renotify; }
    if (typeof daten.requireInteraction === 'boolean') { optionen.requireInteraction = daten.requireInteraction; }
    if (typeof daten.silent === 'boolean') { optionen.silent = daten.silent; }
    if (Array.isArray(daten.vibrate)) { optionen.vibrate = daten.vibrate.slice(0, 8).map(Number).filter(n => n >= 0); }
    if (typeof daten.timestamp === 'number') { optionen.timestamp = daten.timestamp; }
    // Knoepfe: hoechstens zwei (mehr zeigt kein System) und nur mit Text.
    if (Array.isArray(daten.actions)) {
      const knoepfe = daten.actions
        .filter(a => a && typeof a.action === 'string' && typeof a.title === 'string')
        .slice(0, 2)
        .map(a => ({ action: a.action, title: a.title }));
      if (knoepfe.length > 0) { optionen.actions = knoepfe; }
    }
    try {
      await self.registration.showNotification(titel, optionen);
    } catch (e) {
      // Ein System, das eine der Optionen nicht kennt, kann werfen. Dann lieber
      // schmucklos als gar nicht — der Text ist die Nachricht.
      await self.registration.showNotification(titel, { body: text, data: { tab: tab } });
    }
  })());
});

/**
 * Antippen: ein offenes Fenster nach vorne holen und den Bereich mitgeben, sonst
 * die App oeffnen. Ein bestehendes Fenster zu wecken ist immer besser als ein
 * zweites zu oeffnen — in der Home-Screen-App gibt es ohnehin nur eines.
 */
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  // „dismiss" heisst wegklicken: Meldung schliessen und NICHT die App oeffnen.
  // Jeder andere Knopf verhaelt sich wie ein Tipp auf die Meldung selbst.
  if (event.action === 'dismiss') { return; }
  const tab = (event.notification.data && SYMDO_TABS.includes(event.notification.data.tab))
    ? event.notification.data.tab
    : '';
  event.waitUntil((async () => {
    const fenster = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of fenster) {
      if (client.url.indexOf(SYMDO_SEITE) === -1) continue;
      try { client.postMessage({ type: 'symdoTab', tab: tab }); } catch (e) {}
      if ('focus' in client) return client.focus();
    }
    const ziel = tab === '' ? SYMDO_SEITE : SYMDO_SEITE + '?tab=' + encodeURIComponent(tab);
    return self.clients.openWindow(ziel);
  })());
});

/**
 * Der Browser hat das Abo erneuert. Melden kann das nur die Seite — der Worker
 * hat keinen Token. Damit die Luecke nicht stumm bleibt, wird das neue Abo hier
 * zumindest angelegt; die Seite liest es beim naechsten Start aus der
 * Registrierung und schickt es an die API.
 */
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil((async () => {
    try {
      const alt = event.oldSubscription || await self.registration.pushManager.getSubscription();
      const schluessel = alt && alt.options ? alt.options.applicationServerKey : null;
      if (!schluessel) return;
      await self.registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: schluessel,
      });
    } catch (e) {
      // Ohne neues Abo bleibt es beim naechsten Start der Seite: Die legt es an.
    }
  })());
});
