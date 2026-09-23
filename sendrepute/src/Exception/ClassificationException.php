<?php

declare(strict_types=1);

namespace Drupal\sendrepute\Exception;

final class ClassificationException extends \RuntimeException {

  public function __construct(
    private readonly string $category,
    string $safeMessage,
  ) {
    parent::__construct($safeMessage);
  }

  public function category(): string {
    return $this->category;
  }

}
