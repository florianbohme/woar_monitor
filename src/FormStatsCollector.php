<?php

declare(strict_types=1);

namespace Drupal\woar_monitor;

use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Zählt eingegangene Formularanfragen je Monat.
 *
 * Der Zweck: einen belastbaren Nullpunkt liefern. Wie viele Anfragen eine
 * Website tatsächlich erzeugt, weiß sonst niemand — Analytics zählt nur die
 * Besucher, die eingewilligt haben, und die Erinnerung des Kunden ist
 * regelmäßig zu optimistisch. In der Formulartabelle steht die Wahrheit,
 * rückwirkend und unabhängig von jeder Einwilligung.
 *
 * Was hier NICHT herausgeht: Namen, Nachrichten, E-Mail-Adressen oder sonst
 * ein Inhalt einer Anfrage. Nur die Anzahl je Monat. Das ist eine
 * Geschäftskennzahl der betreuten Website, keine personenbezogene Angabe —
 * und das muss so bleiben.
 */
final class FormStatsCollector {

  /**
   * Wie viele Monate zurück gezählt wird.
   */
  private const MONATE = 24;

  public function __construct(
    private readonly Connection $database,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Anzahl der Anfragen je Monat.
   */
  public function collect(): array {
    if (!$this->moduleHandler->moduleExists('webform')) {
      return [
        'available' => FALSE,
        'reason' => 'Das Modul "webform" ist nicht aktiviert.',
        'by_month' => [],
      ];
    }

    if (!$this->database->schema()->tableExists('webform_submission')) {
      return [
        'available' => FALSE,
        'reason' => 'Keine Formulartabelle vorhanden.',
        'by_month' => [],
      ];
    }

    $ab = strtotime('-' . self::MONATE . ' months', strtotime('first day of this month 00:00:00'));

    try {
      $abfrage = $this->database->select('webform_submission', 's');
      $abfrage->addExpression("FROM_UNIXTIME(s.created, '%Y-%m')", 'monat');
      $abfrage->addExpression('COUNT(*)', 'anzahl');
      $abfrage->condition('s.created', $ab, '>=');
      // Entwürfe zählen nicht — sie wurden nie abgeschickt.
      $abfrage->condition('s.in_draft', 0);
      $abfrage->groupBy('monat');
      $abfrage->orderBy('monat', 'DESC');

      $ergebnis = $abfrage->execute()->fetchAllKeyed();
    }
    catch (\Throwable $e) {
      // Eine abweichende Datenbank oder ein fehlendes Feld darf die ganze
      // Auskunft nicht zum Scheitern bringen.
      return [
        'available' => FALSE,
        'reason' => 'Formulardaten nicht lesbar.',
        'by_month' => [],
      ];
    }

    $jeMonat = [];

    foreach ($ergebnis as $monat => $anzahl) {
      // Absichern gegen alles, was nicht wie ein Monat aussieht.
      if (preg_match('/^\d{4}-\d{2}$/', (string) $monat) === 1) {
        $jeMonat[(string) $monat] = (int) $anzahl;
      }
    }

    return [
      'available' => TRUE,
      'by_month' => $jeMonat,
    ];
  }

}
