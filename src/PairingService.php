<?php

declare(strict_types=1);

namespace Drupal\woar_monitor;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\woar_monitor\Access\TokenAccessCheck;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Kopplung mit der Zentrale über einen kurzlebigen Code.
 *
 * Der Ablauf und warum er so aussieht:
 *
 * Auf dieser Website wird ein achtstelliger Code erzeugt, der fünfzehn
 * Minuten gilt und genau einmal benutzt werden kann. Wer ihn vorzeigt, bekommt
 * ein frisch erzeugtes Zugangstoken zurück — und der Code ist danach
 * verbraucht.
 *
 * Der entscheidende Vorteil gegenüber einem gemeinsamen Geheimnis, das auf
 * allen betreuten Websites liegt: Hier gibt es nichts, was sich weitertragen
 * ließe. Wird eine Website kompromittiert, erfährt der Angreifer nichts über
 * die anderen. Jede Kopplung ist ein eigener, einzeln freigegebener Vorgang.
 *
 * Der Code wird nur als Hash abgelegt. Ein Blick in die Datenbank verrät ihn
 * also nicht — und da er ohnehin nach fünfzehn Minuten verfällt, ist das
 * Zeitfenster für jeden Angriff denkbar klein.
 */
final class PairingService {

  /**
   * Hash des gültigen Kopplungscodes.
   */
  private const STATE_CODE_HASH = 'woar_monitor.pairing_hash';

  /**
   * Wann der Code verfällt.
   */
  private const STATE_CODE_EXPIRES = 'woar_monitor.pairing_expires';

  /**
   * Gültigkeitsdauer in Sekunden.
   */
  public const LIFETIME = 900;

  public function __construct(
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly RequestStack $requestStack,
    private readonly FloodInterface $flood,
  ) {}

  /**
   * Erzeugt einen neuen Kopplungscode und gibt ihn im Klartext zurück.
   *
   * Der Klartext existiert nur in diesem Augenblick — abgelegt wird ein Hash.
   */
  public function createCode(): string {
    // Acht Zeichen aus einem Alphabet ohne Verwechslungsgefahr: kein 0/O,
    // kein 1/I/l. Wer den Code abtippt, soll sich nicht vertippen können.
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $code = '';

    for ($i = 0; $i < 8; $i++) {
      $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    $this->state->set(self::STATE_CODE_HASH, hash('sha256', $code));
    $this->state->set(self::STATE_CODE_EXPIRES, time() + self::LIFETIME);

    $this->loggerFactory->get('woar_monitor')->notice('Kopplungscode erzeugt, gültig für 15 Minuten.');

    return $code;
  }

  /**
   * Verwirft einen offenen Kopplungscode.
   */
  public function clearCode(): void {
    $this->state->delete(self::STATE_CODE_HASH);
    $this->state->delete(self::STATE_CODE_EXPIRES);
  }

  /**
   * Sekunden, die der aktuelle Code noch gilt. NULL, wenn keiner offen ist.
   */
  public function secondsRemaining(): ?int {
    $verfaellt = (int) $this->state->get(self::STATE_CODE_EXPIRES, 0);

    if ($verfaellt <= time()) {
      return NULL;
    }

    return $verfaellt - time();
  }

  /**
   * Prüft einen vorgezeigten Code und gibt bei Erfolg ein neues Token zurück.
   *
   * @return string|null
   *   Das neue Token, oder NULL wenn der Code nicht stimmt.
   */
  public function claim(string $code): ?string {
    $hash = (string) $this->state->get(self::STATE_CODE_HASH, '');
    $verfaellt = (int) $this->state->get(self::STATE_CODE_EXPIRES, 0);

    if ($hash === '' || $verfaellt <= time()) {
      return NULL;
    }

    // Zeitkonstant, wie beim Token: Aus Laufzeitunterschieden soll sich der
    // Code nicht zeichenweise erraten lassen.
    if (!hash_equals($hash, hash('sha256', strtoupper(trim($code))))) {
      return NULL;
    }

    // Code ist verbraucht — noch bevor irgendetwas anderes passiert.
    $this->clearCode();

    $token = Crypt::randomBytesBase64(48);

    $this->state->set(TokenAccessCheck::STATE_KEY, $token);
    $this->state->set(
      TokenAccessCheck::STATE_SITE_KEY,
      (string) $this->configFactory->get('system.site')->get('uuid')
    );

    // Domain festhalten: Wandert diese Datenbank später in ein neues Projekt,
    // gilt das Token dort nicht mehr.
    $request = $this->requestStack->getCurrentRequest();

    if ($request !== NULL) {
      $this->state->set(TokenAccessCheck::STATE_HOST_KEY, TokenAccessCheck::domain($request->getHost()));
    }

    // Eine geglückte Kopplung beweist, dass die Gegenstelle berechtigt ist.
    // Etwaige Fehlversuche von vorher — etwa weil in der Zentrale noch ein
    // altes Token stand — dürfen die frische Verbindung nicht blockieren.
    $request = $this->requestStack->getCurrentRequest();

    if ($request !== NULL) {
      $this->flood->clear('woar_monitor.failed_auth', (string) $request->getClientIp());
    }

    $this->loggerFactory->get('woar_monitor')->notice('Mit der Zentrale gekoppelt, neues Token erzeugt.');

    return $token;
  }

  /**
   * Ist eine Kopplung überhaupt möglich?
   *
   * Nicht, wenn das Token fest in settings.php steht: Dort kann diese Website
   * nichts ändern, und ein erzeugtes Token wäre wirkungslos — die Zentrale
   * liefe dauerhaft in 403.
   */
  public function isPossible(): bool {
    $ausSettings = Settings::get(TokenAccessCheck::SETTINGS_KEY, '');

    return !is_string($ausSettings) || $ausSettings === '';
  }

}
