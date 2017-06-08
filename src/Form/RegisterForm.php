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
 * Class RegisterForm.
 *
 * @package Drupal\pixelpin_openid_connect\Form
 */
class RegisterForm extends FormBase implements ContainerInjectionInterface {

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
          $form['#attached'] = array(
            'library' => array(
              'pixelpin_openid_connect/pixelpin_logo',
            ),
          );                 
       
          $value = 'Register Using PixelPin';

          $form['pixelpin_openid_connect_text'] = array(
            '#type' => 'inline_template',
            '#template' => '<div><p>Not registered? If you have a PixelPin account already, you can register with us without needing to fill out those time consuming registration forms! If you don\'t have a PixelPin account, sign up to PixelPin using this button too! And use your PixelPin picture to log into our website as well as other websites in the future.</p></div>',
          );

          $form['pixelpin_openid_connect_client_' . $client_id . '_login'] = array(
            '#type' => 'inline_template',
            '#template' => '<div><button data-drupal-selector="edit-pixelpin-openid-connect-client-enable-login" class="button js-form-submit form-submit" id="edit-pixelpin-openid-connect-client-enable-login" name="enable" type="submit"><svg class="icon icon-pixelpin-connect" value="{{value}}"><use xlink:href="#icon-pixelpin-connect"></use></svg> {{text}}</button></div>',
            '#context' => [
              'text' => $value,
              'value' => t($value, array(
              '@client_title' => $client['label'],
            )),
            ],
          );

          $form['pixelpin_openid_connect_link'] = array(
            '#type' => 'inline_template',
            '#template' => '<div><a href="https://www.pixelpin.co.uk" target="_blank">What is PixelPin?</a></div>',
          );

          $form['pixelpin_openid_connect_client_' . $client_id . '_login_hide'] = array(
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
