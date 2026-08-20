<?php

declare(strict_types=1);

namespace Drupal\woar_monitor\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Prüft den Zugang zum Statusendpunkt.
 *
 * Reihenfolge der Prüfungen ist Absicht: Erst die billigen, dann die teuren.
 * Wer durch die Ratenbegrenzung fällt, kostet keine weitere Rechenzeit.
 *
 * Der Endpunkt kennt keine Drupal-Benutzer und keine Sitzung. Es gibt genau
 * einen Weg hinein: das richtige Token im Authorization-Kopf.
 */
final class TokenAccessCheck implements AccessInterface {

  /**
   * Schlüssel, unter dem das Token im State liegt.
   */
  public const STATE_KEY = 'woar_monitor.token';

  /**
   * Name, unter dem das Token wahlweise in settings.php gesetzt werden kann.
   */
  public const SETTINGS_KEY = 'woar_monitor_token';

  /**
   * Kennung der Installation, für die das Token gesetzt wurde.
   *
   * Nur noch als Hinweis. Für die eigentliche Prüfung taugt sie nicht — siehe
   * STATE_HOST_KEY.
   */
  public const STATE_SITE_KEY = 'woar_monitor.token_site_uuid';

  /**
   * Domain, unter der das Token vergeben wurde.
   *
   * Das ist die wirksame Absicherung gegen kopierte Datenbanken.
   *
   * Ursprünglich hing sie an Drupals Installationskennung — in der Annahme,
   * die sei je Installation eindeutig. Das ist sie nicht: Die Kennung steht in
   * config/sync/system.site.yml und wandert damit über Git in jedes Projekt,
   * das aus derselben Vorlage entsteht. Beim Installieren übernimmt Drupal sie
   * von dort, damit sich Konfiguration zwischen den Projekten austauschen
   * lässt. Die Prüfung lief also genau in dem Fall ins Leere, für den sie
   * gedacht war: Vorlage und Ableger hätten dasselbe Token akzeptiert.
   *
   * Die Domain ändert sich beim Klonen dagegen immer. Passt sie nicht mehr,
   * gilt das Token hier nicht — und die Website muss neu verbunden werden.
   */
  public const STATE_HOST_KEY = 'woar_monitor.token_host';

  public function __construct(
    private readonly StateInterface $state,
    private readonly FloodInterface $flood,
    private readonly RequestStack $requestStack,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Gehört das hinterlegte Token zu dieser Website?
   *
   * Verglichen wird die Domain, unter der das Token vergeben wurde, mit der
   * Domain der laufenden Anfrage. Nur für Token aus dem State — ein Token aus
   * settings.php ist von Hand für genau diese Website gesetzt worden.
   */
  private function tokenGehoertHierher(Request $request): bool {
    $vermerkt = (string) $this->state->get(self::STATE_HOST_KEY, '');

    // Kein Vermerk: Token stammt aus einer Fassung vor dieser Prüfung. Dann
    // nicht aussperren, sondern durchlassen — sonst stünden nach einem Update
    // des Moduls schlagartig alle Websites auf Rot.
    if ($vermerkt === '') {
      return TRUE;
    }

    return hash_equals($vermerkt, self::domain($request->getHost()));
  }

  /**
   * Domain in vergleichbarer Form.
   *
   * Kleinschreibung und ohne führendes "www.", damit ein Aufruf über
   * example.de und einer über www.example.de nicht als verschiedene Websites
   * gelten.
   */
  public static function domain(string $host): string {
    $host = mb_strtolower(trim($host));

    return str_starts_with($host, 'www.') ? mb_substr($host, 4) : $host;
  }

  /**
   * Zugangsprüfung für die Route.
   */
  public function access(): AccessResultInterface {
    $request = $this->requestStack->getCurrentRequest();

    if ($request === NULL) {
      return $this->verweigern();
    }

    $config = $this->configFactory->get('woar_monitor.settings');
    $ip = (string) $request->getClientIp();

    // 1. Ratenbegrenzung für Abrufe insgesamt.
    $limit = (int) ($config->get('requests_per_hour') ?? 60);

    if (!$this->flood->isAllowed('woar_monitor.request', $limit, 3600, $ip)) {
      return $this->verweigern();
    }

    $this->flood->register('woar_monitor.request', 3600, $ip);

    // 2. Optionale Freigabeliste.
    if (!$this->ipErlaubt($ip, (array) ($config->get('ip_allowlist') ?? []))) {
      $this->fehlversuchVermerken($ip, 'IP-Adresse nicht in der Freigabeliste');
      return $this->verweigern();
    }

    // 3. Wurde das Token überhaupt für diese Domain vergeben?
    if ($this->erwartetesTokenStammtAusState() && !$this->tokenGehoertHierher($request)) {
      $this->fehlversuchVermerken($ip, 'Token wurde für eine andere Domain vergeben');
      return $this->verweigern();
    }

    // 4. Token prüfen — und zwar VOR der Sperre gegen Durchprobieren.
    //
    // Die Reihenfolge ist der Kern und war zuerst falsch herum: Stand in der
    // Zentrale einmal ein veraltetes Token, sammelten ihre automatischen
    // Abfragen in Minuten genug Fehlversuche an, um die Sperre auszulösen.
    // Danach wurde auch das inzwischen richtige Token abgewiesen — eine
    // Stunde lang, und niemand konnte etwas dagegen tun.
    //
    // So herum gilt: Wer das richtige Token vorzeigt, ist berechtigt, Punkt.
    // Er kommt durch und der Zähler wird geleert. Wer es nicht hat, läuft in
    // die Sperre wie zuvor — für das Durchprobieren ändert sich nichts, denn
    // wer richtig rät, hätte ohnehin gewonnen.
    $erwartet = $this->erwartetesToken();
    $uebergeben = $this->tokenAusAnfrage($request);

    // Ein leeres Token darf niemals als gültig durchgehen.
    if ($erwartet !== '' && $uebergeben !== '' && hash_equals($erwartet, $uebergeben)) {
      $this->flood->clear('woar_monitor.failed_auth', $ip);

      return AccessResult::allowed()->setCacheMaxAge(0);
    }

    // 5. Falsch. Jetzt greift die Sperre.
    $fehlversuche = (int) ($config->get('failed_auth_per_hour') ?? 5);

    if (!$this->flood->isAllowed('woar_monitor.failed_auth', $fehlversuche, 3600, $ip)) {
      return $this->verweigern();
    }

    $this->fehlversuchVermerken(
      $ip,
      $erwartet === '' ? 'Kein Token hinterlegt' : 'Falsches Token'
    );

    return $this->verweigern();
  }

  /**
   * Das hinterlegte Token.
   *
   * Bevorzugt aus settings.php, sonst aus dem State. settings.php hat Vorrang,
   * weil die Datei weder in den Konfigurationsexport noch in ein
   * Datenbank-Abbild gerät.
   *
   * Bewusst NICHT in der Konfiguration: Exportierte Konfiguration landet im
   * Git-Repository des Kunden, und damit läge das Token dort im Klartext.
   */
  private function erwartetesToken(): string {
    $ausSettings = Settings::get(self::SETTINGS_KEY, '');

    if (is_string($ausSettings) && $ausSettings !== '') {
      return $ausSettings;
    }

    $ausState = $this->state->get(self::STATE_KEY, '');

    return is_string($ausState) ? $ausState : '';
  }

  /**
   * Kommt das gültige Token aus dem State (und nicht aus settings.php)?
   */
  private function erwartetesTokenStammtAusState(): bool {
    $ausSettings = Settings::get(self::SETTINGS_KEY, '');

    return !is_string($ausSettings) || $ausSettings === '';
  }

  /**
   * Liest das Token aus dem Authorization-Kopf.
   *
   * Ausschließlich aus dem Kopf, nie aus der Adresszeile: Adressen stehen in
   * Zugriffsprotokollen, in Proxys und in Verlaufsdaten. Ein Token, das dort
   * auftaucht, ist verbrannt.
   */
  private function tokenAusAnfrage(Request $request): string {
    $kopf = (string) $request->headers->get('Authorization', '');

    if (stripos($kopf, 'Bearer ') !== 0) {
      return '';
    }

    return trim(substr($kopf, 7));
  }

  /**
   * Prüft die IP gegen die Freigabeliste.
   *
   * Leere Liste bedeutet: keine Einschränkung.
   *
   * Achtung: Hinter einem Reverse Proxy oder einem CDN ist die ermittelte
   * IP-Adresse nur dann die echte, wenn in settings.php `reverse_proxy` und
   * `reverse_proxy_addresses` korrekt gesetzt sind. Ohne das lässt sie sich
   * fälschen, und die Freigabeliste ist eine Beruhigung ohne Wirkung. Deshalb
   * ist sie optional und nicht der eigentliche Schutz — das ist das Token.
   */
  private function ipErlaubt(string $ip, array $liste): bool {
    $liste = array_values(array_filter(array_map('trim', $liste)));

    if ($liste === []) {
      return TRUE;
    }

    return IpUtils::checkIp($ip, $liste);
  }

  /**
   * Vermerkt einen Fehlversuch und schreibt ihn ins Protokoll.
   */
  private function fehlversuchVermerken(string $ip, string $grund): void {
    $this->flood->register('woar_monitor.failed_auth', 3600, $ip);

    $this->loggerFactory->get('woar_monitor')->warning(
      'Abgewiesener Zugriff auf den Statusendpunkt (@grund) von @ip.',
      ['@grund' => $grund, '@ip' => $ip]
    );
  }

  /**
   * Abweisung ohne Auskunft.
   *
   * Immer dieselbe Antwort, egal ob Token falsch, IP gesperrt oder
   * Ratenbegrenzung erreicht. Wer von außen anklopft, soll nicht erfahren, wie
   * weit er gekommen ist.
   */
  private function verweigern(): AccessResultInterface {
    return AccessResult::forbidden()->setCacheMaxAge(0);
  }

}
