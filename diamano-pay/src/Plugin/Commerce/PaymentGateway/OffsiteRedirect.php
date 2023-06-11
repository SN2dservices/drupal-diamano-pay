<?php

namespace Drupal\diamano_pay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsNotificationsInterface;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Off-site Redirect payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "diamanopaypaymentgateway",
 *   label = "Passerelle de paiement au Sénégal",
 *   display_label = "Diamano Pay",
 *   forms = {
 *     "offsite-payment" = "Drupal\diamano_pay\PluginForm\OffsiteRedirect\PaymentOffsiteForm",
 *   },
 *   requires_billing_information = TRUE,
 * )
 */
class OffsiteRedirect extends OffsitePaymentGatewayBase implements SupportsNotificationsInterface
{

  private $isLive;
  private $clientId;
  private $clientSecret;
  private $apiBaseUrl;
  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration()
  {
    return [
      'sandbox_client_id' => '',
      'sandbox_client_secret' => '',
      'client_id' => '',
      'client_secret' => '',
      'payment_methods' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);

    $this->isLive = $this->configuration['mode'] !== 'test';
    $this->apiBaseUrl = !$this->isLive == 'sandbox' ? 'https://sandbox-api.diamanopay.com' : 'https://api.diamanopay.com';
    if ($this->isLive) {
      $this->clientId = $this->configuration['client_id'];
      $this->clientSecret = $this->configuration['client_secret'];
    } else {
      $this->clientId = $this->configuration['sandbox_client_id'];
      $this->clientSecret = $this->configuration['sandbox_client_secret'];
    }
  }

  /**
   * Sets the API key after the plugin is unserialized.
   */
  public function __wakeup()
  {
    $this->isLive = $this->configuration['mode'] !== 'test';
    $this->apiBaseUrl = !$this->isLive == 'sandbox' ? 'https://sandbox-api.diamanopay.com' : 'https://api.diamanopay.com';
    if ($this->isLive) {
      $this->clientId = $this->configuration['client_id'];
      $this->clientSecret = $this->configuration['client_secret'];
    } else {
      $this->clientId = $this->configuration['sandbox_client_id'];
      $this->clientSecret = $this->configuration['sandbox_client_secret'];
    }
  }
  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state)
  {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['sandbox_client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sandbox client id'),
      '#description' => $this->t("Client id à copier de l'env sandbox diamano pay."),
      '#default_value' => $this->configuration['sandbox_client_id'],
      '#required' => TRUE,
    ];
    $form['sandbox_client_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sandbox client secret'),
      '#description' => $this->t("Client secret à copier de l'env sandbox diamano pay."),
      '#default_value' => $this->configuration['sandbox_client_secret'],
      '#required' => TRUE,
    ];
    $form['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Production client id'),
      '#description' => $this->t("Client id à copier de l'env prod diamano pay."),
      '#default_value' => $this->configuration['client_id'],
      '#required' => TRUE,
    ];
    $form['client_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Production client secret'),
      '#description' => $this->t("Client secret à copier de l'env prod diamano pay."),
      '#default_value' => $this->configuration['client_secret'],
      '#required' => TRUE,
    ];

    $form['payment_methods'] = [
      '#type' => 'select',
      '#multiple' => TRUE,
      '#title' => $this
        ->t('Moyen de paiements'),
      '#options' => [
        'ORANGE_MONEY' => $this
          ->t('Orange money'),
        'WAVE' => $this
          ->t('Wave'),
        'CARD' => $this
          ->t('Carte bancaire')
      ],
      '#default_value' => $this->configuration['payment_methods'],
    ];


    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state)
  {

    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {

      $values = $form_state->getValue($form['#parents']);
      $this->configuration['sandbox_client_id'] = $values['sandbox_client_id'];
      $this->configuration['sandbox_client_secret'] = $values['sandbox_client_secret'];
      $this->configuration['client_id'] = $values['client_id'];
      $this->configuration['client_secret'] = $values['client_secret'];
      $this->configuration['payment_methods'] = $values['payment_methods'];
    }
  }

  /**
   * {@inheritdoc}
   */

  public function onNotify(Request $request)
  {
    $data = $this->getPaymentStatus($request->query->get('token'));
    $realExtraData = $data['extraData'];
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $balance = $realExtraData['balance'];
    $values = [
      'payment_gateway' => $this->parentEntity->id(),
      'order_id' => $realExtraData['order_id'],
      'test' => $this->isLive,
      'remote_id' => NULL,
      'remote_state' => $data['status'],
      'authorized' => $this->time->getRequestTime(),
      'amount' => new Price($balance['number'], $balance['currencyCode'])

    ];
    if ($data['status'] === "SUCCESS") {
      $values['state'] = 'completed';
    } else {
      $values['state'] = 'failed';
    }
    $payment = $payment_storage->create($values);
    $payment->save();

    return new JsonResponse($data);
  }

  private function getPaymentStatus($paymentToken)
  {
    $url = $this->apiBaseUrl . '/api/payment/cms/paymentStatus?clientId=' . $this->clientId . '&clientSecret=' . $this->clientSecret . '&token=' . $paymentToken;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = json_decode(curl_exec($ch), true);
    if ($response["statusCode"] != null && $response["statusCode"] != "200") {
      die($response["message"]);
    }
    return $response;
  }
}
