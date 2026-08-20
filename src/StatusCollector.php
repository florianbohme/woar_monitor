<?php

declare(strict_types=1);

namespace Drupal\woar_monitor;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\update\UpdateFetcherInterface;
use Drupal\update\UpdateManagerInterface;

/**
 * Trägt die Auskunft zusammen, die der Endpunkt ausliefert.
 *
 * Zwei Grundsätze, von denen nicht abgewichen werden darf:
 *
 * 1. Es wird nur gelesen. Keine Methode hier verändert etwas am System.
 *
 * 2. Es wird nichts über die Umgebung preisgegeben. Keine Pfade, keine
 *    Datenbankangaben, keine Benutzer, keine E-Mail-Adressen, kein Sitename,
 *    keine geladenen PHP-Erweiterungen. Was hier hinausgeht, ist:
 *    Versionsnummern, Modulnamen, Update-Stand, Zeitpunkte. Nichts davon hilft
 *    jemandem beim Einbruch, der nicht ohnehin schon drin ist.
 */
final class StatusCollector {

  /**
   * Fassung des Datenformats.
   *
   * Erhöhen, sobald sich die Struktur ändert. Die Zentrale kann daran
   * erkennen, ob sie mit einem älteren Modul spricht.
   */
  public const SCHEMA_VERSION = 1;

  public function __construct(
    private readonly StateInterface $state,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ModuleExtensionList $moduleList,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Die vollständige Auskunft als Datenfeld.
   */
  public function collect(): array {
    return [
      'schema' => self::SCHEMA_VERSION,
      // Kennung dieser Drupal-Installation. Kein Geheimnis — sie steht in
      // jeder exportierten Konfiguration — aber sie erlaubt der Zentrale
      // festzustellen, dass hier wirklich die Installation antwortet, die
      // sich angemeldet hat. Ohne sie ließe sich beim Anmelden die Adresse
      // einer fremden Website angeben.
      'site_uuid' => (string) $this->configFactory->get('system.site')->get('uuid'),
      'agent_version' => $this->modulVersion(),
      'collected_at' => gmdate('c'),
      'drupal' => [
        'core_version' => \Drupal::VERSION,
        'maintenance_mode' => (bool) $this->state->get('system.maintenance_mode', FALSE),
      ],
      'php' => [
        // Nur die Versionsnummer. Ausdrücklich nicht php_uname(), nicht die
        // Liste der Erweiterungen und nicht der Pfad zur php.ini.
        'version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
      ],
      'cron' => [
        'last_run_at' => $this->zeitpunkt((int) $this->state->get('system.cron_last', 0)),
      ],
      'updates' => $this->updates(),
    ];
  }

  /**
   * Update-Stand aus den Bordmitteln von Drupal.
   *
   * Wichtig: `update_get_available(FALSE)` — ausdrücklich ohne Auffrischung.
   *
   * Würde hier mit TRUE aufgefrischt, löste jeder Abruf des Endpunkts
   * Anfragen an drupal.org aus. Das wäre langsam, würde die Antwortzeit
   * verdoppeln und gäbe jedem, der die Adresse kennt, einen Hebel, um über die
   * Kundenseite fremde Server zu belasten. Das Auffrischen ist Sache des
   * Drupal-Cron, so wie bei jeder anderen Seite auch.
   *
   * Sind die Daten alt oder fehlen sie, wird genau das gemeldet. Die Zentrale
   * zeigt dann "Daten sind X Tage alt" statt zu behaupten, alles sei gut —
   * denn ein stehender Cron ist einer der häufigsten Gründe dafür, dass ein
   * Sicherheitsupdate unbemerkt bleibt.
   */
  private function updates(): array {
    if (!$this->moduleHandler->moduleExists('update')) {
      return [
        'available' => FALSE,
        'reason' => 'Das Modul "update" ist nicht aktiviert.',
        'last_checked_at' => NULL,
        'security_count' => 0,
        'update_count' => 0,
        'projects' => [],
      ];
    }

    $this->moduleHandler->loadInclude('update', 'inc', 'update.compare');

    $verfuegbar = update_get_available(FALSE);
    $projektdaten = update_calculate_project_data($verfuegbar);

    $projekte = [];
    $sicherheitszaehler = 0;
    $updatezaehler = 0;

    foreach ($projektdaten as $name => $projekt) {
      $status = (int) ($projekt['status'] ?? UpdateFetcherInterface::UNKNOWN);
      $istSicherheit = $this->istSicherheitsupdate($status);
      $hatUpdate = $this->hatUpdate($status);

      if ($istSicherheit) {
        $sicherheitszaehler++;
      }

      if ($hatUpdate) {
        $updatezaehler++;
      }

      $projekte[] = [
        'name' => (string) $name,
        'title' => $this->text($projekt['title'] ?? $name),
        'type' => $this->text($projekt['project_type'] ?? 'unknown'),
        'current_version' => $this->textOderNull($projekt['existing_version'] ?? NULL),
        'recommended_version' => $this->textOderNull($projekt['recommended'] ?? NULL),
        'status' => $this->statusName($status),
        'is_security_update' => $istSicherheit,
        'has_update' => $hatUpdate,
        // Welche Module gehören zu diesem Projekt. Nützlich, um an einem
        // Sicherheitsmittwoch in Sekunden zu sehen, welche Kunden betroffen
        // sind. Nur Maschinennamen, keine Pfade.
        'includes' => array_values(array_map(
          fn ($wert): string => $this->text($wert),
          array_keys((array) ($projekt['includes'] ?? []))
        )),
      ];
    }

    $letztePruefung = (int) $this->state->get('update.last_check', 0);

    return [
      'available' => $verfuegbar !== [],
      'last_checked_at' => $this->zeitpunkt($letztePruefung),
      'security_count' => $sicherheitszaehler,
      'update_count' => $updatezaehler,
      'projects' => $projekte,
    ];
  }

  /**
   * Gilt dieser Zustand als Sicherheitsupdate?
   *
   * NOT_SECURE ist der klare Fall: Die eingesetzte Fassung hat eine bekannte
   * Lücke. REVOKED bedeutet, dass die eingesetzte Veröffentlichung
   * zurückgezogen wurde — in aller Regel aus demselben Grund. Beides gehört
   * sofort auf den Tisch.
   *
   * NOT_SUPPORTED wird bewusst nicht als Sicherheitsupdate gezählt, sondern
   * nur als vorhandenes Update: Es heißt "wird nicht mehr gepflegt", was
   * dringend ist, aber nicht dasselbe wie eine bekannte Lücke.
   */
  private function istSicherheitsupdate(int $status): bool {
    return in_array($status, [
      UpdateManagerInterface::NOT_SECURE,
      UpdateManagerInterface::REVOKED,
    ], TRUE);
  }

  /**
   * Liegt für dieses Projekt überhaupt ein Update vor?
   */
  private function hatUpdate(int $status): bool {
    return in_array($status, [
      UpdateManagerInterface::NOT_SECURE,
      UpdateManagerInterface::REVOKED,
      UpdateManagerInterface::NOT_SUPPORTED,
      UpdateManagerInterface::NOT_CURRENT,
    ], TRUE);
  }

  /**
   * Statuszahl in einen sprechenden Namen übersetzen.
   *
   * Die Zentrale soll sich nicht auf Zahlenwerte von Drupal-Konstanten
   * verlassen müssen.
   */
  private function statusName(int $status): string {
    return match ($status) {
      UpdateManagerInterface::NOT_SECURE => 'not_secure',
      UpdateManagerInterface::REVOKED => 'revoked',
      UpdateManagerInterface::NOT_SUPPORTED => 'not_supported',
      UpdateManagerInterface::NOT_CURRENT => 'not_current',
      UpdateManagerInterface::CURRENT => 'current',
      UpdateFetcherInterface::NOT_CHECKED => 'not_checked',
      UpdateFetcherInterface::NOT_FETCHED => 'not_fetched',
      UpdateFetcherInterface::FETCH_PENDING => 'fetch_pending',
      default => 'unknown',
    };
  }

  /**
   * Fassung dieses Moduls, damit die Zentrale veraltete Agenten erkennt.
   */
  private function modulVersion(): string {
    try {
      $info = $this->moduleList->getExtensionInfo('woar_monitor');
    }
    catch (\Throwable) {
      return 'unbekannt';
    }

    return $this->text($info['version'] ?? 'unversioniert');
  }

  /**
   * Zeitpunkt als ISO-8601 oder NULL.
   */
  private function zeitpunkt(int $zeitstempel): ?string {
    return $zeitstempel > 0 ? gmdate('c', $zeitstempel) : NULL;
  }

  /**
   * Wert als kurzen, einfachen Text.
   *
   * Übersetzbare Objekte werden zu Zeichenketten, alles wird gekürzt. Damit
   * kann kein unerwarteter Inhalt und keine unerwartete Größe in die Antwort
   * geraten.
   */
  private function text(mixed $wert): string {
    if (is_object($wert) && method_exists($wert, '__toString')) {
      $wert = (string) $wert;
    }

    if (!is_scalar($wert)) {
      return '';
    }

    return mb_substr((string) $wert, 0, 128);
  }

  /**
   * Wie text(), gibt aber NULL statt einer leeren Zeichenkette zurück.
   */
  private function textOderNull(mixed $wert): ?string {
    $text = $this->text($wert);

    return $text === '' ? NULL : $text;
  }

}
