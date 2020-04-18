<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Newsletter\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Mail\EmailMessage;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Mail\Template\TransportBuilderMock;
use PHPUnit\Framework\TestCase;

/**
 * Class checks subscription behavior.
 *
 * @see \Magento\Newsletter\Model\Subscriber
 */
class SubscriberTest extends TestCase
{
    /** @var ObjectManagerInterface  */
    private $objectManager;

    /** @var SubscriberFactory */
    private $subscriberFactory;

    /** @var TransportBuilderMock  */
    private $transportBuilder;

    /** @var CustomerRepositoryInterface */
    private $customerRepository;

    /**
     * @inheritdoc
     */
    protected function setUp()
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->subscriberFactory = $this->objectManager->get(SubscriberFactory::class);
        $this->transportBuilder = $this->objectManager->get(TransportBuilderMock::class);
        $this->customerRepository = $this->objectManager->get(CustomerRepositoryInterface::class);
    }

    /**
     * @magentoConfigFixture current_store newsletter/subscription/confirm 1
     *
     * @magentoDataFixture Magento/Newsletter/_files/subscribers.php
     *
     * @return void
     */
    public function testEmailConfirmation(): void
    {
        $subscriber = $this->subscriberFactory->create();
        $subscriber->subscribe('customer_confirm@example.com');
        // confirmationCode 'ysayquyajua23iq29gxwu2eax2qb6gvy' is taken from fixture
        $this->assertEmailContains(
            '/newsletter/subscriber/confirm/id/' . $subscriber->getSubscriberId()
            . '/code/ysayquyajua23iq29gxwu2eax2qb6gvy',
            '//p/a/@href',
            $this->transportBuilder->getSentMessage()
        );
        $this->assertEquals(Subscriber::STATUS_NOT_ACTIVE, $subscriber->getSubscriberStatus());
    }

    /**
     * @magentoDataFixture Magento/Newsletter/_files/subscribers.php
     *
     * @return void
     */
    public function testLoadByCustomerId(): void
    {
        $subscriber = $this->subscriberFactory->create();
        $this->assertSame($subscriber, $subscriber->loadByCustomerId(1));
        $this->assertEquals('customer@example.com', $subscriber->getSubscriberEmail());
    }

    /**
     * @magentoDataFixture Magento/Newsletter/_files/subscribers.php
     *
     * @magentoAppArea frontend
     *
     * @return void
     */
    public function testUnsubscribeSubscribe(): void
    {
        $subscriber = $this->subscriberFactory->create();
        $this->assertSame($subscriber, $subscriber->loadByCustomerId(1));
        $this->assertEquals($subscriber, $subscriber->unsubscribe());
        $this->assertEmailContains(
            'You have been unsubscribed from the newsletter.',
            '//td[@class="main-content"]',
            $this->transportBuilder->getSentMessage()
        );
        $this->assertEquals(Subscriber::STATUS_UNSUBSCRIBED, $subscriber->getSubscriberStatus());
        // Subscribe and verify
        $this->assertEquals(Subscriber::STATUS_SUBSCRIBED, $subscriber->subscribe('customer@example.com'));
        $this->assertEquals(Subscriber::STATUS_SUBSCRIBED, $subscriber->getSubscriberStatus());
        $this->assertEmailContains(
            'You have been successfully subscribed to our newsletter.',
            '//td[@class="main-content"]',
            $this->transportBuilder->getSentMessage()
        );
    }

    /**
     * @magentoDataFixture Magento/Newsletter/_files/subscribers.php
     *
     * @magentoAppArea frontend
     *
     * @return void
     */
    public function testUnsubscribeSubscribeByCustomerId(): void
    {
        $subscriber = $this->subscriberFactory->create();
        // Unsubscribe and verify
        $this->assertSame($subscriber, $subscriber->unsubscribeCustomerById(1));
        $this->assertEquals(Subscriber::STATUS_UNSUBSCRIBED, $subscriber->getSubscriberStatus());
        $this->assertEmailContains(
            'You have been unsubscribed from the newsletter.',
            '//td[@class="main-content"]',
            $this->transportBuilder->getSentMessage()
        );
        // Subscribe and verify
        $this->assertSame($subscriber, $subscriber->subscribeCustomerById(1));
        $this->assertEquals(Subscriber::STATUS_SUBSCRIBED, $subscriber->getSubscriberStatus());
        $this->assertEmailContains(
            'You have been successfully subscribed to our newsletter.',
            '//td[@class="main-content"]',
            $this->transportBuilder->getSentMessage()
        );
    }

    /**
     * @magentoConfigFixture current_store newsletter/subscription/confirm 1
     *
     * @magentoDataFixture Magento/Newsletter/_files/subscribers.php
     *
     * @return void
     */
    public function testConfirm(): void
    {
        $subscriber = $this->subscriberFactory->create();
        $customerEmail = 'customer_confirm@example.com';
        $subscriber->subscribe($customerEmail);
        $subscriber->loadByEmail($customerEmail);
        $subscriber->confirm($subscriber->getSubscriberConfirmCode());
        $this->assertEmailContains(
            'You have been successfully subscribed to our newsletter.',
            '//td[@class="main-content"]',
            $this->transportBuilder->getSentMessage()
        );
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/customer_confirmation_config_enable.php
     * @magentoDataFixture Magento/Newsletter/_files/newsletter_unconfirmed_customer.php
     *
     * @return void
     */
    public function testSubscribeUnconfirmedCustomerWithSubscription(): void
    {
        $customer = $this->customerRepository->get('unconfirmedcustomer@example.com');
        $subscriber = $this->subscriberFactory->create();
        $subscriber->subscribeCustomerById($customer->getId());
        $this->assertEquals(Subscriber::STATUS_SUBSCRIBED, $subscriber->getStatus());
    }

    /**
     * @magentoDataFixture Magento/Customer/_files/customer_confirmation_config_enable.php
     * @magentoDataFixture Magento/Customer/_files/unconfirmed_customer.php
     *
     * @return void
     */
    public function testSubscribeUnconfirmedCustomerWithoutSubscription(): void
    {
        $customer = $this->customerRepository->get('unconfirmedcustomer@example.com');
        $subscriber = $this->subscriberFactory->create();
        $subscriber->subscribeCustomerById($customer->getId());
        $this->assertEquals(Subscriber::STATUS_UNCONFIRMED, $subscriber->getStatus());
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
