<?php

namespace Drupal\diamano_pay\PluginForm\OffsiteRedirect;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;


class PaymentOffsiteForm extends BasePaymentOffsiteForm
{

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state)
  {
    $form = parent::buildConfigurationForm($form, $form_state);
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;

    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $configuration = $payment_gateway_plugin->getConfiguration();
    // Payment gateway configuration data.
    $IsLive = $configuration['mode'] !== 'test';
    $token = '';
    if ($IsLive) {
      $token = $configuration['production_token'];
    } else {
      $token = $configuration['sandbox_token'];
    }
    $payment_methods = $configuration['payment_methods'];
    // Payment data.
    $total = $payment->getAmount()->getNumber();
    $order_id = $payment->getOrderId();

    // Order and billing address.
    $order = $payment->getOrder();
    $name = 'Commande faite par ';
    if ($order->getBillingProfile() != null && $order->getBillingProfile()->get('address') != null) {
      $info = $order->getBillingProfile()->get('address')->first()->getValue();
      $name .= $info['given_name'] . ' ' . $info['family_name'] . 'avec adresse ' . $info['address_line1'];
    }
    $url = 'https://api.diamanopay.com/api/payment/paymentToken';
    $host = \Drupal::request()->getSchemeAndHttpHost();
    $baseUrl = \Drupal::request()->getBaseUrl();
    $webhook = $host . $baseUrl . "/payment/notify/diamano_pay";
    $balance = $order->getBalance();
    $body = array(
      'amount' => (float)$total,
      'callbackSuccessUrl' => $form['#return_url'],
      'callbackCancelUrl' => $form['#cancel_url'],
      'paymentMethods' => array_values($payment_methods),
      'description' => $name,
      'extraData' => array("order_id" => (int)$order_id, "total" => (float)$total, "balance" => [
        "number" => $balance->getNumber(),
        "currencyCode" => $balance->getCurrencyCode(),
      ]),
      'webhook' => $webhook
    );
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      'Content-Type: application/json',
      'Authorization: Bearer ' . $token,
      'Content-Length: ' . strlen(json_encode($body))
    ));
    $response = json_decode(curl_exec($ch), true);
    if ($response["statusCode"] != null && $response["statusCode"] != "200") {
      if ($response["message"] instanceof string) {
        die($response["message"]);
      } else {
        die($response["message"][0]);
      }
    }
    $data = [];
    return $this->buildRedirectForm(
      $form,
      $form_state,
      $response['paymentUrl'],
      $data,
      self::REDIRECT_GET
    );
  }
}
