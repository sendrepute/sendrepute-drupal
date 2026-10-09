<?php

declare(strict_types=1);

namespace Drupal\sendrepute\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Site\Settings;
use Drupal\sendrepute\Console\Contract;
use Drupal\sendrepute\Console\CurlTransport;
use Drupal\sendrepute\Console\LockStore;
use Drupal\sendrepute\Console\Renderer;
use Drupal\sendrepute\Console\Service;
use Drupal\sendrepute\Service\ConsoleLockStore;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Native Drupal admin page for the SendRepute customer API console.
 *
 * Access: route requirement "use sendrepute console" (restricted) and a
 * re-check here. CSRF: Drupal CSRF token bound to the session, verified on
 * every POST before the shared Service runs. The token comes only from the
 * SENDREPUTE_API_TOKEN environment variable; the API origin is fixed.
 */
final class ConsoleController implements ContainerInjectionInterface {

  public const API_BASE = 'https://www.sendrepute.com/api';
  public const PERMISSION = 'use sendrepute console';
  public const CSRF_VALUE = 'sendrepute_console';
  public const PATH = '/admin/config/system/sendrepute/console';

  /** @var callable */
  private $transport;

  public function __construct(
    private CsrfTokenGenerator $csrf,
    private AccountInterface $user,
    private LockStore $locks,
    callable $transport,
    private string $secret,
  ) {
    $this->transport = $transport;
  }

  public static function create(ContainerInterface $container): static {
    $token = (string) getenv('SENDREPUTE_API_TOKEN');
    return new static(
      $container->get('csrf_token'),
      $container->get('current_user'),
      new ConsoleLockStore($container->get('keyvalue.expirable')->get('sendrepute_console_locks')),
      new CurlTransport(self::API_BASE, $token),
      hash_hmac('sha256', 'sendrepute-console|' . $token, Settings::getHashSalt()),
    );
  }

  /**
   * @return array<string, mixed>|Response
   */
  public function page(Request $request) {
    if (!$this->user->hasPermission(self::PERMISSION)) {
      return new Response('Access denied.', 403);
    }
    $service = new Service($this->transport, $this->locks, $this->secret, 'drupal-' . $this->user->id(), ['hostedReturnOrigin' => (string) getenv('SENDREPUTE_HOSTED_RETURN_ORIGIN')]);
    $tab = (string) $request->query->get('tab', 'account');
    $state = [];
    if ($request->isMethod('POST')) {
      $post = $request->request->all();
      $token = is_string($post['form_token'] ?? NULL) ? $post['form_token'] : '';
      if (!$this->csrf->validate($token, self::CSRF_VALUE)) {
        $state = ['error' => 'The form token expired or is invalid. Reload the page and try again. Nothing was submitted.'];
      }
      elseif (Service::isExport((string) ($post['op'] ?? ''))) {
        $out = $service->download($post);
        if (isset($out['file'])) {
          [$name, $type, $bytes] = $out['file'];
          return new Response($bytes, 200, Contract::downloadHeaders($name, $type, strlen($bytes)));
        }
        ['tab' => $tab, 'state' => $state] = $out;
      }
      else {
        ['tab' => $tab, 'state' => $state] = $service->handle($post);
      }
    }
    $base = $request->getBaseUrl() . self::PATH;
    $renderer = new Renderer([
      'action' => $base,
      'hidden' => ['form_token' => $this->csrf->get(self::CSRF_VALUE)],
      'tabUrl' => static fn (string $t): string => $base . '?tab=' . rawurlencode($t),
      'price' => static fn (string $op) => $service->priceRead($op),
      'hostedOrigin' => $service->hostedOrigin(),
      'platform' => 'Drupal',
      'primary' => 'button button--primary sr-btn sr-btn-primary',
      'secondary' => 'button sr-btn',
    ]);
    return [
      '#type' => 'inline_template',
      '#template' => '{{ console }}',
      '#context' => ['console' => Markup::create($renderer->page($tab, $state))],
      '#cache' => ['max-age' => 0],
    ];
  }

}
