<?php

final class PhortuneCart extends PhortuneDAO
  implements PhabricatorPolicyInterface {

  const STATUS_BUILDING = 'cart:building';
  const STATUS_READY = 'cart:ready';
  const STATUS_PURCHASING = 'cart:purchasing';
  const STATUS_CHARGED = 'cart:charged';
  const STATUS_PURCHASED = 'cart:purchased';

  protected $accountPHID;
  protected $authorPHID;
  protected $merchantPHID;
  protected $cartClass;
  protected $status;
  protected $metadata = array();

  private $account = self::ATTACHABLE;
  private $purchases = self::ATTACHABLE;
  private $implementation = self::ATTACHABLE;
  private $merchant = self::ATTACHABLE;

  public static function initializeNewCart(
    PhabricatorUser $actor,
    PhortuneAccount $account,
    PhortuneMerchant $merchant) {
    $cart = id(new PhortuneCart())
      ->setAuthorPHID($actor->getPHID())
      ->setStatus(self::STATUS_BUILDING)
      ->setAccountPHID($account->getPHID())
      ->setMerchantPHID($merchant->getPHID());

    $cart->account = $account;
    $cart->purchases = array();

    return $cart;
  }

  public function newPurchase(
    PhabricatorUser $actor,
    PhortuneProduct $product) {

    $purchase = PhortunePurchase::initializeNewPurchase($actor, $product)
      ->setAccountPHID($this->getAccount()->getPHID())
      ->setCartPHID($this->getPHID())
      ->save();

    $this->purchases[] = $purchase;

    return $purchase;
  }

  public static function getStatusNameMap() {
    return array(
      self::STATUS_BUILDING => pht('Building'),
      self::STATUS_READY => pht('Ready'),
      self::STATUS_PURCHASING => pht('Purchasing'),
      self::STATUS_CHARGED => pht('Charged'),
      self::STATUS_PURCHASED => pht('Purchased'),
    );
  }

  public static function getNameForStatus($status) {
    return idx(self::getStatusNameMap(), $status, $status);
  }

  public function activateCart() {
    $this->setStatus(self::STATUS_READY)->save();
    return $this;
  }

  public function willApplyCharge(
    PhabricatorUser $actor,
    PhortunePaymentProvider $provider,
    PhortunePaymentMethod $method = null) {

    $account = $this->getAccount();

    $charge = PhortuneCharge::initializeNewCharge()
      ->setAccountPHID($account->getPHID())
      ->setCartPHID($this->getPHID())
      ->setAuthorPHID($actor->getPHID())
      ->setMerchantPHID($this->getMerchant()->getPHID())
      ->setProviderPHID($provider->getProviderConfig()->getPHID())
      ->setAmountAsCurrency($this->getTotalPriceAsCurrency());

    if ($method) {
      $charge->setPaymentMethodPHID($method->getPHID());
    }

    $this->openTransaction();
      $this->beginReadLocking();

        $copy = clone $this;
        $copy->reload();

        if ($copy->getStatus() !== self::STATUS_READY) {
          throw new Exception(
            pht(
              'Cart has wrong status ("%s") to call willApplyCharge(), '.
              'expected "%s".',
              $copy->getStatus(),
              self::STATUS_READY));
        }

        $charge->save();
        $this->setStatus(PhortuneCart::STATUS_PURCHASING)->save();

      $this->endReadLocking();
    $this->saveTransaction();

    return $charge;
  }

  public function didApplyCharge(PhortuneCharge $charge) {
    $charge->setStatus(PhortuneCharge::STATUS_CHARGED);

    $this->openTransaction();
      $this->beginReadLocking();

        $copy = clone $this;
        $copy->reload();

        if ($copy->getStatus() !== self::STATUS_PURCHASING) {
          throw new Exception(
            pht(
              'Cart has wrong status ("%s") to call didApplyCharge(), '.
              'expected "%s".',
              $copy->getStatus(),
              self::STATUS_PURCHASING));
        }

        $charge->save();
        $this->setStatus(self::STATUS_CHARGED)->save();

      $this->endReadLocking();
    $this->saveTransaction();

    foreach ($this->purchases as $purchase) {
      $purchase->getProduct()->didPurchaseProduct($purchase);
    }

    $this->setStatus(self::STATUS_PURCHASED)->save();

    return $this;
  }

  public function didFailCharge(PhortuneCharge $charge) {
    $charge->setStatus(PhortuneCharge::STATUS_FAILED);

    $this->openTransaction();
      $this->beginReadLocking();

        $copy = clone $this;
        $copy->reload();

        if ($copy->getStatus() !== self::STATUS_PURCHASING) {
          throw new Exception(
            pht(
              'Cart has wrong status ("%s") to call didFailCharge(), '.
              'expected "%s".',
              $copy->getStatus(),
              self::STATUS_PURCHASING));
        }

        $charge->save();

        // Move the cart back into STATUS_READY so the user can try
        // making the purchase again.
        $this->setStatus(self::STATUS_READY)->save();

      $this->endReadLocking();
    $this->saveTransaction();

    return $this;
  }


  public function willRefundCharge(
    PhabricatorUser $actor,
    PhortunePaymentProvider $provider,
    PhortuneCharge $charge,
    PhortuneCurrency $amount) {

    if (!$amount->isPositive()) {
      throw new Exception(
        pht('Trying to refund nonpositive amount of money!'));
    }

    if ($amount->isGreaterThan($charge->getAmountRefundableAsCurrency())) {
      throw new Exception(
        pht('Trying to refund more money than remaining on charge!'));
    }

    if ($charge->getRefundedChargePHID()) {
      throw new Exception(
        pht('Trying to refund a refund!'));
    }

    if ($charge->getStatus() !== PhortuneCharge::STATUS_CHARGED) {
      throw new Exception(
        pht('Trying to refund an uncharged charge!'));
    }

    $refund_charge = PhortuneCharge::initializeNewCharge()
      ->setAccountPHID($this->getAccount()->getPHID())
      ->setCartPHID($this->getPHID())
      ->setAuthorPHID($actor->getPHID())
      ->setMerchantPHID($this->getMerchant()->getPHID())
      ->setProviderPHID($provider->getProviderConfig()->getPHID())
      ->setPaymentMethodPHID($charge->getPaymentMethodPHID())
      ->setRefundedChargePHID($charge->getPHID())
      ->setAmountAsCurrency($amount->negate());

    $charge->openTransaction();
      $charge->beginReadLocking();

        $copy = clone $charge;
        $copy->reload();

        if ($copy->getRefundingPHID() !== null) {
          throw new Exception(
            pht('Trying to refund a charge which is already refunding!'));
        }

        $refund_charge->save();
        $charge->setRefundingPHID($refund_charge->getPHID());
        $charge->save();

      $charge->endReadLocking();
    $charge->saveTransaction();

    return $refund_charge;
  }

  public function didRefundCharge(
    PhortuneCharge $charge,
    PhortuneCharge $refund) {

    $refund->setStatus(PhortuneCharge::STATUS_CHARGED);

    $this->openTransaction();
      $this->beginReadLocking();

        $copy = clone $charge;
        $copy->reload();

        if ($charge->getRefundingPHID() !== $refund->getPHID()) {
          throw new Exception(
            pht('Charge is in the wrong refunding state!'));
        }

        $charge->setRefundingPHID(null);

        // NOTE: There's some trickiness here to get the signs right. Both
        // these values are positive but the refund has a negative value.
        $total_refunded = $charge
          ->getAmountRefundedAsCurrency()
          ->add($refund->getAmountAsCurrency()->negate());

        $charge->setAmountRefundedAsCurrency($total_refunded);
        $charge->save();
        $refund->save();

      $this->endReadLocking();
    $this->saveTransaction();

    foreach ($this->purchases as $purchase) {
      $purchase->getProduct()->didRefundProduct($purchase);
    }

    return $this;
  }

  public function didFailRefund(
    PhortuneCharge $charge,
    PhortuneCharge $refund) {

    $refund->setStatus(PhortuneCharge::STATUS_FAILED);

    $this->openTransaction();
      $this->beginReadLocking();

        $copy = clone $charge;
        $copy->reload();

        if ($charge->getRefundingPHID() !== $refund->getPHID()) {
          throw new Exception(
            pht('Charge is in the wrong refunding state!'));
        }

        $charge->setRefundingPHID(null);
        $charge->save();
        $refund->save();

      $this->endReadLocking();
    $this->saveTransaction();
  }

  public function getName() {
    return $this->getImplementation()->getName($this);
  }

  public function getDoneURI() {
    return $this->getImplementation()->getDoneURI($this);
  }

  public function getCancelURI() {
    return $this->getImplementation()->getCancelURI($this);
  }

  public function getDetailURI() {
    return '/phortune/cart/'.$this->getID().'/';
  }

  public function getCheckoutURI() {
    return '/phortune/cart/'.$this->getID().'/checkout/';
  }

  public function canCancelOrder() {
    try {
      $this->assertCanCancelOrder();
      return true;
    } catch (Exception $ex) {
      return false;
    }
  }

  public function canRefundOrder() {
    try {
      $this->assertCanRefundOrder();
      return true;
    } catch (Exception $ex) {
      return false;
    }
  }

  public function assertCanCancelOrder() {
    switch ($this->getStatus()) {
      case self::STATUS_BUILDING:
        throw new Exception(
          pht(
            'This order can not be cancelled because the application has not '.
            'finished building it yet.'));
      case self::STATUS_READY:
        throw new Exception(
          pht(
            'This order can not be cancelled because it has not been placed.'));
    }

    return $this->getImplementation()->assertCanCancelOrder($this);
  }

  public function assertCanRefundOrder() {
    switch ($this->getStatus()) {
      case self::STATUS_BUILDING:
        throw new Exception(
          pht(
            'This order can not be refunded because the application has not '.
            'finished building it yet.'));
      case self::STATUS_READY:
        throw new Exception(
          pht(
            'This order can not be refunded because it has not been placed.'));
    }

    return $this->getImplementation()->assertCanRefundOrder($this);
  }

  public function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
      self::CONFIG_SERIALIZATION => array(
        'metadata' => self::SERIALIZATION_JSON,
      ),
      self::CONFIG_COLUMN_SCHEMA => array(
        'status' => 'text32',
        'cartClass' => 'text128',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'key_account' => array(
          'columns' => array('accountPHID'),
        ),
        'key_merchant' => array(
          'columns' => array('merchantPHID'),
        ),
      ),
    ) + parent::getConfiguration();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(
      PhortuneCartPHIDType::TYPECONST);
  }

  public function attachPurchases(array $purchases) {
    assert_instances_of($purchases, 'PhortunePurchase');
    $this->purchases = $purchases;
    return $this;
  }

  public function getPurchases() {
    return $this->assertAttached($this->purchases);
  }

  public function attachAccount(PhortuneAccount $account) {
    $this->account = $account;
    return $this;
  }

  public function getAccount() {
    return $this->assertAttached($this->account);
  }

  public function attachMerchant(PhortuneMerchant $merchant) {
    $this->merchant = $merchant;
    return $this;
  }

  public function getMerchant() {
    return $this->assertAttached($this->merchant);
  }

  public function attachImplementation(
    PhortuneCartImplementation $implementation) {
    $this->implementation = $implementation;
    return $this;
  }

  public function getImplementation() {
    return $this->assertAttached($this->implementation);
  }

  public function getTotalPriceAsCurrency() {
    $prices = array();
    foreach ($this->getPurchases() as $purchase) {
      $prices[] = $purchase->getTotalPriceAsCurrency();
    }

    return PhortuneCurrency::newFromList($prices);
  }

  public function setMetadataValue($key, $value) {
    $this->metadata[$key] = $value;
    return $this;
  }

  public function getMetadataValue($key, $default = null) {
    return idx($this->metadata, $key, $default);
  }


/* -(  PhabricatorPolicyInterface  )----------------------------------------- */


  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
      PhabricatorPolicyCapability::CAN_EDIT,
    );
  }

  public function getPolicy($capability) {
    return $this->getAccount()->getPolicy($capability);
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    if ($this->getAccount()->hasAutomaticCapability($capability, $viewer)) {
      return true;
    }

    // If the viewer controls the merchant this order was placed with, they
    // can view the order.
    if ($capability == PhabricatorPolicyCapability::CAN_VIEW) {
      $can_admin = PhabricatorPolicyFilter::hasCapability(
        $viewer,
        $this->getMerchant(),
        PhabricatorPolicyCapability::CAN_EDIT);
      if ($can_admin) {
        return true;
      }
    }

    return false;
  }

  public function describeAutomaticCapability($capability) {
    return array(
      pht('Orders inherit the policies of the associated account.'),
      pht('The merchant you placed an order with can review and manage it.'),
    );
  }

}
