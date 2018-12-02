<?php

namespace Drupal\social_auth_pinterest\Settings;

/**
 * Defines an interface for Social Auth Pinterest settings.
 */
interface PinterestAuthSettingsInterface {

  /**
   * Gets the client ID.
   *
   * @return string
   *   The client ID.
   */
  public function getClientId();

  /**
   * Gets the client secret.
   *
   * @return string
   *   The client secret.
   */
  public function getClientSecret();

}
