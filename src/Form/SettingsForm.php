<?php

declare(strict_types=1);

namespace Drupal\woar_monitor\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\woar_monitor\Controller\StatusController;
use Drupal\woar_monitor\PairingService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Einstellungen: Token, Ratenbegrenzung, IP-Freigabeliste.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * Baut das Formular.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Zugriff auf die Konfiguration.
   * @param \Drupal\Core\State\StateInterface $state
   *   Ablage des Tokens. Bewusst State und nicht Konfiguration, damit das
   *   Token nicht im Konfigurationsexport landet.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   Für die Anzeige von Zeitpunkten in der Zeitzone der Website.
   * @param \Drupal\woar_monitor\PairingService $pairing
   *   Kopplung mit der Zentrale über einen kurzlebigen Code.
   */
  public function __construct(
    $config_factory,
    private readonly StateInterface $state,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly PairingService $pairing,
  ) {
    parent::__construct($config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('state'),
      $container->get('date.formatter'),
      $container->get('woar_monitor.pairing'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'woar_monitor_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['woar_monitor.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('woar_monitor.settings');

    $form['endpunkt'] = [
      '#type' => 'item',
      '#title' => $this->t('Adresse des Endpunkts'),
      '#markup' => '<code>' . Url::fromRoute('woar_monitor.status', [], ['absolute' => TRUE])->toString() . '</code>',
      '#description' => $this->t('Diese Adresse wird in der Zentrale hinterlegt. Ohne gültiges Token liefert sie nur "403 Zugriff verweigert".'),
    ];

    // Der aussagekräftigste Wert überhaupt: Holt die Zentrale hier Daten ab?
    $letzterAbruf = (int) $this->state->get(StatusController::STATE_LAST_REQUEST, 0);

    $form['verbindung'] = [
      '#type' => 'item',
      '#title' => $this->t('Letzte Abfrage durch die Zentrale'),
      '#markup' => $letzterAbruf > 0
        ? $this->t('@zeit — die Anbindung funktioniert.', [
          '@zeit' => $this->dateFormatter->format($letzterAbruf, 'medium'),
        ])
        : $this->t('Noch nie. Diese Website ist entweder noch nicht mit dem Monitor verbunden, oder die Verbindung klemmt.'),
    ];

    // Kopplung: der Weg, eine Website mit dem Monitor zu verbinden.
    $moeglich = $this->pairing->isPossible();

    $form['kopplung'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Mit dem Monitor verbinden'),
      // Die Anleitung nur zeigen, wenn sie auch befolgt werden kann. Sonst
      // steht "nicht möglich" direkt unter der Beschreibung, wie es geht.
      '#description' => $moeglich
        ? $this->t('Im Monitor die Website anlegen und dort auf <em>Verbinden</em> klicken. Hier einen Code erzeugen und drüben eintragen — mehr ist nicht zu tun. Der Code gilt 15 Minuten und genau einmal.')
        : NULL,
    ];

    if (!$moeglich) {
      $form['kopplung']['nicht_moeglich'] = [
        '#type' => 'item',
        '#markup' => $this->t('Diese Website hat ihr Token fest in <code>settings.php</code> stehen (<code>woar_monitor_token</code>) und kann es deshalb nicht selbst wechseln. Der Kopplungscode steht darum nicht zur Verfügung — die Verbindung läuft trotzdem, sie wurde nur von Hand eingerichtet.<br><br>Damit liegt das Token im Klartext in einer Datei, die üblicherweise im Git liegt. Sauberer ist der umgekehrte Weg: die Zeile aus <code>settings.php</code> entfernen, ausrollen, und die Website hier neu koppeln. Das Token steht dann in der Datenbank und wird beim Koppeln ohnehin durch ein neues ersetzt.'),
      ];
    }
    else {
      $verbleibend = $this->pairing->secondsRemaining();

      if ($verbleibend !== NULL) {
        $form['kopplung']['laeuft'] = [
          '#type' => 'item',
          '#title' => $this->t('Ein Code ist offen'),
          '#markup' => $this->t('Noch @minuten Minuten gültig. Er wurde beim Erzeugen einmalig angezeigt; erzeuge einen neuen, falls du ihn nicht mehr hast.', [
            '@minuten' => (int) ceil($verbleibend / 60),
          ]),
        ];
      }

      $form['kopplung']['erzeugen'] = [
        '#type' => 'submit',
        '#value' => $verbleibend !== NULL
          ? $this->t('Neuen Code erzeugen')
          : $this->t('Verbinden vorbereiten'),
        '#submit' => ['::kopplungscodeErzeugen'],
        '#limit_validation_errors' => [],
      ];
    }

    $form['zugriff'] = [
      '#type' => 'details',
      '#title' => $this->t('Zugriffsschutz'),
      '#description' => $this->t('Zusätzliche Schranken vor dem Endpunkt. Die Voreinstellungen passen für den Normalfall; hier muss nichts geändert werden.<br><strong>Abrufe je Stunde</strong> begrenzt, wie oft überhaupt angefragt werden darf — damit der Endpunkt niemandem als Werkzeug dient. <strong>Fehlversuche je Stunde</strong> ist der Schutz gegen das Durchprobieren von Token: Nach dieser Zahl vergeblicher Versuche wird von derselben Adresse aus eine Stunde lang alles abgewiesen, auch das richtige Token. Die <strong>IP-Freigabeliste</strong> beschränkt den Zugriff zusätzlich auf bestimmte Adressen.'),
    ];

    $form['zugriff']['ip_allowlist'] = [
      '#type' => 'textarea',
      '#title' => $this->t('IP-Freigabeliste'),
      '#default_value' => implode("\n", (array) ($config->get('ip_allowlist') ?? [])),
      '#rows' => 4,
      '#description' => $this->t('Eine Adresse oder ein CIDR-Bereich je Zeile, zum Beispiel <code>203.0.113.7</code> oder <code>203.0.113.0/24</code>. Leer bedeutet: keine Einschränkung. <strong>Achtung:</strong> Hinter einem Reverse Proxy oder CDN ist die erkannte Adresse nur dann echt, wenn in settings.php <code>reverse_proxy</code> richtig eingestellt ist. Andernfalls lässt sie sich fälschen — der eigentliche Schutz ist und bleibt das Token.'),
    ];

    $form['zugriff']['requests_per_hour'] = [
      '#type' => 'number',
      '#title' => $this->t('Erlaubte Abrufe je Stunde und IP-Adresse'),
      '#default_value' => (int) ($config->get('requests_per_hour') ?? 60),
      '#min' => 1,
      '#max' => 10000,
    ];

    $form['zugriff']['failed_auth_per_hour'] = [
      '#type' => 'number',
      '#title' => $this->t('Erlaubte Fehlversuche je Stunde und IP-Adresse'),
      '#default_value' => (int) ($config->get('failed_auth_per_hour') ?? 5),
      '#min' => 1,
      '#max' => 1000,
      '#description' => $this->t('Schutz gegen das Durchprobieren von Token. Niedrig halten.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Erzeugt einen Kopplungscode und zeigt ihn einmalig an.
   */
  public function kopplungscodeErzeugen(array &$form, FormStateInterface $form_state): void {
    $code = $this->pairing->createCode();

    $this->messenger()->addWarning($this->t('Kopplungscode: <code style="font-size:1.4em;letter-spacing:.15em">@code</code><br>Jetzt im Monitor eintragen. Gültig für 15 Minuten, danach verfällt er.', [
      '@code' => $code,
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    foreach ($this->zeilen((string) $form_state->getValue('ip_allowlist')) as $eintrag) {
      if (!$this->istGueltigeIpAngabe($eintrag)) {
        $form_state->setErrorByName('ip_allowlist', $this->t('Keine gültige IP-Adresse oder CIDR-Angabe: @eintrag', ['@eintrag' => $eintrag]));
        break;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('woar_monitor.settings')
      ->set('ip_allowlist', $this->zeilen((string) $form_state->getValue('ip_allowlist')))
      ->set('requests_per_hour', (int) $form_state->getValue('requests_per_hour'))
      ->set('failed_auth_per_hour', (int) $form_state->getValue('failed_auth_per_hour'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Textfeld in eine bereinigte Liste von Zeilen verwandeln.
   */
  private function zeilen(string $text): array {
    $zeilen = preg_split('/\R/', $text) ?: [];

    return array_values(array_filter(array_map('trim', $zeilen), static fn ($z) => $z !== ''));
  }

  /**
   * Prüft eine Angabe auf Gültigkeit, indem sie einmal angewendet wird.
   */
  private function istGueltigeIpAngabe(string $eintrag): bool {
    if (filter_var($eintrag, FILTER_VALIDATE_IP)) {
      return TRUE;
    }

    if (!str_contains($eintrag, '/')) {
      return FALSE;
    }

    [$netz, $maske] = explode('/', $eintrag, 2);

    return (bool) filter_var($netz, FILTER_VALIDATE_IP)
      && ctype_digit($maske)
      && (int) $maske >= 0
      && (int) $maske <= 128
      && IpUtils::checkIp('127.0.0.1', $eintrag) !== NULL;
  }

}
