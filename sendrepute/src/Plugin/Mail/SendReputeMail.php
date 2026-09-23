<?php

declare(strict_types=1);

namespace Drupal\sendrepute\Plugin\Mail;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\sendrepute\Service\MailPolicy;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Classifies the final body produced by an explicitly configured delegate.
 *
 * @Mail(
 *   id = "sendrepute_mail",
 *   label = @Translation("SendRepute mail policy"),
 *   description = @Translation("Delegates formatting, then classifies the final body before delivery.")
 * )
 */
final class SendReputeMail extends PluginBase implements MailInterface, ContainerFactoryPluginInterface {

  private ?MailInterface $delegateInstance = NULL;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly PluginManagerInterface $mailManager,
    private readonly MailPolicy $policy,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.mail'),
      $container->get('sendrepute.policy'),
    );
  }

  public function format(array $message): array {
    $route = $this->policy->routeFor($message);
    return $this->delegate($route)->format($message);
  }

  public function mail(array $message): bool {
    $route = $this->policy->routeFor($message);
    if ($route === NULL) {
      // Configuration can change between format() and mail(). Never turn that
      // race into an accidental mail outage; fall back to the supported core
      // delegate without making a paid request.
      return $this->delegate(NULL)->mail($message);
    }
    return $this->policy->allow($message, $route)
      ? $this->delegate($route)->mail($message)
      : FALSE;
  }

  private function delegate(?array $route): MailInterface {
    $id = is_array($route) ? ($route['delegate'] ?? 'php_mail') : 'php_mail';
    if ($id !== 'php_mail') {
      throw new \LogicException('SendRepute 0.1.0 supports only the core php_mail delegate.');
    }
    if ($this->delegateInstance === NULL) {
      /** @var \Drupal\Core\Mail\MailInterface $delegate */
      $delegate = $this->mailManager->createInstance($id);
      $this->delegateInstance = $delegate;
    }
    return $this->delegateInstance;
  }

}
