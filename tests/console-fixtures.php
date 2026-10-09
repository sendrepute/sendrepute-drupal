<?php

declare(strict_types=1);

// Offline tests for the Drupal service console adapter. Minimal framework
// stubs; the controller, lock store and shared core are the real files.

namespace Symfony\Component\DependencyInjection {
  interface ContainerInterface { public function get($id); }
}

namespace Symfony\Component\HttpFoundation {
  class ParameterBag {
    public function __construct(private array $p = []) {}
    public function get($k, $d = NULL) { return $this->p[$k] ?? $d; }
    public function all(): array { return $this->p; }
  }
  class Request {
    public ParameterBag $query;
    public ParameterBag $request;
    public function __construct(array $q = [], array $p = [], private string $method = 'GET') {
      $this->query = new ParameterBag($q);
      $this->request = new ParameterBag($p);
    }
    public function isMethod($m): bool { return strtoupper($m) === $this->method; }
    public function getBaseUrl(): string { return '/drupal'; }
  }
  class Response {
    public function __construct(public string $content = '', public int $status = 200, public array $headers = []) {}
  }
}

namespace Drupal\Core\DependencyInjection {
  interface ContainerInjectionInterface {}
}

namespace Drupal\Core\Access {
  class CsrfTokenGenerator {
    public function get($value = '') { return 'tok-' . $value; }
    public function validate($token, $value = '') { return $token === 'tok-' . $value; }
  }
}

namespace Drupal\Core\Render {
  final class Markup {
    public function __construct(public string $s) {}
    public static function create($s) { return new self($s); }
  }
}

namespace Drupal\Core\Session {
  interface AccountInterface { public function hasPermission($p); public function id(); }
}

namespace Drupal\Core\Site {
  final class Settings { public static function getHashSalt() { return 'salt'; } }
}

namespace Drupal\Core\KeyValueStore {
  interface KeyValueStoreExpirableInterface { public function setWithExpireIfNotExists($key, $value, $expire); }
}

namespace {
  use Drupal\Core\Access\CsrfTokenGenerator;
  use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
  use Drupal\Core\Session\AccountInterface;
  use Drupal\sendrepute\Controller\ConsoleController;
  use Drupal\sendrepute\Service\ConsoleLockStore;
  use Symfony\Component\HttpFoundation\Request;
  use Symfony\Component\HttpFoundation\Response;

  $root = dirname(__DIR__) . '/sendrepute/src';
  foreach (['Contract', 'LockStore', 'FileLockStore', 'CurlTransport', 'Service', 'Renderer'] as $c) {
    require_once "$root/Console/$c.php";
  }
  require_once "$root/Service/ConsoleLockStore.php";
  require_once "$root/Controller/ConsoleController.php";

  $n = 0;
  $ok = function ($c, string $l) use (&$n): void {
    if (!$c) { fwrite(STDERR, "FAIL: $l\n"); exit(1); }
    $n++;
  };

  $kv = new class implements KeyValueStoreExpirableInterface {
    public array $d = [];
    public function setWithExpireIfNotExists($key, $value, $expire) {
      if (isset($this->d[$key])) { return FALSE; }
      $this->d[$key] = [$value, $expire];
      return TRUE;
    }
  };
  $user = new class implements AccountInterface {
    public bool $allowed = TRUE;
    public function hasPermission($p) { return $this->allowed && $p === 'use sendrepute console'; }
    public function id() { return 7; }
  };
  $calls = [];
  $routes = [
    'GET /v1/account' => ['username' => 'drupal-fixture'],
    'GET /v1/vip' => ['active' => TRUE, 'regularAiTemplatePriceMillicents' => 199000, 'aiTemplatePriceMillicents' => 99000, 'templateAccessPriceMillicents' => 50000],
    'GET /v1/vip/plans' => ['monthlyPriceMillicents' => 900000],
    'POST /v1/vip/purchase' => ['active' => TRUE],
    'POST /v1/email-builder/export' => ['valid' => TRUE, 'filename' => 'x.html', 'html' => '<p>x</p>'],
    'GET /customer/paid-results/identity' => ['credentialId' => 'c1'],
  ];
  $transport = function ($m, $p, $b, $max) use (&$calls, $routes) {
    $calls[] = "$m $p";
    return isset($routes["$m $p"]) ? ['status' => 200, 'data' => $routes["$m $p"], 'error' => ''] : ['status' => 404, 'data' => NULL, 'error' => 'Not found'];
  };
  $c = new ConsoleController(new CsrfTokenGenerator(), $user, new ConsoleLockStore($kv), $transport, 'secret');

  // Route and permission registration.
  $routing = file_get_contents(dirname(__DIR__) . '/sendrepute/sendrepute.routing.yml');
  $ok(str_contains($routing, "sendrepute.console:") && str_contains($routing, "ConsoleController::page") && str_contains($routing, "_permission: 'use sendrepute console'"), 'route registered with permission');
  $perm = file_get_contents(dirname(__DIR__) . '/sendrepute/sendrepute.permissions.yml');
  $ok(preg_match("/use sendrepute console:[\\s\\S]*restrict access: true/", $perm) === 1, 'permission restricted');
  $ok(str_contains(file_get_contents(dirname(__DIR__) . '/sendrepute/sendrepute.links.menu.yml'), 'route_name: sendrepute.console'), 'menu link');

  // GET renders all tabs with CSRF field.
  foreach (['account', 'billing', 'catalog', 'ai', 'builder', 'recovery'] as $t) {
    $calls = [];
    $r = $c->page(new Request(['tab' => $t]));
    $html = $r['#context']['console']->s;
    $ok(str_contains($html, 'name="form_token" value="tok-sendrepute_console"') && $r['#cache']['max-age'] === 0, "$t csrf + no cache");
    $ok(!str_contains($html, '<script'), "$t no script");
    $ok(array_diff($calls, ['GET /v1/vip', 'GET /v1/vip/plans']) === [], "$t only price reads on GET");
  }

  // Permission re-check.
  $user->allowed = FALSE;
  $ok($c->page(new Request()) instanceof Response && $c->page(new Request())->status === 403, 'permission denied');
  $user->allowed = TRUE;

  // CSRF.
  $calls = [];
  $r = $c->page(new Request([], ['op' => 'account', 'form_token' => 'bad'], 'POST'));
  $ok($calls === ['GET /v1/vip', 'GET /v1/vip/plans'] || !in_array('GET /v1/account', $calls, TRUE), 'bad csrf no call');
  $ok(str_contains($r['#context']['console']->s, 'form token'), 'csrf error shown');

  // Valid read.
  $calls = [];
  $r = $c->page(new Request([], ['op' => 'account', 'form_token' => 'tok-sendrepute_console'], 'POST'));
  $ok(in_array('GET /v1/account', $calls, TRUE) && str_contains($r['#context']['console']->s, 'drupal-fixture'), 'account read');
  $r = $c->page(new Request([], ['op' => 'paid_identity', 'form_token' => 'tok-sendrepute_console'], 'POST'));
  $ok(str_contains($r['#context']['console']->s, 'c1'), 'recovery identity');

  // Paid: once, then durable lock refuses.
  $post = ['op' => 'vip_purchase', 'paid_consent' => '1', 'expected_price' => '900000', 'form_token' => 'tok-sendrepute_console'];
  $calls = [];
  $c->page(new Request([], $post, 'POST'));
  $ok(count(array_keys($calls, 'POST /v1/vip/purchase')) === 1 && count($kv->d) === 1 && reset($kv->d)[1] === 86400, 'paid once with 24h lock');
  $calls = [];
  $r = $c->page(new Request([], $post, 'POST'));
  $ok(!in_array('POST /v1/vip/purchase', $calls, TRUE) && str_contains($r['#context']['console']->s, 'already submitted'), 'replay refused');
  $calls = [];
  $c->page(new Request([], ['expected_price' => '1'] + $post, 'POST'));
  $ok(!in_array('POST /v1/vip/purchase', $calls, TRUE), 'stale price refused');

  // Download.
  $r = $c->page(new Request([], ['op' => 'mjml_export', 'mjml' => '<mjml></mjml>', 'format' => 'html', 'form_token' => 'tok-sendrepute_console'], 'POST'));
  $ok($r instanceof Response && $r->headers['Content-Type'] === 'application/octet-stream' && str_starts_with($r->headers['Content-Disposition'], 'attachment;') && $r->headers['X-Content-Type-Options'] === 'nosniff', 'safe download');

  // Lock key validation.
  $ok(!(new ConsoleLockStore($kv))->claim('../x', 60), 'lock key validated');
  $ok(ConsoleController::API_BASE === 'https://www.sendrepute.com/api', 'fixed origin');

  echo "drupal console-fixtures: $n assertions passed\n";
}
