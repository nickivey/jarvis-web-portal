const { test, expect } = require('@playwright/test');

test('Home permissions & simple voice flow smoke test', async ({ page, context }) => {
  // Grant permissions so the page won't be blocked by prompts
  await context.grantPermissions(['geolocation','microphone','notifications']);
  // Capture browser console and page errors for debugging
  page.on('console', msg => console.log('BROWSER_CONSOLE:', msg.text()));
  page.on('pageerror', err => console.log('BROWSER_ERROR:', err.message));
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

  // Debugging: dump a few client-side values
  const dump = await page.evaluate(()=>({ ready: document.readyState, tokenType: typeof window.jarvisJwt, sendBtnPresent: !!document.getElementById('sendBtn') }));
  console.log('PAGE DUMP:', JSON.stringify(dump));
  // Print the first ~1200 chars of page content to inspect inline scripts (debug only)
  const content = await page.content();
  console.log('PAGE HTML SNIPPET:\n' + content.slice(0, 1200));
  // Wait until client-side scripts are initialized (JWT exposed) and Send button is enabled
  await page.waitForFunction(()=> typeof window.jarvisJwt !== 'undefined', null, { timeout: 5000 });
  await expect(page.locator('#sendBtn')).toBeEnabled({ timeout: 5000 });

  // Send a simple whoami command and expect a response containing '@' (email)
  await page.fill('#messageInput', 'whoami');
  console.log('Clicking send button');
  await page.click('#sendBtn', { force: true });
  // Wait for input to clear (indicates send processed) then confirm our message was appended
  // Wait for the send handlers to receive the click/submit event (debug hooks)
  await page.waitForFunction(()=> window._sendBtnClicked || window._chatFormSubmitFired || window._handleSendActionInvoked, null, { timeout: 3000 });
  await expect(page.locator('#messageInput')).toHaveValue('', {timeout: 5000});
  // As a fallback, wait for an internal test hook set by the client to ensure the message append ran
  await page.waitForFunction(()=> window._lastAppendedMessage && window._lastAppendedMessage.includes('whoami'), null, { timeout: 5000 });
  await expect(page.locator('.msg.me .bubble')).toContainText('whoami', {timeout: 5000});
  // Wait for a jarvis response bubble to appear with 'You are' text
  await expect(page.locator('.msg.jarvis .bubble')).toContainText('@', {timeout: 10000});

  // Simulate an audio blob upload (as if recorded) and confirm it's saved via /api/voice
  await page.evaluate(async ()=>{
    if (!window.jarvisJwt) return; // shouldn't happen
    const blob = new Blob(['dummy audio content'], { type: 'audio/webm' });
    const fd = new FormData(); fd.append('file', blob, 'test.webm'); fd.append('transcript','auto test transcript'); fd.append('duration','1234'); fd.append('meta', JSON.stringify({ test:true }));
    const r = await fetch('/api/voice', { method: 'POST', body: fd, headers: { 'Authorization': 'Bearer ' + window.jarvisJwt } });
    return r.status;
  });

  // Fetch recent voice inputs via API and assert that our transcript is present
  const voiceResp = await page.request.get('/api/voice?limit=5', { headers: { 'Authorization': 'Bearer ' + (await page.evaluate(()=>window.jarvisJwt)) } });
  const vjson = await voiceResp.json();
  await expect(vjson.ok).toBeTruthy();
  await expect(vjson.voice.some(v => (v.transcript||'').includes('auto test transcript'))).toBeTruthy();
});
