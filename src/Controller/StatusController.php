<?php

declare(strict_types=1);

namespace Drupal\woar_monitor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\State\StateInterface;
use Drupal\woar_monitor\StatusCollector;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Liefert die Auskunft aus.
 *
 * Der Zugang ist zu diesem Zeitpunkt bereits geprüft (siehe TokenAccessCheck).
 * Hier passiert nur noch: zusammentragen, verpacken, ausliefern — und zwar
 * ausschließlich lesend.
 */
final class StatusController extends ControllerBase {

  /**
   * Baut den Controller.
   *
   * @param \Drupal\woar_monitor\StatusCollector $collector
   *   Trägt die auszuliefernde Auskunft zusammen.
   */
  /**
   * Zeitpunkt der letzten erfolgreichen Abfrage.
   */
  public const STATE_LAST_REQUEST = 'woar_monitor.last_request_at';

  public function __construct(
    protected StatusCollector $collector,
    protected StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('woar_monitor.status_collector'),
      $container->get('state'),
    );
  }

  /**
   * Liefert den Zustand der Website als JSON.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Die Auskunft, ohne Zwischenspeicherung.
   */
  public function status(): JsonResponse {
    $this->abrufVermerken();

    $response = new JsonResponse($this->collector->collect());

    // Nirgends zwischenspeichern: weder im Browser, noch in einem Proxy, noch
    // in einem CDN. Die Antwort enthält den aktuellen Zustand der Website und
    // ist an genau ein Token gebunden.
    $response->setPrivate();
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    $response->headers->set('Pragma', 'no-cache');

    // Falls die Adresse doch einmal irgendwo auftaucht: nicht indexieren.
    $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

    return $response;
  }

  /**
   * Hält fest, wann die Zentrale zuletzt erfolgreich abgefragt hat.
   *
   * Das ist die einzige Schreiboperation im gesamten Endpunkt, und sie
   * verdient eine Erklärung, weil das Modul sonst ausschließlich liest:
   *
   * Geschrieben wird ein Zeitstempel, sonst nichts — kein Wert, der von außen
   * beeinflussbar wäre. Er beantwortet auf der Kundenseite die Frage, die man
   * dort tatsächlich hat: "Holt die Zentrale hier überhaupt etwas ab?" Ohne
   * ihn müsste man das in der Zentrale nachsehen, und genau dann, wenn etwas
   * klemmt, sieht man dort nichts.
   *
   * Höchstens einmal je Minute, damit auch viele Abrufe hintereinander keine
   * nennenswerte Schreiblast erzeugen.
   */
  private function abrufVermerken(): void {
    $letzter = (int) $this->state->get(self::STATE_LAST_REQUEST, 0);

    if ((time() - $letzter) < 60) {
      return;
    }

    $this->state->set(self::STATE_LAST_REQUEST, time());
  }

}
