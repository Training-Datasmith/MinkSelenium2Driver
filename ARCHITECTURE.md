# Architecture: MinkSelenium2Driver

## Purpose

Mink driver that uses the WebDriver protocol (Selenium 2 / W3C WebDriver) to control real or headless browsers. Supports JavaScript execution, file uploads, and browser-native interactions.

## Directory Structure

```
src/
  Selenium2Driver.php   Single-file driver implementing Mink's Driver_Interface via WebDriver
tests/                  PHPUnit integration tests (require a running WebDriver server)
```

## Key Design Decisions

- **WebDriver protocol**: All browser control happens over HTTP to a WebDriver endpoint (ChromeDriver, geckodriver, Selenium Grid). This supports any W3C-compliant browser.
- **Element caching**: WebElement references are cached per XPath to reduce round-trips. Cache is invalidated on navigation.
- **JavaScript bridge**: `executeScript` and `executeAsyncScript` allow arbitrary JS execution, enabling full interaction testing.

## Extension Points

- Pass `desiredCapabilities` array to the constructor to target specific browsers or remote Selenium Grid nodes.
- Override `getWebDriverUrl()` if the WebDriver endpoint is non-standard.

## Dependency Flow

```
Mink Session
  -> Selenium2Driver::visit(url)
    -> WebDriver HTTP POST /session/{id}/url
  -> Selenium2Driver::find(xpath)
    -> WebDriver POST /session/{id}/elements  {using: xpath, value: ...}
    -> returns WebElement id references
  -> NodeElement::click()
    -> Selenium2Driver::click(xpath)
      -> WebDriver POST /session/{id}/element/{id}/click
```
