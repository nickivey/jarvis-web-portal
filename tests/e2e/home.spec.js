const { test, expect } = require('@playwright/test');

test('Home permissions & simple voice flow smoke test', async ({ page, context }) => {
  // Grant permissions so the page won't be blocked by prompts
  await context.grantPermissions(['geolocation','microphone','notifications']);
  // Navigate to login and sign in
  await page.goto('/login.php');
  await page.fill('input[name="email"]', 'e2e_bot@example.com');
  await page.fill('input[name="password"]', 'password');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/home.php');

  // Ensure the chat input exists
  await expect(page.locator('#messageInput')).toBeVisible();

  // Ensure enableNotif checkbox exists and reflect notification permission
  const cb = page.locator('#enableNotif');
  await expect(cb).toBeVisible();

  // Send a simple whoami command and expect a response containing '@' (email)
  await page.fill('#messageInput', 'whoami');
  await page.click('#sendBtn');
  // Wait for a jarvis response bubble to appear with 'You are' text
  await expect(page.locator('.msg.jarvis .bubble')).toContainText('@', {timeout: 5000});
});
