<?php

declare (strict_types=1);
/*
 * This file is part of the Behat\Mink.
 * (c) Konstantin Kudryashov <ever.zet@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Behat\Mink\Driver;

use Behat\Mink\Exception\Driver_Exception;
use Behat\Mink\Key_Modifier;
use Behat\Mink\Selector\Xpath\Escaper;
use Web_Driver\Element;
use Web_Driver\Exception\Invalid_Argument;
use Web_Driver\Exception\No_Such_Element;
use Web_Driver\Exception\Script_Timeout;
use Web_Driver\Exception\Stale_Element_Reference;
use Web_Driver\Exception\Timeout;
use Web_Driver\Exception\Unknown_Command;
use Web_Driver\Exception\Unknown_Error;
use Web_Driver\Key;
use Web_Driver\Session;
use Web_Driver\Web_Driver;
use Web_Driver\Window;
/**
 * Selenium2 driver.
 *
 * @author Pete Otaqui <pete@otaqui.com>
 */
class Selenium2Driver extends Core_Driver
{
    private const W3C_WINDOW_HANDLE_PREFIX = 'w3cwh:';
    /**
     * Whether the browser has been started
     * @var bool
     */
    private $started = false;
    /**
     * The WebDriver instance
     * @var WebDriver
     */
    private $web_driver;
    /**
     * @var string
     */
    private $browser_name;
    /**
     * @var array
     */
    private $desired_capabilities;
    /**
     * The WebDriverSession instance
     * @var Session|null
     */
    private $wd_session;
    /**
     * @var bool
     */
    private $is_w3c = false;
    /**
     * The timeout configuration
     * @var array{script?: int, implicit?: int, page?: int}
     */
    private $timeouts = [];
    /**
     * @var string|null
     */
    private $initial_window_handle;
    /**
     * @var Escaper
     */
    private $xpath_escaper;
    /**
     * Instantiates the driver.
     *
     * @param string     $browserName         Browser name
     * @param array|null $desiredCapabilities The desired capabilities
     * @param string     $wdHost              The WebDriver host
     */
    public function __construct(string $browser_name = 'firefox', ?array $desired_capabilities = null, string $wd_host = 'http://localhost:4444/wd/hub')
    {
        $this->set_browser_name($browser_name);
        $this->set_desired_capabilities($desired_capabilities);
        $this->set_web_driver(new Web_Driver($wd_host));
        $this->xpath_escaper = new Escaper();
    }
    /**
     * Sets the browser name
     *
     * @param string $browserName the name of the browser to start, default is 'firefox'
     *
     * @return void
     */
    protected function set_browser_name(string $browser_name = 'firefox')
    {
        $this->browser_name = $browser_name;
    }
    /**
     * Sets the desired capabilities - called on construction.  If null is provided, will set the
     * defaults as desired.
     *
     * See http://code.google.com/p/selenium/wiki/DesiredCapabilities
     *
     * @param array|null $desiredCapabilities an array of capabilities to pass on to the WebDriver server
     *
     *
     * @throws DriverException
     */
    public function set_desired_capabilities(?array $desired_capabilities = null): void
    {
        if ($this->started) {
            throw new Driver_Exception('Unable to set desiredCapabilities, the session has already started');
        }
        if (null === $desired_capabilities) {
            $desired_capabilities = [];
        }
        $desired_capabilities['browserName'] = $this->browser_name;
        // Join $desiredCapabilities with defaultCapabilities
        $desired_capabilities = array_replace(self::get_default_capabilities(), $desired_capabilities);
        if (isset($desired_capabilities['firefox'])) {
            foreach ($desired_capabilities['firefox'] as $capability => $value) {
                switch ($capability) {
                    case 'profile':
                        $file_contents = file_get_contents($value);
                        if ($file_contents === false) {
                            throw new Driver_Exception(sprintf('Could not read the profile file "%s".', $value));
                        }
                        $desired_capabilities['firefox_' . $capability] = base64_encode($file_contents);
                        break;
                    default:
                        $desired_capabilities['firefox_' . $capability] = $value;
                }
            }
            unset($desired_capabilities['firefox']);
        }
        // See https://sites.google.com/a/chromium.org/chromedriver/capabilities
        if (isset($desired_capabilities['chrome'])) {
            $chrome_options = isset($desired_capabilities['goog:chromeOptions']) && is_array($desired_capabilities['goog:chromeOptions']) ? $desired_capabilities['goog:chromeOptions'] : [];
            foreach ($desired_capabilities['chrome'] as $capability => $value) {
                if ($capability == 'switches') {
                    $chrome_options['args'] = $value;
                } else {
                    $chrome_options[$capability] = $value;
                }
                $desired_capabilities['chrome.' . $capability] = $value;
            }
            $desired_capabilities['goog:chromeOptions'] = $chrome_options;
            unset($desired_capabilities['chrome']);
        }
        $this->desired_capabilities = $desired_capabilities;
    }
    /**
     * Gets the desiredCapabilities
     *
     * @return array
     */
    public function get_desired_capabilities()
    {
        return $this->desired_capabilities;
    }
    /**
     * Sets the WebDriver instance
     *
     * @param WebDriver $webDriver An instance of the WebDriver class
     */
    public function set_web_driver(Web_Driver $web_driver): void
    {
        $this->web_driver = $web_driver;
    }
    /**
     * Gets the WebDriverSession instance
     *
     * @return Session
     *
     * @throws DriverException if the session is not started
     */
    public function get_web_driver_session()
    {
        if ($this->wd_session === null) {
            throw new Driver_Exception('The driver is not started.');
        }
        return $this->wd_session;
    }
    /**
     * Returns the default capabilities
     *
     * @return array
     */
    public static function get_default_capabilities()
    {
        return ['browserName' => 'firefox', 'name' => 'Behat Test'];
    }
    /**
     * Makes sure that the Syn event library has been injected into the current page,
     * and return $this for a fluid interface,
     *
     *     $this->withSyn()->executeJsOnXpath($xpath, $script);
     *
     * @return Selenium2Driver
     *
     * @throws DriverException
     */
    protected function with_syn()
    {
        $has_syn = $this->get_web_driver_session()->execute(['script' => 'return window.syn !== undefined && window.syn.trigger !== undefined', 'args' => []]);
        if (!$has_syn) {
            $syn_js = file_get_contents(__DIR__ . '/Resources/syn.js');
            \assert($syn_js !== false);
            $this->get_web_driver_session()->execute(['script' => $syn_js, 'args' => []]);
        }
        return $this;
    }
    /**
     * Creates some options for key events
     *
     * @param string|int          $char     the character or code
     * @param KeyModifier::*|null $modifier
     *
     * @return string a json encoded options array for Syn
     *
     * @throws DriverException
     */
    protected static function char_to_options($char, ?string $modifier = null)
    {
        if (is_int($char)) {
            $char_code = $char;
            $char = chr($char_code);
        } else {
            $char_code = ord($char);
        }
        $options = ['key' => $char, 'which' => $char_code, 'charCode' => $char_code, 'keyCode' => $char_code];
        if ($modifier) {
            $options[$modifier . 'Key'] = true;
        }
        $json = json_encode($options);
        if ($json === false) {
            throw new Driver_Exception('Failed to encode options: ' . json_last_error_msg());
        }
        return $json;
    }
    /**
     * Executes JS on a given element - pass in a js script string and {{ELEMENT}} will
     * be replaced with a reference to the result of the $xpath query
     *
     * @example $this->executeJsOnXpath($xpath, 'return {{ELEMENT}}.childNodes.length');
     *
     * @param string $xpath  the xpath to search with
     * @param string $script the script to execute
     * @param bool   $sync   whether to run the script synchronously (default is TRUE)
     *
     * @return mixed
     *
     * @throws DriverException
     */
    protected function execute_js_on_xpath(string $xpath, string $script, bool $sync = true)
    {
        return $this->execute_js_on_element($this->find_element($xpath), $script, $sync);
    }
    /**
     * Executes JS on a given element - pass in a js script string and {{ELEMENT}} will
     * be replaced with a reference to the element
     *
     * @example $this->executeJsOnXpath($xpath, 'return {{ELEMENT}}.childNodes.length');
     *
     * @param Element $element the webdriver element
     * @param string  $script  the script to execute
     * @param bool    $sync    whether to run the script synchronously (default is TRUE)
     *
     * @return mixed
     */
    private function execute_js_on_element(Element $element, string $script, bool $sync = true)
    {
        $script = str_replace('{{ELEMENT}}', 'arguments[0]', $script);
        $options = ['script' => $script, 'args' => [$element]];
        if ($sync) {
            return $this->get_web_driver_session()->execute($options);
        }
        return $this->get_web_driver_session()->execute_async($options);
    }
    public function start(): void
    {
        try {
            $status = $this->web_driver->status();
            $selenium_version = $status['build']['version'] ?? $status['nodes'][0]['version'] ?? 'unknown';
            $selenium_major_version = (int) explode('.', $selenium_version)[0];
        } catch (\Throwable $ex) {
            throw new Driver_Exception("Selenium Server version could not be detected: {$ex->get_message()}", 0, $ex);
        }
        if ($selenium_major_version > 3) {
            throw new Driver_Exception(<<<TEXT
            This driver requires Selenium version 3 or lower, but version {$selenium_version} was found.
            
            Please use the "mink/webdriver-classic-driver" Mink driver or switch to Selenium Server 2.x/3.x.
            TEXT);
        }
        try {
            $this->is_w3c = $selenium_major_version === 3;
            $this->wd_session = $this->web_driver->session($this->browser_name, $this->desired_capabilities);
            $this->apply_timeouts();
            $this->initial_window_handle = $this->get_web_driver_session()->window_handle();
        } catch (\Exception $e) {
            throw new Driver_Exception('Could not open connection: ' . $e->get_message(), 0, $e);
        }
        $this->started = true;
    }
    /**
     * Sets the timeouts to apply to the webdriver session
     *
     * @param array{script?: int, implicit?: int, page?: int} $timeouts times are in milliseconds
     *
     *
     * @throws DriverException
     */
    public function set_timeouts(array $timeouts): void
    {
        $this->timeouts = $timeouts;
        if ($this->is_started()) {
            $this->apply_timeouts();
        }
    }
    /**
     * Applies timeouts to the current session
     */
    private function apply_timeouts(): void
    {
        $valid_timeout_types = ['script', 'implicit', 'page', 'page load', 'pageLoad'];
        try {
            foreach ($this->timeouts as $type => $param) {
                if (!in_array($type, $valid_timeout_types)) {
                    throw new Driver_Exception('Invalid timeout type: ' . $type);
                }
                if ($type === 'page load' || $type === 'pageLoad') {
                    @trigger_error('Using "' . $type . '" timeout type is deprecated, please use "page" instead', E_USER_DEPRECATED);
                    $type = 'page';
                }
                if ($type === 'page') {
                    $type = $this->is_w3c ? 'pageLoad' : 'page load';
                }
                if ($this->is_w3c) {
                    $this->get_web_driver_session()->timeouts([$type => $param]);
                } else {
                    $this->get_web_driver_session()->timeouts($type, $param);
                }
            }
        } catch (Unknown_Error|Invalid_Argument $e) {
            // UnknownError (Selenium 2.x). InvalidArgument (Selenium 3.x).
            throw new Driver_Exception('Error setting timeout: ' . $e->get_message(), 0, $e);
        }
    }
    public function is_started()
    {
        return $this->started;
    }
    public function stop(): void
    {
        if (!$this->wd_session) {
            throw new Driver_Exception('Could not connect to a Selenium 2 / WebDriver server');
        }
        $this->started = false;
        $this->is_w3c = false;
        try {
            $this->wd_session->close();
        } catch (\Exception $e) {
            throw new Driver_Exception('Could not close connection', 0, $e);
        }
    }
    public function reset(): void
    {
        $web_driver_session = $this->get_web_driver_session();
        // Close all windows except the initial one.
        foreach ($web_driver_session->window_handles() as $window_handle) {
            if ($window_handle === $this->initial_window_handle) {
                continue;
            }
            $web_driver_session->focus_window($window_handle);
            $web_driver_session->delete_window();
        }
        $this->switch_to_window();
        $web_driver_session->delete_all_cookies();
    }
    public function visit(string $url): void
    {
        try {
            $this->get_web_driver_session()->open($url);
        } catch (Script_Timeout|Timeout $e) {
            // ScriptTimeout (Selenium 2.x). Timeout (Selenium 3.x).
            throw new Driver_Exception('Page failed to load: ' . $e->get_message(), 0, $e);
        }
    }
    public function get_current_url()
    {
        return $this->get_web_driver_session()->url();
    }
    public function reload(): void
    {
        $this->get_web_driver_session()->refresh();
    }
    public function forward(): void
    {
        $this->get_web_driver_session()->forward();
    }
    public function back(): void
    {
        $this->get_web_driver_session()->back();
    }
    public function switch_to_window(?string $name = null): void
    {
        $handle = $name === null ? $this->initial_window_handle : $this->get_window_handle_from_name($name);
        $this->get_web_driver_session()->focus_window($handle);
    }
    /**
     * @throws DriverException
     */
    private function get_window_handle_from_name(string $name): string
    {
        // if name is actually prefixed window handle, just remove the prefix
        if (strpos($name, self::W3C_WINDOW_HANDLE_PREFIX) === 0) {
            return substr($name, strlen(self::W3C_WINDOW_HANDLE_PREFIX));
        }
        // ..otherwise check if any existing window has the specified name
        $orig_window_handle = $this->get_web_driver_session()->window_handle();
        try {
            foreach ($this->get_web_driver_session()->window_handles() as $handle) {
                $this->get_web_driver_session()->focus_window($handle);
                if ($this->evaluate_script('window.name') === $name) {
                    return $handle;
                }
            }
            throw new Driver_Exception("Could not find handle of window named \"{$name}\"");
        } finally {
            $this->get_web_driver_session()->focus_window($orig_window_handle);
        }
    }
    public function switch_to_i_frame(?string $name = null): void
    {
        $frame_query = $name;
        if ($name) {
            try {
                $frame_query = $this->get_web_driver_session()->element('id', $name);
            } catch (No_Such_Element $e) {
                $frame_query = $this->get_web_driver_session()->element('name', $name);
            }
            $frame_query = $this->serialize_web_element($frame_query);
        }
        $this->get_web_driver_session()->frame(['id' => $frame_query]);
    }
    /**
     * Serialize an Web Element
     *
     * @param Element $webElement Web webElement.
     *
     * @todo   Remove once the https://github.com/instaclick/php-webdriver/issues/131 is fixed.
     */
    private function serialize_web_element(Element $web_element): array
    {
        // Code for WebDriver 2.x version.
        if (class_exists('\WebDriver\LegacyElement') && \defined('\WebDriver\Element::WEB_ELEMENT_ID')) {
            if ($web_element instanceof \Web_Driver\Legacy_Element) {
                return [\Web_Driver\Legacy_Element::LEGACY_ELEMENT_ID => $web_element->get_id()];
            }
            return [Element::WEB_ELEMENT_ID => $web_element->get_id()];
        }
        // Code for WebDriver 1.x version.
        return [\Web_Driver\Container::WEBDRIVER_ELEMENT_ID => $web_element->get_id(), \Web_Driver\Container::LEGACY_ELEMENT_ID => $web_element->get_id()];
    }
    public function set_cookie(string $name, ?string $value = null): void
    {
        if (null === $value) {
            $this->get_web_driver_session()->delete_cookie($name);
            return;
        }
        // PHP 7.4 changed the way it encodes cookies to better respect the spec.
        // This assumes that the server and the Mink client run on the same version (or
        // at least the same side of the behavior change), so that the server and Mink
        // consider the same value.
        if (\PHP_VERSION_ID >= 70400) {
            $encoded_value = rawurlencode($value);
        } else {
            $encoded_value = urlencode($value);
        }
        $cookie_array = ['name' => $name, 'value' => $encoded_value, 'secure' => false];
        $this->get_web_driver_session()->set_cookie($cookie_array);
    }
    public function get_cookie(string $name)
    {
        $cookies = $this->get_web_driver_session()->get_all_cookies();
        foreach ($cookies as $cookie) {
            if ($cookie['name'] === $name) {
                // PHP 7.4 changed the way it encodes cookies to better respect the spec.
                // This assumes that the server and the Mink client run on the same version (or
                // at least the same side of the behavior change), so that the server and Mink
                // consider the same value.
                if (\PHP_VERSION_ID >= 70400) {
                    return rawurldecode($cookie['value']);
                }
                return urldecode($cookie['value']);
            }
        }
        return null;
    }
    public function get_content()
    {
        return $this->get_web_driver_session()->source();
    }
    public function get_screenshot()
    {
        return base64_decode($this->get_web_driver_session()->screenshot());
    }
    public function get_window_names()
    {
        $orig_window = $this->get_web_driver_session()->window_handle();
        try {
            $result = [];
            foreach ($this->get_web_driver_session()->window_handles() as $temp_window) {
                $this->get_web_driver_session()->focus_window($temp_window);
                $result[] = $this->get_window_name();
            }
            return $result;
        } finally {
            $this->get_web_driver_session()->focus_window($orig_window);
        }
    }
    public function get_window_name()
    {
        $name = (string) $this->evaluate_script('window.name');
        if ($name === '') {
            return self::W3C_WINDOW_HANDLE_PREFIX . $this->get_web_driver_session()->window_handle();
        }
        return $name;
    }
    /**
     * @protected
     */
    public function find_element_xpaths(string $xpath)
    {
        $nodes = $this->get_web_driver_session()->elements('xpath', $xpath);
        $elements = [];
        foreach ($nodes as $i => $node) {
            $elements[] = sprintf('(%s)[%d]', $xpath, $i + 1);
        }
        return $elements;
    }
    public function get_tag_name(string $xpath)
    {
        return $this->find_element($xpath)->name();
    }
    public function get_text(string $xpath)
    {
        return trim(str_replace(["\r\n", "\r", "\n", " "], ' ', $this->execute_js_on_xpath($xpath, 'return {{ELEMENT}}.innerText;')));
    }
    public function get_html(string $xpath)
    {
        return $this->execute_js_on_xpath($xpath, 'return {{ELEMENT}}.innerHTML;');
    }
    public function get_outer_html(string $xpath)
    {
        return $this->execute_js_on_xpath($xpath, 'return {{ELEMENT}}.outerHTML;');
    }
    public function get_attribute(string $xpath, string $name)
    {
        $script = 'return {{ELEMENT}}.getAttribute(' . json_encode($name) . ')';
        return $this->execute_js_on_xpath($xpath, $script);
    }
    public function get_value(string $xpath)
    {
        $element = $this->find_element($xpath);
        $element_name = strtolower($element->name());
        $element_type = strtolower($element->attribute('type') ?: '');
        // Getting the value of a checkbox returns its value if selected.
        if ('input' === $element_name && 'checkbox' === $element_type) {
            return $element->selected() ? $element->attribute('value') : null;
        }
        if ('input' === $element_name && 'radio' === $element_type) {
            $script = <<<JS
            var node = {{ELEMENT}},
                value = null;
            
            var name = node.getAttribute('name');
            if (name) {
                var fields = window.document.getElementsByName(name),
                    i, l = fields.length;
                for (i = 0; i < l; i++) {
                    var field = fields.item(i);
                    if (field.form === node.form && field.checked) {
                        value = field.value;
                        break;
                    }
                }
            }
            
            return value;
            JS;
            return $this->execute_js_on_element($element, $script);
        }
        // Using $element->attribute('value') on a select only returns the first selected option
        // even when it is a multiple select, so a custom retrieval is needed.
        if ('select' === $element_name && $element->attribute('multiple')) {
            $script = <<<JS
            var node = {{ELEMENT}},
                value = [];
            
            for (var i = 0; i < node.options.length; i++) {
                if (node.options[i].selected) {
                    value.push(node.options[i].value);
                }
            }
            
            return value;
            JS;
            return $this->execute_js_on_element($element, $script);
        }
        return $element->attribute('value');
    }
    public function set_value(string $xpath, $value): void
    {
        $element = $this->find_element($xpath);
        $element_name = strtolower($element->name());
        if ('select' === $element_name) {
            if (is_array($value)) {
                $this->deselect_all_options($element);
                foreach ($value as $option) {
                    $this->select_option_on_element($element, $option, true);
                }
                return;
            }
            if (\is_bool($value)) {
                throw new Driver_Exception('Boolean values cannot be used for a select element.');
            }
            $this->select_option_on_element($element, $value);
            return;
        }
        if ('input' === $element_name) {
            $element_type = strtolower($element->attribute('type') ?: '');
            if (in_array($element_type, ['submit', 'image', 'button', 'reset'])) {
                throw new Driver_Exception(sprintf('Impossible to set value an element with XPath "%s" as it is not a select, textarea or textbox', $xpath));
            }
            if ('checkbox' === $element_type) {
                if (!is_bool($value)) {
                    throw new Driver_Exception('Only boolean values can be used for a checkbox input.');
                }
                if ($element->selected() xor $value) {
                    $this->click_on_element($element);
                }
                return;
            }
            if ('radio' === $element_type) {
                if (!\is_string($value)) {
                    throw new Driver_Exception('Only string values can be used for a radio input.');
                }
                $this->select_radio_value($element, $value);
                return;
            }
            if ('file' === $element_type) {
                if (!\is_string($value)) {
                    throw new Driver_Exception('Only string values can be used for a file input.');
                }
                $element->post_value(['value' => [strval($value)]]);
                return;
            }
        }
        if (!\is_string($value)) {
            throw new Driver_Exception(sprintf('Only string values can be used for a %s element.', $element_name));
        }
        $value = strval($value);
        if (in_array($element_name, ['input', 'textarea'])) {
            $existing_value_length = strlen($element->attribute('value'));
            $value = str_repeat(Key::BACKSPACE . Key::DELETE, $existing_value_length) . $value;
        }
        $element->post_value(['value' => [$value]]);
        // Remove the focus from the element if the field still has focus in
        // order to trigger the change event. By doing this instead of simply
        // triggering the change event for the given xpath we ensure that the
        // change event will not be triggered twice for the same element if it
        // has lost focus in the meanwhile. If the element has lost focus
        // already then there is nothing to do as this will already have caused
        // the triggering of the change event for that element.
        $script = <<<JS
        var node = {{ELEMENT}};
        if (document.activeElement === node) {
          document.activeElement.blur();
        }
        JS;
        // Cover case, when an element was removed from DOM after its value was
        // changed (e.g. by a JavaScript of a SPA) and therefore can't be focused.
        try {
            $this->execute_js_on_element($element, $script);
        } catch (Stale_Element_Reference $e) {
            // Do nothing because an element was already removed and therefore
            // blurring is not needed.
        }
    }
    public function check(string $xpath): void
    {
        $element = $this->find_element($xpath);
        $this->ensure_input_type($element, $xpath, 'checkbox', 'check');
        if ($element->selected()) {
            return;
        }
        $this->click_on_element($element);
    }
    public function uncheck(string $xpath): void
    {
        $element = $this->find_element($xpath);
        $this->ensure_input_type($element, $xpath, 'checkbox', 'uncheck');
        if (!$element->selected()) {
            return;
        }
        $this->click_on_element($element);
    }
    public function is_checked(string $xpath)
    {
        return $this->find_element($xpath)->selected();
    }
    public function select_option(string $xpath, string $value, bool $multiple = false): void
    {
        $element = $this->find_element($xpath);
        $tag_name = strtolower($element->name());
        if ('input' === $tag_name && 'radio' === strtolower($element->attribute('type') ?: '')) {
            $this->select_radio_value($element, $value);
            return;
        }
        if ('select' === $tag_name) {
            $this->select_option_on_element($element, $value, $multiple);
            return;
        }
        throw new Driver_Exception(sprintf('Impossible to select an option on the element with XPath "%s" as it is not a select or radio input', $xpath));
    }
    public function is_selected(string $xpath)
    {
        return $this->find_element($xpath)->selected();
    }
    public function click(string $xpath): void
    {
        $this->click_on_element($this->find_element($xpath));
    }
    private function click_on_element(Element $element): void
    {
        try {
            // Move the mouse to the element as Selenium does not allow clicking on an element which is outside the viewport
            $this->get_web_driver_session()->moveto(['element' => $element->get_id()]);
        } catch (Unknown_Command $e) {
            // If the Webdriver implementation does not support moveto (which is not part of the W3C WebDriver spec), proceed to the click
        } catch (Unknown_Error $e) {
            // Chromium driver sends back UnknownError (WebDriver\Exception with code 13)
        }
        $element->click();
    }
    public function double_click(string $xpath): void
    {
        $this->mouse_over($xpath);
        $this->get_web_driver_session()->doubleclick();
    }
    public function right_click(string $xpath): void
    {
        if ($this->is_w3c) {
            // See: https://github.com/SeleniumHQ/selenium/commit/085ceed1f55fbaaa1d419b19c73264415c394905.
            throw new Driver_Exception(<<<TEXT
            Right-clicking via JsonWireProtocol is not possible on Selenium Server 3.x.
            
            Please use the "mink/webdriver-classic-driver" Mink driver or switch to Selenium Server 2.x.
            TEXT);
        }
        $this->mouse_over($xpath);
        $this->get_web_driver_session()->click(['button' => 2]);
    }
    public function attach_file(string $xpath, string $path): void
    {
        $element = $this->find_element($xpath);
        $this->ensure_input_type($element, $xpath, 'file', 'attach a file on');
        // Upload the file to Selenium and use the remote path. This will
        // ensure that Selenium always has access to the file, even if it runs
        // as a remote instance.
        try {
            $remote_path = $this->upload_file($path);
        } catch (\Exception $e) {
            // File could not be uploaded to remote instance. Use the local path.
            $remote_path = $path;
        }
        $element->post_value(['value' => [$remote_path]]);
    }
    public function is_visible(string $xpath)
    {
        return $this->find_element($xpath)->displayed();
    }
    public function mouse_over(string $xpath): void
    {
        $this->get_web_driver_session()->moveto(['element' => $this->find_element($xpath)->get_id()]);
    }
    public function focus(string $xpath): void
    {
        $this->trigger($xpath, 'focus');
    }
    public function blur(string $xpath): void
    {
        $this->trigger($xpath, 'blur');
    }
    public function key_press(string $xpath, $char, ?string $modifier = null): void
    {
        $options = self::char_to_options($char, $modifier);
        $this->trigger($xpath, 'keypress', $options);
    }
    public function key_down(string $xpath, $char, ?string $modifier = null): void
    {
        $options = self::char_to_options($char, $modifier);
        $this->trigger($xpath, 'keydown', $options);
    }
    public function key_up(string $xpath, $char, ?string $modifier = null): void
    {
        $options = self::char_to_options($char, $modifier);
        $this->trigger($xpath, 'keyup', $options);
    }
    public function drag_to(string $source_xpath, string $destination_xpath): void
    {
        $source = $this->find_element($source_xpath);
        $target = $this->find_element($destination_xpath);
        $this->get_web_driver_session()->moveto(['element' => $source->get_id()]);
        $this->get_web_driver_session()->buttondown();
        $this->execute_js_on_element($source, <<<'JS'
                    (function (sourceElement) {
                        window['__minkDragAndDropSourceElement'] = sourceElement;
        
                        sourceElement.dispatchEvent(new DragEvent('dragstart', {bubbles: true, cancelable: true}));
                    }({{ELEMENT}}));
        JS);
        $this->get_web_driver_session()->moveto(['element' => $target->get_id()]);
        $this->get_web_driver_session()->buttonup();
        $this->execute_js_on_element($target, <<<'JS'
                    (function (targetElement) {
                        var sourceElement = window['__minkDragAndDropSourceElement'];
        
                        sourceElement.dispatchEvent(new DragEvent('drag', {bubbles: true, cancelable: true}));
                        targetElement.dispatchEvent(new DragEvent('dragover', {bubbles: true, cancelable: true}));
                        targetElement.dispatchEvent(new DragEvent('drop', {bubbles: true, cancelable: true}));
                        sourceElement.dispatchEvent(new DragEvent('dragend', {bubbles: true, cancelable: true}));
                    }({{ELEMENT}}));
        JS);
    }
    public function execute_script(string $script): void
    {
        if (preg_match('/^function[\s\(]/', $script)) {
            $script = preg_replace('/;$/', '', $script);
            $script = '(' . $script . ')';
        }
        $this->get_web_driver_session()->execute(['script' => $script, 'args' => []]);
    }
    public function evaluate_script(string $script)
    {
        if (0 !== strpos(trim($script), 'return ')) {
            $script = 'return ' . $script;
        }
        return $this->get_web_driver_session()->execute(['script' => $script, 'args' => []]);
    }
    public function wait(int $timeout, string $condition)
    {
        $script = 'return (' . rtrim($condition, " \t\n\r;") . ');';
        $start = microtime(true);
        $end = $start + $timeout / 1000.0;
        do {
            $result = $this->get_web_driver_session()->execute(['script' => $script, 'args' => []]);
            if ($result) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $end);
        return (bool) $result;
    }
    public function resize_window(int $width, int $height, ?string $name = null): void
    {
        $this->with_window($name, function () use ($width, $height): void {
            $window = $this->get_web_driver_session()->window('current');
            \assert($window instanceof Window);
            $window->post_size(['width' => $width, 'height' => $height]);
        });
    }
    public function submit_form(string $xpath): void
    {
        $this->find_element($xpath)->submit();
    }
    public function maximize_window(?string $name = null): void
    {
        $this->with_window($name, function (): void {
            $window = $this->get_web_driver_session()->window('current');
            \assert($window instanceof Window);
            $window->maximize();
        });
    }
    private function with_window(?string $name, callable $callback): void
    {
        if ($name === null) {
            $callback();
            return;
        }
        $orig_name = $this->get_window_name();
        try {
            if ($orig_name !== $name) {
                $this->switch_to_window($name);
            }
            $callback();
        } finally {
            if ($orig_name !== $name) {
                $this->switch_to_window($orig_name);
            }
        }
    }
    /**
     * Returns Session ID of WebDriver or `null`, when session not started yet.
     *
     * @return string|null
     */
    public function get_web_driver_session_id()
    {
        return $this->wd_session !== null ? basename($this->wd_session->get_url()) : null;
    }
    /**
     *
     *
     * @throws DriverException
     */
    private function find_element(string $xpath): Element
    {
        return $this->get_web_driver_session()->element('xpath', $xpath);
    }
    /**
     * Selects a value in a radio button group
     *
     * @param Element $element An element referencing one of the radio buttons of the group
     * @param string  $value   The value to select
     *
     * @throws DriverException when the value cannot be found
     */
    private function select_radio_value(Element $element, string $value): void
    {
        // short-circuit when we already have the right button of the group to avoid XPath queries
        if ($element->attribute('value') === $value) {
            $element->click();
            return;
        }
        $name = $element->attribute('name');
        if (!$name) {
            throw new Driver_Exception(sprintf('The radio button does not have the value "%s"', $value));
        }
        $form_id = $element->attribute('form');
        try {
            if (null !== $form_id) {
                $xpath = <<<'XPATH'
                //form[@id=%1$s]//input[@type="radio" and not(@form) and @name=%2$s and @value = %3$s]
                |
                //input[@type="radio" and @form=%1$s and @name=%2$s and @value = %3$s]
                XPATH;
                $xpath = sprintf($xpath, $this->xpath_escaper->escape_literal($form_id), $this->xpath_escaper->escape_literal($name), $this->xpath_escaper->escape_literal($value));
                $input = $this->get_web_driver_session()->element('xpath', $xpath);
            } else {
                $xpath = sprintf('./ancestor::form//input[@type="radio" and not(@form) and @name=%s and @value = %s]', $this->xpath_escaper->escape_literal($name), $this->xpath_escaper->escape_literal($value));
                $input = $element->element('xpath', $xpath);
            }
        } catch (No_Such_Element $e) {
            $message = sprintf('The radio group "%s" does not have an option "%s"', $name, $value);
            throw new Driver_Exception($message, 0, $e);
        }
        $input->click();
    }
    /**
     * @throws DriverException
     */
    private function select_option_on_element(Element $element, string $value, bool $multiple = false): void
    {
        $escaped_value = $this->xpath_escaper->escape_literal($value);
        // The value of an option is the normalized version of its text when it has no value attribute
        $option_query = sprintf('.//option[@value = %s or (not(@value) and normalize-space(.) = %s)]', $escaped_value, $escaped_value);
        $option = $element->element('xpath', $option_query);
        if ($multiple || !$element->attribute('multiple')) {
            if (!$option->selected()) {
                $option->click();
            }
            return;
        }
        // Deselect all options before selecting the new one
        $this->deselect_all_options($element);
        $option->click();
    }
    /**
     * Deselects all options of a multiple select
     *
     * Note: this implementation does not trigger a change event after deselecting the elements.
     *
     *
     * @throws DriverException
     */
    private function deselect_all_options(Element $element): void
    {
        $script = <<<JS
        var node = {{ELEMENT}};
        var i, l = node.options.length;
        for (i = 0; i < l; i++) {
            node.options[i].selected = false;
        }
        JS;
        $this->execute_js_on_element($element, $script);
    }
    /**
     * Ensures the element is of the specified type
     *
     * @throws DriverException
     */
    private function ensure_input_type(Element $element, string $xpath, string $type, string $action): void
    {
        if ('input' !== strtolower($element->name()) || $type !== strtolower($element->attribute('type') ?: '')) {
            $message = 'Impossible to %s the element with XPath "%s" as it is not a %s input';
            throw new Driver_Exception(sprintf($message, $action, $xpath, $type));
        }
    }
    /**
     * @throws DriverException
     */
    private function trigger(string $xpath, string $event, string $options = '{}'): void
    {
        $script = 'syn.trigger({{ELEMENT}}, "' . $event . '", ' . $options . ')';
        $this->with_syn()->execute_js_on_xpath($xpath, $script);
    }
    /**
     * Uploads a file to the Selenium instance.
     *
     * Note that uploading files is not part of the official WebDriver
     * specification, but it is supported by Selenium.
     *
     * @param string $path     The path to the file to upload.
     *
     * @return string          The remote path.
     *
     * @throws DriverException When PHP is compiled without zip support, or the file doesn't exist.
     * @throws UnknownError    When an unknown error occurred during file upload.
     * @throws \Exception      When a known error occurred during file upload.
     *
     * @see https://github.com/SeleniumHQ/selenium/blob/master/py/selenium/webdriver/remote/webelement.py#L533
     */
    private function upload_file(string $path): string
    {
        if (!is_file($path)) {
            throw new Driver_Exception('File does not exist locally and cannot be uploaded to the remote instance.');
        }
        if (!class_exists('ZipArchive')) {
            throw new Driver_Exception('Could not compress file, PHP is compiled without zip support.');
        }
        // Selenium only accepts uploads that are compressed as a Zip archive.
        $temp_filename = tempnam('', 'WebDriverZip');
        if ($temp_filename === false) {
            throw new Driver_Exception('Could not create a temporary file.');
        }
        $archive = new \Zip_Archive();
        $result = $archive->open($temp_filename, \Zip_Archive::OVERWRITE);
        if ($result !== true) {
            throw new Driver_Exception('Zip archive could not be created. Error ' . $result);
        }
        $result = $archive->add_file($path, basename($path));
        if (!$result) {
            throw new Driver_Exception('File could not be added to zip archive.');
        }
        $result = $archive->close();
        if (!$result) {
            throw new Driver_Exception('Zip archive could not be closed.');
        }
        $file_contents = file_get_contents($temp_filename);
        \assert($file_contents !== false);
        try {
            $remote_path = $this->get_web_driver_session()->file(['file' => base64_encode($file_contents)]);
            // If no path is returned the file upload failed silently. In this
            // case it is possible Selenium was not used but another web driver
            // such as PhantomJS.
            // @todo Support other drivers when (if) they get remote file transfer
            // capability.
            if (empty($remote_path)) {
                throw new Unknown_Error();
            }
        } finally {
            unlink($temp_filename);
        }
        return $remote_path;
    }
}