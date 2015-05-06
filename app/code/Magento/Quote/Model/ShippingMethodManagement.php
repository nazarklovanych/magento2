<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

// @codingStandardsIgnoreFile

namespace Magento\Quote\Model;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\StateException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Quote\Api\ShippingMethodManagementInterface;

/**
 * Shipping method read service.
 */
class ShippingMethodManagement implements ShippingMethodManagementInterface
{
    /**
     * Quote repository.
     *
     * @var QuoteRepository
     */
    protected $quoteRepository;

    /**
     * Shipping method converter
     *
     * @var \Magento\Quote\Model\Cart\ShippingMethodConverter
     */
    protected $converter;

    /**
     * Constructs a shipping method read service object.
     *
     * @param QuoteRepository $quoteRepository Quote repository.
     * @param \Magento\Quote\Model\Cart\ShippingMethodConverter $converter Shipping method converter.
     */
    public function __construct(
        QuoteRepository $quoteRepository,
        Cart\ShippingMethodConverter $converter
    ) {
        $this->quoteRepository = $quoteRepository;
        $this->converter = $converter;
    }

    /**
     * {@inheritDoc}
     */
    public function get($cartId)
    {
        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $this->quoteRepository->getActive($cartId);

        /** @var \Magento\Quote\Model\Quote\Address $shippingAddress */
        $shippingAddress = $quote->getShippingAddress();
        if (!$shippingAddress->getCountryId()) {
            throw new StateException(__('Shipping address not set.'));
        }

        $shippingMethod = $shippingAddress->getShippingMethod();
        if (!$shippingMethod) {
            return null;
        }

        $shippingAddress->collectShippingRates();
        /** @var \Magento\Quote\Model\Quote\Address\Rate $shippingRate */
        $shippingRate = $shippingAddress->getShippingRateByCode($shippingMethod);
        return $this->converter->modelToDataObject($shippingRate, $quote->getQuoteCurrencyCode());
    }

    /**
     * {@inheritDoc}
     */
    public function getList($cartId)
    {
        $output = [];

        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $this->quoteRepository->getActive($cartId);

        // no methods applicable for empty carts or carts with virtual products
        if ($quote->isVirtual() || 0 == $quote->getItemsCount()) {
            return [];
        }

        $shippingAddress = $quote->getShippingAddress();
        if (!$shippingAddress->getCountryId()) {
            throw new StateException(__('Shipping address not set.'));
        }
        $shippingAddress->collectShippingRates();
        $shippingRates = $shippingAddress->getGroupedAllShippingRates();
        foreach ($shippingRates as $carrierRates) {
            foreach ($carrierRates as $rate) {
                $output[] = $this->converter->modelToDataObject($rate, $quote->getQuoteCurrencyCode());
            }
        }
        return $output;
    }

    /**
     * {@inheritDoc}
     *
     * @param int $cartId The shopping cart ID.
     * @param string $carrierCode The carrier code.
     * @param string $methodCode The shipping method code.
     * @return bool
     * @throws \Magento\Framework\Exception\InputException The shipping method is not valid for an empty cart.
     * @throws \Magento\Framework\Exception\CouldNotSaveException The shipping method could not be saved.
     * @throws \Magento\Framework\Exception\NoSuchEntityException The specified cart contains only virtual products and the shipping method is not applicable.
     * @throws \Magento\Framework\Exception\StateException The billing or shipping address is not set.
     */
    public function set($cartId, $carrierCode, $methodCode)
    {
        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $this->quoteRepository->getActive($cartId);
        if (0 == $quote->getItemsCount()) {
            throw new InputException(__('Shipping method is not applicable for empty cart'));
        }
        if ($quote->isVirtual()) {
            throw new NoSuchEntityException(
                __('Cart contains virtual product(s) only. Shipping method is not applicable.')
            );
        }
        $shippingAddress = $quote->getShippingAddress();
        if (!$shippingAddress->getCountryId()) {
            throw new StateException(__('Shipping address is not set'));
        }
        $billingAddress = $quote->getBillingAddress();
        if (!$billingAddress->getCountryId()) {
            throw new StateException(__('Billing address is not set'));
        }
        $shippingAddress->setShippingMethod($carrierCode . '_' . $methodCode);
        if (!$shippingAddress->requestShippingRates()) {
            throw new NoSuchEntityException(
                __('Carrier with such method not found: %1, %2', $carrierCode, $methodCode)
            );
        }
        try {
            $this->quoteRepository->save($quote->collectTotals());
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Cannot set shipping method. %1', $e->getMessage()));
        }
        return true;
    }
}
