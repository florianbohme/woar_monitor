<?php

declare(strict_types=1);

namespace Drupal\woar_monitor;

use Drupal\Core\Database\Connection;

/**
 * Wie groß die Datenbank ist, und welche Tabellen den Platz belegen.
 *
 * Der Anlass: Eine Tabelle, die still vor sich hin wächst, bis der
 * Speicherplatz voll ist oder jede Abfrage darauf hängt — bei einem Kunden
 * zuletzt die Tabelle eines Merkliste-Moduls. So etwas fällt erst auf, wenn
 * die Website langsam wird. Mit der Größe je Tabelle sieht die Zentrale es
 * kommen.
 *
 * Was hinausgeht: Tabellennamen, Größe in Bytes, ungefähre Zeilenzahl. Kein
 * Inhalt, kein Datenbankname, kein Server, kein Benutzer. Die Tabellennamen
 * verraten nur, welche Module installiert sind — und das steht in der
 * Update-Liste ohnehin.
 */
final class DatabaseSizeCollector {

  /**
   * So viele der größten Tabellen werden gemeldet.
   */
  private const TABELLEN = 25;

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * Gesamtgröße und die größten Tabellen.
   */
  public function collect(): array {
    try {
      return match ($this->database->driver()) {
        'mysql' => $this->mysql(),
        'pgsql' => $this->pgsql(),
        default => $this->nichtVerfuegbar('Datenbank "' . $this->database->driver() . '" wird nicht ausgewertet.'),
      };
    }
    catch (\Throwable) {
      // Fehlende Rechte auf information_schema oder Ähnliches. Kein Grund,
      // die ganze Auskunft scheitern zu lassen.
      return $this->nichtVerfuegbar('Die Größe ließ sich nicht abfragen.');
    }
  }

  /**
   * MySQL und MariaDB: aus information_schema, ohne die Tabellen anzufassen.
   *
   * Die Zeilenzahl ist bei InnoDB eine Schätzung. Für "wächst diese Tabelle"
   * reicht sie; ein COUNT(*) auf einer riesigen Tabelle wäre genau die Last,
   * die man hier nicht erzeugen will.
   */
  private function mysql(): array {
    $zeilen = $this->database->query(
      'SELECT TABLE_NAME AS name, COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) AS bytes, COALESCE(TABLE_ROWS, 0) AS zeilen
       FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
    )->fetchAll();

    return $this->ergebnis($zeilen);
  }

  /**
   * PostgreSQL: Tabellen samt Indizes und ausgelagerten Werten.
   */
  private function pgsql(): array {
    $zeilen = $this->database->query(
      "SELECT c.relname AS name, pg_total_relation_size(c.oid) AS bytes, GREATEST(c.reltuples, 0)::bigint AS zeilen
       FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
       WHERE c.relkind = 'r' AND n.nspname = current_schema()"
    )->fetchAll();

    return $this->ergebnis($zeilen);
  }

  /**
   * In feste Form bringen: Summe über alle, die größten einzeln.
   */
  private function ergebnis(array $zeilen): array {
    $tabellen = array_map(fn ($z): array => [
      'name' => mb_substr((string) $z->name, 0, 64),
      'bytes' => max(0, (int) $z->bytes),
      'rows' => max(0, (int) $z->zeilen),
    ], $zeilen);

    usort($tabellen, fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

    return [
      'available' => TRUE,
      'driver' => $this->database->driver(),
      'size_bytes' => array_sum(array_column($tabellen, 'bytes')),
      'table_count' => count($tabellen),
      'tables' => array_slice($tabellen, 0, self::TABELLEN),
    ];
  }

  private function nichtVerfuegbar(string $grund): array {
    return ['available' => FALSE, 'reason' => $grund];
  }

}
