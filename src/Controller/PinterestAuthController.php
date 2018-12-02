<?php

namespace Drupal\social_auth_pinterest\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\social_api\Plugin\NetworkManager;
use Drupal\social_auth\SocialAuthDataHandler;
use Drupal\social_auth\SocialAuthUserManager;
use Drupal\social_auth_pinterest\PinterestAuthManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Returns responses for Social Auth Pinterest routes.
 */
class PinterestAuthController extends ControllerBase {

  /**
   * The network plugin manager.
   *
   * @var \Drupal\social_api\Plugin\NetworkManager
   */
  private $networkManager;

  /**
   * The user manager.
   *
   * @var \Drupal\social_auth\SocialAuthUserManager
   */
  private $userManager;

  /**
   * The pinterest authentication manager.
   *
   * @var \Drupal\social_auth_pinterest\PinterestAuthManager
   */
  private $pinterestManager;

  /**
   * Used to access GET parameters.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $request;

  /**
   * The Social Auth Data Handler.
   *
   * @var \Drupal\social_auth\SocialAuthDataHandler
   */
  private $dataHandler;


  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * PinterestAuthController constructor.
   *
   * @param \Drupal\social_api\Plugin\NetworkManager $network_manager
   *   Used to get an instance of social_auth_pinterest network plugin.
   * @param \Drupal\social_auth\SocialAuthUserManager $user_manager
   *   Manages user login/registration.
   * @param \Drupal\social_auth_pinterest\PinterestAuthManager $pinterest_manager
   *   Used to manage authentication methods.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request
   *   Used to access GET parameters.
   * @param \Drupal\social_auth\SocialAuthDataHandler $data_handler
   *   SocialAuthDataHandler object.
   */
  public function __construct(NetworkManager $network_manager,
                              SocialAuthUserManager $user_manager,
                              PinterestAuthManager $pinterest_manager,
                              RequestStack $request,
                              SocialAuthDataHandler $data_handler) {

    $this->networkManager = $network_manager;
    $this->userManager = $user_manager;
    $this->pinterestManager = $pinterest_manager;
    $this->request = $request;
    $this->dataHandler = $data_handler;

    // Sets the plugin id.
    $this->userManager->setPluginId('social_auth_pinterest');

    // Sets the session keys to nullify if user could not logged in.
    $this->userManager->setSessionKeysToNullify(['access_token', 'oauth2state']);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.network.manager'),
      $container->get('social_auth.user_manager'),
      $container->get('social_auth_pinterest.manager'),
      $container->get('request_stack'),
      $container->get('social_auth.data_handler')
    );
  }

  /**
   * Response for path 'user/login/pinterest'.
   *
   * Redirects the user to Pinterest for authentication.
   */
  public function redirectToPinterest() {
    /* @var \League\OAuth2\Client\Provider\Pinterest|false $pinterest */
    $pinterest = $this->networkManager->createInstance('social_auth_pinterest')->getSdk();

    // If pinterest client could not be obtained.
    if (!$pinterest) {
      drupal_set_message($this->t('Social Auth Pinterest not configured properly. Contact site administrator.'), 'error');
      return $this->redirect('user.login');
    }

    // Pinterest service was returned, inject it to $pinterestManager.
    $this->pinterestManager->setClient($pinterest);

    // Generates the URL where the user will be redirected for Pinterest login.
    $pinterest_login_url = $this->pinterestManager->getAuthorizationUrl();

    $state = $this->pinterestManager->getState();

    $this->dataHandler->set('oauth2state', $state);

    return new TrustedRedirectResponse($pinterest_login_url);
  }

  /**
   * Response for path 'user/login/pinterest/callback'.
   *
   * Pinterest returns the user here after user has authenticated in Pinterest.
   */
  public function callback() {

    // Checks if user cancel login.
    $error = $this->request->getCurrentRequest()->get('error');
    if ($error == 'access_denied') {
      drupal_set_message($this->t('You could not be authenticated.'), 'error');
      return $this->redirect('user.login');
    }

    /* @var \League\OAuth2\Client\Provider\Pinterest false $pinterest */
    $pinterest = $this->networkManager->createInstance('social_auth_pinterest')->getSdk();

    // If Pinterest client could not be obtained.
    if (!$pinterest) {
      drupal_set_message($this->t('Social Auth Pinterest not configured properly. Contact site administrator.'), 'error');
      return $this->redirect('user.login');
    }

    $state = $this->dataHandler->get('oauth2state');

    // Retreives $_GET['state'].
    $retrievedState = $this->request->getCurrentRequest()->query->get('state');
    if (empty($retrievedState) || ($retrievedState !== $state)) {
      $this->userManager->nullifySessionKeys();
      drupal_set_message($this->t('Pinterest login failed. Unvalid OAuth2 State.'), 'error');
      return $this->redirect('user.login');
    }

    $this->pinterestManager->setClient($pinterest)->authenticate();

    // Saves access token to session.
    $this->dataHandler->set('access_token', $this->pinterestManager->getAccessToken());

    // Gets user's info from Pinterest API.
    /* @var \League\OAuth2\Client\Provider\PinterestResourceOwner $pinterest_profile */
    if (!$pinterest_profile = $this->pinterestManager->getUserInfo()) {
      drupal_set_message($this->t('Pinterest login failed, could not load Pinterest profile. Contact site administrator.'), 'error');
      return $this->redirect('user.login');
    }

    $data = $this->userManager->checkIfUserExists($pinterest_profile->getId()) ? NULL : $this->pinterestManager->getExtraDetails();

    // If user information could be retrieved.
    return $this->userManager->authenticateUser($pinterest_profile->getName(), '', $pinterest_profile->getId(), $this->pinterestManager->getAccessToken(), $pinterest_profile->getImageurl(), $data);

  }

}
