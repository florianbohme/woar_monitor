<?php

declare(strict_types=1);

namespace Drupal\woar_monitor\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\woar_monitor\PairingService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Nimmt den Kopplungscode entgegen und gibt ein Token zurück.
 *
 * Das ist die einzige Stelle im Modul, an der ein Geheimnis nach außen geht —
 * und sie ist entsprechend eng gefasst:
 *
 * - Nur mit gültigem, nicht abgelaufenem Code, der genau einmal zieht.
 * - Streng ratenbegrenzt: Ein Code hat 31^8 Möglichkeiten, aber Durchprobieren
 *   soll gar nicht erst in Frage kommen.
 * - Jeder Versuch landet im Protokoll, erfolgreich wie erfolglos.
 * - Die Antwort enthält das Token und sonst nichts, was nicht ohnehin über den
 *   Statusendpunkt zu haben wäre.
 */
final class ClaimController extends ControllerBase {

  /**
   * Baut den Controller.
   *
   * @param \Drupal\woar_monitor\PairingService $pairing
   *   Prüft Kopplungscodes und erzeugt das Token.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   Ratenbegrenzung gegen das Durchprobieren von Codes.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $einstellungen
   *   Für Kennung und Namen der Website in der Antwort.
   */
  public function __construct(
    protected PairingService $pairing,
    protected FloodInterface $flood,
    protected ConfigFactoryInterface $einstellungen,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('woar_monitor.pairing'),
      $container->get('flood'),
      $container->get('config.factory'),
    );
  }

  /**
   * Nimmt einen Kopplungscode entgegen und gibt bei Erfolg ein Token zurück.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Die Anfrage mit dem Code im JSON-Körper.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   Das Token, oder eine Abweisung ohne Auskunft über den Grund.
   */
  public function claim(Request $request): JsonResponse {
    $ip = (string) $request->getClientIp();

    // Zehn Versuche je Stunde und Adresse. Wer den Code hat, braucht einen.
    if (!$this->flood->isAllowed('woar_monitor.pairing', 10, 3600, $ip)) {
      return $this->abweisen($ip, 'Zu viele Versuche');
    }

    $this->flood->register('woar_monitor.pairing', 3600, $ip);

    if (!$this->pairing->isPossible()) {
      return new JsonResponse([
        'status' => 'not_possible',
        'message' => 'Das Token dieser Website steht fest in settings.php und muss von Hand übertragen werden.',
      ], 409);
    }

    $daten = json_decode((string) $request->getContent(), TRUE);
    $code = is_array($daten) ? (string) ($daten['code'] ?? '') : '';

    if ($code === '') {
      return $this->abweisen($ip, 'Kein Code übergeben');
    }

    $token = $this->pairing->claim($code);

    if ($token === NULL) {
      return $this->abweisen($ip, 'Code stimmt nicht oder ist abgelaufen');
    }

    $this->getLogger('woar_monitor')->notice('Kopplung erfolgreich von @ip.', ['@ip' => $ip]);

    $antwort = new JsonResponse([
      'status' => 'paired',
      'token' => $token,
      'site_uuid' => (string) $this->einstellungen->get('system.site')->get('uuid'),
      'site_name' => (string) $this->einstellungen->get('system.site')->get('name'),
      'core_version' => \Drupal::VERSION,
    ]);

    $antwort->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    $antwort->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

    return $antwort;
  }

  /**
   * Immer dieselbe Abweisung, egal woran es lag.
   */
  private function abweisen(string $ip, string $grund): JsonResponse {
    $this->getLogger('woar_monitor')->warning(
      'Kopplung abgewiesen (@grund) von @ip.',
      ['@grund' => $grund, '@ip' => $ip]
    );

    return new JsonResponse(['status' => 'rejected'], 403);
  }

}
