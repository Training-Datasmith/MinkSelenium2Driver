<?php

declare(strict_types=1);

/**
 * MinkSelenium2Driver — browser automation with Selenium/ChromeDriver example.
 *
 * Shows how to run JavaScript-enabled tests against a real browser.
 *
 * --- Prerequisites ---
 *
 * 1. Start ChromeDriver:
 *    chromedriver --port=4444
 *
 *    Or start Selenium Grid:
 *    java -jar selenium-server-4.jar standalone --port 4444
 *
 * 2. Install driver:
 *    composer require behat/mink-selenium2-driver
 *
 * --- PHPUnit test class ---
 *
 * use Behat\Mink\Mink;
 * use Behat\Mink\Session;
 * use Behat\Mink\Driver\Selenium2Driver;
 *
 * class CheckoutTest extends TestCase
 * {
 *     private Mink $mink;
 *
 *     protected function setUp(): void
 *     {
 *         $driver  = new Selenium2Driver('chrome', null, 'http://localhost:4444/wd/hub');
 *         $session = new Session($driver);
 *
 *         $this->mink = new Mink(['chrome' => $session]);
 *         $this->mink->set_default_session_name('chrome');
 *         $this->mink->get_session()->start();
 *     }
 *
 *     protected function tearDown(): void
 *     {
 *         $this->mink->stop_sessions();
 *     }
 *
 *     public function testAddToCartWithJavaScript(): void
 *     {
 *         $session = $this->mink->get_session();
 *         $session->visit('http://localhost:8080/products/widget');
 *
 *         $page = $session->getPage();
 *         $page->pressButton('Add to Cart');
 *
 *         // Wait for AJAX to complete
 *         $session->wait(2000, "document.querySelector('.cart-count').textContent === '1'");
 *
 *         $cartCount = $page->find('css', '.cart-count');
 *         $this->assertEquals('1', $cartCount->getText());
 *     }
 *
 *     public function testJavaScriptExecution(): void
 *     {
 *         $session = $this->mink->get_session();
 *         $session->visit('http://localhost:8080/');
 *
 *         // Execute arbitrary JavaScript
 *         $title = $session->evaluateScript('return document.title;');
 *         $this->assertStringContainsString('My Shop', $title);
 *     }
 * }
 */

echo 'MinkSelenium2Driver requires a running WebDriver server.' . PHP_EOL;
echo 'See the docblock above for test patterns.' . PHP_EOL;
