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
