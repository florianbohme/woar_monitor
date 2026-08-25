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
      return $this->nichtVerfuegbar('Das Modul "webform" ist nicht aktiviert.');
    }

    if (!$this->database->schema()->tableExists('webform_submission')) {
      return $this->nichtVerfuegbar('Keine Formulartabelle vorhanden.');
    }

    $ab = strtotime('-' . self::MONATE . ' months', strtotime('first day of this month 00:00:00'));

    try {
      $abfrage = $this->database->select('webform_submission', 's');
      $abfrage->addField('s', 'webform_id', 'formular');
      $abfrage->addExpression("FROM_UNIXTIME(s.created, '%Y-%m')", 'monat');
      $abfrage->addExpression('COUNT(*)', 'anzahl');
      $abfrage->condition('s.created', $ab, '>=');
      // Entwürfe zählen nicht — sie wurden nie abgeschickt.
      $abfrage->condition('s.in_draft', 0);
      $abfrage->groupBy('formular');
      $abfrage->groupBy('monat');

      $zeilen = $abfrage->execute()->fetchAll();
    }
    catch (\Throwable $e) {
      // Eine abweichende Datenbank oder ein fehlendes Feld darf die ganze
      // Auskunft nicht zum Scheitern bringen.
      return $this->nichtVerfuegbar('Formulardaten nicht lesbar.');
    }

    $jeFormular = [];
    $gesamt = [];

    foreach ($zeilen as $zeile) {
      $monat = (string) $zeile->monat;
      $formular = (string) $zeile->formular;

      // Absichern gegen alles, was nicht wie ein Monat aussieht.
      if (preg_match('/^\d{4}-\d{2}$/', $monat) !== 1 || $formular === '') {
        continue;
      }

      $anzahl = (int) $zeile->anzahl;

      $jeFormular[$formular]['by_month'][$monat] = $anzahl;
      $gesamt[$monat] = ($gesamt[$monat] ?? 0) + $anzahl;
    }

    // Lesbare Bezeichnungen nachtragen, damit in der Zentrale nicht nur
    // Maschinennamen stehen.
    foreach (array_keys($jeFormular) as $formular) {
      $jeFormular[$formular]['title'] = $this->formularName($formular);
      krsort($jeFormular[$formular]['by_month']);
    }

    krsort($gesamt);

    return [
      'available' => TRUE,
      'by_month' => $gesamt,
      // Aufgeschlüsselt, damit in der Zentrale entschieden werden kann, welche
      // Formulare als Anfrage zählen. Ein Newsletter ist keine Anfrage.
      'by_form' => array_slice($jeFormular, 0, 30, TRUE),
    ];
  }

  /**
   * Lesbarer Name eines Formulars, ersatzweise der Maschinenname.
   */
  private function formularName(string $id): string {
    try {
      $webform = \Drupal::entityTypeManager()->getStorage('webform')->load($id);

      if ($webform !== NULL) {
        return mb_substr((string) $webform->label(), 0, 128);
      }
    }
    catch (\Throwable) {
      // Nicht schlimm — dann steht eben der Maschinenname da.
    }

    return $id;
  }

  /**
   * Antwort, wenn nichts zu zählen ist.
   */
  private function nichtVerfuegbar(string $grund): array {
    return [
      'available' => FALSE,
      'reason' => $grund,
      'by_month' => [],
      'by_form' => [],
    ];
  }


}
