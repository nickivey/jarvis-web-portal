const { test, expect } = require('@playwright/test');

// Verify that a photo with GPS appears on the Photos map via EXIF/metadata
// This test uploads a dummy image, triggers a reprocess with a GPS override, then checks for a Leaflet marker.
test('Photo map shows GPS marker after reprocess override', async ({ page, context }) => {
  await context.grantPermissions(['geolocation','notifications']);
  // Login
  await page.goto('/login.php');
  await page.fill('input[name="email"]', 'e2e_bot@example.com');
  await page.fill('input[name="password"]', 'password');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/home.php');

  // Ensure JWT present or inject one via helper script
  try {
    const token = require('child_process').execSync("bash -lc \"export JWT_SECRET=$(grep -E '^JWT_SECRET=' .env | sed -E 's/^JWT_SECRET=\"?([^\"]*)\"?/\\1/') && php scripts/get-jwt.php\"", { encoding: 'utf8' }).trim();
    if (token && (await page.evaluate(() => typeof window.jarvisJwt === 'undefined'))) {
      await page.evaluate((t) => { window.jarvisJwt = t; window.dispatchEvent(new CustomEvent('jarvis.token.set')); }, token);
    }
  } catch (e) { throw e; }
  await page.waitForFunction(()=> typeof window.jarvisJwt !== 'undefined');
  const tokenVal = await page.evaluate(()=>window.jarvisJwt);

  // Upload a dummy image via API (multipart)
  const uploadRes = await page.evaluate(async ()=>{
    const blob = new Blob(['gps test content'], { type: 'image/jpeg' });
    const fd = new FormData(); fd.append('file', blob, 'gps_test.jpg'); fd.append('meta', JSON.stringify({ source: 'e2e-gps' }));
    const r = await fetch('/api/photos', { method: 'POST', body: fd, headers: { 'Authorization': 'Bearer ' + window.jarvisJwt } });
    return r.json();
  });
  expect(uploadRes && uploadRes.id).toBeTruthy();
  const photoId = uploadRes.id;

  // Trigger reprocess with GPS override so the map marker is created
  const rep = await page.request.post(`/api/photos/${photoId}/reprocess`, { headers: { 'Authorization': 'Bearer ' + tokenVal, 'Content-Type':'application/json' }, data: { gps_lat: 37.7749, gps_lon: -122.4194 } });
  expect(rep.status()).toBe(200);
  const repJson = await rep.json();
  expect(repJson.ok).toBeTruthy();

  // Navigate to Photos page and expect a Leaflet marker to be present
  await page.goto('/public/photos.php');
  // Wait for map to initialize and markers to be added
  await page.waitForSelector('.leaflet-marker-icon', { timeout: 10000 });
  const markersCount = await page.locator('.leaflet-marker-icon').count();
  expect(markersCount).toBeGreaterThan(0);
});
