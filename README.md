# WOAR Monitor

Drupal-Modul für Drupal 10 und 11. Stellt einen tokengeschützten JSON-Endpunkt
bereit, über den die Monitoring-Zentrale den Zustand der Website abfragt.

Das Modul liest. Es verändert nichts an der Website, nimmt keine Befehle
entgegen und kann nichts ausführen.

Eine einzige Ausnahme, der Vollständigkeit halber: Bei einer erfolgreichen
Abfrage vermerkt es einen Zeitstempel, wann die Zentrale zuletzt Daten geholt
hat — höchstens einmal je Minute. Er beantwortet auf der Kundenseite die Frage
"holt die Zentrale hier überhaupt etwas ab?", ohne dass man dafür in die
Zentrale schauen muss. Geschrieben wird nur die Uhrzeit, nichts, was von außen
beeinflussbar wäre.

## Was der Endpunkt liefert

    GET /woar-monitor/status
    Authorization: Bearer <token>

```json
{
  "schema": 3,
  "agent_version": "1.2.0",
  "collected_at": "2026-08-20T06:52:16+00:00",
  "drupal":  { "core_version": "10.6.12", "maintenance_mode": false },
  "php":     { "version": "8.3.31" },
  "cron":    { "last_run_at": "2026-08-20T06:52:14+00:00" },
  "updates": {
    "available": true,
    "last_checked_at": "2026-08-20T06:52:16+00:00",
    "security_count": 1,
    "update_count": 8,
    "projects": [
      {
        "name": "drupal",
        "type": "core",
        "current_version": "10.6.12",
        "recommended_version": "10.6.15",
        "status": "not_secure",
        "is_security_update": true,
        "has_update": true,
        "includes": ["node", "user", "views"]
      }
    ]
  },
  "forms": {
    "available": true,
    "by_month": { "2026-08": 20, "2026-07": 14 },
    "by_form": {
      "kontaktformular": {
        "title": "Kontaktformular",
        "by_month": { "2026-08": 12, "2026-07": 9 }
      },
      "newsletter": {
        "title": "Newsletter",
        "by_month": { "2026-08": 8, "2026-07": 5 }
      }
    }
  }
}
```

### Zu `forms`

Gezählt werden abgeschickte Webform-Einsendungen der letzten 24 Monate,
Entwürfe bleiben außen vor. **Nur Anzahlen — keine Inhalte, keine Namen, keine
E-Mail-Adressen, keine Zeitpunkte einzelner Einsendungen.** Aus einer Zahl pro
Monat lässt sich nichts über eine einzelne Person erfahren.

`by_month` ist die Summe über alle Formulare, `by_form` dieselben Zahlen
getrennt nach Formular. Die Aufschlüsselung gibt es, weil eine
Newsletter-Anmeldung keine Anfrage ist: In der Zentrale wird je Website
angehakt, welche Formulare als Anfrage zählen. Ohne diese Trennung stünde
später eine Zahl im Kundenbericht, die bei der ersten Rückfrage auseinanderfällt.

Fehlt das Modul `webform`, steht `available: false` und beide Listen sind leer.
Das ist kein Fehler — nicht jede Website hat Formulare.

Was der Endpunkt **nicht** liefert: Pfade, Datenbankangaben, Benutzer,
E-Mail-Adressen, den Sitenamen, geladene PHP-Erweiterungen, Konfigurationswerte.
Wer die Antwort abfängt, erfährt Versionsnummern und Modulnamen — mehr nicht.

## Einrichtung in einem Satz

Modul kopieren, aktivieren, im Monitor die Website anlegen und dort auf
**Verbinden** klicken. Den achtstelligen Code holst du dir unter
*Konfiguration → System → WOAR Monitor → Verbinden vorbereiten*. Fertig — ein
Token bekommst du nie zu Gesicht.

## Wie die Kopplung funktioniert

    Monitor                                Kundenwebsite
       │                                        │
       │                      1. Mensch klickt "Verbinden vorbereiten"
       │                         → Code ABCD2345, gültig 15 Minuten
       │                                        │
       │  2. POST /woar-monitor/claim           │
       │     { "code": "ABCD2345" }             │
       │ ─────────────────────────────────────► │
       │                                        │ prüft Code, erzeugt Token,
       │                                        │ verbraucht den Code
       │  3. { "token": "..." }                 │
       │ ◄───────────────────────────────────── │
       │                                        │
    speichert das Token

Zwei Dinge, die dieser Weg richtig macht:

**Es gibt kein gemeinsames Geheimnis.** Läge auf allen betreuten Websites
derselbe Schlüssel, wäre eine kompromittierte Website der Schlüssel zu allen
anderen — ein Schlüssel, dreißig Türen. Hier wird jede Kopplung einzeln über
einen Code freigegeben, der 15 Minuten gilt und genau einmal zieht. Er liegt
nur als Hash in der Datenbank.

**Die Zentrale muss nicht erreichbar sein.** Sie ruft an, sie wird nicht
angerufen. Sie darf hinter einer Firewall stehen. (Ist sie ohnehin öffentlich
erreichbar, ändert das an der Kopplung nichts.)

Alle Versuche, geglückte wie abgewiesene, stehen im Drupal-Protokoll unter
`woar_monitor`. Zehn Versuche je Stunde und IP-Adresse, danach ist zu.

## Einrichtung im Einzelnen (alte Fassung)

Modulordner kopieren, Modul aktivieren, fertig. Die Website erzeugt sich beim
nächsten Cron-Lauf selbst ein Token und meldet sich bei der Zentrale an. Dort
gibst du sie mit einem Klick frei. Es wird **nie** ein Token von Hand kopiert.

## Wie die Selbstanmeldung funktioniert

    1. Website erzeugt sich ein eigenes, zufälliges Token (64 Zeichen)
       │
       ├─ 2. meldet der Zentrale: Kennung, Adresse, Token
       │     (ausgewiesen durch das gemeinsame Anmeldegeheimnis)
       │
       ▼
    3. Zentrale ruft zurück: Antwortet unter dieser Adresse wirklich
       diese Installation mit genau diesem Token?
       │
       ▼
    4. Du gibst frei — ein Klick. Ab jetzt wird überwacht.

Warum der Rückruf: Ohne ihn könnte jeder, der das Anmeldegeheimnis besitzt, eine
beliebige fremde Adresse in die Liste schreiben. Mit ihm kommt nur durch, wer
unter der angegebenen Adresse tatsächlich dieses Modul mit diesem Token betreibt.

Warum die Freigabe von Hand: Damit in deiner Übersicht ausschließlich steht, was
du selbst aufgenommen hast — gerade, wenn du sie im Kundengespräch aufmachst.

**Das gemeinsame Anmeldegeheimnis liegt im Klartext im Modulordner auf jeder
betreuten Website.** Das ist der bewusst in Kauf genommene Preis für die
Bequemlichkeit. Wer es in die Finger bekommt, kann bei deiner Zentrale
*behaupten*, eine neue Website zu sein — mehr nicht. Er kann damit **keine**
Daten irgendeiner Kundenseite lesen, denn dafür braucht es das
seitenindividuelle Token, das jede Installation sich selbst erzeugt und das
niemals geteilt wird. Der schlimmste Fall ist ein Fantasieeintrag in deiner
Liste, den du wegklickst. Wenn du das Geheimnis wechseln willst: in der `.env`
der Zentrale ändern und die `central.settings.php` neu ausrollen.

## Verteilen über Composer (empfohlen)

Statt den Ordner in jedes Projekt zu kopieren, zu committen und auf dem Server
zu pullen: Das Modul bekommt ein eigenes Git-Repository, und jedes Projekt zieht
es sich als Abhängigkeit.

**Einmalig:** Repository anlegen und das Modul hineinschieben.

    cd drupal-module
    git add -A && git commit -m "WOAR Monitor 1.0.0"
    git remote add origin git@github.com:DEINKONTO/woar_monitor.git
    git push -u origin main
    git tag v1.0.0 && git push --tags

Das Repository darf öffentlich sein — im Modul steht kein Geheimnis. Bei einem
privaten Repository braucht jeder Server, der `composer install` ausführt, einen
Lesezugriff (Deploy-Key oder Token).

**Je Projekt einmalig:**

    composer config repositories.woar_monitor vcs git@github.com:DEINKONTO/woar_monitor.git
    composer require woar/woar_monitor:^1.0

Composer legt es unter `web/modules/custom/woar_monitor/` ab — das ergibt sich
aus den `installer-paths` deiner Projekte. Anschließend wie gewohnt aktivieren.

**Bei jeder neuen Fassung:** im Modul-Repository committen und einen neuen Tag
setzen (`git tag v1.0.1 && git push --tags`), dann in den Projekten:

    composer update woar/woar_monitor
    drush cr

## Beim Deploy: Cache leeren ist Pflicht, nicht Kür

    git pull
    composer install
    ./vendor/bin/drush cr     # <- ohne das ist die Website kaputt

Der letzte Schritt ist keine Empfehlung. Verschiebt sich der Modulordner oder
ändert sich eine Dienstdefinition, passt Drupals kompilierter Container nicht
mehr zum Code — und **jeder Aufruf, der nicht aus dem Seiten-Cache kommt,
scheitert mit einem Fehler 500**.

Das ist tückisch, weil die Startseite dabei gesund aussieht: Sie wird
zwischengespeichert ausgeliefert, während `/user/login` und der Adminbereich
längst tot sind. Wer nur die Startseite prüft, hält den Deploy für geglückt.

Ohne Drush im Pfad:

    php vendor/bin/drush cr

Bricht Drush selbst ab — auch das kommt vor, wenn der Container so kaputt ist,
dass Drush ihn nicht laden kann —, hilft nur der Holzhammer: den Ordner
`web/sites/default/files/php` löschen. Darin steht ausschließlich Erzeugtes,
Drupal baut ihn beim nächsten Aufruf neu.

**Nach dem Deploy kurz prüfen**, und zwar nicht die Startseite:

    curl -o /dev/null -w "%{http_code}\n" https://deine-seite.de/user/login

Kein Kopieren mehr, und du siehst in jeder `composer.lock`, welche Fassung auf
welcher Website liegt.

**Achtung beim Umstieg:** Liegt das Modul in einem Projekt schon von Hand unter
`web/modules/custom/woar/woar_monitor/`, muss dieser Ordner weg, bevor Composer
seine Fassung ablegt. Sonst kennt Drupal das Modul zweimal und meldet einen
Fehler.

## Installation im Einzelnen

### 1. Dateien

Ordner nach `web/modules/custom/woar/woar_monitor/` kopieren. Kein Composer,
keine Abhängigkeit außer dem Drupal-Kernmodul `update`.

Bei Websites ohne SSH-Zugang geht das über den Dateimanager des Hosters oder
per FTP. **Hinweis für Drupal 11:** Dort gibt es die Oberfläche zum Hochladen
von Modulen nicht mehr — ohne Dateizugriff lässt sich das Modul dort nicht
installieren.

### 2. Aktivieren

    drush pm:install woar_monitor

Oder unter *Erweitern* im Adminbereich.

### 3. Warten oder anstoßen

Beim nächsten Cron-Lauf meldet sich die Website von selbst. Wer nicht warten
will: *Konfiguration → System → WOAR Monitor* → **Jetzt anmelden**.

Dort steht auch, was beim letzten Versuch herauskam — die erste Stelle, an der
man nachsieht, wenn eine Website nicht in der Zentrale auftaucht.

### 4. In der Zentrale freigeben

Unter *Anmeldungen* steht die Website mit dem Vermerk „Wartet auf Freigabe".
Kunde zuordnen, freigeben, fertig.

Entwicklungsumgebungen melden sich absichtlich **nicht** an: Adressen auf
`.ddev.site`, `.local`, `.test`, `.localhost` und private IP-Bereiche werden
übersprungen. Soll eine solche Adresse doch überwacht werden — etwa ein
erreichbares Staging —, dann in `settings.php`:

    $settings['woar_monitor_allow_local_enrollment'] = TRUE;

## Websites, die aus derselben Vorlage stammen

Entstehen deine Projekte aus einer Boilerplate, tragen sie alle dieselbe
Drupal-Installationskennung. Das ist kein Versehen: Die Kennung steht in
`config/sync/system.site.yml`, wandert also über Git mit — und genau deshalb
lässt sich Konfiguration zwischen den Projekten überhaupt austauschen.

Für das Token ist sie deshalb nutzlos. Das Token ist stattdessen an die
**Domain** gebunden, unter der es vergeben wurde. Taucht dasselbe Token später
unter einer anderen Domain auf — weil ein Datenbankabzug in ein anderes
Projekt gewandert ist —, gilt es dort nicht. Der Endpunkt bleibt zu, im
Statusbericht steht der Grund, und im Monitor genügt ein Klick auf
*Neu verbinden*.

`www.example.de` und `example.de` gelten dabei als dieselbe Website.

## Schutzmaßnahmen im Einzelnen

| Maßnahme | Wirkung |
|---|---|
| Token nur im `Authorization`-Kopf | Adressen landen in Zugriffsprotokollen und Proxys, Kopfzeilen nicht |
| Vergleich mit `hash_equals` | Kein Rückschluss auf das Token über Laufzeitunterschiede |
| Ratenbegrenzung, 60 Abrufe je Stunde und IP | Der Endpunkt taugt nicht als Werkzeug |
| Fehlversuchssperre, 5 je Stunde und IP | Nach fünf Fehlschlägen wird auch das richtige Token abgewiesen |
| Optionale IP-Freigabeliste | Zusätzliche Einschränkung, siehe Warnung unten |
| Bindung an die Installation | Kopierte Datenbank führt nicht zu geteilten Token |
| Nur GET | Schreibende Methoden werden mit 405 abgewiesen |
| `no-store`, `X-Robots-Tag: noindex` | Antwort wird nirgends zwischengespeichert oder indexiert |
| Immer dieselbe Abweisung | Von außen ist nicht erkennbar, woran es lag |
| `hook_uninstall` löscht das Token | Kein vergessenes Zugangsgeheimnis in der Datenbank |
| Token verlässt die Website nur einmal | Beim Anmelden, über HTTPS, zur eigenen Zentrale |

**Zur IP-Freigabeliste:** Hinter einem Reverse Proxy oder CDN ist die erkannte
IP-Adresse nur dann echt, wenn in `settings.php` `reverse_proxy` und
`reverse_proxy_addresses` korrekt gesetzt sind. Sonst lässt sie sich fälschen
und die Liste ist eine Beruhigung ohne Wirkung. Der eigentliche Schutz ist und
bleibt das Token.

## Warum der Endpunkt keine Update-Daten abholt

Der Endpunkt liest nur den Stand, den Drupal ohnehin zwischengespeichert hat
(`update_get_available(FALSE)`). Er stößt **keine** Abfrage bei drupal.org an.

Andernfalls würde jeder Abruf ausgehende Anfragen auslösen — langsam, und ein
Hebel für jeden, der die Adresse kennt, um über die Kundenseite fremde Server zu
belasten. Das Auffrischen bleibt Sache des Drupal-Cron.

Deshalb liefert der Endpunkt `last_checked_at` mit. Läuft der Cron auf der
Website nicht, sieht die Zentrale „Update-Daten sind 9 Tage alt" statt zu
behaupten, es sei alles in Ordnung. Ein stehender Cron ist einer der häufigsten
Gründe dafür, dass ein Sicherheitsupdate unbemerkt bleibt.

## Was `is_security_update` bedeutet

`true` bei den Zuständen `not_secure` (eingesetzte Fassung hat eine bekannte
Lücke) und `revoked` (Veröffentlichung wurde zurückgezogen, meist aus demselben
Grund).

`not_supported` — die Version wird nicht mehr gepflegt — zählt bewusst **nicht**
als Sicherheitsupdate, sondern nur als vorhandenes Update. Dringend, aber nicht
dasselbe wie eine bekannte Lücke.

## Fehlersuche

**403 trotz richtigem Token.** Kann nicht mehr an der Fehlversuchssperre liegen:
Das Token wird vor der Sperre geprüft, wer es richtig vorzeigt kommt immer
durch. Wahrscheinlicher ist, dass der Monitor ein veraltetes Token hat — dann
im Monitor auf *Neu verbinden* klicken.

Frühere Fassungen prüften die Sperre zuerst. Stand im Monitor einmal ein
veraltetes Token, sammelten seine automatischen Abfragen genug Fehlversuche an,
um sich selbst für eine Stunde auszusperren. Wer noch eine solche Fassung im
Einsatz hat: Modul aktualisieren.

**403 direkt nach dem Umzug in ein neues Projekt.** Die Installationsbindung.
Löst sich beim nächsten Cron-Lauf von selbst, oder sofort über *Jetzt anmelden*.

**Läuft die Anbindung?** Unter *Konfiguration → System → WOAR Monitor* steht
bei *Letzte Abfrage durch die Zentrale* ein Zeitpunkt. Steht dort einer, ist
alles in Ordnung — dann hat die Zentrale gerade Daten geholt. Das Feld darunter
zeigt nur, was diese Website beim Anmelden erlebt hat; ob die Anmeldung
inzwischen freigegeben wurde, erfährt sie von sich aus nie.

**Die Website taucht nicht in der Zentrale auf.** Unter *Konfiguration → System
→ WOAR Monitor* steht beim letzten Versuch, woran es lag: keine Zentrale
hinterlegt, Entwicklungsumgebung erkannt, Zentrale nicht erreichbar oder
Anmeldegeheimnis abgelehnt.

**`"available": false`.** Der Drupal-Cron hat die Update-Daten noch nicht
geholt. `drush cron` ausführen und prüfen, ob der Cron auf der Website
überhaupt regelmäßig läuft.

Alle abgewiesenen Zugriffe stehen mit Grund im Drupal-Protokoll unter dem Typ
`woar_monitor`.
