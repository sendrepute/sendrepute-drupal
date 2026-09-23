<?php

declare(strict_types=1);

namespace Drupal\sendrepute\Form;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures explicit mail routes without storing a credential.
 */
final class SendReputeSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly PluginManagerInterface $mailManager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('plugin.manager.mail'),
    );
  }

  public function getFormId(): string {
    return 'sendrepute_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['sendrepute.settings', 'system.mail'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('sendrepute.settings');
    $lines = [];
    foreach ($config->get('routes') ?? [] as $route) {
      if (is_array($route)) {
        $lines[] = implode('|', [
          $route['module'] ?? '',
          $route['key'] ?? '',
          $route['delegate'] ?? 'php_mail',
          $route['mode'] ?? 'advisory',
          $route['failure_policy'] ?? 'preserve',
          $route['threshold'] ?? '0.9',
          !empty($route['allow_critical']) ? 'yes' : 'no',
        ]);
      }
    }
    $form['warning'] = [
      '#type' => 'item',
      '#markup' => $this->t('<strong>Paid operation.</strong> The configured non-address sender display name, message subject, and final body leave this server. Recipients, sender email addresses, headers, and attachments are excluded. Configure <code>SENDREPUTE_API_TOKEN</code> in the server environment; it is never stored or exported by Drupal.'),
    ];
    $form['paid_consent'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I authorize paid classification for the exact routes below'),
      '#default_value' => (bool) $config->get('paid_consent'),
    ];
    $form['sender_display_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sender display name for analysis'),
      '#description' => $this->t('Required when paid consent is enabled. Use a human-readable organization or application name only, never an email address.'),
      '#default_value' => (string) ($config->get('sender_display_name') ?? ''),
      '#maxlength' => 320,
    ];
    $form['routes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Exact module/key routes'),
      '#description' => $this->t('One per line: module|key|php_mail|advisory-or-block|preserve-or-block|threshold-0-to-1|critical-yes-or-no. Example: contact|page_mail|php_mail|advisory|preserve|0.90|no. Critical yes deliberately permits blocking account/security mail. Version 0.1.0 supports only Drupal core php_mail.'),
      '#default_value' => implode("\n", $lines),
      '#rows' => 10,
    ];
    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $sender = trim((string) $form_state->getValue('sender_display_name'));
    if ((bool) $form_state->getValue('paid_consent')
      && ($sender === '' || strlen($sender) > 320
        || preg_match('//u', $sender) !== 1
        || preg_match('/[\x00-\x1F\x7F@<>]/u', $sender) === 1)) {
      $form_state->setErrorByName('sender_display_name', $this->t('Enter a non-empty display name without an email address, angle brackets, or control characters.'));
    }
    $form_state->setValue('sender_display_name', $sender);
    try {
      $routes = $this->parseRoutes((string) $form_state->getValue('routes'));
      $form_state->set('sendrepute_routes', $routes);
    }
    catch (\InvalidArgumentException $exception) {
      $form_state->setErrorByName('routes', $this->t($exception->getMessage()));
    }
    parent::validateForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $oldRoutes = $this->config('sendrepute.settings')->get('routes') ?? [];
    $routes = $form_state->get('sendrepute_routes') ?? [];
    $mail = $this->config('system.mail');
    $interfaces = $mail->get('interface') ?? [];
    foreach ($oldRoutes as $route) {
      if (is_array($route)) {
        $name = ($route['module'] ?? '') . '_' . ($route['key'] ?? '');
        if (($interfaces[$name] ?? NULL) === 'sendrepute_mail') {
          $interfaces[$name] = $route['delegate'] ?? 'php_mail';
        }
      }
    }
    foreach ($routes as $route) {
      $interfaces[$route['module'] . '_' . $route['key']] = 'sendrepute_mail';
    }
    $mail->set('interface', $interfaces)->save();
    $this->config('sendrepute.settings')
      ->set('paid_consent', (bool) $form_state->getValue('paid_consent'))
      ->set('sender_display_name', (string) $form_state->getValue('sender_display_name'))
      ->set('routes', $routes)
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * @return list<array<string, mixed>>
   */
  private function parseRoutes(string $input): array {
    $routes = [];
    $seen = [];
    foreach (preg_split('/\R/', trim($input)) ?: [] as $line) {
      if (trim($line) === '') {
        continue;
      }
      $parts = array_map('trim', explode('|', $line));
      if (count($parts) !== 7) {
        throw new \InvalidArgumentException('Every non-empty route must contain exactly seven pipe-separated fields.');
      }
      [$module, $key, $delegate, $mode, $failure, $threshold, $critical] = $parts;
      if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $module)
        || !preg_match('/^[a-z0-9_][a-z0-9_.-]{0,127}$/', $key)
        || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $delegate)
        || $delegate !== 'php_mail'
        || !in_array($mode, ['advisory', 'block'], TRUE)
        || !in_array($failure, ['preserve', 'block'], TRUE)
        || !is_numeric($threshold) || (float) $threshold < 0.0 || (float) $threshold > 1.0
        || !in_array($critical, ['yes', 'no'], TRUE)) {
        throw new \InvalidArgumentException('A route contains an invalid module, key, policy, threshold, or critical selection. This release supports only the core php_mail delegate.');
      }
      if (!$this->mailManager->hasDefinition($delegate)) {
        throw new \InvalidArgumentException('A route names a mail delegate plugin that is not installed.');
      }
      $name = $module . ':' . $key;
      if (isset($seen[$name])) {
        throw new \InvalidArgumentException('Each module/key route may appear only once.');
      }
      $seen[$name] = TRUE;
      $routes[] = [
        'module' => $module,
        'key' => $key,
        'delegate' => $delegate,
        'mode' => $mode,
        'failure_policy' => $failure,
        'threshold' => (float) $threshold,
        'allow_critical' => $critical === 'yes',
      ];
    }
    return $routes;
  }

}
