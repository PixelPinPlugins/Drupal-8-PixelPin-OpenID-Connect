<?php

namespace Drupal\pixelpin_openid_connect\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\pixelpin_openid_connect\Claims;
use Drupal\pixelpin_openid_connect\Plugin\OpenIDConnectClientManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
//use Drupal\pixelpin_openid_connect\src\Form\SettingsForm;

/**
 * Class LoginForm.
 *
 * @package Drupal\pixelpin_openid_connect\Form
 */
class LoginForm extends FormBase implements ContainerInjectionInterface {

  /**
   * Drupal\pixelpin_openid_connect\Plugin\OpenIDConnectClientManager definition.
   *
   * @var \Drupal\pixelpin_openid_connect\Plugin\OpenIDConnectClientManager
   */
  protected $pluginManager;

  /**
   * The OpenID Connect claims.
   *
   * @var \Drupal\pixelpin_openid_connect\Claims
   */
  protected $claims;

  /**
   * The constructor.
   *
   * @param \Drupal\pixelpin_openid_connect\Plugin\OpenIDConnectClientManager $plugin_manager
   *   The plugin manager.
   * @param \Drupal\pixelpin_openid_connect\Claims $claims
   *   The OpenID Connect claims.
   */
  public function __construct(
      OpenIDConnectClientManager $plugin_manager,
      Claims $claims
  ) {

    $this->pluginManager = $plugin_manager;
    $this->claims = $claims;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.pixelpin_openid_connect_client.processor'),
      $container->get('pixelpin_openid_connect.claims')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'pixelpin_openid_connect_login_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $definitions = $this->pluginManager->getDefinitions();
    foreach ($definitions as $client_id => $client) {
        if (!$this->config('pixelpin_openid_connect.settings.enable')
          ->get('enabled')) {
          continue;
        }                   
          $url = \Drupal::service('path.current')->getPath();

          $find = 'login';

          $pos = strpos($url, $find);

          if ($pos === false){
                $value = 'Register Using PixelPin';
                $label = 'PixelPin OpenID Connect Register';
            } else {
                $value = 'Log in with PixelPin';
                $label = 'PixelPin OpenID Connect Login';
            } 
          $form['pixelpin_openid_connect_client_' . $client_id . '_login_title'] = array(
            '#type' => 'item',
            '#title' => t($label),
          );  

          $form['pixelpin_openid_connect_client_' . $client_id . '_login'] = array(
            '#type' => 'submit',
            '#value' => t($value, array(
              '@client_title' => $client['label'],
            )),
            '#name' => $client_id,
            '#prefix' => '<div>',
            '#suffix' => '</div>',
          );      
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    pixelpin_openid_connect_save_destination();
    $client_name = $form_state->getTriggeringElement()['#name'];

    $configuration = $this->config('pixelpin_openid_connect.settings.' . $client_name)
      ->get('settings');
    $client = $this->pluginManager->createInstance(
      $client_name,
      $configuration
    );
    $scopes = $this->claims->getScopes();
    $_SESSION['pixelpin_openid_connect_op'] = 'login';
    $response = $client->authorize($scopes, $form_state);
    $form_state->setResponse($response);
  }

}
