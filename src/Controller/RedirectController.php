<?php

namespace Drupal\pixelpin_openid_connect\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\pixelpin_openid_connect\Plugin\OpenIDConnectClientManager;
use Drupal\pixelpin_openid_connect\StateToken;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class RedirectController.
 *
 * @package Drupal\pixelpin_openid_connect\Controller
 */
class RedirectController extends ControllerBase implements AccessInterface {

  /**
   * Drupal\pixelpin_openid_connect\Plugin\OpenIDConnectClientManager definition.
   *
   * @var \Drupal\pixelpin_openid_connect\Plugin\OpenIDConnectClientManager
   */
  protected $pluginManager;

  /**
   * The request stack used to access request globals.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Drupal\Core\Session\AccountProxy definition.
   *
   * @var \Drupal\Core\Session\AccountProxy
   */
  protected $currentUser;

  /**
   * {@inheritdoc}
   */
  public function __construct(
      OpenIDConnectClientManager $plugin_manager,
      RequestStack $request_stack,
      LoggerChannelFactory $logger_factory,
      AccountInterface $current_user
  ) {

    $this->pluginManager = $plugin_manager;
    $this->requestStack = $request_stack;
    $this->loggerFactory = $logger_factory;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.pixelpin_openid_connect_client.processor'),
      $container->get('request_stack'),
      $container->get('logger.factory'),
      $container->get('current_user')
    );
  }

  /**
   * Access callback: Redirect page.
   *
   * @return bool
   *   Whether the state token matches the previously created one that is stored
   *   in the session.
   */
  public function access() {
    // Confirm anti-forgery state token. This round-trip verification helps to
    // ensure that the user, not a malicious script, is making the request.
    $query = $this->requestStack->getCurrentRequest()->query;
    $state_token = $query->get('state');
    if ($state_token && StateToken::confirm($state_token)) {
      return AccessResult::allowed();
    }
    return AccessResult::forbidden();
  }

  /**
   * Redirect.
   *
   * @param string $client_name
   *   The client name.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   The redirect response starting the authentication request.
   */
  public function authenticate($client_name) {
    $client_name = 'enable';
    $query = $this->requestStack->getCurrentRequest()->query;

    // Delete the state token, since it's already been confirmed.
    unset($_SESSION['pixelpin_openid_connect_state']);

    // Get parameters from the session, and then clean up.
    $parameters = array(
      'destination' => 'user',
      'op' => 'login',
      'connect_uid' => NULL,
    );
    foreach ($parameters as $key => $default) {
      if (isset($_SESSION['pixelpin_openid_connect_' . $key])) {
        $parameters[$key] = $_SESSION['pixelpin_openid_connect_' . $key];
        unset($_SESSION['pixelpin_openid_connect_' . $key]);
      }
    }
    $destination = $parameters['destination'];

    $configuration = $this->config('pixelpin_openid_connect.settings.' . $client_name)
      ->get('settings');
    $client = $this->pluginManager->createInstance(
      $client_name,
      $configuration
    );
    if (!$query->get('error') && (!$client || !$query->get('code'))) {
      // In case we don't have an error, but the client could not be loaded or
      // there is no state token specified, the URI is probably being visited
      // outside of the login flow.
      throw new NotFoundHttpException();
    }

    if ($query->get('error')) {
      if (in_array($query->get('error'), [
        'interaction_required',
        'login_required',
        'account_selection_required',
        'consent_required',
      ])) {
        // If we have an one of the above errors, that means the user hasn't
        // granted the authorization for the claims.
        drupal_set_message(t('Logging in with PixelPin has been canceled.'), 'warning');
      }
      else {
        // Any other error should be logged. E.g. invalid scope.
        $variables = array(
          '@error' => $query->get('error'),
          '@details' => $query->get('error_description') ? $query->get('error_description') : $this->t('Unknown error.'),
        );
        $message = 'Authorization failed: @error. Details: @details';
        $this->loggerFactory->get('pixelpin_openid_connect_' . $client_name)->error($message, $variables);
        drupal_set_message(t('Could not authenticate with PixelPin.'), 'error');
      }
    }
    else {
      // Process the login or connect operations.
      $tokens = $client->retrieveTokens($query->get('code'));
      if ($tokens) {
        if ($parameters['op'] === 'login') {
          $success = pixelpin_openid_connect_complete_authorization($client, $tokens, $destination);
          if (!$success) {
            drupal_set_message(t('Logging in with PixelPin could not be completed due to an error. Could be because PixelPin cannot access your email address.'), 'error');
          }
        }
        elseif ($parameters['op'] === 'connect' && $parameters['connect_uid'] === $this->currentUser->id()) {
          $success = pixelpin_openid_connect_connect_current_user($client, $tokens);
          if ($success) {
            drupal_set_message(t('Account successfully connected with PixelPin.'));
          }
          else {
            drupal_set_message(t('Connecting with PixelPin could not be completed due to an error.'), 'error');
          }
        }
      }
    }

    // It's possible to set 'options' in the redirect destination.
    if (is_array($destination)) {
      $redirect = Url::fromUri('internal:/' . ltrim($destination[0], '/'), $destination[1])->toString();
      return new RedirectResponse($redirect);
    }
    else {
      $redirect = Url::fromUri('internal:/' . ltrim($destination, '/'))->toString();
      return new RedirectResponse($redirect);
    }
  }

}
