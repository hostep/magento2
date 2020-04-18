<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ProductAlert\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Mail\EmailMessage;
use Magento\ProductAlert\Model\Email;
use Magento\Store\Model\Website;
use Magento\TestFramework\Mail\Template\TransportBuilderMock;

/**
 * Test for Magento\ProductAlert\Model\Email class.
 *
 * @magentoAppIsolation enabled
 */
class EmailTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Email
     */
    protected $_emailModel;

    /**
     * @var \Magento\TestFramework\ObjectManager
     */
    protected $_objectManager;

    /**
     * @var \Magento\Customer\Api\AccountManagementInterface
     */
    protected $customerAccountManagement;

    /**
     * @var \Magento\Customer\Helper\View
     */
    protected $_customerViewHelper;

    /**
     * @var TransportBuilderMock
     */
    private $transportBuilder;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @inheritdoc
     */
    protected function setUp()
    {
        $this->_objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();
        $this->customerAccountManagement = $this->_objectManager->create(
            \Magento\Customer\Api\AccountManagementInterface::class
        );
        $this->_customerViewHelper = $this->_objectManager->create(\Magento\Customer\Helper\View::class);
        $this->transportBuilder = $this->_objectManager->get(TransportBuilderMock::class);
        $this->customerRepository = $this->_objectManager->create(CustomerRepositoryInterface::class);
        $this->productRepository = $this->_objectManager->create(ProductRepositoryInterface::class);

        $this->_emailModel = $this->_objectManager->create(Email::class);
    }

    /**
     * @magentoAppArea frontend
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @dataProvider customerFunctionDataProvider
     *
     * @param bool isCustomerIdUsed
     */
    public function testSend($isCustomerIdUsed)
    {
        /** @var Website $website */
        $website = $this->_objectManager->create(Website::class);
        $website->load(1);
        $this->_emailModel->setWebsite($website);

        $customer = $this->customerRepository->getById(1);

        if ($isCustomerIdUsed) {
            $this->_emailModel->setCustomerId(1);
        } else {
            $this->_emailModel->setCustomerData($customer);
        }

        /** @var \Magento\Catalog\Model\Product $product */
        $product = $this->productRepository->getById(1);

        $this->_emailModel->addPriceProduct($product);
        $this->_emailModel->send();

        $this->assertEmailContains(
            'John Smith,',
            '//p[@class="greeting"]',
            $this->transportBuilder->getSentMessage()
        );
    }

    public function customerFunctionDataProvider()
    {
        return [
            [true],
            [false],
        ];
    }

    /**
     * Assert that product price shown correct in email for customers with different customer groups.
     *
     * @magentoAppArea frontend
     * @magentoDataFixture Magento/Catalog/_files/product_simple_with_wholesale_tier_price.php
     * @magentoDataFixture Magento/Customer/_files/two_customers_with_different_customer_groups.php
     *
     * @return void
     */
    public function testEmailForDifferentCustomers(): void
    {
        $customerGeneral = $this->customerRepository->get('customer@example.com');
        $customerWholesale = $this->customerRepository->get('customer_two@example.com');
        $product = $this->productRepository->get('simple');

        /** @var Website $website */
        $website = $this->_objectManager->create(Website::class);
        $website->load(1);

        $data = [
            $customerGeneral->getId() => '10',
            $customerWholesale->getId() => '5',
        ];

        foreach ($data as $customerId => $expectedPrice) {
            $this->_emailModel->clean();
            $this->_emailModel->setCustomerId($customerId);
            $this->_emailModel->setWebsite($website);
            $this->_emailModel->addStockProduct($product);
            $this->_emailModel->setType('stock');
            $this->_emailModel->send();

            $this->assertEmailContains(
                $expectedPrice,
                '//span[@id="product-price-' . $product->getId() . '"]/@data-price-amount',
                $this->transportBuilder->getSentMessage()
            );
            $this->assertEmailContains(
                '$' . $expectedPrice . '.00',
                '//span[@id="product-price-' . $product->getId() . '"]/span[@class="price"]',
                $this->transportBuilder->getSentMessage()
            );
        }
    }

    /**
     * Verifies if certain content is contained in the provided xpath in the email template
     * The xpath provided can only match a single node!
     *
     * @param string $expectedGreeting
     * @param string $xpath
     * @param EmailMessage $message
     */
    private function assertEmailContains(string $expected, string $xpath, EmailMessage $message)
    {
        $messageContent = $this->getMessageRawContent($message);
        $emailDom = new \DOMDocument();
        $emailDom->loadHTML($messageContent);

        $emailXpath = new \DOMXPath($emailDom);
        $emailDomNodes = $emailXpath->query($xpath);

        $this->assertSame(1, $emailDomNodes->length);
        $this->assertContains($expected, $emailDomNodes->item(0)->textContent);
    }

    /**
     * Returns raw content of provided message
     *
     * @param EmailMessage $message
     * @return string
     */
    private function getMessageRawContent(EmailMessage $message): string
    {
        $emailParts = $message->getBody()->getParts();
        return current($emailParts)->getRawContent();
    }
}
